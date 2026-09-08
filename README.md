# Inventra Smart Trade

Inventra Smart Trade is an internal business application built as a Laravel monolith. This repository currently contains the production-oriented application foundation; business modules are intentionally out of scope.

## Stack

- PHP 8.4.1+ (current lock) and Laravel 13
- Blade, Livewire 4 and Alpine.js
- Tailwind CSS 4 and Vite
- MySQL
- Database-backed sessions and queues
- Laravel Scheduler

## Local setup

Prerequisites are PHP 8.4.1 or newer with BCMath, mbstring and PDO MySQL, Composer 2, Node.js 22.13 or newer, npm, and MySQL. Local development currently uses MySQL 9.6. Verify that the production host provides these PHP extensions before deployment.

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

The frontend uses Livewire's CSP-compatible Alpine runtime. CSP nonce support and report-only rollout configuration are implemented; enforcement awaits production-like browser and host verification. The production rollout must build a restrictive policy containing `frame-ancestors 'none'`, apply a per-request nonce where required, deploy it in report-only mode, exercise all application functionality, understand and resolve violations, and only then enforce it. Broad wildcards, `unsafe-inline`, and `unsafe-eval` are not acceptable shortcuts.

`TRUSTED_PROXIES` is intentionally empty by default. If production terminates HTTPS at a reverse proxy, set it to the proxy's verified IP address or CIDR ranges as a comma-separated list. Never use a trust-all value. Confirm secure-cookie behaviour, generated HTTPS URLs, and HSTS after deployment; leave it empty when Namecheap passes requests directly to the application.

For Namecheap shared hosting, point the web root at `public/`, keep `.env` and writable storage outside publicly executable paths, disable directory listing in the hosting configuration, and cache production configuration only after all environment values are set.
## Sale payments

Initial and later Sale payments are preserved in the immutable `sale_payments` ledger. Sale payment totals and status are derived transactionally under a row lock; overpayments are rejected and payment entries cannot be edited or deleted. Browser submissions use server-issued, session-bound, single-use confirmation tokens.

Refunds, payment reversals, customer credit, split tender, and overpayment/change handling are not implemented. A Sale with a later settlement payment cannot be voided until an audited reversal or refund workflow is available.

Expired unused settlement-request tokens may be pruned by a future reviewed maintenance process. Consumed tokens and any token linked to a payment must be retained because HTTP replay idempotency depends on them.

The Sale payment and WhatsApp forms currently both use the conventional field name `request_token`, but they post to separate endpoints and are validated against separate server-side token stores.

## Suppliers and received purchases

Suppliers use immutable, persisted-ID-based `SUP-` codes and an active/inactive lifecycle; they are never hard-deleted. A Purchase is a completed stock-receiving transaction, not a purchase order or payable. Receiving is restricted to Administrators and Managers and atomically locks the active Supplier and eligible Products, creates immutable Purchase and PurchaseItem snapshots, increases `products.current_stock`, adds positive `purchase` inventory movements, records business audit history, and consumes a hashed actor/session-bound expiring request token.

Purchase history has no edit/delete/reversal path in Phase 1. `PurchaseItem.unit_cost` is the authoritative acquisition-cost snapshot. Receiving does not update `Product.cost_price` because no approved latest-cost or inventory-valuation policy exists; weighted average, FIFO/LIFO, COGS, returns, supplier payments, and Accounts Payable remain deferred. Sales Representatives cannot access Supplier or Purchase surfaces or purchase-cost data.

Expired Purchase request tokens are pruned only while unused and not linked to a Purchase. Consumed or result-linked request records are retained because replay idempotency depends on them.

## Business expenses

Expenses record completed, paid non-inventory operating costs. A Purchase receives resale stock and is never automatically converted to an Expense; Expenses never affect Products, inventory movements, Sales, Sale Payments, Customers, or WhatsApp delivery.

Expense Categories are editable and deactivatable but cannot be deleted. Expenses are immutable and retain category and recorder snapshots. `EXPCAT-xxxxxx` and `EXP-xxxxxx` identifiers are generated from persisted IDs. `incurred_at` is a required date, may be historically backdated, and cannot be future-dated. Supported payment methods are cash, transfer, and POS.

Business-date validation and Expense form dates use `BUSINESS_TIMEZONE` (`Africa/Lagos` by default), while technical timestamps remain UTC. Every committed Expense and its retained request row reference each other through restrictive foreign keys, preventing direct deletion of either half of the idempotency evidence.

Only Administrators and Managers can access Expense records, summaries, vouchers, and categories. Creation uses hash-only, actor/session-bound expiring request tokens. Only expired, unused, unlinked request rows are pruned; consumed and Expense-linked rows remain for replay idempotency. Rollback refuses to destroy Category or Expense history. Corrections/reversals, approvals, attachments, accounting ledgers, reimbursements, recurring Expenses, and external integrations are deferred.

## Operational reporting

Reports are read-only and restricted to Administrators and Managers. Financial-flow reports default to the current month in `BUSINESS_TIMEZONE`; ranges are inclusive and timestamps are converted to UTC query boundaries. Sales report completed transaction value, Collections report every immutable Sale Payment ledger receipt in the period (including receipts for Sales later voided), Expenses report non-inventory operating costs, and Purchases report received inventory. Refunds are not modelled. Customer and Staff performance collections are limited to completed Sales so those metrics describe the same active-sale population.

The Sales report's `Voided in Period` metric uses the authoritative `voided_at` event timestamp and the selected Lagos business-date boundaries, rather than the Sale creation date.

Current Outstanding Receivables includes every currently outstanding completed Sale, regardless of its creation date, and is explicitly not a historical as-of reconstruction. The Receivables report therefore has no date range. Product Performance preserves historical SKU/name snapshot variants after a Product rename; repeated current stock values belong to the same live Product and are not additive.

Historical rows render transaction snapshots rather than renamed live entities. Independent aggregates prevent Sale, SaleItem, and SalePayment joins from multiplying values. Reports do not calculate profit, COGS, inventory valuation, tax, cash flow, or formal accounting statements. Exports, charts, predictive analytics, scheduled delivery, and report caching remain deferred.

## Sales Returns and customer refunds

A Sale Void cancels an eligible whole Sale; a Return records customer merchandise received back; a Refund records money paid back. Returns and Refunds are additive immutable evidence and never rewrite original SaleItems, Sale totals, Customer/Product/seller snapshots, or the immutable Sale Payment receipt ledger. A Sale with Return or Refund history cannot subsequently use whole-Sale void.

Return quantity is exact `DECIMAL(15,3)`, cannot cumulatively exceed its original SaleItem quantity, and is valued from the immutable original unit price. Only an explicit `restock` disposition restores Product stock through a positive `sale_return` movement; `non_restock` leaves sellable stock unchanged. Archived or inactive Products may be restocked because the historical Product identity remains authoritative, but their lifecycle state remains unchanged.

The adjusted obligation is original Sale total less accepted merchandise returns. Net cash retained is received payments less refunds. Current receivable is the positive difference between adjusted obligation and net cash retained; refundable credit is the positive inverse difference. `sales.balance_due`, payment status, returned amount, refunded amount, and refundable credit are synchronized under the locked Sale while original `total_amount` and actual `amount_paid` remain historical. Refunds use a separate immutable cash-out ledger and Phase 1 supports Cash and Bank transfer only.

Administrators and Managers may record and view Returns and Refunds; Sales Representatives are denied. Both workflows use hash-only, actor/session/Sale-bound, expiring, retained request tokens and serialize through the Sale lock. Refunds are not Expenses, Returns are not Supplier returns, and neither creates Purchase, WhatsApp, COGS, profit, tax, exchange, store-credit, gateway, or accounting-journal activity.

Linked request and result rows reference each other through restrictive foreign keys so committed business evidence and replay identity cannot be independently deleted. Return and Refund lists are paginated and use scalar-safe literal search/date filters. Rejected Return and Refund submissions redirect back and render the standard Laravel validation messages through the shared `<x-validation-errors />` component, which never exposes request tokens, token hashes, session identifiers, private notes, or database errors. Historical receipts omit private notes and use the delegated print mechanism. Expired unused Return and Refund requests are pruned at 02:45 and 02:50 respectively; consumed or linked requests are retained.

`sale_returns`, `sale_refunds`, `sale_return_requests` and `sale_refund_requests` are mutually protected by those reciprocal foreign keys. `sale_return_items` is the one committed Return table that nothing references, so a direct database session can still delete an individual return line; this is an accepted Low risk at the database-credential trust boundary rather than an application path, because no route, action, or model permits it, and the `sales_return_financials_reconcile` CHECK independently keeps `returned_amount` within `total_amount` even if line detail were removed. Protecting it relationally would require a guard table written by the Return action purely to block deletes, which was judged disproportionate. `sale_refunds.sale_return_id` is reserved for a future per-Return refund attribution workflow: the current workflow refunds against the Sale's aggregate refundable credit and has no authoritative single-Return attribution, so the column is validated to belong to the Sale when supplied and otherwise left null rather than populated artificially.

## Operational dashboard

`GET /dashboard` is the single canonical dashboard. It is read-only: loading it, filtering it, or reloading it never writes business data and never emits an audit event. All queries live in `App\Dashboard\DashboardData`; the controller only resolves filters and renders, and Blade performs formatting only.

Metrics are split into two clearly labelled groups. **Period metrics** honour the selected range, default to the current calendar month in `BUSINESS_TIMEZONE`, and convert Lagos day boundaries to UTC exactly as Reporting does: Gross Sales (completed, non-voided Sale totals transacted in the period — Returns never reduce it), Customer Collections (Sale Payment receipts in the period — Refunds never reduce it), Returns Value, Refunds Paid, Operating Expenses (by `incurred_at`), Inventory Purchases (by `received_at`), and Sales Recorded. **Current-state metrics** deliberately ignore the range because they describe the business today: Current Outstanding Receivables (return-adjusted `balance_due`), Refundable Customer Credit, Low-stock Products, Active Customers, and Active Staff. Refunds are shown as their own figure and are never netted off Collections, and no profit, net income, COGS, margin, inventory valuation, tax, or forecast is calculated anywhere on the page.

Administrators and Managers see the full operational picture. Sales Representatives get a server-side restricted dashboard scoped to `sold_by = their own id`: their Gross Sales, their seller-attributed Collections (receipts against Sales they sold, matching the Staff Performance report), their outstanding receivables, their recent Sales, plus shared low-stock and active-customer counts. Expenses, Purchases, Suppliers, Returns, Refunds, refundable credit, staff counts, integrity internals, and cost prices are omitted from the query layer itself, not merely hidden in Blade.

Operational alerts are read-only observations with three visual severities and no persistence, acknowledgement, or delivery: ledger-integrity mismatches, zero-stock and low-stock active Products, Sales with outstanding balances, Sales holding refundable credit, and locked or inactive staff accounts. The integrity alert reuses the same read-only detector as Reporting, comparing `sales.amount_paid`, `returned_amount`, and `refunded_amount` against the payment, return, and refund ledgers; it states plainly that no records were changed and never reconciles. Alert lists and recent-activity lists are bounded at five rows and link to the authoritative detail page. Outstanding Sales are ordered oldest first; refundable-credit Sales are ordered largest credit first. Low stock reuses the single shared definition, `active` Products at or below `reorder_level`.

The previous dashboard's "Total Inventory Value" card (`SUM(current_stock * cost_price)`) and its hardcoded placeholder chart were removed: the first is inventory valuation, which is explicitly outside this module's non-accounting scope, and the second displayed invented figures that were never derived from data.

## Audit Trail & Activity History

`audit_logs` is the single append-only business audit system. Every audited business mutation is
written by `App\Services\AuditLogger` inside the same database transaction as the mutation itself, so
the change and its evidence commit or roll back together.

- **The one exception** is the pair of WhatsApp provider outcomes, `whatsapp_receipt_accepted` and
  `whatsapp_receipt_failed`. A database transaction must never be held open across the provider
  network call, so `TransitionWhatsAppDelivery` commits the status change and the audit row is written
  immediately afterwards, outside it. A delivery outcome can therefore in principle commit without its
  audit row. This is accepted: the `whatsapp_deliveries` record is itself the authoritative lifecycle
  evidence, and the request-side event (`whatsapp_receipt_requested` / `_retried`) is transactional.

- **Boundary.** `security_events` covers authentication and account-security incidents and is pruned
  after `SECURITY_EVENT_RETENTION_DAYS` (90 by default). `audit_logs` covers business mutations and is
  never pruned. Staff lifecycle changes are recorded in both: the security event is the incident, the
  audit row is the permanent business record that outlives the retention window.
- **Snapshots.** Each row stores `actor_name_snapshot`, `actor_role_snapshot` and
  `subject_label_snapshot`, so history stays readable after a User is renamed or deleted and after a
  subject record changes. Rows written before this was introduced keep NULL snapshots and fall back to
  the live actor relation, which the index eager-loads so the fallback stays a constant cost rather
  than one query per legacy row. They are not backfilled, because a User's current name is not evidence
  of the name they had at the time. The subject fallback needs no relation: it reads
  `auditable_type`/`auditable_id` as columns and renders a dash when the label is absent.
- **Immutability.** The model rejects updates and deletes, is fully guarded against mass assignment,
  and only two read-only routes exist (`GET /audit`, `GET /audit/{audit}`). There is no edit, delete or
  acknowledgement path. Protection is application-layer by deliberate decision — there are no database
  triggers, because the shared-hosting target cannot be relied on to grant the `TRIGGER` privilege.
  Model events do not fire for query-builder mass writes, so `AuditLog::query()->update()/delete()`,
  `DB::table('audit_logs')` and raw SQL would bypass the guard. No production code uses them, and none
  may be added.
- **Access.** Administrator only. Managers and Sales Representatives keep their existing per-entity
  activity views and receive 403 on the cross-domain trail.
- **Growth.** The table grows with business volume and is never trimmed. At roughly one row per audited
  mutation, a shop recording 200 mutations a day accumulates about 73,000 rows a year; listings are
  paginated at 25 and every filter path is index-backed.

## Business Settings & Configuration

`business_settings` holds the identity of the single Inventra installation. It is one typed row, not
a key/value store: `singleton_key` is UNIQUE and a CHECK constraint pins it to one literal, so a
second row cannot be created by any code path, race or console command. The row is bootstrapped by
its migration, so a fresh install and an existing upgrade both arrive at exactly one record.

- **Editable.** Business name (required), registered legal name, phone, email, address, city, state
  and a plain-text receipt footer. Nothing else. Writes go through `UpdateBusinessSettings` inside a
  transaction that locks the row, applies only fields that actually differ, and records a
  `business_settings_updated` audit event with just those fields. Submitting unchanged values writes
  nothing and audits nothing.
- **Fixed by design.** Currency (NGN, ₦) and business timezone (`config('business.timezone')`) are
  displayed read-only. No table snapshots a currency, and historical Expense business dates were
  evaluated in the configured timezone, so making either editable would silently reinterpret existing
  records. Changing them is a deployment decision, not an operator setting.
- **Reading.** `App\Settings\BusinessSettings` is the only read path. It is a container singleton, so
  a page that renders business identity several times costs one query. It never creates the row: a
  missing record raises a clear "not installed" error rather than being repaired by a GET.
  `UpdateBusinessSettings` drops the memo itself once its transaction commits, so a write is visible
  to every later read without the caller having to remember.
- **Worker lifetime.** That memo is scoped to one container, which on the deployment target —
  PHP-FPM on shared hosting — means one request, so it is safe as written. A long-lived process
  (Octane, RoadRunner, a queue worker rendering business identity) keeps one container across many
  requests, and a worker that did not handle the write would keep serving the pre-write value.
  Running Inventra that way would require explicit forget/refresh semantics at the request boundary.
  Octane is not supported and none of it is wired up today.
- **Receipts.** Sale, Payment, Return and Refund receipts carry the business letterhead and the
  receipt footer; the internal Stock Receiving Record and Expense Voucher carry the letterhead only.
  Receipts render **live** business identity — renaming the business changes what historical receipts
  display. Identifiers, amounts and customer/product snapshots are untouched. Snapshotting business
  identity per transaction is deliberately deferred; it would mean new columns on Sale, Return, Refund
  and Purchase and should be a reviewed decision, not a side effect of this module.
- **WhatsApp.** Message bodies come from a provider-approved template filled with immutable Sale
  snapshots and carry no business identity, so settings changes cannot alter historical or re-rendered
  WhatsApp content.
- **Access.** Administrator only, enforced by policy and route middleware. Deferred: logo upload
  (the application has no file-storage architecture yet), and registration/tax identifiers (no current
  receipt or business requirement).

## Notifications & Operational Alerts

`operational_alerts` turns the conditions the Dashboard already detects into persistent, role-aware,
in-app alerts with a lifecycle. Delivery is **in-app only** — no email, SMS, WhatsApp or push — and
every alert is system-generated: there is no create route, no delete route and no message template.

- **Derived state.** Alerts are a projection of `products` and `sales`, never a source of truth. The
  evaluator reuses the Dashboard's own predicates, so a persistent alert and the Dashboard counter
  can never disagree: active non-archived Product at or below `reorder_level`; completed Sale with
  `balance_due > 0`; completed Sale with `refundable_credit > 0`; completed Sale whose aggregates
  disagree with its ledger. Comparisons use `bccomp` or SQL — no decimal is cast to float.
- **One active alert per condition.** `active_key` holds `type:subject_type:subject_id` while active
  and is UNIQUE; resolving sets it to NULL, and MySQL allows unlimited NULLs in a UNIQUE index. So a
  duplicate active alert is impossible at the database level rather than by check-then-insert, while
  history accumulates freely. A recurrence opens a new row with the next `occurrence`, so the trail
  answers when an issue first appeared, when it cleared, and whether it came back.
- **Three independent states.** `read` is what one operator saw; `acknowledged` is that operator
  saying "I am aware"; `resolved` is the business condition itself clearing. Only the system resolves
  an alert — acknowledging a low-stock alert never closes it while stock is still low.
- **Generation.** Product and Sale observers project after the business transaction commits, via
  `DB::afterCommit`, so a failing alert write can never fail or roll back a stock movement or a
  payment, and a rolled-back sale projects nothing. `inventra:reconcile-operational-alerts` re-derives
  everything from source truth and repairs whatever a projection missed. It is idempotent, reads
  business records and writes only alert tables. Scheduled hourly: ledger integrity is the one
  condition with no mutation to observe, so an hour bounds detection latency while staying light on
  shared hosting. No queue worker, Redis, Horizon or Supervisor is involved.
- **Recipients.** Inventory, receivable and refundable-credit alerts go to Administrators and
  Managers; integrity warnings to Administrators only. Sales Representatives receive nothing in this
  foundation — receivables, credit and integrity are business-wide financial facts, and a
  notification list is the wrong place to widen what a representative can see. Inactive and locked
  accounts get no new deliveries and keep every historical one.
- **Ownership and current entitlement.** Two conditions gate every notification, and both must
  hold. A notification belongs to the person it was delivered to — being an Administrator confers no
  authority over another operator's read or acknowledgement state, because that state is a record of
  what *they* saw. Separately, the holder's **current** role must still be one the alert type is
  meant for: `OperationalAlertType::allowsRole()` is the single source of truth, consumed by the
  policy, the inbox query, the unread badge and mark-all-as-read. Demoting an Administrator to
  Manager immediately hides the integrity warnings they were sent; demoting a Manager to Sales
  Representative empties their inbox and badge. Filtering happens in SQL, so a demoted operator
  cannot see a title, a message, a severity, or infer a count from pagination totals.
- **History is never rewritten.** Losing access changes visibility only. Recipient rows, `read_at`
  and `acknowledged_at` all survive a demotion untouched, and restoring the role restores visibility
  of the same rows without creating a second delivery. Acknowledgement is audited
  (`operational_alert_acknowledged`); read state deliberately is not, so the Audit Trail is not
  buried under glances.
- **Retention.** Nothing is pruned. Alert history is operational evidence; a retention policy would
  be its own reviewed decision.
- **Concurrency.** Two evaluators racing to open the same alert contend on the UNIQUE `active_key`,
  and MySQL resolves that either as a duplicate-key error or as a deadlock. The projector adopts the
  winner in the first case and retries the transaction in the second. Retries require the projector
  to be the outermost transaction, which both callers satisfy — observers run after commit through
  `DB::afterCommit`, and reconciliation opens no transaction of its own. Wrapping either in an outer
  transaction would silently disable the retries, because Laravel will not retry a nested one.
- **Cost.** Recipients depend only on the alert type's role set, so a sweep resolves them once per
  role set rather than once per subject: reconciling 1,000 low-stock Products issues one `users`
  query. Reconciliation is otherwise linear — roughly 4.7 queries and 1.4s per 1,000 subjects. The
  unread badge reads one operator's rows through `(user_id, read_at)` and then checks each row's
  alert type by primary key; that is sub-millisecond at ordinary volumes and about 13ms for an
  operator sitting on 5,000 unread alerts.
- **Requires MySQL 8.0.16 or newer.** Six CHECK constraints carry this module's state coherence —
  valid type, severity and status, the active/resolved lifecycle pairing, a positive occurrence, and
  acknowledgement implying read. MySQL parses but silently ignores CHECK constraints before 8.0.16,
  which would downgrade all six to application-enforced only. The UNIQUE `active_key` remains the
  primary protection against duplicate active alerts and works on any supported version. MariaDB
  parity has not been verified. Confirm the production MySQL version before deploying.
- **Indexes.** `operational_alerts_created_at_id_index` is currently unused: the inbox orders on the
  alert id, which is both a total order and index-backed. It is retained deliberately rather than
  dropped, because removing it would mean an extra migration against a table that does not exist in
  production yet, for no measurable gain, and it will serve any future date-ordered alert view.
- **Deferred.** One Sale write projects its two alert types more than once, because `CreateSale`
  saves the Sale several times in a single transaction and each save queues a post-commit
  projection. The work is idempotent and guarded by the dedupe invariant, so this is redundant cost
  rather than a correctness problem. De-duplicating it would mean request-scoped state around the
  financial write path, which is not worth the risk for the saving.

## Product completion boundaries

Sale product search updates the existing eight line selectors in place. Draft customer, quantities,
payment and notes stay in the page; search requests carry only the search phrase. Selected Products
are retained across searches, and validation redisplays selected active Products even outside the
first search page. Prices, eligibility and stock are revalidated by the Sale action at submission.

Customer and Staff Performance include entities with completed Sales created in the selected period
or payments received in that period against completed Sales, including older Sales. Global Collections
continues to include receipts on later-voided Sales; performance reports intentionally exclude those
Sales. Sales value/count and outstanding balances refer to Sales created in the selected period;
collection-only rows have zero period Sales and no latest period Sale. Names are current profile
names grouped by permanent IDs, while transaction receipts retain their historical snapshots.
The Sales report's void count applies staff/customer filters to the void-event date range.

Customer-facing Sale and Payment receipts provide browser printing and exclude private transaction
notes from their HTML. Payment notes remain on the authorized internal payment detail page.

## Production runtime readiness

See [the production runtime contract](docs/production-runtime.md) for the locked PHP minimum, extension/MySQL requirements, CSP rollout, host evidence gates, scheduler, backups and future deployment sequence. PHP 8.3 is not a supported deployment target for the current lock.
