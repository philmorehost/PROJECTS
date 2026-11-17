<?php
declare(strict_types=1);

namespace RISE63\Core;

/**
 * Read-only view of the schema's migration state, for the Settings page.
 *
 * Why this exists. Migration 0002 added `users.parish_name_raw`. The People page is new and reads
 * that column, so on an installation created before 0002 the page returned a 500 — and nothing in
 * the admin UI said why, or that a migration was waiting. The only way to find out was to be told
 * `php bin/migrate.php` exists.
 *
 * This does not run migrations. A web request is the wrong place to alter a schema: migrations are
 * the one operation that can leave a database half-changed, and doing that behind a button with no
 * shell and no backup is how an installation becomes unrecoverable. What it does is make the state
 * visible and put the exact command in front of the person who needs it.
 */
final class Schema
{
    /**
     * @return array{
     *   known: bool,
     *   error: string,
     *   pending: list<array{version:string,file:string,summary:string}>,
     *   applied: list<array{version:string,summary:string}>,
     *   modified: list<string>,
     *   count: int
     * }
     */
    public static function state(string $root): array
    {
        $out = [
            'known' => false, 'error' => '', 'pending' => [], 'applied' => [], 'modified' => [],
            'count' => 0, 'selfHeal' => SelfHeal::summary(),
        ];

        try {
            $migrator = new Migrator($root);
            $all      = $migrator->all();
        } catch (\Throwable $error) {
            // A database that cannot be reached, or a migrations directory with a bad file name.
            // Reported on the page rather than thrown: Settings must still open.
            $out['error'] = $error->getMessage();
            return $out;
        }

        $out['known'] = true;
        $out['count'] = count($all);

        foreach ($all as $migration) {
            $name    = basename((string) $migration['file']);
            $summary = self::describe($name);

            if ($migration['applied']) {
                $out['applied'][] = ['version' => (string) $migration['version'], 'summary' => $summary];
            } else {
                $out['pending'][] = [
                    'version' => (string) $migration['version'],
                    'file'    => $name,
                    'summary' => $summary,
                ];
            }
        }

        try {
            // A shipped file that changed after it was applied means the code expects a schema this
            // database does not have. The runner refuses to continue in that state, so it is shown.
            foreach ($migrator->verify() as $problem) {
                $out['modified'][] = $problem;
            }
        } catch (\Throwable $error) {
            $out['modified'][] = $error->getMessage();
        }

        // What the automatic check did on the last request, so an operator can see the healing
        // happened rather than take it on faith.
        $out['selfHeal'] = SelfHeal::summary();

        return $out;
    }

    /**
     * Turn `0002_unlisted_parish_name.sql` into "0002 — unlisted parish name".
     *
     * The file name is the only description a migration carries, and `unlisted_parish_name` is not
     * something most operators should have to decode.
     */
    private static function describe(string $filename): string
    {
        if (preg_match('/^(\d{4})_(.+)\.sql$/', $filename, $match) !== 1) {
            return $filename;
        }
        return $match[1] . ' — ' . str_replace('_', ' ', $match[2]);
    }
}
