# Inventra Smart Trade

Inventra Smart Trade is an internal business application built as a Laravel monolith. This repository currently contains the production-oriented application foundation; business modules are intentionally out of scope.

## Stack

- PHP 8.3+ and Laravel 13
- Blade, Livewire 4 and Alpine.js
- Tailwind CSS 4 and Vite
- MySQL
- Database-backed sessions and queues
- Laravel Scheduler

## Local setup

Prerequisites are PHP 8.3 or newer, Composer 2, Node.js 22.13 or newer, npm, and MySQL. Local development currently uses MySQL 9.6.

```bash
composer install
cp .env.example .env
php artisan key:generate
npm install
```

Set local values in `.env`. Never commit that file or place credentials in `.env.example`.

Create the first administrator interactively after migrating:

```bash
php artisan inventra:create-admin
```

The command prompts securely for a password, refuses to create a second initial administrator, and does not expose a public registration route.

## Identity and access

Phase 1 provides session authentication for `admin`, `manager`, and `sales_rep` staff. Login accepts a normalized email address or phone number. Five consecutive failures temporarily lock an account; the duration and request throttles are configured with the `AUTH_*` environment values documented in `.env.example`.

Staff marked for first-login setup must replace their temporary password before accessing protected routes, then may set or skip an optional four-digit Quick PIN. Quick PINs are hashed and are not a password replacement. Password reset uses Laravel's expiring, throttled reset-token broker and requires a working production mail configuration. The safe local placeholder uses the non-logging `array` mailer so reset tokens are not written to application logs.

There is no public registration route. Later domain modules must use Laravel Policies in addition to the reusable `role` middleware.

## Database

MySQL is the only supported database engine. Local development uses MySQL 9.6 and the `inventra` database. Configure local credentials in `.env`, then run `php artisan migrate`. Database sessions and queues use the tables included in the default migrations.

The production MySQL version must be verified with the hosting provider before deployment. Avoid unnecessary MySQL 9.6-specific features so migrations and queries remain broadly compatible with the verified production version.

## Development and assets

```bash
composer run dev
npm run build
```

Livewire supplies the Alpine.js runtime through its ESM bundle, preventing duplicate Alpine instances.

## Tests and code style

```bash
composer test
vendor/bin/pint --test
```

All automated tests use the dedicated local MySQL database `inventra_test`:

```bash
php artisan test
```

The test bootstrap resolves the active connection before testing traits execute and refuses any non-MySQL driver, non-local host, database URL, or database name other than `inventra_test`. Never point the test suite at development or production data.

## Dependency security

Run both ecosystem audits during maintenance and before deployments. Vulnerabilities must be reviewed and resolved, not automatically ignored.

```bash
composer audit
npm audit
```

## Queue and scheduler

The queue uses the database driver. In environments with a process manager, run `php artisan queue:work`. On shared hosting, configure a cron task suitable for the host's execution limits.

Configure the scheduler to run every minute:

```cron
* * * * * cd /path/to/inventra && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler prunes security events older than `SECURITY_EVENT_RETENTION_DAYS` (90 days by default) each night. Keep the scheduler active so the retention policy is enforced.

Production must set `APP_URL` to the exact public HTTPS origin and use `SESSION_SECURE_COOKIE=true`. Requests with any other host are rejected, and generated password-reset links use this configured origin. The security middleware sends baseline browser protections, denies framing, and enables HSTS only for secure production requests. HSTS initially uses `max-age` without `includeSubDomains` or `preload`; add those directives only after every affected subdomain is confirmed HTTPS-only.

The frontend uses Livewire's CSP-compatible Alpine runtime. CSP enforcement remains deferred until application asset requirements are known. The production rollout must build a restrictive policy containing `frame-ancestors 'none'`, apply a per-request nonce where required, deploy it in report-only mode, exercise all application functionality, understand and resolve violations, and only then enforce it. Broad wildcards, `unsafe-inline`, and `unsafe-eval` are not acceptable shortcuts.

`TRUSTED_PROXIES` is intentionally empty by default. If production terminates HTTPS at a reverse proxy, set it to the proxy's verified IP address or CIDR ranges as a comma-separated list. Never use a trust-all value. Confirm secure-cookie behaviour, generated HTTPS URLs, and HSTS after deployment; leave it empty when Namecheap passes requests directly to the application.

For Namecheap shared hosting, point the web root at `public/`, keep `.env` and writable storage outside publicly executable paths, disable directory listing in the hosting configuration, and cache production configuration only after all environment values are set.
