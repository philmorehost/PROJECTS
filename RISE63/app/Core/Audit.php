<?php
declare(strict_types=1);

namespace RISE63\Core;

/** Human-readable, append-only record of administrative and financial actions. */
final class Audit
{
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
        return Database::all("SELECT * FROM audit_events ORDER BY id DESC LIMIT {$limit}");
    }
}
