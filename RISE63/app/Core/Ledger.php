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
     * Remaining pool for a program in a cycle year.
     * For Seed Grants the pool is a monthly allowance multiplied by the months elapsed,
     * minus everything already disbursed in that year.
     */
    public static function poolBalance(string $program, int $cycleYear): Money
    {
        if ($program !== 'seed_grant') {
            return Money::fromCents(Money::MAX_CENTS);
        }
        $monthly  = Money::fromDecimal((string) Settings::get('seed_grant_monthly_pool', '0'));
        $thisYear = (int) gmdate('Y');
        if ($cycleYear === $thisYear) {
            $months = (int) gmdate('n');
        } elseif ($cycleYear < $thisYear) {
            $months = 12;
        } else {
            $months = 0;
        }
        // Pool accrues monthly: months elapsed ÷ 12 of the annual allowance, half-up on kobo.
        $basisPoints = (int) round($months * 10000 / 12);
        $reserved    = $monthly->percent($basisPoints, 10000);

        $commitment = Money::fromDecimal((string) Database::value(
            "SELECT COALESCE(SUM(r.amount),0) FROM remittances r
             JOIN program_applications a ON a.id = r.application_id
             WHERE a.program_type = 'seed_grant' AND a.cycle_year = ? AND r.status IN ('paid','pending')",
            [$cycleYear]
        ));
        return $reserved->compare($commitment) === 1 ? $reserved->minus($commitment) : Money::zero();
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
