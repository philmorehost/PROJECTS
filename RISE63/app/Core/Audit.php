<?php
declare(strict_types=1);

namespace RISE63\Core;

/**
 * Human-readable, append-only record of administrative and financial actions.
 *
 * Two things were added after the first field reports:
 *
 *  1. **An actor name, resolved at read time.** `actor_id` is `ON DELETE SET NULL`-safe in the
 *     table, but if an account is ever removed the audit row must still say who did it. The e-mail
 *     is snapshotted on write (it survives account changes), and the current display name is joined
 *     on read. `actor_email` alone is not enough for an operator scanning a list — "yemi@…" is a
 *     mail address, "Yemi Abiose" is a person.
 *  2. **Filters and keyset pagination.** The trail is written to while it is being read, so OFFSET
 *     paging repeats and skips rows; see {@see Paginator}.
 */
final class Audit
{
    /**
     * Actions that move money or change who can move it. Grouping them is not decoration: the
     * question an auditor actually asks is "what financial and permission changes happened", and
     * that is a small subset of a table dominated by page-level events.
     */
    public const FINANCIAL_ACTIONS = [
        'remittance.request', 'remittance.confirm', 'remittance.reverse',
        'ledger.deposit', 'ledger.set_pool', 'ledger.adjust', 'ledger.reverse',
        'fund.set_pool', 'settings.update',
    ];

    public const ACCOUNT_ACTIONS = [
        'user.create', 'user.update', 'user.role', 'user.suspend', 'user.reinstate',
        'user.delete', 'user.password.temporary', 'user.register', 'user.login',
        'user.logout', 'user.login_failed', 'admin.create',
    ];

    public static function log(
        ?int $actorId,
        string $action,
        string $entity,
        ?string $entityId = null,
        ?string $summary = null
    ): void {
        $email = null;
        if ($actorId !== null) {
            try {
                $email = Database::value('SELECT email FROM users WHERE id = ?', [$actorId]);
                $email = $email === null ? null : (string) $email;
            } catch (\Throwable) {
                $email = null;
            }
        }
        Database::run(
            'INSERT INTO audit_events (actor_id, actor_email, action, entity, entity_id, summary) VALUES (?,?,?,?,?,?)',
            [$actorId, $email, substr($action, 0, 60), substr($entity, 0, 60), $entityId, $summary === null ? null : substr($summary, 0, 255)]
        );
    }

    /** @return list<array<string, mixed>> */
    public static function recent(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        return Database::all(
            "SELECT e.*, u.full_name AS actor_name, u.role AS actor_role
             FROM audit_events e LEFT JOIN users u ON u.id = e.actor_id
             ORDER BY e.id DESC LIMIT {$limit}"
        );
    }

    /**
     * One page of the trail, filtered.
     *
     * @param array<string, mixed> $get the request's query string
     * @param array{actor?:string, action?:string, entity?:string, kind?:string, q?:string} $filters
     * @return array{rows: list<array<string, mixed>>, page: Paginator, filters: array<string,string>}
     */
    public static function page(array $get, array $filters, string $page = 'audit'): array
    {
        $where  = [];
        $params = [];
        $clean  = ['actor' => '', 'action' => '', 'entity' => '', 'kind' => '', 'q' => ''];

        $actor = preg_replace('/[^0-9]/', '', (string) ($filters['actor'] ?? '')) ?? '';
        if ($actor !== '') {
            $where[] = 'e.actor_id = :actor';
            $params['actor'] = (int) $actor;
            $clean['actor'] = $actor;
        }

        $action = substr(preg_replace('/[^a-z0-9._-]/i', '', (string) ($filters['action'] ?? '')) ?? '', 0, 60);
        if ($action !== '') {
            // A prefix match, because actions are dotted namespaces ("user.role", "user.*").
            $where[] = 'e.action LIKE :action';
            $params['action'] = str_replace(['%', '_'], ['\%', '\_'], $action) . '%';
            $clean['action'] = $action;
        }

        $entity = substr(preg_replace('/[^a-z0-9._-]/i', '', (string) ($filters['entity'] ?? '')) ?? '', 0, 60);
        if ($entity !== '') {
            $where[] = 'e.entity = :entity';
            $params['entity'] = $entity;
            $clean['entity'] = $entity;
        }

        $kind = (string) ($filters['kind'] ?? '');
        if ($kind === 'financial') {
            $where[] = 'e.action IN (' . self::placeholders('fin', self::FINANCIAL_ACTIONS, $params) . ')';
            $clean['kind'] = 'financial';
        } elseif ($kind === 'accounts') {
            $where[] = 'e.action IN (' . self::placeholders('acct', self::ACCOUNT_ACTIONS, $params) . ')';
            $clean['kind'] = 'accounts';
        } elseif ($kind === 'admins') {
            // "What did the administrators do" — the question behind the audit card, and the one
            // that is almost impossible to answer by scrolling.
            $where[] = "u.role IN ('provincial_admin','provincial_treasurer')";
            $clean['kind'] = 'admins';
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(e.summary LIKE :q OR e.actor_email LIKE :q OR e.action LIKE :q OR e.entity_id = :exact)';
            $params['q']     = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $params['exact'] = $q;
            $clean['q'] = mb_substr($q, 0, 80);
        }

        // The actor name is needed for display and for the "admins only" filter, so the join is
        // always present rather than added conditionally.
        $base = self::fromRequestWithJoin($get, $page, $where, $params, $clean);

        return $base;
    }

    /**
     * Keyset page over audit_events joined to its actor.
     *
     * Written out here rather than in Paginator because the join and the alias make the generic
     * helper's `SELECT * FROM table` shape wrong — and a single-table helper quietly used on a
     * join is how filtering gets lost.
     *
     * @param list<string> $where
     * @param array<string, mixed> $params
     * @param array<string, string> $clean
     * @return array{rows: list<array<string, mixed>>, page: Paginator, filters: array<string,string>}
     */
    private static function fromRequestWithJoin(array $get, string $page, array $where, array $params, array $clean): array
    {
        $perPage = (int) ($get['per_page'] ?? Paginator::DEFAULT_PER_PAGE);
        if ($perPage < Paginator::MIN_PER_PAGE || $perPage > Paginator::MAX_PER_PAGE) {
            $perPage = max(Paginator::MIN_PER_PAGE, min(Paginator::MAX_PER_PAGE, $perPage > 0 ? $perPage : Paginator::DEFAULT_PER_PAGE));
        }
        $perPage = $perPage > 100 ? 100 : $perPage;

        $before = null;
        $raw    = $get['before'] ?? null;
        if (is_scalar($raw) && preg_match('/^\d{1,18}$/', (string) $raw) === 1) {
            $before = (int) $raw;
        }

        $clauses = $where;
        if ($before !== null) {
            $clauses[] = 'e.id < :cursor';
            $params['cursor'] = $before;
        }

        $sql = 'SELECT e.*, u.full_name AS actor_name, u.role AS actor_role, u.is_suspended AS actor_suspended
                FROM audit_events e LEFT JOIN users u ON u.id = e.actor_id';
        if ($clauses !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }
        $sql .= ' ORDER BY e.id DESC LIMIT ' . ($perPage + 1);

        $rows   = Database::all($sql, $params);
        $hasNext = count($rows) > $perPage;
        if ($hasNext) {
            array_pop($rows);
        }

        // Filters are carried into the page links so a page change keeps the view the operator set.
        $carry = $clean;
        $carry['per_page'] = (string) $perPage;
        $carry = array_filter($carry, static fn (string $v): bool => $v !== '');

        return [
            'rows'    => $rows,
            'filters' => $clean,
            'page'    => new Paginator($perPage, $before, $before !== null, $hasNext, count($rows), $carry, $page),
        ];
    }

    /**
     * @param list<string> $values
     * @param array<string, mixed> $params
     */
    private static function placeholders(string $prefix, array $values, array &$params): string
    {
        $names = [];
        foreach ($values as $index => $value) {
            $key = $prefix . $index;
            $params[$key] = $value;
            $names[] = ':' . $key;
        }
        return implode(',', $names);
    }

    /**
     * Who has been doing what: one row per actor, with counts and the last time they acted.
     *
     * This is the summary an administrator needs before reading the detail — it answers "is there
     * one account behaving oddly" without scanning a table.
     *
     * @return list<array<string, mixed>>
     */
    public static function actorSummary(int $limit = 60): array
    {
        $limit = max(1, min(200, $limit));
        return Database::all(
            "SELECT e.actor_id,
                    MAX(e.actor_email)                    AS actor_email,
                    MAX(COALESCE(u.full_name, e.actor_email)) AS actor_name,
                    MAX(u.role)                           AS actor_role,
                    COUNT(*)                              AS event_count,
                    SUM(CASE WHEN e.action LIKE 'remittance%' OR e.action LIKE 'ledger%' THEN 1 ELSE 0 END) AS money_count,
                    SUM(CASE WHEN e.action LIKE 'user.%' THEN 1 ELSE 0 END) AS account_count,
                    MAX(e.created_at)                     AS last_seen
             FROM audit_events e LEFT JOIN users u ON u.id = e.actor_id
             WHERE e.actor_id IS NOT NULL
             GROUP BY e.actor_id
             ORDER BY event_count DESC
             LIMIT {$limit}"
        );
    }

    /** Actors that can be chosen in the filter — staff only, because they are who writes here. */
    /** @return list<array<string, mixed>> */
    public static function actors(): array
    {
        return Database::all(
            "SELECT DISTINCT u.id, u.full_name, u.email, u.role
             FROM audit_events e JOIN users u ON u.id = e.actor_id
             ORDER BY u.full_name"
        );
    }

    /** @return list<string> distinct actions, for the filter dropdown */
    public static function actions(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['action'],
            Database::all('SELECT DISTINCT action FROM audit_events ORDER BY action')
        );
    }

    public static function count(?string $where = null, array $params = []): int
    {
        $sql = 'SELECT COUNT(*) FROM audit_events e LEFT JOIN users u ON u.id = e.actor_id';
        if ($where !== null && trim($where) !== '') {
            $sql .= ' WHERE ' . $where;
        }
        return (int) Database::value($sql, $params);
    }
}
