<?php
declare(strict_types=1);
namespace KB;

/** Read-only cron helpers for administrators. Nothing here executes a cron command. */
final class Cron {
    public static function phpPath(): array {
        $configured = trim((string)Settings::get('php_cli_path', ''));
        if ($configured !== '' && self::validPath($configured)) return ['path'=>$configured,'detected'=>is_file($configured) && is_executable($configured)];
        $env = trim((string)getenv('KB_PHP_CLI_PATH'));
        if ($env !== '' && self::validPath($env)) return ['path'=>$env,'detected'=>is_file($env) && is_executable($env)];
        $candidates = [];
        if (PHP_SAPI === 'cli' && self::validPath(PHP_BINARY)) $candidates[] = PHP_BINARY;
        $version = PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
        foreach (["/opt/cpanel/ea-php{$version}/root/usr/bin/php", "/usr/local/bin/ea-php{$version}", "/usr/bin/php{$version}"] as $path) $candidates[] = $path;
        foreach (['/usr/local/bin/php','/usr/bin/php','/bin/php'] as $path) $candidates[] = $path;
        foreach (array_unique($candidates) as $path) {
            if (is_file($path) && is_executable($path)) return ['path'=>$path,'detected'=>true];
        }
        return ['path'=>'/usr/bin/php','detected'=>false];
    }

    public static function info(): array {
        $php = self::phpPath();
        $worker = realpath(ROOT . '/bin/worker.php') ?: ROOT . '/bin/worker.php';
        $configDir = config_path();
        $log = $configDir . '/worker.log';
        $command = 'KB_CONFIG_DIR=' . escapeshellarg($configDir) . ' ' . escapeshellarg($php['path']) . ' ' . escapeshellarg($worker) . ' >> ' . escapeshellarg($log) . ' 2>&1';
        return [
            'php' => $php['path'],
            'php_detected' => $php['detected'],
            'worker' => $worker,
            'config_dir' => $configDir,
            'log' => $log,
            'command' => $command,
            'crontab' => '* * * * * ' . $command,
            'heartbeat' => self::heartbeat(),
        ];
    }

    public static function heartbeat(): ?array {
        $file = config_path() . '/worker-heartbeat.json';
        if (!is_file($file)) return null;
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    public static function record(array $data): void {
        $data['recorded_at'] = now();
        Security::atomicWrite(config_path() . '/worker-heartbeat.json', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private static function validPath(string $path): bool {
        return str_starts_with($path, '/') && !preg_match('/[\r\n\0;&|<>`$]/', $path);
    }
}
