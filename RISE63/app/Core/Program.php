<?php
declare(strict_types=1);

namespace RISE63\Core;

use LogicException;

/**
 * Program definitions and the application state machine.
 *
 * Transitions are data, not scattered `if` statements, so an illegal move is impossible
 * rather than merely discouraged. Applications::transition() is the only writer of
 * program_applications.status and it refuses anything not listed here.
 */
final class Program
{
    public const TYPES = [
        'academy'           => 'RISE Academy',
        'seed_grant'        => 'Seed Grant',
        'cooperative'       => 'Cooperative Access',
        'education_bridge'  => 'Education Bridge',
    ];

    public const SHORT = [
        'academy'          => 'Academy',
        'seed_grant'       => 'Seed grant',
        'cooperative'      => 'Cooperative',
        'education_bridge' => 'Education Bridge',
    ];

    public const STATUSES = [
        'draft'                => 'Draft',
        'submitted'            => 'Submitted',
        'under_review'         => 'Under review',
        'documents_requested'  => 'Documents requested',
        'verified'             => 'Verified',
        'approved'             => 'Approved',
        'disbursed'            => 'Disbursed',
        'completed'            => 'Completed',
        'rejected'             => 'Rejected',
        'withdrawn'            => 'Withdrawn',
    ];

    /** Only these transitions are legal; everything else is a 409. */
    public const TRANSITIONS = [
        'draft'               => ['submitted', 'withdrawn'],
        'submitted'           => ['under_review', 'withdrawn', 'rejected'],
        'under_review'        => ['documents_requested', 'verified', 'rejected', 'withdrawn'],
        'documents_requested' => ['under_review', 'withdrawn', 'rejected'],
        'verified'            => ['approved', 'rejected'],
        'approved'            => ['disbursed', 'rejected'],
        'disbursed'           => ['completed'],
        'completed'           => [],
        'rejected'            => [],
        'withdrawn'           => [],
    ];

    /** Statuses that require a written reason before they may be entered. */
    public const REASON_REQUIRED = ['rejected', 'documents_requested'];

    /** Reviewers may only drive these status changes. */
    private const REVIEWER_TRANSITIONS = ['under_review', 'documents_requested', 'verified', 'rejected', 'approved'];

    public static function label(string $type): string
    {
        return self::TYPES[$type] ?? ucfirst(str_replace('_', ' ', $type));
    }

    public static function short(string $type): string
    {
        return self::SHORT[$type] ?? self::label($type);
    }

    public static function statusLabel(string $status): string
    {
        return self::STATUSES[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    public static function exists(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /** @return list<string> */
    public static function allowedFrom(string $status): array
    {
        return self::TRANSITIONS[$status] ?? [];
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::allowedFrom($from), true);
    }

    public static function assertTransition(string $from, string $to, string $note = ''): void
    {
        if (!isset(self::STATUSES[$to])) {
            throw new LogicException('Unknown application status.');
        }
        if (!self::canTransition($from, $to)) {
            throw new StatusConflict(self::statusLabel($from), self::statusLabel($to));
        }
        if (in_array($to, self::REASON_REQUIRED, true) && trim($note) === '') {
            throw new \InvalidArgumentException('A short reason is required when moving an application to ' . strtolower(self::statusLabel($to)) . '.');
        }
    }

    public static function requiresReason(string $to): bool
    {
        return in_array($to, self::REASON_REQUIRED, true);
    }

    /** A member may withdraw their own application until money has moved. */
    public static function memberCanEdit(string $status): bool
    {
        return in_array($status, ['draft', 'submitted', 'documents_requested'], true);
    }

    public static function memberCanWithdraw(string $status): bool
    {
        return self::canTransition($status, 'withdrawn');
    }

    public static function reviewerCanSet(string $status): bool
    {
        return in_array($status, self::REVIEWER_TRANSITIONS, true);
    }

    /**
     * Three-step tracker data for the applicant's card.
     *
     * @return array{step:int, total:int, label:string, detail:string}
     */
    public static function tracker(string $type, string $status, ?string $sinceUtc = null): array
    {
        $map = [
            'draft'               => [0, 'Draft', 'Not submitted yet'],
            'submitted'           => [1, 'Submitted', 'Waiting to enter review'],
            'under_review'        => [2, 'Under review', 'A reviewer is checking your details'],
            'documents_requested' => [2, 'Documents requested', 'Upload the missing documents'],
            'verified'            => [2, 'Verified', 'Details confirmed, awaiting a decision'],
            'approved'            => [3, 'Approved', 'Approved for disbursement'],
            'disbursed'           => [3, 'Disbursed', 'Payment recorded by the treasury'],
            'completed'           => [3, 'Completed', 'This application is closed'],
            'rejected'            => [0, 'Rejected', 'See the reviewer’s note'],
            'withdrawn'           => [0, 'Withdrawn', 'You withdrew this application'],
        ];
        [$step, $label, $detail] = $map[$status] ?? [0, ucfirst($status), ''];
        if ($sinceUtc !== null && $step > 0) {
            $detail .= ' · since ' . \RISE63\human_date($sinceUtc);
        }
        return ['step' => $step, 'total' => 3, 'label' => $label, 'detail' => $detail];
    }

    /** Labels for the tracker's three dots. */
    public static function trackerSteps(): array
    {
        return [1 => 'Submitted', 2 => 'Under review', 3 => 'Approved / Disbursed'];
    }

    /**
     * Which education levels a program accepts.
     *
     * @return list<string>
     */
    public static function educationLevels(string $type): array
    {
        return match ($type) {
            'education_bridge' => ['super_teen', 'tertiary'],
            'seed_grant'       => ['none', 'tertiary', 'super_teen'],
            'academy'          => ['none', 'tertiary', 'super_teen'],
            default            => ['none'],
        };
    }

    public static function educationLabel(string $level): string
    {
        return match ($level) {
            'super_teen' => 'Super Teen (SS1–SS3)',
            'tertiary'   => 'Tertiary institution',
            default      => 'Not applicable',
        };
    }

    /** Short guidance shown above each intake form. */
    public static function summary(string $type): string
    {
        return match ($type) {
            'academy'          => 'A three-month entrepreneurship and digital/financial literacy programme for young professionals and super teens.',
            'seed_grant'       => 'Non-repayable micro-grants for top Academy graduates with a verified business proposal, funded from a ₦100,000 monthly pool.',
            'cooperative'      => 'Structured savings and loan access through registered cooperative societies partnered with the Province.',
            'education_bridge' => 'Needs-based tuition support for Super Teens (SS1–SS3) and tertiary students, paid directly to the school after verification.',
        };
    }

    /** Extra questions per program, rendered by the intake form. */
    public static function questions(string $type): array
    {
        return match ($type) {
            'academy' => [
                'business_idea'  => ['label' => 'Describe your business or career idea', 'type' => 'textarea', 'required' => true, 'max' => 1000],
                'availability'   => ['label' => 'Which days can you attend classes?', 'type' => 'text', 'required' => true, 'max' => 120],
            ],
            'seed_grant' => [
                'business_idea'  => ['label' => 'Describe the business you will fund', 'type' => 'textarea', 'required' => true, 'max' => 1000],
                'budget_breakdown' => ['label' => 'How will the grant be spent?', 'type' => 'textarea', 'required' => true, 'max' => 1000],
                'academy_graduate' => ['label' => 'Have you completed RISE Academy?', 'type' => 'yesno', 'required' => true],
            ],
            'cooperative' => [
                'preferred_cooperative' => ['label' => 'Preferred cooperative society', 'type' => 'text', 'required' => true, 'max' => 150],
                'monthly_savings' => ['label' => 'Comfortable monthly savings (naira)', 'type' => 'money', 'required' => true],
                'purpose' => ['label' => 'What do you need the loan for?', 'type' => 'textarea', 'required' => true, 'max' => 800],
            ],
            'education_bridge' => [
                'fee_due'        => ['label' => 'Outstanding fee (naira)', 'type' => 'money', 'required' => true],
                'session_year'   => ['label' => 'Academic session', 'type' => 'text', 'required' => true, 'max' => 40],
                'guardian_phone' => ['label' => 'Parent / guardian phone', 'type' => 'text', 'required' => true, 'max' => 30],
            ],
            default => [],
        };
    }
}

/** Raised when a state transition is not legal; mapped to HTTP 409 by the controller. */
final class StatusConflict extends \RuntimeException
{
    public function __construct(string $from, string $to)
    {
        parent::__construct(sprintf('An application that is “%s” cannot move to “%s”. Reload the page and check its current state.', $from, $to));
    }
}
