# cPanel shared-hosting installation

This beginner guide prepares cPanel and then completes setup in the browser. You do not need Composer or Node.js on the hosting account when the prepared production package already contains `vendor/` and `public/build/`.

## Step 1 — Prepare hosting

Confirm the plan provides PHP 8.2+, MySQL 8.x or MariaDB 10.x/11.x, and PDO, PDO MySQL, OpenSSL, mbstring, tokenizer, JSON, cURL, fileinfo, and XML. PHP must be able to write `storage/`, `bootstrap/cache/`, and `.env`. Obtain an SSL certificate before entering production credentials.

In **MultiPHP Manager** or **Select PHP Version**, choose PHP 8.2+. In **Extensions** or **PHP Extensions**, enable the modules above. Use **PHP Info** if your host provides it to confirm the active version and modules. If PDO MySQL is missing, ask the host to enable it; the wizard requires it.

## Step 2 — Upload application

1. Upload the prepared production ZIP in **File Manager**, preferably outside `public_html`, for example `/home/ACCOUNT/upi-gateway`.
2. Extract it. The Laravel root must contain `artisan`, `app/`, `bootstrap/`, `config/`, `database/`, `public/`, `resources/`, `routes/`, `storage/`, and `vendor/` directly.
3. Set the domain document root to `/home/ACCOUNT/upi-gateway/public` in **Domains** or **Document Root** settings.
4. Make `storage/` and `bootstrap/cache/` writable by PHP. Do not use `777` permanently.

Only `public/` may be served. Never point the domain at the project root: `.env`, `vendor/`, `storage/`, configuration, and source files must not be downloadable.

## Step 3 — Create MySQL database

1. Log in to cPanel.
2. Open **MySQL Database Wizard** (or **MySQL Databases** if the wizard is unavailable).
3. Create a database named `upi_gateway`.
4. Create a dedicated database user.
5. Generate and save a strong password.
6. Add the user to the database.
7. Select **ALL PRIVILEGES** and apply them.
8. Save the full database name, username, host, port, and password.

cPanel may prefix names with the account username. For an account named `acme`, values may be:

| Wizard field | Example |
|---|---|
| Database Name | `acme_upi_gateway` |
| Database Username | `acme_gateway_user` |
| Database Host | `127.0.0.1` or host-provided hostname |
| Database Port | `3306` |

Use the full prefixed values in the wizard.

## Step 4 — Open the Installation Wizard

Open `https://yourdomain.com/install` after the domain points to `public/` and the database exists.

### Requirements

The page checks PHP 8.2+, PDO, PDO MySQL, OpenSSL, mbstring, tokenizer, JSON, cURL, writable `storage/`, and writable `.env` handling. Fix any red required check in cPanel/server settings, then refresh.

### Database Setup

Enter App Name, HTTPS App URL, MySQL host, port (normally `3306`), full database name, full username, and password. MySQL is fixed and required. Select **Test connection and continue**. If it fails, check cPanel prefixes, privileges, host, port, and spelling.

### Run Migrations

Start the migration to create the required MySQL tables. Failures show a generic message; correct database permissions and retry without exposing credentials.

### Create Admin Account

Enter Full Name, Email Address, Password, and Confirm Password. Use a unique email address and a strong password of at least 10 characters. These credentials become the admin dashboard login.

## Step 5 — Installation complete

Choose **Go to Login** and sign in at `/login`. The installation marker locks the wizard after success. Do not delete or bypass it; it protects the existing database and administrator.

## Security after installation

Use HTTPS, keep `APP_DEBUG=false`, never share `.env` or database/API credentials, and keep `storage/` and `bootstrap/cache/` writable only as required. Do not use `777` permanently. Update PHP, MySQL, dependencies, and the OS. Back up MySQL before migrations. Configure a queue worker for queued webhook delivery and cPanel Cron for `php artisan schedule:run` every minute.

See [INSTALLATION.md](INSTALLATION.md) for the common wizard flow and screenshots.
