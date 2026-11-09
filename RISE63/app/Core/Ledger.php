<?php
declare(strict_types=1);

namespace RISE63\Core;

use InvalidArgumentException;

/**
 * Remittance records and the append-only ledger.
 *
 * Two invariants:
 *  1. A row in remittance_ledger is never updated or deleted (database triggers enforce it).
 *  2. A Seed Grant disbursement can never exceed the remaining monthly pool, even when two
 *     treasurers confirm at the same instant — the pool row is locked with SELECT … FOR UPDATE.
 */
final class Ledger
{
    /**
     * Record a disbursement request. The treasurer confirms it separately with the PIN.
     *
     * @param array<string, mixed> $application
     * @param array<string, mixed> $actor
     */
    public static function requestDisbursement(array $application, array $actor, array $input): int
    {
        if (!Auth::isTreasurer($actor)) {
            throw new InvalidArgumentException('Only the provincial treasurer can start a disbursement.');
        }
        if (!in_array((string) $application['status'], ['approved', 'disbursed'], true)) {
            throw new InvalidArgumentException('Only an approved application can be paid out.');
        }
        $amount = Money::fromNaira($input['amount'] ?? '0');
        if ($amount->isZero()) {
            throw new InvalidArgumentException('Enter the amount to disburse.');
        }
        $payee  = trim((string) ($input['payee_name'] ?? ''));
        $bank   = trim((string) ($input['bank_name'] ?? ''));
        $number = preg_replace('/\s+/', '', (string) ($input['account_number'] ?? '')) ?? '';
        if ($payee === '' || $bank === '') {
            throw new InvalidArgumentException('Enter the payee name and bank.');
        }
        if (preg_match('/^\d{10}$/D', $number) !== 1) {
            throw new InvalidArgumentException('Enter a 10-digit Nigerian account number.');
        }
        if ($payee === (string) $application['full_name'] && (string) $application['program_type'] === 'education_bridge') {
            // Education Bridge pays the school, not the student.
            throw new InvalidArgumentException('Education Bridge payments go directly to the school, not the applicant.');
        }

        $reference = 'RS63-' . strtoupper(bin2hex(random_bytes(4))) . '-' . gmdate('ymd');

        Database::run(
            'INSERT INTO remittances (application_id, amount, payee_name, bank_name, account_number, payment_reference, requested_by)
             VALUES (?,?,?,?,?,?,?)',
            [(int) $application['id'], $amount->toDecimal(), $payee, $bank, $number, $reference, (int) $actor['id']]
        );
        $id = Database::id();
        Audit::log((int) $actor['id'], 'remittance.request', 'remittance', (string) $id, $amount->format() . ' → ' . $payee);
        return $id;
    }

    /**
     * Confirm a pending disbursement. Wallet-moving step: PIN verified by the controller.
     *
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public static function confirm(int $remittanceId, array $actor): array
    {
        if (!Auth::isTreasurer($actor)) {
            throw new InvalidArgumentException('Only the provincial treasurer can confirm a disbursement.');
        }

        return Database::transaction(function () use ($remittanceId, $actor): array {
            $remittance = Database::one('SELECT * FROM remittances WHERE id = ? FOR UPDATE', [$remittanceId]);
            if ($remittance === null) {
                throw new NotFound('That disbursement no longer exists.');
            }
            if ((string) $remittance['status'] !== 'pending') {
                throw new InvalidArgumentException('That disbursement has already been confirmed.');
            }

            $application = Database::one('SELECT * FROM program_applications WHERE id = ? FOR UPDATE', [(int) $remittance['application_id']]);
            if ($application === null) {
                throw new NotFound('The linked application no longer exists.');
            }

            $amount = Money::fromDecimal($remittance['amount']);
            $program = (string) $application['program_type'];

            // Serialise every confirmation that draws on the same fund. Locking the remittance row
            // above is not enough: two treasurers confirming two *different* pending grants at the
            // same instant hold two different remittance locks, both read the same remaining pool,
            // and both conclude there is room — which overdraws it. The blueprint calls for a
            // `SELECT … FOR UPDATE` guard on the pool, and this is it: one row per program per year,
            // taken after the remittance lock so the lock order is always the same and cannot
            // deadlock against itself.
            self::lockPool($program, (int) $application['cycle_year']);

            $balanceAfter = self::poolBalance($program, (int) $application['cycle_year']);
            if ($program === 'seed_grant') {
                if ($amount->compare($balanceAfter) === 1) {
                    throw new InvalidArgumentException(
                        'This would overdraw the Seed Grant pool. Remaining for this year: ' . $balanceAfter->format() . '.'
                    );
                }
            }

            $newBalance = $balanceAfter->minus($amount);
            $reference  = 'LED-' . (string) $remittance['payment_reference'];

            Database::run(
                'INSERT INTO remittance_ledger (entry_type, program_type, application_id, amount, balance_after, reference, description, created_by)
                 VALUES (?,?,?,?,?,?,?,?)',
                [
                    'disbursement',
                    $program,
                    (int) $application['id'],
                    $amount->toDecimal(),
                    $newBalance->toDecimal(),
                    $reference,
                    mb_substr((string) $remittance['payee_name'] . ' · ' . (string) $remittance['bank_name'], 0, 255),
                    (int) $actor['id'],
                ]
            );

            Database::run('UPDATE remittances SET status = ?, confirmed_by = ?, confirmed_at = UTC_TIMESTAMP() WHERE id = ?', ['paid', (int) $actor['id'], $remittanceId]);
            Database::run('UPDATE program_applications SET status = ?, disbursed_at = UTC_TIMESTAMP() WHERE id = ?', ['disbursed', (int) $application['id']]);
            Applications::timeline((int) $application['id'], (int) $actor['id'], (string) $application['status'], 'disbursed', 'Disbursement ' . (string) $remittance['payment_reference'] . ' confirmed.');
            Audit::log((int) $actor['id'], 'remittance.confirm', 'remittance', (string) $remittanceId, $amount->format());

            $fresh = Database::one('SELECT * FROM remittances WHERE id = ?', [$remittanceId]);
            $applicationFresh = Database::one('SELECT * FROM program_applications WHERE id = ?', [(int) $application['id']]);
            if ($fresh !== null && $applicationFresh !== null) {
                Notifier::disbursement($fresh, $applicationFresh);
                Notifier::applicationStatus($applicationFresh, 'disbursed');
            }
            return $fresh ?? $remittance;
        });
    }

    /**
     * Take the advisory lock for one program's pool in one cycle year.
     *
     * `GET_LOCK` with a deterministic name (`rise63:pool:<program>:<year>`) rather than
     * `SELECT … FOR UPDATE` on a table, because MySQL only blocks row locks on rows that exist:
     * locking a fund with no ledger rows yet would lock nothing and let the race through. A named
     * advisory lock has no such blind spot, is released automatically when the transaction ends or
     * the connection dies, and is the same mechanism on MySQL 8 and MariaDB 10.6.
     *
     * The name is deterministic and the timeout is short: a stale lock can delay a confirmation by
     * at most 5 seconds before the attempt is refused with a clear message, rather than appearing
     * to hang. No schema change is needed — GET_LOCK is a server feature, not a table.
     */
    public static function lockPool(string $program, int $cycleYear): void
    {
        $name = 'rise63:pool:' . substr(preg_replace('/[^a-z_]/', '', $program) ?? 'unknown', 0, 20) . ':' . $cycleYear;
        $got  = Database::value('SELECT GET_LOCK(?, 5)', [$name]);
        if ((int) $got !== 1) {
            throw new \RuntimeException(
                'Another disbursement for this fund is being confirmed right now. Try again in a moment.'
            );
        }
        // The lock is held until the transaction commits; register the release for the outer scope
        // so a thrown exception inside the transaction still unlocks before the connection returns
        // to the pool.
        register_shutdown_function(static function () use ($name): void {
            try {
                Database::value('SELECT RELEASE_LOCK(?)', [$name]);
            } catch (\Throwable) {
                // The connection may already be gone; MySQL releases named locks on disconnect.
            }
        });
    }

    /**
     * Remaining pool for a program in a cycle year.
     *
     * This used to be derived from the calendar (monthly allowance × months elapsed), which meant
     * the "Seed grant pool left" figure on the treasury screen could not be set by anyone and moved
     * on its own. It is now the real balance from {@see Fund}: opening commitment + deposits +
     * adjustments − disbursements, all of which are attributable ledger rows.
     *
     * `pending` is subtracted as well, because a queued disbursement is money promised. Showing it
     * as still-available would let two treasurers promise the same naira twice.
     */
    public static function poolBalance(string $program, int $cycleYear): Money
    {
        $balance = Fund::balance($program, $cycleYear);
        $pending = Money::fromDecimal((string) Database::value(
            "SELECT COALESCE(SUM(r.amount),0) FROM remittances r
             JOIN program_applications a ON a.id = r.application_id
             WHERE a.program_type = ? AND a.cycle_year = ? AND r.status = 'pending'",
            [$program, $cycleYear]
        ));
        return $balance['remaining']->compare($pending) === 1 ? $balance['remaining']->minus($pending) : Money::zero();
    }

    /** @return list<array<string, mixed>> */
    public static function entries(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return Database::all(
            "SELECT l.*, u.full_name AS actor_name FROM remittance_ledger l LEFT JOIN users u ON u.id = l.created_by
             ORDER BY l.id DESC LIMIT {$limit}"
        );
    }

    /** @return list<array<string, mixed>> */
    public static function pending(): array
    {
        return Database::all(
            "SELECT r.*, a.program_type, a.cycle_year, u.full_name AS applicant, p.parish_name
             FROM remittances r
             JOIN program_applications a ON a.id = r.application_id
             JOIN users u ON u.id = a.user_id
             LEFT JOIN parishes p ON p.id = a.parish_id
             WHERE r.status = 'pending' ORDER BY r.created_at ASC LIMIT 100"
        );
    }

    public static function totals(): array
    {
        $disbursed = Money::fromDecimal((string) Database::value(
            "SELECT COALESCE(SUM(amount),0) FROM remittance_ledger WHERE entry_type = 'disbursement'"
        ));
        $pending = Money::fromDecimal((string) Database::value(
            "SELECT COALESCE(SUM(amount),0) FROM remittances WHERE status = 'pending'"
        ));
        return [
            'disbursed' => $disbursed,
            'pending'   => $pending,
            'pool'      => self::poolBalance('seed_grant', Settings::int('current_cycle_year', (int) gmdate('Y'))),
        ];
    }
}
