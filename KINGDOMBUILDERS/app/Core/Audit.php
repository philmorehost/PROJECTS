<?php
declare(strict_types=1);
namespace KB;

final class Audit {
    public static function log(string $action, string $type, string|int|null $id = null, array $metadata = [], ?int $actor = null): void {
        $actor ??= Auth::id();
        DB::run('INSERT INTO audit_events(actor_id,admin_id,action,subject_type,subject_id,metadata,source_hash,created_at) VALUES (?,?,?,?,?,?,?,?)', [
            $actor, $_SESSION['admin_id'] ?? null, $action, $type, $id === null ? null : (string)$id,
            json_encode($metadata, JSON_THROW_ON_ERROR), Security::sourceHash(), now(),
        ]);
    }
    public static function activity(string $action, string $type, string|int|null $id, array $metadata = [], ?array $user = null, string $detail = ''): void {
        DB::transaction(static function () use ($action,$type,$id,$metadata,$user,$detail): void {
            self::log($action,$type,$id,$metadata,$user['id'] ?? null);
            $user ??= Auth::user();
            $recipient = $user['email'] ?? Settings::get('admin_email');
            $title = Settings::get('site_title');
            $text = 'Hello ' . ($user['name'] ?? 'Administrator') . ",\n\n" . $detail . "\n\nActivity: " . str_replace(['.','_'], ' ', $action) . "\nTime: " . now() . " UTC\n\nIf this was not expected, contact your administrator. Do not share your password or sign-in links.\n\n" . $title;
            if ($recipient) Mail::queue($recipient, $title . ' · ' . ucfirst(str_replace(['.','_'], ' ', $action)), $text, $action . ':' . $type . ':' . $id . ':' . bin2hex(random_bytes(8)));
            if ($user && $recipient !== Settings::get('admin_email') && Settings::get('admin_email')) {
                Mail::queue(Settings::get('admin_email'), $title . ' · Activity notification', "An activity was recorded.\n\nActivity: {$action}\nMember: {$user['name']}\nTime: " . now() . " UTC\n\nReview the audit trail in your administration dashboard.", $action . ':admin:' . $id . ':' . bin2hex(random_bytes(8)));
            }
        });
    }
}
