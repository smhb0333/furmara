<?php
/**
 * One-time installer: creates .env, imports schema/seed, sets admin password.
 * Delete this file after successful install.
 */
declare(strict_types=1);

$root = is_dir(__DIR__ . '/app') ? __DIR__ . '/app' : dirname(__DIR__);
$done = false;
$error = null;
$info = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $dbHost = trim($_POST['db_host'] ?? 'localhost');
        $dbName = trim($_POST['db_name'] ?? 'furmara');
        $dbUser = trim($_POST['db_user'] ?? '');
        $dbPass = (string) ($_POST['db_pass'] ?? '');
        $appUrl = rtrim(trim($_POST['app_url'] ?? ''), '/');
        $adminEmail = mb_strtolower(trim($_POST['admin_email'] ?? 'sonuhussyn09@gmail.com'));
        $adminPass = (string) ($_POST['admin_pass'] ?? '@waqasaly1');
        $resendKey = trim($_POST['resend_api_key'] ?? '');
        $smtpUser = trim($_POST['smtp_user'] ?? 'info@furmara.com');
        $smtpPass = (string) ($_POST['smtp_pass'] ?? '');
        $smtpTestMode = isset($_POST['smtp_test_mode']) ? 'true' : 'false';

        if ($dbUser === '' || $appUrl === '') {
            throw new RuntimeException('DB user and APP URL are required');
        }

        $pdo = new PDO(
            "mysql:host={$dbHost};charset=utf8mb4",
            $dbUser,
            $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$dbName}`");

        $schema = file_get_contents($root . '/sql/schema.sql');
        $seed = file_get_contents($root . '/sql/seed.sql');
        if ($schema === false || $seed === false) {
            throw new RuntimeException('Missing sql files');
        }
        $pdo->exec($schema);
        $pdo->exec($seed);

        $hash = password_hash($adminPass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('UPDATE users SET email = ?, password_hash = ?, role = ? WHERE id = ?');
        $stmt->execute([$adminEmail, $hash, 'SUPER_ADMIN', 'admin001']);

        $env = "APP_URL={$appUrl}\nAPP_DEBUG=false\n\n"
            . "DB_HOST={$dbHost}\nDB_NAME={$dbName}\nDB_USER={$dbUser}\nDB_PASS=\"{$dbPass}\"\n\n"
            . "# —— Hostinger SMTP order emails (customer receipt + owner notify) ——\n"
            . "SMTP_HOST=smtp.hostinger.com\n"
            . "SMTP_PORT=587\n"
            . "SMTP_ENCRYPTION=tls\n"
            . "SMTP_USER={$smtpUser}\n"
            . "SMTP_PASSWORD=\"{$smtpPass}\"\n"
            . "SMTP_FROM=\"FURMARA <{$smtpUser}>\"\n"
            . "ORDER_NOTIFY_EMAIL=\"Sonuhussyn09@gmail.com,{$smtpUser}\"\n"
            . "MAIL_TEST_MODE={$smtpTestMode}\n"
            . "MAIL_TEST_TO=Sonuhussyn09@gmail.com\n\n"
            . "RESEND_API_KEY=\"{$resendKey}\"\n"
            . "RESEND_FROM_EMAIL=\"FURMARA Orders <orders@furmara.com>\"\n"
            . "WHATSAPP_NUMBER=923223483031\n";
        file_put_contents($root . '/.env', $env);

        $info[] = 'Database created and seeded';
        $info[] = 'Admin: ' . $adminEmail;
        $info[] = '.env written (SMTP included)';
        if ($smtpPass === '') {
            $info[] = 'WARNING: SMTP password khali hai — emails nahi jayengi. hPanel → Emails se mailbox banao aur .env mein SMTP_PASSWORD set karo.';
        }
        $info[] = 'DELETE public/install.php now for security';
        $done = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>FURMARA PHP Installer</title>
  <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
  <main class="container section" style="max-width:560px">
    <h1>FURMARA PHP install</h1>
    <?php if ($error): ?><div class="flash flash-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($done): ?>
      <div class="flash flash-success">
        <?php foreach ($info as $line): ?><div><?= htmlspecialchars($line) ?></div><?php endforeach; ?>
      </div>
      <p><a class="btn" href="/">Open store</a> <a class="btn btn-outline" href="/admin/login">Admin</a></p>
    <?php else: ?>
      <form method="post" class="form">
        <label>App URL<input name="app_url" required placeholder="https://www.furmara.com"></label>
        <label>DB host<input name="db_host" value="localhost"></label>
        <label>DB name<input name="db_name" value="furmara"></label>
        <label>DB user<input name="db_user" required></label>
        <label>DB password<input name="db_pass" type="password"></label>
        <label>Admin email<input name="admin_email" value="Sonuhussyn09@gmail.com"></label>
        <label>Admin password<input name="admin_pass" type="password" value="@waqasaly1"></label>
        <label>SMTP email (hPanel → Emails mein banao)<input name="smtp_user" value="info@furmara.com"></label>
        <label>SMTP password (mailbox ka password)<input name="smtp_pass" type="password" placeholder="Order emails ke liye zaroori"></label>
        <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="smtp_test_mode" style="width:auto"> Test mode (emails sirf apne inbox mein)</label>
        <label>Resend API key (optional)<input name="resend_api_key" placeholder="re_…"></label>
        <button class="btn" type="submit">Install</button>
      </form>
    <?php endif; ?>
  </main>
</body>
</html>
