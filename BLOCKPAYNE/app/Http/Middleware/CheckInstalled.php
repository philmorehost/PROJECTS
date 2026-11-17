<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Security;

final class CheckInstalled
{
    public static function handle(bool $isInstallerRoute = false): void
    {
        $installed = bp_installed();

        if (!$installed && !$isInstallerRoute) {
            if (Security::wantsJson()) {
                bp_json([
                    'ok'       => false,
                    'error'    => 'Application is not installed yet.',
                    'redirect' => bp_url('install'),
                ], 503);
            }
            bp_redirect(bp_url('install'), 302);
        }
    }
}
