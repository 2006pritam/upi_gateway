# Deploying on Render (Docker) with Neon PostgreSQL

The repository ships a `Dockerfile` that runs Apache + PHP, the queue worker and the Laravel
scheduler in one container. Render's filesystem is ephemeral, so **all configuration lives in
environment variables**, and the database lives outside the container (for example Neon).

> The README's production-readiness notice still applies. Review it before taking live payments.

## 1. Database

Create a PostgreSQL database (Neon works well). Use the **direct** connection host (not the
`-pooler` one) so migrations and row locks (`lockForUpdate`) behave normally.

## 2. Render web service

Create a **Web Service** from this repository with runtime **Docker**, and set:

| Variable | Value |
|---|---|
| `APP_KEY` | output of `php artisan key:generate --show` (keep it stable across deploys) |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | your `https://<service>.onrender.com` URL |
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST` / `DB_PORT` | database host / `5432` |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | database credentials |
| `DB_SSLMODE` | `require` |
| `SESSION_SECURE_COOKIE` | `true` |
| `LOG_LEVEL` | `error` |

## 3. First run

Open the service URL and complete the web installer (choose **PostgreSQL**, enter the same
database credentials, run migrations, create the administrator). Do this **immediately** after the
first deploy: until an administrator exists, anyone who can reach the URL can complete the installer.

The installation lock is stored in the database (administrator row), so it survives redeploys.

## 4. Later deploys

Set `RUN_MIGRATIONS=true` to apply pending migrations on boot (take a database backup first), and
set it back to `false` afterwards.

## Notes

- The free Render plan sleeps when idle. While asleep the scheduler and queue worker do not run, so
  pending payments are not re-verified and webhooks can be delayed. Use an always-on plan for real traffic.
- `docker/apache.conf` trusts `X-Forwarded-Proto` / `X-Forwarded-For` from private proxy ranges so
  Laravel sees HTTPS requests and the real client IP.
