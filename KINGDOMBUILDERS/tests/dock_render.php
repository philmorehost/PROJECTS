<?php
declare(strict_types=1);
/**
 * Mobile dock rendering tests. No database is required: the view is rendered
 * with small stand-ins for the KB helper functions and the resulting markup is
 * inspected. Run with: php tests/dock_render.php
 */
namespace KB {
    function e(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    function url(string $path = '', array $query = []): string { return '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : ''); }
    final class Security { public static function csrfField(): string { return '<input type="hidden" name="_token" value="test-token">'; } }
}

namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

    const ATTEMPT = '7d1d5c1e-1111-4aaa-8bbb-1234567890ab';

    function dock_html(array $state): string {
        $defaults = ['isAdmin' => false, 'current' => 'dashboard', 'dockAttempt' => null, 'dockQuizOpen' => true, 'dockVerified' => true];
        extract(array_merge($defaults, $state), EXTR_SKIP);
        ob_start();
        require dirname(__DIR__) . '/app/Views/_dock.php';
        return (string) ob_get_clean();
    }

    function dock_assert(bool $condition, string $label): void {
        if (!$condition) { fwrite(STDERR, "FAIL {$label}\n"); exit(1); }
        echo "PASS {$label}\n";
    }

    function labels(string $html): array {
        preg_match_all('/<span class="dock-label">([^<]+)<\/span>/', $html, $m);
        return $m[1];
    }

    // 1. A member without an attempt gets a POST form that starts the quiz.
    $start = dock_html(['current' => 'dashboard']);
    dock_assert(labels($start) === ['Home', 'Start quiz', 'Profile'], 'dock labels: Home, Start quiz, Profile');
    dock_assert(str_contains($start, 'method="post"') && str_contains($start, 'action="/quiz/start"'), 'start tab posts to the quiz route');
    dock_assert(str_contains($start, 'name="_token" value="test-token"'), 'start tab carries a CSRF token');
    dock_assert(!str_contains($start, 'dock-ping'), 'no resume badge before an attempt exists');
    dock_assert(str_contains($start, 'aria-current="page"') && str_contains($start, 'href="/dashboard"'), 'home tab is current');
    dock_assert(!str_contains($start, '/quiz?id='), 'no attempt link before an attempt exists');

    // 2. An open attempt becomes Resume quiz and links straight to it.
    $resume = dock_html(['current' => 'dashboard', 'dockAttempt' => ATTEMPT]);
    dock_assert(labels($resume) === ['Home', 'Resume quiz', 'Profile'], 'dock labels: Resume quiz while an attempt is open');
    dock_assert(str_contains($resume, 'href="/quiz?id=' . ATTEMPT . '"'), 'resume links to the saved attempt');
    dock_assert(str_contains($resume, 'dock-ping'), 'resume tab shows the attention badge');
    dock_assert(!str_contains($resume, 'action="/quiz/start"'), 'resume does not start a second attempt');

    // 3. A closed assessment is an inert label rather than a dead link.
    $closed = dock_html(['current' => 'dashboard', 'dockQuizOpen' => false]);
    dock_assert(labels($closed) === ['Home', 'Quiz closed', 'Profile'], 'dock labels: Quiz closed when assessments are shut');
    dock_assert(str_contains($closed, 'aria-disabled="true"') && !str_contains($closed, 'href="/quiz'), 'closed tab is not a link');

    // 3b. Unconfirmed accounts are sent to the confirmation screen, never a 403.
    $unverified = dock_html(['current' => 'dashboard', 'dockVerified' => false, 'dockAttempt' => null]);
    dock_assert(labels($unverified) === ['Home', 'Verify email', 'Profile'], 'dock labels: Verify email before the account is confirmed');
    dock_assert(str_contains($unverified, 'href="/dashboard"') && !str_contains($unverified, 'action="/quiz/start"'), 'unverified tab opens the confirmation screen instead of the quiz');
    dock_assert(!str_contains($unverified, 'dock-ping'), 'no resume badge for unconfirmed accounts');

    // 4. The profile tab marks itself on the account page.
    $account = dock_html(['current' => 'account', 'dockAttempt' => ATTEMPT]);
    preg_match('/<a class="dock-item is-active"[^>]*href="([^"]+)"/', $account, $current);
    dock_assert(($current[1] ?? '') === '/account', 'profile tab is current on the account page');
    dock_assert(substr_count($account, 'aria-current="page"') === 1, 'exactly one tab is current');

    // 5. Administrators get the management variant and no quiz shortcut.
    $admin = dock_html(['isAdmin' => true, 'current' => 'admin/users']);
    dock_assert(labels($admin) === ['Overview', 'Members', 'Profile'], 'dock labels: administrator variant');
    dock_assert(str_contains($admin, 'href="/admin/users"') && str_contains($admin, 'href="/admin"'), 'administrator tabs link to management pages');
    dock_assert(!str_contains($admin, '/quiz'), 'administrator dock never offers the quiz');

    // 6. Shared markup guarantees.
    foreach (['start', 'resume', 'closed', 'unverified', 'account', 'admin'] as $variant) {
        $html = $$variant;
        dock_assert(substr_count($html, '<nav class="mobile-dock"') === 1, "{$variant}: one dock landmark");
        dock_assert(str_contains($html, 'aria-label="Quick navigation"'), "{$variant}: dock is labelled");
        dock_assert(substr_count($html, '<span class="dock-icon" aria-hidden="true">') === 3, "{$variant}: three decorative icons");
        dock_assert(substr_count($html, 'class="dock-label"') === 3, "{$variant}: every tab has a visible label");
    }

    echo "Mobile dock tests passed.\n";
}
