# Production runtime and security contract

Audited 2026-09-08 against commit `9b717e4db29ee0259094e82fa8efe37c89496bf7`.
The starting working tree, index and stash were empty. Phase 1 is the approved baseline.
No hosting account was accessed. This document prepares a future deployment; its deployment commands were not executed.

## Runtime decision

Use the current locked dependencies on a maintained PHP 8.4 patch release, **at least 8.4.1**, for both web and CLI/cron. PHP 8.4.0 and PHP 8.3 cannot install this lock. PHP 8.5 is not required. Local PHP 8.4.21 is available for validation alongside PHP 8.5.6; these installed patch numbers are evidence, not recommendations to retain outdated patches.

Retire the README's PHP 8.3 deployment target. Root Composer `^8.3` and Laravel 13.26.1's `^8.3` describe individual constraints, not the intersection of the locked graph. The following production dependencies raise that intersection:

| Package | Locked version | PHP constraint | Direct/transitive | Blocking PHP 8.3? |
| --- | --- | --- | --- | --- |
| symfony/clock | v8.1.0 | `>=8.4.1` | Transitive, production | Yes |
| symfony/console | v8.1.4 | `>=8.4.1` | Transitive, production | Yes |
| symfony/css-selector | v8.1.0 | `>=8.4.1` | Transitive, production | Yes |
| symfony/error-handler | v8.1.2 | `>=8.4.1` | Transitive, production | Yes |
| symfony/event-dispatcher | v8.1.2 | `>=8.4.1` | Transitive, production | Yes |
| symfony/finder | v8.1.1 | `>=8.4.1` | Transitive, production | Yes |
| symfony/http-foundation | v8.1.4 | `>=8.4.1` | Transitive, production | Yes |
| symfony/http-kernel | v8.1.4 | `>=8.4.1` | Transitive, production | Yes |
| symfony/mailer | v8.1.2 | `>=8.4.1` | Transitive, production | Yes |
| symfony/mime | v8.1.4 | `>=8.4.1` | Transitive, production | Yes |
| symfony/process | v8.1.0 | `>=8.4.1` | Transitive, production | Yes |
| symfony/routing | v8.1.2 | `>=8.4.1` | Transitive, production | Yes |
| symfony/string | v8.1.2 | `>=8.4.1` | Transitive, production | Yes |
| symfony/translation | v8.1.4 | `>=8.4.1` | Transitive, production | Yes |
| symfony/uid | v8.1.4 | `>=8.4.1` | Transitive, production | Yes |
| symfony/var-dumper | v8.1.2 | `>=8.4.1` | Transitive, production | Yes |

Root direct packages are Laravel Framework 13.26.1, Tinker 3.0.2 and Livewire 4.4.1. Symfony is reached through Laravel, Carbon and other libraries; these blockers remain with `--no-dev`. PHPUnit 12.5.33 requires PHP >=8.3 and is not responsible for the production floor. Composer runtime API >=2.2 is required; use Composer 2.2 or newer.

The application also references `Pdo\Mysql::ATTR_SSL_CA` in config/database.php. [Pdo\Mysql requires PHP >=8.4.0](https://www.php.net/manual/en/class.pdo-mysql.php), so a hypothetical PHP 8.3 dependency resolution alone would not make this checkout compatible.

**Decision: path A with corrected documentation (path C).** No dependency changes are necessary. PHP 8.3 would require a separately reviewed resolution of the Symfony graph and full compatibility/security regression, not a flag or an ignored platform check. No update/downgrade or platform override was performed. Never deploy with `--ignore-platform-reqs`.

## PHP extensions

Check both web and CLI SAPIs; passing CLI checks does not prove web configuration.

| Extension | Required by | Mandatory for Inventra? | Shared-host check needed? |
| --- | --- | --- | --- |
| bcmath | Root requirement; fixed-precision money/stock actions | Yes | Yes |
| pdo_mysql, PDO | Root requirement; MySQL, sessions, cache | Yes | Yes |
| mbstring | Root, Laravel, prompts, report/input processing | Yes; provision native extension even though Composer accepts polyfill | Yes |
| ctype | Laravel; identifier validation | Yes; locked polyfill available | Yes |
| filter, hash, session, tokenizer | Laravel locked requirements | Yes | Yes |
| openssl | Laravel, encryption, HTTPS and SMTP TLS | Yes | Yes, including CA trust |
| json | Guzzle, Carbon, PHP parser; request/audit data | Yes, built into PHP 8 | Verify SAPI |
| fileinfo | Flysystem local and MIME detection | Yes, locked production dependency | Yes |
| dom, libxml | Mail HTML-to-inline-style conversion | Yes, locked production dependency | Yes |
| pcre | Dotenv and application validation | Yes | Verify SAPI |
| iconv | Locked mbstring polyfill | Yes for this installed graph | Yes |
| curl | Optional Guzzle transport; Composer download acceleration | Not mandatory if HTTPS stream transport is usable | Verify curl OR allow_url_fopen with working TLS |
| xml, xmlwriter, phar | Pint/PHPUnit development tooling; Composer PHAR execution | Not web-runtime requirements; needed for respective CLI tools | Yes if running those tools |
| intl | Covered by locked Symfony IDN/normalizer/grapheme polyfills | No native requirement found | No |
| redis, memcached, pcntl, posix | Optional drivers/development process tooling | No launch workflow requires them | No |

SMTP uses PHP sockets/streams and OpenSSL; no ext-sockets requirement was found. GD, Imagick and ZIP are not application requirements. OPcache is useful but is not a correctness dependency. `composer check-platform-reqs --no-dev` is a required deployment gate, complemented by actual SMTP/HTTP transport checks.

## Database contract

Two engines are supported, each validated independently. Neither statement generalises beyond the versions named.

| Engine | Minimum accepted | Validated against | Connection driver |
| --- | --- | --- | --- |
| MySQL | 8.0.16 | 9.6.0 (local dev/test) | `DB_CONNECTION=mysql` |
| MariaDB | 11.4.x | 11.4.13 (isolated compatibility instance) | `DB_CONNECTION=mariadb` |

**MariaDB 11.4.x is supported as of the 2026-09-09 compatibility pass**, superseding this document's earlier "MariaDB is intentionally unsupported" position. That position was recorded before any MariaDB instance had been tested; it is withdrawn on evidence, not on preference. MariaDB releases outside 11.4.x, and MariaDB reached through the `mysql` driver rather than `mariadb`, remain unvalidated. Prefer a provider-maintained release of whichever engine is used. A minimum feature floor is not proof of current vendor security support.

Evidence: product nonnegative-stock checks, sale/payment integrity checks, return/refund and expense positive-value checks, business-settings singleton check and operational-alert lifecycle checks all use enforced CHECK constraints. MySQL began enforcing CHECK in 8.0.16; MariaDB has enforced them since 10.2.1. Migrations also use ENUM modification, `UPDATE ... INNER JOIN`, `AFTER`, JSON audit metadata, decimal fields, unique indexes and restrictive foreign keys. No generated-column, spatial, full-text, partitioning, window-function, CTE or MySQL-9-only feature requirement was found. Report SQL uses only `SUM`, `COUNT`, `COUNT(DISTINCT …)`, `ABS` and `COALESCE`.

### Cross-engine differences that are documented, not defects

- **`DROP CHECK` is MySQL-only.** Migrations use the SQL-standard `ALTER TABLE … DROP CONSTRAINT`, which both engines accept. `DROP CHECK` raises MariaDB error 1064 and must not be reintroduced.
- **JSON storage differs.** MySQL uses its native `json` type; MariaDB stores `LONGTEXT` plus an implicit `json_valid()` CHECK constraint, so `information_schema` reports four extra CHECK constraints named after the JSON columns. Both reject malformed JSON and accept NULL. Inventra uses JSON only for storage/serialisation through Laravel's `array` cast — no `JSON_EXTRACT`, `->>`, `whereJson*` or JSON index anywhere — so the difference has no application consequence.
- **JSON object key order differs.** MySQL's native type reorders object keys; MariaDB preserves insertion order. Assertions on audit/security metadata must compare key membership and values, never key order.
- **CHECK-violation error codes differ.** MySQL raises SQLSTATE `HY000`/3819; MariaDB raises `23000`/4025. Because SQLSTATE class `23000` also covers duplicate keys, the three duplicate-detection handlers (`StaffController`, `CustomerController`, `Inventory\ProductController`) would classify a CHECK violation as a duplicate on MariaDB. Not currently reachable: `users` and `customers` carry no CHECK constraints, and product stock/reorder validation rejects negatives before the write. Narrowing those handlers to the duplicate-entry code (1062) would remove the ambiguity if a CHECK is ever added to those tables.
- **Introspection text differs cosmetically.** `information_schema` reports `CURRENT_TIMESTAMP` vs `current_timestamp()` and quotes string defaults differently. Effective defaults are identical, and there are zero true generated columns on either engine.

All domain tables must use **InnoDB**, including sessions/cache. Configuration currently inherits the server engine (`engine=null`), so verify `@@default_storage_engine` and actual table engines. Require transactions, row-level `SELECT ... FOR UPDATE`, deterministic product lock ordering and FK enforcement. Never disable checks/FKs for deployment. Financial values use DECIMAL plus BCMath; do not convert them to floating point.

Laravel strict mode establishes `ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` on MySQL >=8.0.11, and the identical mode was observed on MariaDB 11.4.13 through the `mariadb` driver. Verify this on the application connection. Use `utf8mb4` / `utf8mb4_unicode_ci` as configured; comparisons are case/accent insensitive. Identity canonicalization remains server-side; changing collation is a separate reviewed change. Transactions use the server isolation default; no custom isolation requirement was found.

Migration account needs CREATE/ALTER/INDEX/REFERENCES and normal DML, including migration backfills. Runtime needs SELECT/INSERT/UPDATE/DELETE for legitimate workflows, sessions/cache and scheduled pruning. Application immutability controls protect business ledgers; no TRIGGER privilege is required. Validate host limits on a disposable database outside this phase before any first production migration.

## Shared-host compatibility evidence

| Project requires | Host verified | Host unverified |
| --- | --- | --- |
| PHP >=8.4.1, matching CLI/web extensions | None | cPanel PHP selector, CLI absolute path, extension and disabled-function lists |
| MySQL >=8.0.16 or MariaDB 11.4.x, InnoDB, strict mode, CHECK/FK enforcement | MariaDB 11.4.12 reported by cPanel (2026-09-09) | SELECT VERSION() on the production DB, engines, SQL modes, grants |
| Domain document root at release/public; rewrite support | None | cPanel root configuration, .htaccess AllowOverride, directory listing disabled |
| HTTPS for every request before credentials reach PHP | None | Certificate, HTTP redirect, proxy topology and forwarded-header behavior |
| Writable storage and bootstrap/cache outside public access | None | Ownership, quotas, permissions, release-switch capability |
| One scheduler invocation per minute | None | cPanel minimum cadence, CLI binary, cron limits and failure monitoring |
| Authenticated outbound SMTP with TLS | None | Provider settings, ports, sender authorization and delivery |
| Composer-built vendor and Vite-built public/build | None | SSH/Composer availability OR secure build-artifact upload |

No statement about a Namecheap plan or account is verified. General provider marketing would not verify this account. Node >=22.13.0 and npm are **build-only** (package.json); use `npm ci` with the lock, then `npm run build`. Deploy public/build including manifest/fonts along with public/images. Node is not needed at runtime. No Vite development server or `public/hot` belongs in production.

## Production environment checklist

Do not copy local `.env.example` unchanged. Store secrets outside the document root; never print `.env` or cached config in support tickets.

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<exact-public-origin>`, `APP_NAME` set to the installation name. Generate APP_KEY once on first installation; retain it across releases and back it up. APP_PREVIOUS_KEYS is optional for a separately planned rotation, never a substitute for preserving keys.
- Keep technical `config/app.php` timezone UTC; `BUSINESS_TIMEZONE=Africa/Lagos` and fixed NGN business currency. Do not reinterpret existing dates by changing timezone. `APP_LOCALE=en`, `APP_FALLBACK_LOCALE=en`; APP_FAKER_LOCALE is development-only.
- `DB_CONNECTION` must match the server: `mysql` for MySQL, **`mariadb` for MariaDB** (the `mariadb` driver selects Laravel's MariaDB grammar; pointing the `mysql` driver at a MariaDB server is unvalidated). Exact host-assigned DB name/user/password; host-supplied host/port or optional DB_SOCKET. DB_URL empty when using discrete values. DB_CHARSET=utf8mb4, DB_COLLATION=utf8mb4_unicode_ci. MYSQL_ATTR_SSL_CA only where verified remote-DB TLS is required. Do not configure testing credentials in production.
- `SESSION_DRIVER=database`, `SESSION_LIFETIME=120`, `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, `SESSION_SAME_SITE=lax`, `SESSION_PATH=/`, `SESSION_DOMAIN=null` (host-only). SESSION_ENCRYPT=false is the current server-side payload setting; cookies are protected through Laravel. No plaintext one-time secrets belong in sessions. Leave SESSION_CONNECTION/TABLE overrides unset to use the authoritative connection and sessions table.
- `CACHE_STORE=database`; use a unique CACHE_PREFIX if installations share cache tables. Leave DB_CACHE_CONNECTION/TABLE/LOCK_CONNECTION/LOCK_TABLE unset unless a reviewed equivalent is configured. Cache powers throttles and scheduler overlap locks; array/file substitutions are not the launch contract.
- `QUEUE_CONNECTION=sync`, `BROADCAST_CONNECTION=log`, `FILESYSTEM_DISK=local`, `APP_MAINTENANCE_DRIVER=file`. No worker is required. Optional Redis/Memcached/AWS scaffold values in the example are unused by this profile; they are not missing services.
- `MAIL_MAILER=smtp`, verified MAIL_SCHEME (`smtps` for implicit TLS or `smtp` with negotiated STARTTLS), MAIL_HOST/PORT/USERNAME/PASSWORD, authorized MAIL_FROM_ADDRESS and MAIL_FROM_NAME. Optional MAIL_EHLO_DOMAIN only if required by the provider; leave MAIL_URL empty when using discrete values. Test delivery and TLS. Never use `log`, failover-to-log or `array` in production: reset links either leak to logs or are not delivered. Missing-mail fallback now uses array to avoid logging secrets; this fails delivery safely, not launch readiness.
- `TRUSTED_PROXIES` empty for direct connections, otherwise only verified proxy IPs/CIDRs. Never `*`. Trusted host is derived from APP_URL; no separate TRUSTED_HOSTS variable exists. Require the edge/web server to redirect HTTP to HTTPS; application code does not supply this redirect. APP_URL alone is not HTTPS enforcement.
- `STRICT_TRANSPORT_SECURITY=max-age=31536000` only after HTTPS is verified. Middleware sends it only for secure production requests. No includeSubDomains/preload until separately verified.
- `LOG_CHANNEL=stack`, `LOG_STACK=daily`, `LOG_LEVEL=warning`, `LOG_DEPRECATIONS_CHANNEL=null`; configure restricted log access, rotation and disk monitoring. LOG_DAILY_DAYS defaults to 14. Do not select query/request-body logging. Keep business audit history indefinitely; security-event retention is separate.
- Retain BCRYPT_ROUNDS=12 and existing AUTH_* limits (five account login attempts, separate IP throttling, 15-minute account lock, password reset expiry/throttle, PIN limits). SECURITY_EVENT_RETENTION_DAYS=90. Do not alter AUTH_MODEL/GUARD/BROKER/table scaffold overrides for launch.
- Set the CSP values below. Rebuild config cache after env changes.
- Leave WHATSAPP_ACCESS_TOKEN, PHONE_NUMBER_ID, BUSINESS_ACCOUNT_ID, VERIFY_TOKEN, APP_SECRET, RECEIPT_TEMPLATE_NAME empty. Template language remains en. The example Graph version is not verified production support. Meta configuration/activation remains a later task; no browser connection to Meta is needed.
- Business Settings singleton is created by its migration; complete identity/contact/receipt footer as Administrator after installation. Reads must never bootstrap it. Fixed currency/timezone and numbering remain unchanged.

Newly documented active overrides include DB_SOCKET/CHARSET/COLLATION, MYSQL_ATTR_SSL_CA, MAIL_URL/EHLO_DOMAIN and CSP report-only mode. Optional transport/driver variables in scaffold configs are not all production obligations. Local APP_DEBUG, HTTP APP_URL, insecure-cookie override, array mail and debug logging are deliberate development defaults and must be replaced above.

## Sessions, HTTPS and CSP

Existing CSRF protection remains on state-changing browser routes; WhatsApp webhook exemption remains protected by raw-body HMAC. Authentication regenerates sessions; logout invalidates the session and regenerates CSRF. Security-sensitive revocation requires database sessions. Current role/status is refreshed server-side. No authentication redesign was made.

Livewire is bundled from `livewire.csp.esm`. A new nonce is allocated before each CSP-enabled response render through Laravel Vite, and Livewire's scriptConfig directive automatically uses it. The configured CSP replaces literal `{nonce}` with that response nonce. Do not cache personalized HTML or reuse nonce-bearing responses across users. PHP-FPM request isolation remains required; long-lived app servers need separate review.

Start with this **report-only** production candidate, using built assets:

```dotenv
CONTENT_SECURITY_POLICY="default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; script-src 'self' 'nonce-{nonce}'; script-src-attr 'none'; style-src 'self' 'nonce-{nonce}'; style-src-attr 'none'; img-src 'self' data:; font-src 'self'; connect-src 'self'"
CONTENT_SECURITY_POLICY_REPORT_ONLY=true
```

No wildcard, unsafe-inline or unsafe-eval is allowed. No external font/CDN source is required by the application layouts; the framework health page exception is recorded below. Tailwind contains small embedded SVG form-control images, hence data: is restricted to images. External Meta delivery is server-to-server and does not widen connect-src.

WhatsApp confirmations now use a bundled submit listener instead of inline onsubmit/onclick. Cancel does not consume the UI submit state; confirmed first send disables repeat submission. Backend request tokens remain authoritative. Receipt footer whitespace uses a stylesheet utility rather than an inline style attribute. Existing Alpine directives use the CSP-compatible runtime.

Exercise login/reveal, onboarding PIN, confirmation cancel/accept, sale search, receipts, all role dashboards and domain forms in a production-like environment, including error pages. Inspect browser CSP violations; report-only here has no remote collector and must not be mistaken for enforcement. Then set REPORT_ONLY=false and repeat. Keep DENY framing and other existing headers throughout. Confirmed framework exceptions: vendor/laravel/framework/src/Illuminate/Foundation/resources/health-up.blade.php loads fonts.bunny.net, a jsDelivr Tailwind browser script and inline Tailwind styles; framework minimal/layout error views contain inline CSS without nonces. The proposed policy blocks these resources. Health HTTP status/JSON and error status remain usable, but these HTML pages are not CSP-clean. Do not widen the policy to those CDNs. A reviewed local health/error renderer or nonce-compatible view override is still required before blanket enforcement; no vendor files were modified. Local Vite HMR is intentionally outside this production policy.

## Scheduler and queue

All scheduled times below are **UTC**, because the scheduler uses the application timezone. Business report dates remain Africa/Lagos.

| Command | Cadence | Purpose | Required at launch? | Failure impact |
| --- | --- | --- | --- | --- |
| model:prune --model=App\Models\SecurityEvent | Daily 02:00 UTC | Apply 90-day operational security retention | Yes | Retention and disk growth |
| model:prune --model=App\Models\PurchaseRequest | Daily 02:15 UTC | Expired, unused, unlinked request identities | Yes | Unused rows accumulate |
| model:prune --model=App\Models\ExpenseRequest | Daily 02:30 UTC | Same, for Expenses | Yes | Unused rows accumulate |
| model:prune --model=App\Models\SaleReturnRequest | Daily 02:45 UTC | Same, for Returns | Yes | Unused rows accumulate |
| model:prune --model=App\Models\SaleRefundRequest | Daily 02:50 UTC | Same, for Refunds | Yes | Unused rows accumulate |
| inventra:reconcile-operational-alerts | Hourly | Repair derived alert state; detect ledger divergence | Yes | Delayed/missing alerts until next success |
| inventra:dispatch-whatsapp-receipts | Every minute | Send WhatsApp receipts queued by completed sales | Only if WhatsApp is configured | Automatic receipts are not sent; sales are unaffected; manual Send still works |

Consumed/result-linked request rows are retained. No scheduled pruning exists for audit_logs or operational alerts. No extra payment/WhatsApp request-pruning schedule was found. `inspire` is registered but not scheduled. All seven scheduled jobs use withoutOverlapping with persistent database cache locks.

Future cPanel cron (replace both absolute paths with verified values; do not paste placeholders):

```cron
* * * * * cd /home/ACCOUNT/inventra && /ABSOLUTE/PHP84/bin/php artisan schedule:run >> /home/ACCOUNT/logs/inventra-scheduler.log 2>&1
```

Once per minute is sufficient for current schedules, and is now **required** rather than merely sufficient: automatic WhatsApp receipt delivery runs on this cadence. Rotate this private operational log and monitor job failures/last success; do not discard all cron evidence. Host minute-cadence permission remains unverified.

### Automatic WhatsApp receipts — exact cron requirement

Completing a sale does not call Meta. It commits the sale, then persists an eligible delivery as
`whatsapp_deliveries` row with `origin = 'automatic'`, `status = 'pending'` and
`dispatch_claimed_at = NULL`. `inventra:dispatch-whatsapp-receipts` is what actually sends it.

- **The single once-per-minute `schedule:run` cron above is the only cron required.** There is no
  second cron entry, no queue worker, no Supervisor and no Horizon.
- If that cron is absent, sales still complete normally and receipts simply queue and wait. No sale,
  payment, return, refund or stock figure is affected. Staff can still send each receipt by hand.
- If WhatsApp credentials are empty, the command exits immediately without contacting Meta and
  nothing is queued in the first place.
- Duplicate sending is prevented **in the database**, not by the scheduler: a delivery is taken with
  a conditional `UPDATE ... WHERE dispatch_claimed_at IS NULL`, and the row is only dispatched if
  that update matched exactly one row. Overlapping runs, a re-run after an interrupted command, or a
  manually invoked command therefore cannot send the same receipt twice. `withoutOverlapping()` is a
  courtesy on top of this, not the safety mechanism.
- If the process dies between claiming and sending, the receipt is never sent rather than sent
  twice. Such a row stays visible as `pending` in WhatsApp history for manual follow-up.
- Consent is re-checked at dispatch time, not just when queued. A customer who opts out in the
  interval, or a sale voided in the interval, closes the delivery as `failed` with the reason
  recorded (`consent_withdrawn` / `sale_no_longer_completed`) and Meta is never contacted.

To dispatch by hand (for example while verifying a deployment):

```
php artisan inventra:dispatch-whatsapp-receipts --limit=25
```

**QUEUE WORKER NOT REQUIRED FOR CURRENT LAUNCH SCOPE**

No application ShouldQueue jobs/notifications or queued dispatches were found. Password-reset mail and WhatsApp calls are synchronous; alerts project after commit and reconcile on the scheduler. Do not start Horizon, Supervisor or queue workers solely because scaffold queue tables exist.

## Filesystem and future deployment

**storage:link is still not required.** Product photographs and staff profile photographs are the
only upload workflows, and both write to the **private** `local` disk at `storage/app/private`,
which no web server maps to a URL. They are read back exclusively through authorized controller
routes (`inventory.products.image`, `users.photo`) that run the relevant policy first, so there is
no public uploaded asset and no symlink to create. Brand images remain repository public assets.

Two directories are created on first upload and must persist across releases:

| Path | Contents | Served by |
| --- | --- | --- |
| `storage/app/private/product-images` | Product photographs | `GET /inventory/products/{product}/image`, gated by ProductPolicy@view |
| `storage/app/private/staff-photos` | Staff profile photographs | `GET /users/{user}/photo`, gated by UserPolicy@viewPhoto |

Filenames are generated by the server (40 random characters plus an extension derived from the
file's *detected* type), so a client-supplied name never reaches the filesystem. Uploads are capped
at 2 MB and 4000x4000 pixels and restricted to JPEG, PNG and WebP; SVG is refused because it can
carry script. Responses are sent with `Cache-Control: private` and `X-Content-Type-Options: nosniff`
so a proxy never retains an image only some users may see.

Keep storage/app/private, storage/framework and storage/logs plus bootstrap/cache writable by the application user; avoid world-writable permissions. Storage must persist across releases — **losing storage/app/private loses every uploaded photograph**, and the database will then reference files that are gone (the routes answer 404 and the initials placeholder is shown, so nothing breaks). Never serve the project root. No release symlink is inherently required, but the chosen release-switch mechanism must preserve these paths and the document-root boundary.

These are future instructions, not commands executed in this phase. Do not run Composer's setup/dev/test scripts on production; setup includes migrations and development tooling, and tests may reset schemas.

First deployment:

1. Verify every host gate above; take an initial backup and confirm recovery access. Prepare a release outside the document root. Install the production .env securely and establish writable paths.
2. On a trusted build machine use the unchanged composer.lock/package-lock.json: `composer install --no-dev --optimize-autoloader`, `npm ci`, `npm run build`. Alternatively run Composer on the host with the verified PHP binary. Deploy vendor, code, public assets and manifests together. Node/npm and tests are not runtime requirements.
3. Run `composer check-platform-reqs --no-dev` with the target PHP; verify DB identity explicitly before migration. Generate `php artisan key:generate --force` only for this new installation, never an existing one.
4. With maintenance/traffic protection in place, run `php artisan migrate --force`; do not run demo/factory seeders. This phase did not execute this command. The project now ships **29** migrations.
5. Run `php artisan inventra:create-admin` interactively using its secret prompt; do not pass passwords in command-line arguments. Complete Business Settings through the protected UI.
6. Run `php artisan config:cache`, `php artisan route:cache`, `php artisan view:cache` after final environment values. Verify public/hot is absent. Configure document root, HTTPS, scheduler and mail only through an authorized future deployment.
7. Perform smoke checks below before opening access.

Subsequent deployments:

1. Back up MySQL, .env/keys and persistent storage; retain the previous matching code/vendor/assets artifact. Review every pending migration for data/locking/rollback impact. Pause cron while applying an incompatible migration or release switch.
2. Enter maintenance (`php artisan down`) before replacing live code if no atomic release switch is available. Stage the new locked vendor/assets build; retain .env, APP_KEY and storage.
3. Clear stale configuration on the new release with `php artisan config:clear`, check target platform and database identity, then apply only reviewed pending migrations using `php artisan migrate --force`.
4. Rebuild config/route/view caches, switch the release, run smoke checks, resume cron, and `php artisan up` when safe.

Do not blindly run migrate:rollback: many ledger/integrity migrations are not safe data reversals. Restore compatible code only when the schema remains backward-compatible. Otherwise use a reviewed forward fix or restore the full consistent backup while access is stopped, accounting for any transactions accepted since backup. Never regenerate APP_KEY to fix a deployment.

## Backups and post-deployment smoke checks

Before launch require encrypted off-host MySQL backups at least daily and before every release, retained under an approved retention policy; business ownership must explicitly accept the resulting recovery-point/time objectives. Include ledger/audit evidence, sessions and settings. Back up .env/APP_KEY/previous keys separately with tightly restricted access. Back up persistent private files if introduced later; there are no launch uploads today. Perform a timed restore into an isolated database and reconcile ledger/aggregate evidence without repairing it. A backup file without a demonstrated restore is insufficient.

Future smoke checklist (never against Namecheap in this phase):

- `/up` returns 200; it proves application boot only, not database/mail/scheduler health. Unknown hosts fail; HTTP redirects before authentication; HTTPS cookies/HSTS and non-debug errors are correct.
- Login, failed-login lockout, logout, password reset delivery/expiry and session revocation work. No reset URL appears in logs. Exercise password/PIN onboarding and CSP password reveal.
- Admin/Manager/Rep dashboards show their permitted populations; direct unauthorized URLs fail. Rep cannot read inactive products, costs, Reports, Settings or cross-domain audit history; Manager cannot access Administrator-only Settings.
- In a designated controlled acceptance dataset, product/customer registration and consent, sale validation/commit, stock ledger, payment settlement and receipt privacy/printing work. Verify permission boundaries and no duplicated writes on retries.
- Reports retain separate Gross Sales/Collections/Receivables/Returns/Refunds semantics; Settings identity/footer renders; notification ownership/current entitlement is enforced.
- Check scheduler success and reconciliation, pruning retention rules, cache locks, writable paths, disk quotas, and restored backups. Do not manufacture financial activity merely as an unattended health probe.
- WhatsApp remains disabled. Only after separately approved activation test consent, destination checks, provider delivery and authenticated idempotent webhooks.

## Sources and evidence limits

- [MySQL CHECK introduction](https://dev.mysql.com/blog-archive/mysql-8-0-16-introducing-check-constraint/) supports the 8.0.16 enforcement floor.
- [Livewire CSP](https://livewire.laravel.com/docs/4.x/csp) and the installed FrontendAssets implementation establish CSP-runtime/nonce handling.
- [PHP supported versions](https://www.php.net/supported-versions.php) must be checked when selecting the actual patched production binary.

Host evidence remains mandatory: CLI/web versions and extensions, database vendor/version/engines/modes/grants, public root/rewrite/HTTPS/proxy behavior, cron limits, SMTP delivery, filesystem ownership/quotas and backup restoration. Local compatibility checks do not certify Namecheap. Production rollout remains blocked until these are evidenced and the report-only CSP checklist is completed before enforcement.


## Validation record

Validation used PHP 8.4.21, Node 22.23.2 and local MySQL 9.6.0. Composer platform requirements passed on both PHP 8.4.21 and 8.5.6. Read-only Composer prohibits diagnostics confirmed the 16 blockers for PHP 8.3.0 and 8.4.0. No dependency was changed.

- Targeted security/auth/session/WhatsApp/completion regression: 45 tests, 335 assertions passed.
- Broad eligible existing-schema regression: 429 tests, 4,161 assertions passed on PHP 8.4.21.
- Transactional browser fixture rendering: 1 test, 18 assertions passed. Real Chrome enforced the candidate CSP with zero observed violations on the exercised login, sale entry, sale/payment receipts, product role/rejection/success views and WhatsApp send confirmation. Confirm cancel, accept and repeat submission were exercised without a provider call. This does not certify every route or the framework health/error HTML described above.
- Pint, strict Composer validation, Composer audit (no advisories), npm audit (zero vulnerabilities), Vite build and diff whitespace checks passed. The existing auth-check.svg runtime-resolution warning remains; the asset is present in public/images/figma.
- Isolated route/view cache compilation passed and those verification caches were cleared. No production cache or deployment commands ran.

The temporary test harness verifies local MySQL and SELECT DATABASE()=inventra_test before testing traits, verifies the existing migration list, blocks schema commands, and bypasses RefreshDatabase migration execution while retaining test transactions. Random session garbage collection is disabled only in that harness to preserve deterministic query-count tests; production settings and existing query limits are unchanged.

Excluded unsafe suites: ExpenseConcurrencyTest, ExpenseMigrationTest, PurchaseConcurrencyTest, PurchaseMigrationTest, SupplierConcurrencyTest, ReturnRefundMigrationTest, SalePaymentMigrationTest and WhatsAppMigrationTest; also ReturnRefundFoundationTest::test_independent_processes_cannot_over_return_or_over_refund. They contain schema reset/reversal or commit/reset behavior and were not run. This is not an unrestricted full-suite claim.

Final database verification remained inventra_test, with no pending migrations and zero users/sales after rollback. No development database connection/mutation, production migration, deployment, Meta activation, dependency update, commit, stash or reset occurred. Host evidence and complete CSP rollout remain outstanding; do not treat the passing local checks as deployment approval.

## MariaDB 11.4 compatibility pass (2026-09-09)

Triggered by verified Namecheap host evidence: PHP 8.4.24 (web) and **MariaDB 11.4.12-MariaDB-cll-lve** over a local UNIX socket.

Method: MariaDB 11.4.13 installed keg-only via Homebrew and run as a disposable instance on 127.0.0.1:3307 with its own datadir, against database `inventra_mariadb_compat` and a dedicated least-privilege user. The existing MySQL 9.6 install on 3306 was never modified; `inventra` (development) was never connected to. Docker was unavailable on the machine. Every destructive step printed and asserted vendor, version and database name first. `tests/TestCase.php` pins the `mariadb` driver to `inventra_mariadb_compat` and the `mysql` driver to `inventra_test`, so neither run can reach the other's database or the development database. The tested patch (11.4.13) is one patch ahead of the host's 11.4.12; both are 11.4.x.

Single incompatibility found and fixed: `ALTER TABLE … DROP CHECK` is MySQL-only and fails on MariaDB with error 1064. Ten occurrences across five migrations were changed to the SQL-standard `DROP CONSTRAINT`, which both engines accept. Two were in `up()` and would have aborted a fresh production migration at `2026_09_04_010000`; eight were in `down()` and would have blocked rollback. No migration was added, since the schema has never been deployed to production. No constraint, foreign key, lock, ENUM or report calculation was weakened.

Results on MariaDB 11.4.13:

- All 25 migrations apply forward, and all 25 roll back.
- 35 tables, all InnoDB, all `utf8mb4_unicode_ci`. 65 foreign keys (44 RESTRICT, 17 SET NULL, 4 CASCADE), 171 distinct indexes (72 unique), 35 DECIMAL columns at 15,2 and 15,3, 15 ENUM columns, zero generated columns, zero FULLTEXT. Identical to MySQL apart from the documented differences above.
- All 30 application CHECK constraints present, plus MariaDB's 4 implicit `json_valid()` constraints.
- 19 deliberate invalid writes were rejected on both engines with equivalent semantics: stock/reorder non-negativity, the business-settings singleton and blank-name checks, expense and purchase totals, JSON validity, ENUM strictness, foreign keys and RESTRICT, varchar truncation, non-numeric DECIMAL, and operational-alert type/occurrence. Zero invariants were unenforced.
- Full suite: **483 tests, 5094 assertions passing** — the same totals as MySQL 9.6. This included the migration and concurrency suites that earlier phases excluded, because the target was disposable. `lockForUpdate()` (40 sites) and `DB::transaction` (46 sites) behaved identically.
- All ten reports execute, including the grouped supplier/product/receiver breakdowns.

One test assertion was corrected, not weakened: `SecurityEventLifecycleTest` compared sanitised metadata with an order-sensitive `assertSame`, which relied on MySQL reordering JSON keys. It now asserts the key set canonically and each value with `assertSame`, so it is order-independent and type-strict on both engines.

Not validated by this pass: MariaDB versions outside 11.4.x, the `mysql` driver against a MariaDB server, Galera/replication topologies, and the production host itself. `SELECT VERSION()` must still be run on the real production database before first migration.
