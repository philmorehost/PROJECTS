<?php
declare(strict_types=1);

namespace RISE63\Core;

use InvalidArgumentException;

/**
 * Program pools: what has been funded, what has been spent, and what is left.
 *
 * The figure the treasury screen used to show ("Seed grant pool left") was not a pool at all. It
 * was `monthly × months-elapsed ÷ 12` minus commitments — a number derived from the calendar, which
 * the administrator could not see, could not override, and which reset itself on 1 January without
 * anyone deciding that it should. Two problems followed: an admin looking at the figure had no way
 * to change it, and the *monthly* setting it was derived from could not even be saved (see
 * Settings::validateMoney — a `money` type with no bounds rejected every value).
 *
 * A pool is now a real balance with three named parts:
 *
 *   opening balance      the commitment for the cycle, set by an administrator
 *   + deposits           money that actually arrived (pool_deposit ledger rows)
 *   + adjustments        corrections (adjustment ledger rows)
 *   − disbursements      money actually paid out (disbursement ledger rows)
 *   = remaining          what is left to commit
 *
 * **What belongs in the ledger, and what does not.** The ledger is a record of money moving: a
 * deposit and a disbursement are events that happened and cannot be un-happened. Restating a
 * budget is not an event — it is a decision — so it changes the opening balance and is recorded in
 * the audit trail, not as a ledger row. Writing it to both places would count the same naira twice.
 *
 * For Seed Grants the documented ₦100,000-per-month behaviour survives as the *default* opening
 * balance for a year nobody has set: it accrues as the year passes, so in March three months are
 * available and in December twelve, and a closed year offers all twelve. The moment an
 * administrator sets the figure, that figure is the answer and the calendar stops mattering.
 *
 * Invariant: a remaining balance is never negative, and a pool can never be set below what has
 * already been paid from it. Both are enforced here rather than merely displayed, because a
 * negative pool is a promise the province cannot keep.
 */
final class Fund
{
    /** Config keys holding each program's opening balance. */
    public const OPENING_KEYS = [
        'seed_grant'       => 'seed_grant_opening_balance',
        'academy'          => 'academy_pool',
        'cooperative'      => 'cooperative_pool',
        'education_bridge' => 'education_bridge_pool',
    ];

    /** The monthly allowance that seeds the Seed Grant opening balance. */
    public const MONTHLY_KEY = 'seed_grant_monthly_pool';

    public const LABELS = [
        'seed_grant'       => 'Seed Grants',
        'academy'          => 'RISE Academy',
        'cooperative'      => 'Cooperative Access',
        'education_bridge' => 'Education Bridge',
    ];

    /**
     * The balance of a program in a cycle year.
     *
     * @return array{opening: Money, deposited: Money, adjusted: Money, disbursed: Money, committed: Money, remaining: Money, funded: bool, source: string}
     */
    public static function balance(string $program, int $cycleYear): array
    {
        self::assertProgram($program);

        $opening   = self::openingBalance($program, $cycleYear);
        $deposited = self::sumLedger('pool_deposit', $program);
        $adjusted  = self::sumLedger('adjustment', $program);
        $disbursed = self::sumDisbursed($program, $cycleYear);

        $committed = self::sum($opening, $deposited, $adjusted);
        // A pool that has been over-paid (possible only through a manual database change, since
        // confirmation refuses to overdraw) reports zero rather than a negative number.
        $remaining = $committed->compare($disbursed) === 1 ? $committed->minus($disbursed) : Money::zero();

        return [
            'opening'   => $opening,
            'deposited' => $deposited,
            'adjusted'  => $adjusted,
            'disbursed' => $disbursed,
            'committed' => $committed,
            'remaining' => $remaining,
            'funded'    => !$committed->isZero(),
            'source'    => self::isSetFor($program, $cycleYear) ? 'set' : ($program === 'seed_grant' ? 'accrued' : 'unset'),
        ];
    }

    /**
     * The opening balance for a cycle year.
     *
     * Seed Grants accrue the documented monthly allowance while no figure has been set for the
     * year. Every other program has a plain opening balance that an administrator sets.
     */
    public static function openingBalance(string $program, int $cycleYear): Money
    {
        self::assertProgram($program);
        $key = self::OPENING_KEYS[$program];

        if ($program === 'seed_grant') {
            if (self::isSetFor($program, $cycleYear)) {
                return self::stored($key);
            }
            return self::accruedSeedGrant($cycleYear);
        }

        return self::stored($key);
    }

    /**
     * The documented monthly accrual, used only until an administrator sets the year's figure.
     *
     * A past year offers the whole annual allowance (the year is over; all of it was available).
     * A future year offers nothing (it has not started). The current year offers the months so far.
     */
    public static function accruedSeedGrant(int $cycleYear): Money
    {
        $monthly  = self::stored(self::MONTHLY_KEY);
        $thisYear = (int) gmdate('Y');
        if ($cycleYear > $thisYear) {
            return Money::zero();
        }
        $months = $cycleYear === $thisYear ? (int) gmdate('n') : 12;
        return self::percentOf($monthly, $months);
    }

    /** `$monthly × months / 12`, half-up on the kobo, without overflowing in kobo×10000. */
    private static function percentOf(Money $monthly, int $months): Money
    {
        $months = max(0, min(12, $months));
        // Divide first, then multiply by the remainder: ₦100,000/month for 3 months is
        // 100000×3/12 = 25,000. Doing it in cents avoids Money::percent()'s kobo×basis-points
        // intermediate, which overflows a 32-bit int on large pools.
        $cents = intdiv($monthly->cents() * $months + 6, 12);
        return Money::fromCents($cents);
    }

    /**
     * Set the pool for a cycle year, explicitly.
     *
     * `$total` is the *whole* commitment for the year — an administrator setting ₦600,000 means
     * "six hundred thousand is available for this year's grants", and what remains is that minus
     * what has been paid. Because deposits and adjustments are real ledger rows that also count
     * towards the commitment, the opening balance is set to `total − deposits − adjustments`, so
     * the parts still add up to exactly what was asked for rather than double-counting.
     *
     * Setting it below what has been disbursed is refused rather than silently clamped: that would
     * hide a real accounting problem behind a tidy number.
     */
    public static function setPool(string $program, int $cycleYear, Money $total, ?int $actorId, string $reason = ''): Money
    {
        self::assertProgram($program);
        if ($cycleYear < 2024 || $cycleYear > 2100) {
            throw new InvalidArgumentException('Enter a program year between 2024 and 2100.');
        }

        $current   = self::balance($program, $cycleYear);
        $disbursed = $current['disbursed'];
        if ($total->compare($disbursed) === -1) {
            throw new InvalidArgumentException(
                'That is less than the ' . $disbursed->format() . ' already disbursed for ' . $cycleYear
                . '. A pool cannot be smaller than what has been paid from it.'
            );
        }

        $others  = self::sum($current['deposited'], $current['adjusted']);
        $target  = $total->compare($others) === 1 ? $total->minus($others) : Money::zero();

        Database::transaction(function () use ($program, $cycleYear, $total, $target, $current, $actorId, $reason): void {
            Settings::set(self::OPENING_KEYS[$program], $target->toDecimal(), $actorId);
            if ($program === 'seed_grant') {
                // Which year the figure belongs to. Without this, setting a pool for 2027 would
                // silently become the figure for 2026 as well.
                Settings::set('seed_grant_opening_balance_year', (string) $cycleYear, $actorId);
            }

            Audit::log(
                $actorId,
                'ledger.set_pool',
                'fund',
                $program,
                self::LABELS[$program] . ' pool for ' . $cycleYear . ' set to ' . $total->format()
                . ' (was ' . $current['committed']->format() . ')'
                . ($reason !== '' ? ' · ' . $reason : '')
            );
        });

        return self::balance($program, $cycleYear)['remaining'];
    }

    /**
     * Record money actually received into a program pool.
     *
     * Separate from setPool on purpose: "we received ₦200,000 for grants" is an event, and "the
     * year's pool is ₦500,000" is a statement of the budget. Mixing them makes the ledger lie
     * about what happened.
     */
    public static function deposit(string $program, Money $amount, ?int $actorId, string $note = ''): void
    {
        self::assertProgram($program);
        if ($amount->compare(Money::zero()) !== 1) {
            throw new InvalidArgumentException('Enter an amount greater than zero.');
        }

        $cycleYear = Settings::cycleYear();

        Database::transaction(function () use ($program, $amount, $actorId, $note, $cycleYear): void {
            $balance = self::balance($program, $cycleYear);
            Database::run(
                'INSERT INTO remittance_ledger (entry_type, program_type, application_id, amount, balance_after, reference, description, created_by)
                 VALUES (?,?,?,?,?,?,?,?)',
                [
                    'pool_deposit',
                    $program,
                    null,
                    $amount->toDecimal(),
                    $balance['remaining']->plus($amount)->toDecimal(),
                    'FUND-' . strtoupper(bin2hex(random_bytes(4))),
                    mb_substr('Deposit to ' . self::LABELS[$program] . ($note !== '' ? ' · ' . $note : ''), 0, 255),
                    $actorId,
                ]
            );
            Audit::log($actorId, 'ledger.deposit', 'fund', $program, $amount->format() . ' into ' . self::LABELS[$program]);
        });
    }

    /**
     * Every program, for the treasury and settings screens.
     *
     * @return list<array{program:string, label:string, balance:array<string, Money|bool|string>, configured: Money}>
     */
    public static function all(int $cycleYear): array
    {
        $rows = [];
        foreach (self::LABELS as $program => $label) {
            $rows[] = [
                'program'    => $program,
                'label'      => $label,
                'balance'    => self::balance($program, $cycleYear),
                'configured' => Money::fromDecimal((string) Settings::get(self::OPENING_KEYS[$program], '0')),
            ];
        }
        return $rows;
    }

    /** Remaining Seed Grant pool — the one figure the treasury screen and the disbursement gate share. */
    public static function seedGrantRemaining(?int $cycleYear = null): Money
    {
        return self::balance('seed_grant', $cycleYear ?? Settings::cycleYear())['remaining'];
    }

    /**
     * Times an administrator restated a pool, newest first.
     *
     * Read from the audit trail rather than the ledger, because that is where a decision lives.
     *
     * @return list<array<string, mixed>>
     */
    public static function poolHistory(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        return Database::all(
            "SELECT e.*, u.full_name AS actor_name
             FROM audit_events e LEFT JOIN users u ON u.id = e.actor_id
             WHERE e.action = 'ledger.set_pool' AND e.entity = 'fund'
             ORDER BY e.id DESC LIMIT {$limit}"
        );
    }

    private static function isSetFor(string $program, int $cycleYear): bool
    {
        if ($program !== 'seed_grant') {
            return true;
        }
        // "Set" means an administrator named this year. A stored 0.00 with no year recorded is the
        // schema default, not a decision, so it must not suppress the documented accrual.
        $year = (int) Settings::get('seed_grant_opening_balance_year', 0);
        return $year === $cycleYear;
    }

    private static function stored(string $key): Money
    {
        return Money::fromDecimal((string) Settings::get($key, '0'));
    }

    private static function sumLedger(string $entryType, string $program): Money
    {
        return Money::fromDecimal((string) Database::value(
            'SELECT COALESCE(SUM(amount),0) FROM remittance_ledger WHERE entry_type = ? AND program_type = ?',
            [$entryType, $program]
        ));
    }

    /**
     * Disbursed against a cycle year, read through the application that carried the grant.
     *
     * Deliberately not `YEAR(created_at)`: a grant approved for 2027 and paid in January 2027 must
     * count against 2027's pool, and one approved for 2026 but paid late must still count against
     * 2026. The application's `cycle_year` is the accounting year; the timestamp is not.
     */
    private static function sumDisbursed(string $program, int $cycleYear): Money
    {
        return Money::fromDecimal((string) Database::value(
            "SELECT COALESCE(SUM(l.amount),0)
             FROM remittance_ledger l
             JOIN program_applications a ON a.id = l.application_id
             WHERE l.entry_type = 'disbursement' AND l.program_type = ? AND a.cycle_year = ?",
            [$program, $cycleYear]
        ));
    }

    private static function sum(Money ...$parts): Money
    {
        $total = Money::zero();
        foreach ($parts as $part) {
            $total = $total->plus($part);
        }
        return $total;
    }

    private static function assertProgram(string $program): void
    {
        if (!isset(self::LABELS[$program])) {
            throw new InvalidArgumentException('Unknown program fund.');
        }
    }
}
