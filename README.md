# UPI Gateway

<p align="center">
  <strong>A self-hosted, multi-merchant UPI payment gateway for Indian businesses.</strong>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Laravel-12-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel 12">
  <img src="https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.2+">
  <img src="https://img.shields.io/badge/Database-MySQL-4479A1?style=for-the-badge&logo=mysql&logoColor=white" alt="MySQL">
  <img src="https://img.shields.io/badge/License-MIT-16A34A?style=for-the-badge" alt="MIT License">
</p>

<p align="center">
  <a href="#features">Features</a> ·
  <a href="#installation">Installation</a> ·
  <a href="#installation-wizard">Installation Wizard</a> ·
  <a href="#security">Security</a> ·
  <a href="#api-overview">API</a>
</p>

---

## Overview

UPI Gateway is a self-hosted payment management platform that allows businesses to create UPI payment orders, manage multiple merchant accounts, verify payment status through configured integrations, and notify merchant applications through signed webhooks.

The platform includes an administrator dashboard, merchant API credentials, Live and Sandbox environments, payment-account management, transaction monitoring, and a browser-based installation wizard.

> **Important:** This software does not itself provide regulatory authorization to operate a payment system or payment aggregation business. Review applicable RBI requirements, provider terms, and legal obligations before commercial deployment.

## Features

- **Multi-merchant management** — separate merchant accounts and API credentials.
- **UPI checkout** — dynamic UPI QR codes, UPI intent, and hosted payment pages.
- **Payment verification** — configurable Paytm and PhonePe integrations, plus manual UTR review where supported.
- **UPI account management** — multiple payment accounts, rotation, and account-level limits.
- **Live and Sandbox environments** — separate test and live credentials and transaction data.
- **Webhooks** — HMAC-SHA256 signatures, delivery logs, and retry handling.
- **Administration** — dashboard, transaction monitoring, payment-account management, and verification history.
- **Browser installation wizard** — guided requirements check, MySQL connection, database migrations, and initial administrator setup.

## Technology Stack

| Area | Technology |
|---|---|
| Backend | Laravel 12 |
| Runtime | PHP 8.2 or newer |
| Database | MySQL (required) |
| Frontend | Blade, JavaScript, compiled frontend assets |
| Queue | Laravel Queue |
| Web server | Nginx or a compatible PHP hosting environment |
| API authentication | API key and secret |
| Webhook signatures | HMAC-SHA256 |

**Database requirement:** MySQL is the supported application database. SQLite is not supported for runtime installation.

## Installation

There are two documented deployment paths. Both finish using the same browser-based Installation Wizard.

| Deployment | Guide |
|---|---|
| General wizard walkthrough | [`docs/INSTALLATION.md`](docs/INSTALLATION.md) |
| cPanel shared hosting | [`docs/CPANEL.md`](docs/CPANEL.md) |
| Ubuntu VPS with Nginx, PHP-FPM and MySQL | [`docs/VPS.md`](docs/VPS.md) |

### Before you begin

You need:

- A domain or subdomain pointed to your hosting account.
- PHP 8.2 or newer with the extensions required by the application.
- A MySQL database and a database user with the required privileges.
- HTTPS enabled for the domain.
- A prepared production application package containing Composer dependencies and compiled frontend assets.

The exact PHP extension checklist and hosting preparation steps are documented in the deployment guides. On cPanel, create the MySQL database and database user in cPanel before opening the wizard.

### cPanel installation at a glance

1. Prepare the hosting account and select PHP 8.2 or newer.
2. Upload and extract the prepared production package using cPanel File Manager.
3. Configure the domain's document root to point to the Laravel application's `public/` directory. Do not expose the project root.
4. In cPanel, open **MySQL Database Wizard** (or **MySQL Databases**):
   - Create a database.
   - Create a database user and a strong password.
   - Add the user to the database and grant the required privileges.
   - Save the full database name and username; cPanel may prepend your account username.
5. Open `https://yourdomain.com/install`.
6. Complete the Requirements, Database, Migrations, and Admin steps.
7. Sign in to the administrator dashboard and complete the post-installation security checks.

See the complete [cPanel installation guide](docs/CPANEL.md) before deploying.

### Ubuntu VPS installation at a glance

Prepare Ubuntu, Nginx, PHP-FPM, MySQL, the required PHP extensions, application files, permissions, HTTPS, queue processing, and the scheduler. Point Nginx's document root to the application's `public/` directory, then open `https://yourdomain.com/install` and complete the same wizard.

See the [Ubuntu VPS guide](docs/VPS.md) for commands and configuration.

## Installation Wizard

The wizard guides the administrator through four steps:

1. **Requirements** — checks whether the server has the required PHP/runtime capabilities.
2. **Database** — enter the application URL and MySQL connection details, then test the connection.
3. **Migrations** — create the application's database tables.
4. **Admin** — create the initial administrator account.

All installation methods use this same wizard after server preparation and database creation.

### 1. MySQL database setup

Enter the database details created in cPanel or configured on the VPS. The example below shows the wizard fields; replace the example values with your own hosting credentials.

![UPI Gateway application homepage](docs/images/installation/home.png)

### 2. Run database migrations

After the database connection succeeds, the wizard runs the database migrations and displays the result.

![UPI Gateway administrator dashboard](docs/images/installation/dashboard.png)

### 3. Create the administrator account

Create the first administrator account. Use a unique email address and a strong password. The screenshot uses example identity details.

![UPI Gateway payment account screen](docs/images/installation/payment-account.png)

### 4. Finish installation

After successful installation, sign in using the administrator credentials you created. The installer is protected by an installation lock; do not try to bypass or manually remove that protection.

> The Requirements screen is part of the wizard flow. Add its current application screenshot here when a clean, public-safe capture is available.

## API Overview

Merchant API requests use the credentials issued for the relevant merchant and environment.

Typical authentication headers:

```http
X-Api-Key: key_xxx
X-Api-Secret: sec_xxx
Content-Type: application/json
```

### Main endpoints

| Method | Endpoint | Purpose |
|---|---|---|
| `POST` | `/api/v1/orders` | Create a payment order |
| `GET` | `/api/v1/orders/{id}` | Retrieve an order |
| `GET` | `/api/v1/accounts` | List available payment accounts |

The interactive API documentation is available at `/docs` when enabled by the running application.

### Create an order

```bash
curl -X POST "https://yourdomain.com/api/v1/orders" \
  -H "X-Api-Key: key_xxx" \
  -H "X-Api-Secret: sec_xxx" \
  -H "Content-Type: application/json" \
  -d '{
    "amount": 199,
    "client_order_id": "INV1001"
  }'
```

The response includes the generated order reference, status, payment URL, and other order details. Use the actual response returned by your installation; values vary by configuration.

## Webhooks

Merchants can configure webhook endpoints through the dashboard. Webhook requests are signed using HMAC-SHA256 and include an `X-Signature` header.

Verify the signature against the **raw request body** using a constant-time comparison before processing an event. Implement idempotent event handling so that retries do not cause duplicate fulfilment.

Events currently documented by the project include:

- `order.paid`
- `order.expired`
- `order.under_review`
- Sandbox lifecycle events such as `order.failed`, `order.pending`, and `order.timeout`

## Security

Before accepting production traffic:

- Use HTTPS and set the correct public application URL.
- Keep `APP_DEBUG=false` in production.
- Use a strong, unique administrator password.
- Protect database credentials and payment-provider secrets.
- Never expose `.env` or the Laravel project root through the web server.
- Point the web server document root only to `public/`.
- Keep `storage/` and `bootstrap/cache/` writable by the application user only as required. Do not use `777` as a permanent permission setting.
- Keep the installation lock in place after setup. Do not bypass it.
- Configure database backups and test restoration.
- Configure the queue worker and scheduled tasks as described in the hosting guide.
- Keep PHP, Laravel dependencies, the operating system, and server packages updated.
- Review webhook signatures, idempotency, and payment-provider verification before going live.

Read the [installation guide](docs/INSTALLATION.md) and your deployment-specific guide for the complete checklist.

## Payment Integrations

The project includes integrations for configured Paytm and PhonePe payment flows and supports manual UTR review where enabled.

Provider capabilities depend on the merchant account, API credentials, provider approval, and current provider API behavior. In particular, any unofficial or undocumented provider endpoint may change without notice; do not treat it as a guaranteed production integration.

## Development Setup

For local development, install the dependencies listed by the repository:

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Configure a MySQL database in `.env`, then run:

```bash
php artisan migrate
npm run build
```

Start the local development environment using the scripts defined in `composer.json`:

```bash
composer run dev
```

Do not use development commands or development server settings for production deployment. Production hosting should use the prepared production package and the relevant deployment guide.

## Testing

Run the test suite using the repository's configured test command:

```bash
composer run test
```

The project has been validated with MySQL 9.4 in the release test environment. MySQL 8 and MariaDB compatibility should be verified against the exact target hosting environment before making a compatibility claim.

## Project Structure

```text
upi-gateway/
├── app/
├── bootstrap/
├── config/
├── database/
├── docs/
│   └── images/
│       └── installation/
├── public/
├── resources/
├── routes/
├── screenshots/
├── storage/
├── tests/
├── .env.example
├── artisan
├── composer.json
└── package.json
```

This is a high-level overview; the repository may contain additional Laravel files and directories.

## License

This project is distributed under the MIT License. See [`LICENSE`](LICENSE) for details.

## Author

**Mayank Singh**
Full Stack Developer · Android Engineer · Founder, AmTech INFO

- GitHub: [@mynkdev-21](https://github.com/mynkdev-21)
- LinkedIn: [mynk-dev](https://linkedin.com/in/mynk-dev)
- Portfolio: [mayankdeveloper.site](https://mayankdeveloper.site)
- Contact: [Email](mailto:mayanksingh3132@gmail.com)

---

<p align="center">
  Built with ❤️ for Indian merchants.
</p>
