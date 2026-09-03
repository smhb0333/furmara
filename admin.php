<?php
/**
 * Admin dashboard entry — /admin/* routes are rewritten here by .htaccess.
 */
declare(strict_types=1);

$appBase = is_file(__DIR__ . '/app/bootstrap.php') ? __DIR__ . '/app' : dirname(__DIR__);
require_once $appBase . '/bootstrap.php';

try {
    $router = require $appBase . '/routes.php';
    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
} catch (Throwable $e) {
    error_log('[admin] ' . $e->getMessage());
    http_response_code(500);
    $isDb = str_contains($e->getMessage(), 'DB_NOT_CONFIGURED') || $e instanceof PDOException;
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>FURMARA Setup</title></head>'
        . '<body style="font-family:system-ui,sans-serif;background:#f4f5f7;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0">'
        . '<div style="background:#fff;border:1px solid #e2e6ec;border-radius:14px;padding:2rem;max-width:460px;text-align:center">'
        . '<h2 style="margin:0 0 .5rem">FURMARA — Setup incomplete</h2>'
        . ($isDb
            ? '<p style="color:#64748b;line-height:1.6">Database abhi connect nahi hui. Pehle hPanel mein MySQL database banao, phir <a href="/install.php" style="color:#165dff;font-weight:600">install.php chalao</a>. Install ke baad yeh page khud kaam karne lagega.</p>'
            : '<p style="color:#64748b;line-height:1.6">Kuch masla hua. Dobara try karo — na chale to server ka error_log check karo.</p>')
        . '</div></body></html>';
}
