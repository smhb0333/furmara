<?php
declare(strict_types=1);

/**
 * Load php/.env into getenv / $_ENV (no Composer required).
 */
function load_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}

function env(string $key, ?string $default = null): ?string
{
    $v = $_ENV[$key] ?? getenv($key);
    if ($v === false || $v === null || $v === '') {
        return $default;
    }
    return (string) $v;
}

$appRoot = __DIR__;
load_env($appRoot . DIRECTORY_SEPARATOR . '.env');

$composerAutoload = $appRoot . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

// Non-public secrets (Hostinger: place next to .env, never inside public/)
$secretsLocal = $appRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'secrets.local.php';
if (is_file($secretsLocal)) {
    $secrets = require $secretsLocal;
    if (is_array($secrets)) {
        foreach ($secrets as $key => $value) {
            if (!is_string($key) || $value === null || $value === '') {
                continue;
            }
            $str = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $_ENV[$key] = $str;
            putenv($key . '=' . $str);
        }
    }
}

date_default_timezone_set('Asia/Karachi');

// Web docroot: sibling `public_html`/`public`, or the parent folder when the
// app lives INSIDE the docroot (public_html/app layout on Hostinger)
if (!defined('PUBLIC_DIR')) {
    if (is_dir($appRoot . '/public_html')) {
        define('PUBLIC_DIR', $appRoot . '/public_html');
    } elseif (is_dir($appRoot . '/public')) {
        define('PUBLIC_DIR', $appRoot . '/public');
    } elseif (is_file(dirname($appRoot) . '/api.php')) {
        define('PUBLIC_DIR', dirname($appRoot));
    } else {
        define('PUBLIC_DIR', $appRoot . '/public');
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_save_path($appRoot . '/storage/sessions');
    if (!is_dir(session_save_path())) {
        mkdir(session_save_path(), 0755, true);
    }
    session_start();
}

spl_autoload_register(static function (string $class) use ($appRoot): void {
    $base = $appRoot . '/src/';
    $file = $base . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

require_once $appRoot . '/src/helpers.php';
