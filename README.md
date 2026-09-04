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

Prerequisites are PHP 8.3 or newer with BCMath, mbstring and PDO MySQL, Composer 2, Node.js 22.13 or newer, npm, and MySQL. Local development currently uses MySQL 9.6. Verify that the production host provides these PHP extensions before deployment.

```bash
composer install
cp .env.example .env
php artisan key:generate
npm install
```

Set local values in `.env`. Never commit that file or place credentials in `.env.example`.

The example uses `APP_URL=http://localhost:8000`. Keep that value when using `php artisan serve`, or change it to the exact local HTTPS or virtual-host origin you actually use. The configured host is enforced, so browsing through a different hostname is rejected.

Create the first administrator interactively after migrating:

```bash
php artisan inventra:create-admin
```

The command prompts securely for a password, refuses to create a second initial administrator, and does not expose a public registration route. It is the exclusive bootstrap path for the initial Administrator. The Staff Management UI can create only Manager and Sales Representative accounts and cannot create or promote another Administrator.

## Identity and access

Phase 1 provides session authentication for `admin`, `manager`, and `sales_rep` staff. Login accepts a normalized email address or phone number. Five consecutive failures temporarily lock an account; the duration and request throttles are configured with the `AUTH_*` environment values documented in `.env.example`.

Staff marked for first-login setup must replace their temporary password before accessing protected routes, then may set or skip an optional four-digit Quick PIN. Quick PINs are hashed and are not a password replacement. Password reset uses Laravel's expiring, throttled reset-token broker and requires a working production mail configuration. The safe local placeholder uses the non-logging `array` mailer so reset tokens are not written to application logs.

There is no public registration route. Later domain modules must use Laravel Policies in addition to the reusable `role` middleware.

Staff security events use `actor_id` for the initiating account and `subject_user_id` for the affected account. The original `user_id` column remains for historical compatibility only and must not be used as the target identity by new Staff Management code. Account activation, deactivation, manual locking, and temporary login lockouts are separate transitions; unlocking is permitted only for a manually locked account and clears its temporary failure state.

## Inventory foundation

Phase 1 inventory provides product categories, products, low-stock visibility and an append-only stock movement ledger. Product quantities use `DECIMAL(15,3)` and prices use `DECIMAL(15,2)`; supported units are piece, pair, set, pack, box and litre. Low stock is derived whenever `current_stock` is less than or equal to `reorder_level`. Database constraints also reject negative current stock and reorder levels.

`products.current_stock` is the current snapshot. Every product receives an opening movement, including a zero-value opening balance. Every later stock change is performed inside a transaction after locking and revalidating the active product row, and records a signed movement with its before and after balances. Initial stock, restocks, adjustments, damage, loss and corrections are supported. Inventory history must not be edited or deleted.

Administrators and Managers can maintain catalog data and adjust stock. Sales Representatives can browse active products but cannot see cost prices or movement history. Only Administrators can archive products. Product and category changes also write separate, allowlisted business audit records without credentials or other secrets.

Security events use the configured short operational retention window. Business `audit_logs` are long-lived accounting and operational history and are not automatically pruned. A reviewed archive/export strategy must exist before any future destructive retention process is introduced.

## Customer foundation

Customer phone numbers are stored as unique canonical Nigerian E.164 identifiers. Customer codes are generated transactionally from the persisted row ID and are never accepted from browser input or reused. Administrators and Managers can manage customer lifecycle and view customer audit history; Sales Representatives can maintain active contact profiles for future sale execution but cannot change status or view audit history.

WhatsApp consent is an explicit audited preference with server-generated opt-in and opt-out timestamps. A phone number never implies consent, and changing the canonical phone number invalidates and resets all current consent state. Receipt delivery rechecks `customer.is_active AND customer.whatsapp_opt_in AND customer.whatsapp_opt_out_at IS NULL` immediately before every attempt and records the destination number actually used.

Customers are deactivated rather than deleted so future sales retain stable references. A future Sale must store `customer_id` plus server-populated `customer_code_snapshot`, `customer_name_snapshot`, and `customer_phone_snapshot`. Customer history and navigation use the relational ID, while immutable receipts render these snapshots rather than later Customer edits.

Long-lived business audit logs deliberately retain selected customer name, phone, email, and city changes. Address and notes remain excluded. Customer PII in audit history must be included in the future archive, export, privacy, and retention strategy; these records must never be pruned using the 90-day security-event rule.

## Sales foundation

Completed sales are created in one transaction after locking the active Customer and every active, non-archived Product. Duplicate cart lines are aggregated and unique Product IDs are locked in ascending order. Prices, totals, payment state, Customer snapshots, Product snapshots, seller-name snapshot, inventory movements, stock balances, and audit data are calculated from authoritative server records. Sale money uses `DECIMAL(15,2)`, quantities use `DECIMAL(15,3)`, and Phase 1 payment methods are cash, transfer, and POS. Discounts and price overrides are not accepted in this phase.

Phase 1 Sales require a registered Customer. Do not create synthetic walk-in Customers or use fake or placeholder phone numbers to bypass Customer registration. Partial and unpaid Sales represent outstanding balances, but later settlement and payment history are deferred to a future audited payment-ledger module. For cash Sales, the current amount records only the amount applied to the Sale; tender and change tracking are deferred. Discounts remain unsupported.

Sales Representatives can create and view only their own sales. Administrators and Managers can view all sales; only Administrators may void. Voiding never deletes history: it locks the Sale and referenced Products, appends `sale_void` inventory movements, restores stock, records the void actor/reason, and writes mandatory business audit data atomically. Receipt identity comes exclusively from immutable Customer and Product snapshots. WhatsApp delivery, payment gateways, reporting, returns, and accounting exports remain deferred.

Manual WhatsApp receipt delivery uses immutable Sale and SaleItem snapshots for template content while resolving eligibility and destination from a locked live Customer. Immediately before every attempt it requires an active Customer with current explicit opt-in and no opt-out timestamp. Consent is authoritative at `consent_checked_at`: the Customer is locked and checked immediately before the delivery attempt is created. Each attempt is append-oriented and records its server request ID, destination, consent evidence, lifecycle state and provider identity. Signed Meta webhooks progress delivery state monotonically without retaining raw payloads.

Phase 1 sends synchronously with strict connection and overall timeouts because shared hosting cannot assume Supervisor or a persistent queue worker. The eligibility transaction intentionally commits before the provider call so no MySQL row lock is held across network I/O. A narrow race therefore exists in which consent can be withdrawn after the committed check but after an already-authorized provider request begins; an in-flight message may not be preventable in that window.

If Meta's acceptance outcome is unknown and no provider message ID was obtained, webhook reconciliation is impossible. The attempt remains protected from retry and requires an Administrator to record a manual operational resolution as terminal `unresolved`; this does not authorize resending. A future queued implementation may use the database queue with cron-driven workers while preserving these transaction and idempotency boundaries.

Meta activation requires all `WHATSAPP_*` settings documented in `.env.example`. Before production activation, confirm the currently supported, non-deprecated Graph API version in the Meta App Dashboard or official Meta documentation and set `WHATSAPP_GRAPH_VERSION` accordingly. The example default is not an assertion of current Meta support. Configure only the actual production reverse proxies in `TRUSTED_PROXIES`; arbitrary `X-Forwarded-For` values must never be trusted. Webhook requests are capped at 256 KB and limited to 600 requests per minute per trusted client IP. This IP-based limit is secondary abuse protection; raw-body HMAC verification is the authenticity control.

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
## Sale payments

Initial and later Sale payments are preserved in the immutable `sale_payments` ledger. Sale payment totals and status are derived transactionally under a row lock; overpayments are rejected and payment entries cannot be edited or deleted. Browser submissions use server-issued, session-bound, single-use confirmation tokens.

Refunds, payment reversals, customer credit, split tender, and overpayment/change handling are not implemented. A Sale with a later settlement payment cannot be voided until an audited reversal or refund workflow is available.

Expired unused settlement-request tokens may be pruned by a future reviewed maintenance process. Consumed tokens and any token linked to a payment must be retained because HTTP replay idempotency depends on them.

The Sale payment and WhatsApp forms currently both use the conventional field name `request_token`, but they post to separate endpoints and are validated against separate server-side token stores.
