# FURMARA — PHP website (Hostinger-ready)

This folder is a **plain PHP** store for Hostinger (no Node.js required on the server).

## What is included
- Storefront: home, shop, product pages, cart, checkout (JazzCash + bank transfer + screenshot)
- **Automatic order emails** via Hostinger SMTP + PHPMailer
- Track order, login/signup, account orders
- Admin: dashboard, orders (status + resend email), products publish toggle, email setup + SMTP test
- Real product images + seed catalog (6 fragrances)

## Upload to Hostinger
1. Create a **MySQL** database in hPanel.
2. Upload the contents of `php/` so that **`public/` is the website document root**.
3. SSH / terminal: `cd php && composer install --no-dev`
4. Copy `.env.example` → `.env` and set `SMTP_PASSWORD`.
5. Open `https://yoursite.com/install.php` and fill DB + admin details.
6. **Delete** `public/install.php` after success.

## Admin
- URL: `/admin/login`
- Default (set during install): `Sonuhussyn09@gmail.com` / `@waqasaly1`

## Order emails (Hostinger SMTP)
See **`docs/SMTP_HOSTINGER.md`**.

Start with test mode (`MAIL_TEST_MODE=true`) so emails go only to your inbox.

## Local note
This PC may not have PHP/Composer. Use Hostinger SSH, XAMPP, or Laragon locally.
