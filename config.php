<?php
/**
 * config.php — Session-based config helpers
 *
 * lighttpd alias config needed:
 * alias.url += ("/migration" => "/var/www/migration")
 * $HTTP["url"] =~ "^/migration/tmp/" { url.access-deny = ("") }
 */

declare(strict_types=1);

date_default_timezone_set('Europe/Copenhagen');

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Setters ──────────────────────────────────────────────────────────────────

function cfg_set(string $key, mixed $value): void
{
    $_SESSION['cfg'][$key] = $value;
}

function cfg_get(string $key, mixed $default = null): mixed
{
    return $_SESSION['cfg'][$key] ?? $default;
}

function cfg_require(string ...$keys): void
{
    foreach ($keys as $k) {
        if (empty(cfg_get($k))) {
            http_response_code(400);
            die(json_encode(['error' => "Missing required config: $k"]));
        }
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function subdomain_valid(string $sub): bool
{
    return (bool) preg_match('/^[a-z0-9\-]+$/', $sub);
}

function pixelunion_base(string $sub): string
{
    return "https://{$sub}.pixelunion.eu";
}

function tmp_dir(): string
{
    $dir = __DIR__ . '/tmp';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    return $dir;
}

function progress_file(): string
{
    return tmp_dir() . '/progress.json';
}

function progress_read(): array
{
    $f = progress_file();
    if (!file_exists($f)) {
        return ['total' => 0, 'done' => 0, 'skipped' => 0, 'errors' => 0, 'log' => [], 'status' => 'idle'];
    }
    $data = json_decode(file_get_contents($f), true);
    return is_array($data) ? $data : [];
}

function progress_write(array $data): void
{
    file_put_contents(progress_file(), json_encode($data, JSON_UNESCAPED_UNICODE));
}

function progress_log(array &$data, string $msg): void
{
    $entry = '[' . date('H:i:s') . '] ' . $msg;
    array_unshift($data['log'], $entry);
    // Keep at most 500 log lines to avoid huge files
    if (count($data['log']) > 500) {
        $data['log'] = array_slice($data['log'], 0, 500);
    }
}
