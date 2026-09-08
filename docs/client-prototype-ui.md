# Client prototype UI completion

The starting commit was `9b717e4db29ee0259094e82fa8efe37c89496bf7`. The earlier runtime/security changes were already uncommitted and have been preserved. This pass does not approve production deployment or change the frozen business scope.

## Design and implementation

Direct access to the supplied Figma node was denied. The visual system therefore uses the repository's local Figma-exported logo/icons, Inter typography, Inventra blue and existing authentication presentation. Exact Figma parity is not claimed.

The shared `prototype.css` layer standardizes containers, cards, fields, buttons, table scrolling, focus states, status badges, filter reset links, empty states and responsive spacing. Blade page-header, empty-state and filter-reset components are reusable. Existing forms continue posting their original fields to their original routes.

The shell groups legitimate destinations into Workspace, Operations and Administration. The sidebar scrolls independently while identity/logout remain reachable. Returns/Refunds remain Admin/Manager-only; Staff/Audit/Settings remain Administrator-only. The nonfunctional global-search decoration was removed. At 1100px and below the same sidebar opens as an overlay with visible labels, backdrop/close/Escape dismissal, focus return, focus containment and an inert background. Current destinations remain marked and brought into view.

Dashboard cards use only the existing DashboardData values, with distinct selected-period and current-state sections. Existing operational alerts and recent activity remain unchanged in scope. Accounting-boundary copy remains present.

Sale entry has a three-step guide, numbered existing product rows, clear search/customer/payment sections and accessible quantity labels. There are still eight rows; no dynamic line-entry scope was introduced. Phase 1 search/state preservation and server-authoritative pricing/stock/totals remain unchanged.

All existing module pages inherit the shared treatment. Inventory/customer/sales/staff/supplier and reporting filters and empty states were made consistent. The ten report entry points share card treatment; no charts or exports were added. Sensitive and destructive actions retain their policy gates and visual distinction.

Sale/payment/return/refund documents use the receipt-aware shell. Purchase/expense documents retain standalone internal-document rendering with the same typography/spacing and a single bundled Livewire runtime. Existing Business Settings letterhead and customer-only footer semantics are preserved. Private transaction notes are not added to customer receipts.

## Verification

- Rendered 79 populated/empty page fixtures covering all requested modules, ten reports, forms/details, role dashboards, long names and pagination at 1440, 1280, 1024, 768 and 390px: 395 browser checks. No page-level horizontal overflow, broken image assets or uncaught JavaScript errors remained.
- Inspected screenshots of the dashboard, navigation, sale entry/receipt, inventory, reports, purchase entry and settings, including small-screen states. Wide tables intentionally scroll inside their containers.
- Mobile navigation labels, open/close, backdrop, Escape, logout position and focus return were checked at collapsed widths. Legacy CSS that hid the menu labels was removed. Staff table overflow caused by positioned accessibility content was contained in its scroll region.
- Chrome exercised the Phase 1 A/search-B workflow, network failure preservation, validation restoration, login reveal and confirmation cancellation/repeat protection with actual built JavaScript.
- All six receipt types were checked for usable print actions and PDF generation. Customer receipt markup excludes the private-note fixture sentinel. Purchase/expense double runtime loading was corrected with the existing Livewire script-config directive.
- Targeted UI/access/completion tests: 28 tests, 311 assertions passed. Broad eligible regression: 431 tests, 4,207 assertions passed on PHP 8.4.21/MySQL inventra_test.
- Transactional visual fixture preparation: 1 test, 84 assertions. Separate interaction fixtures: 1 test, 18 assertions. These are additional browser preparation checks, not included in the broad-suite total.
- Pint, strict Composer validation, Composer audit, npm audit, build and diff whitespace checks passed. Both audits reported no vulnerabilities. The build retains the existing auth-check.svg runtime-resolution warning; the asset exists and browser image checks passed.

The safe regression harness verifies local MySQL and SELECT DATABASE()=inventra_test before test traits, checks the existing migration list, blocks schema commands and retains test transactions. Random session garbage collection is disabled in that temporary harness only. No existing assertion was weakened.

Excluded unsafe suites: ExpenseConcurrencyTest, ExpenseMigrationTest, PurchaseConcurrencyTest, PurchaseMigrationTest, SupplierConcurrencyTest, ReturnRefundMigrationTest, SalePaymentMigrationTest, WhatsAppMigrationTest, and ReturnRefundFoundationTest::test_independent_processes_cannot_over_return_or_over_refund. No unrestricted full-suite claim is made.

Screenshots, PDFs and detailed check results are available in the local temporary directory `/private/tmp/inventra-ui-qa`. They use disposable test fixtures, not development records. Browser pages were served from captured Laravel responses; HTTP integration tests separately exercised writes under rollback. No requests were made to a deployed application.

## Files in this UI pass

- `docs/client-prototype-ui.md`
- `resources/css/app.css`
- `resources/css/prototype.css`
- `resources/js/app.js`
- `resources/views/audit/index.blade.php`
- `resources/views/components/app-layout.blade.php`
- `resources/views/components/empty-state.blade.php`
- `resources/views/components/filter-reset.blade.php`
- `resources/views/components/page-header.blade.php`
- `resources/views/components/receipt-footer.blade.php`
- `resources/views/components/status-badge.blade.php`
- `resources/views/customers/_form.blade.php`
- `resources/views/customers/index.blade.php`
- `resources/views/dashboard.blade.php`
- `resources/views/expense-categories/_form.blade.php`
- `resources/views/expense-categories/index.blade.php`
- `resources/views/expenses/create.blade.php`
- `resources/views/expenses/index.blade.php`
- `resources/views/expenses/receipt.blade.php`
- `resources/views/inventory/categories/index.blade.php`
- `resources/views/inventory/index.blade.php`
- `resources/views/inventory/products/_form.blade.php`
- `resources/views/inventory/products/show.blade.php`
- `resources/views/notifications/index.blade.php`
- `resources/views/purchases/create.blade.php`
- `resources/views/purchases/index.blade.php`
- `resources/views/purchases/receipt.blade.php`
- `resources/views/reports/index.blade.php`
- `resources/views/reports/show.blade.php`
- `resources/views/returns/create.blade.php`
- `resources/views/returns/index.blade.php`
- `resources/views/returns/receipt.blade.php`
- `resources/views/returns/refund-receipt.blade.php`
- `resources/views/returns/refund.blade.php`
- `resources/views/returns/refunds-index.blade.php`
- `resources/views/sale-payments/index.blade.php`
- `resources/views/sales/create.blade.php`
- `resources/views/sales/index.blade.php`
- `resources/views/sales/show.blade.php`
- `resources/views/settings/business.blade.php`
- `resources/views/staff/_form.blade.php`
- `resources/views/staff/index.blade.php`
- `resources/views/staff/show.blade.php`
- `resources/views/suppliers/_form.blade.php`
- `resources/views/suppliers/index.blade.php`
- `resources/views/whatsapp/deliveries/index.blade.php`
- `resources/views/whatsapp/deliveries/show.blade.php`
- `tests/Feature/Ui/PrototypeUiTest.php`

Some files overlap the pre-existing Phase 2 changes (notably app.js, receipt footer and Sales/WhatsApp detail views); those changes remain intact. Runtime middleware/configuration, Composer dependencies, migrations and domain actions were not modified by this UI pass.

## Proposed demonstration data — not created

For a separately authorized, isolated client-demo database, propose one account per role, two customers with explicitly approved valid contact details, six products across two categories (including low stock/inactive examples), two suppliers, two expense categories and a small set of paid/partial sales, receiving, expense and return/refund examples. Use the existing workflows so ledger, snapshots, consent and audit evidence stay consistent. Do not use invented placeholder phone numbers to bypass registration. No development/demo seeding was performed by this pass.

## Remaining presentation limits

The design is coherent with available local evidence rather than an exact Figma reproduction. Wide financial/history tables deliberately require horizontal scrolling on phones. Existing fixed sale/purchase rows produce longer forms on small screens. Print verification used Chrome/PDF rather than a physical printer. Some secondary detail pages rely on the shared styling rather than a bespoke layout.

Unavailable Figma, disabled Meta production configuration and the separate production CSP/host/backup checklist do not block evaluation of this client prototype. No new business module, calculation, authorization rule, integration, chart or export was added. No commit, deployment, development-data mutation or schema reset was performed.
