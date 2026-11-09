<?php
declare(strict_types=1);

/**
 * Namespace-resolution guard.
 *
 * PHP resolves an unqualified function call by looking in the current namespace, then falling back
 * to the global namespace. A *non-namespaced* file has no current namespace, so a call to
 * RISE63\url() from app/pages.php does not fall back — it throws
 *
 *     Error: Call to undefined function config_dir()
 *
 * and the request dies as a 500. That is exactly how the Updates page was unreachable in v1.0.1:
 * app/pages.php called config_dir() without importing it. Nothing caught it. The linter parses;
 * the unit tests never require the router; the render harness stubs its data; and the e2e suite
 * tests the domain layer, not the page.
 *
 * This guard statically resolves every call site against the functions bootstrap actually loaded.
 * It is fast (no database, no network) and it fails the build rather than the page.
 *
 *   php bin/guard-namespaces.php
 *   node tests/support/phpwasm-audit.mjs      (or npm test, which runs it)
 */

const GUARD_BASE = __DIR__ . '/..';

// Load the real bootstrap: "which functions exist in namespace RISE63" is answered by PHP itself,
// not by a second list that would drift away from the first.
ob_start();
require GUARD_BASE . '/app/bootstrap.php';
ob_end_clean();

/** @return array<string,true> RISE63 free functions, keyed by bare name. */
function guard_known_functions(): array
{
    $known = [];
    foreach (get_defined_functions()['user'] as $name) {
        if (str_starts_with($name, 'rise63\\') && !str_starts_with($name, 'rise63\\guard')) {
            $known[substr($name, strlen('rise63\\'))] = true;
        }
    }
    return $known;
}

/** @return list<string> absolute paths of every PHP file the build ships. */
function guard_files(string $root): array
{
    $out  = [];
    $skip = ['node_modules', '.git', 'docs', 'storage'];
    $walk = static function (string $dir) use (&$walk, &$out, $skip): void {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $full = $dir . '/' . $entry;
            if (is_dir($full)) {
                if (in_array($entry, $skip, true)) continue;
                $walk($full);
            } elseif (str_ends_with($entry, '.php')) {
                $out[] = $full;
            }
        }
    };
    $walk($root);
    sort($out);
    return $out;
}

/** @return list<string> */
function guard_check(string $root, array $known): array
{
    $problems = [];

    foreach (guard_files($root) as $path) {
        $rel    = ltrim(substr($path, strlen($root)), '/');
        $tokens = token_get_all((string) file_get_contents($path));
        $n      = count($tokens);

        // ── the file's own namespace ────────────────────────────────────────────────
        $namespace = '';
        for ($i = 0; $i < $n; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_NAMESPACE) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $t = $tokens[$j];
                    if (!is_array($t)) break;
                    if (in_array($t[0], [T_STRING, T_NAME_QUALIFIED], true)) { $namespace = $t[1]; break; }
                    if ($t[0] === T_WHITESPACE) continue;
                    break;
                }
                break;
            }
        }

        // ── `use function` imports ──────────────────────────────────────────────────
        // Whitespace is its own token, so `use function` is T_USE, T_WHITESPACE, T_FUNCTION.
        $imported = [];
        for ($i = 0; $i < $n; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_USE) continue;
            $k = $i + 1;
            while ($k < $n && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) { $k++; }
            if ($k >= $n || !is_array($tokens[$k]) || $tokens[$k][0] !== T_FUNCTION) continue;
            for ($j = $k + 1; $j < $n; $j++) {
                $t = $tokens[$j];
                if ($t === ';') break;
                if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $imported[substr(strrchr('\\' . $t[1], '\\'), 1)] = true;
                }
            }
        }

        // ── functions declared in this file, at any depth ──────────────────────────
        $declaredHere = [];
        for ($i = 0; $i < $n - 1; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
            for ($j = $i + 1; $j < $n; $j++) {
                $t = $tokens[$j];
                if (is_array($t)) {
                    if ($t[0] === T_STRING) { $declaredHere[$t[1]] = true; break; }
                    if (in_array($t[0], [
                        T_WHITESPACE, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG,
                        T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG,
                    ], true)) {
                        continue;
                    }
                    break;
                }
                break;
            }
        }

        // ── unqualified call sites ─────────────────────────────────────────────────
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_STRING) continue;

            // The token after the name must be '('.
            $next = $i + 1;
            while ($next < $n && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) { $next++; }
            if ($next >= $n || $tokens[$next] !== '(') continue;

            // The token before it decides whether this is a call at all.
            $prev = $i - 1;
            while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) { $prev--; }
            $prevToken = $prev >= 0 ? $tokens[$prev] : null;
            if (is_array($prevToken)) {
                if (in_array($prevToken[0], [
                    T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON,
                    T_NEW, T_CONST, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED,
                ], true)) {
                    continue;
                }
            } elseif (in_array($prevToken, ['\\', '>', ':', '&', '?'], true)) {
                continue;
            }

            $name = $t[1];
            if (!isset($known[$name])) continue;         // not a RISE63 function
            if (isset($declaredHere[$name])) continue;   // declared in this file
            if ($namespace === 'RISE63') continue;       // same namespace, resolves
            if (isset($imported[$name])) continue;       // imported, resolves

            $problems[] = $rel . ':' . $t[2] . '  ' . $name . '()  ['
                . ($namespace === '' ? 'global namespace' : 'namespace ' . $namespace) . ']';
        }
    }

    return $problems;
}

$known    = guard_known_functions();
$problems = guard_check(GUARD_BASE, $known);

if ($problems === []) {
    fwrite(STDOUT, 'GUARD-OK ' . count($known) . " RISE63 functions, every call site resolves\n");
    exit(0);
}

fwrite(STDOUT, 'GUARD-PROBLEMS ' . count($problems) . "\n" . implode("\n", $problems) . "\n");
exit(1);
