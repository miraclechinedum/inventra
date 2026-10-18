# Recorded follow-ups

Items found while auditing and stabilising the Sales, Discounts, Returns/Refunds and Dashboard modules (2026-10-17, on top of commit `abca853`). The open items below are not implemented. Each needs its own approved change. Completed items are listed at the end.

## Customer WhatsApp Automation history

The customer profile's "Communication history" panel reads only the legacy `whatsapp_deliveries` table (`CustomerController::show`, `$customer->whatsappDeliveries()`). Since `abca853`, messages are queued by the WhatsApp Automation module into `whatsapp_messages`. Those messages (welcome, post-purchase, pickup reminder) never appear on the profile, so a customer who has been messaged can look as if they have not.

Proposed end state: the panel lists the customer's `whatsapp_messages` (type, status, queued/sent time), with legacy deliveries kept as history. It stays read-only and scoped to the customer's Business.

## Immutable sale information in queued messages

`WhatsAppAutomationTriggers::postPurchase()` fills the template from the live Customer (`$customer->full_name`) and the Sale's current totals at queue time. `AGENTS.md` requires WhatsApp receipt content to come from immutable Sale and SaleItem snapshots, with only eligibility and destination taken from the live Customer.

Proposed end state: content values come from the Sale's snapshots (`customer_name_snapshot`, `sale_number`, `total_amount`, `balance_due`), and consent and destination are rechecked against the locked live Customer at dispatch. This needs a product decision on which name a message should carry after a customer is renamed.

## Legacy product activation and deactivation routes

`inventory.products.activate` and `inventory.products.deactivate` (`SetProductActiveState`) still exist with no screen. The product page uses archive and reactivate (`ArchiveProduct`). The two paths change the same `is_active` flag but record different audit actions (`product_activated` / `product_reactivated`), so the audit trail describes one lifecycle two ways.

Proposed end state: one lifecycle path. Retire the legacy routes once no client depends on them, and keep the audit actions already recorded.

## Outdated Record Sale documentation

`docs/client-prototype-ui.md` still says sale entry has "eight rows" with "no dynamic line-entry scope". Since `abca853` Record Sale is the `recordSale` cart: dynamic lines, walk-in or registered buyer, sale date, pre-sale discount approval, and no payment-method or notes fields (cash is the server default).

Proposed end state: the document describes the current cart and points to `RecordSaleFlowTest` as its executable specification.

## Smaller items

- **Rejected panel return loses list filters.** A Return rejected from the Sales-list side panel lands on `sales.index` without the operator's filters (minor UX).

## Deployment notes from Phase 2

- Four new migrations must run on deployment: `2026_10_17_010000_create_sale_requests_table`, `2026_10_17_020000_add_discount_request_lifecycle`, `2026_10_17_030000_add_discount_aware_return_values`, and the pinned status list in `2026_09_10_040000` (a no-op where it has already run). They have run only against `inventra_test`; the local development database still lists them as pending.
- After migrating production, run `php artisan inventra:check-return-valuations`. It reports, read-only, any return recorded on a discounted Sale before returns were valued net of the discount. The local development data has no discounted Sales, so there is nothing to report locally.
- The scheduler gains `inventra:expire-discount-requests` (hourly) and a `SaleRequest` prune (daily). Both are bookkeeping only; expiry and token validity are enforced at the moment of use.

## Open after Phase 2

- **Migration test on a copy of development data.** Not yet run. A separate `inventra_migration_verify` database is required, and the application's MySQL user has no privilege to create one; a database administrator must create it and grant `inventra_app` access first.

## Completed

- **Customer address and internal notes.** Administrators and Managers read them in an "Internal details" card on the profile and edit them in a management-only group of the shared customer form. Sales Representatives neither receive nor write them, and saving the fields they may change leaves them untouched. Neither field enters the audit trail. Covered by `CustomerInternalDetailsTest`.
- **WhatsApp message log S/N.** The log carries the standardized S/N column, counted across pages and restarted within each filtered result. Covered by `WhatsAppLogAccessTest` and `RecordTableCoverageTest`.
- **Record Sale idempotency.** Each Record Sale page carries a hashed, operator- and session-bound, 12-hour, single-use token; a repeated or concurrent submission resolves to the Sale it already recorded, with stock moved once. Covered by `SaleSubmissionIdempotencyTest`.
- **Discount cancellation, expiry and the sole-Administrator exception.** Covered by `DiscountRequestLifecycleTest`.
- **Unit-aware quantities** in sales, returns, corrections, purchases, stock adjustments and opening stock. Covered by `UnitQuantityRulesTest`.
- **Discount-aware returns and refunds**, replacing the temporary discounted-sale return safeguard. Covered by `DiscountAwareReturnTest` and `ReturnPanelTest`.
- **Unit-aware quantity inputs on purchase and stock-adjustment forms**, matching the server rule, including a fractional step for a counted product still holding a legacy fraction.
- **Return receipt** shows the returned value, the amount that reduced the balance owed and the refundable credit separately, and says when fully discounted goods came back with no money changing hands.
- **Product reporting.** Top Products ranks on net product revenue and Product Performance shows gross, allocated discount and net (`docs/report-definitions-proposal.md`). Both now read current lines only, so a corrected Sale is no longer counted twice.
- **Test ordering.** A `DatabaseTransactions` test now migrates the schema itself when Laravel's migration state says it is not current, so any class runs alone or in any order.
- **Customer receivables and payment history.** Restored on the profile as "Balances & payments", narrowed from the Receivables report's own scope. Covered by `CustomerBalancesPanelTest`.
