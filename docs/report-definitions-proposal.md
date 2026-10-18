# Product reporting: gross and net-of-discount definitions (proposal)

Status: **approved (Option A) and implemented** in `App\Reports\ProductSales`, used by Top Products and Product Performance. Covered by `ProductNetRevenueTest`.
Prepared 2026-10-18 as part of the Phase 2 hardening review.

## Why this is needed

A Sale's discount is held on the Sale (`sales.discount_amount`), not on its lines. `sale_items.line_total` is always the price as sold times the quantity. Since Phase 2, Return values are net of the discount: `sale_return_items.return_line_value` is what the goods were worth to the customer, and `gross_line_value` / `discount_share` explain it.

Two product-level figures read `line_total` directly:

| Report | Current calculation | Label | Accurate? |
|---|---|---|---|
| Reports dashboard › Top Products (`ReportsDashboard::topProducts`) | Σ `line_total` − Σ `return_line_value`, by Sale trading day | "earned" / ranking | **No.** It subtracts net returns from gross sales. A discounted Sale returned in full leaves its product a positive residue equal to the discount share. Undiscounted Sales are unaffected. |
| Product Performance (`BusinessReports::products`) | Σ `line_total`, Σ quantity | "Gross Product Sales" | Accurate *as gross*. It does not show discounts or returns, and the label says so. |
| Revenue headline (`ReportsDashboard::revenueBetween`) | Σ `total_amount` − Σ `merchandise_value` | "Revenue" | Accurate: both sides are net of discount. |

Before Phase 2, discounted returns were valued gross, so Top Products was consistent with itself but Revenue over-subtracted. Phase 2 corrected Revenue and exposed the mismatch in Top Products.

## Proposed definitions

All by the Sale's trading day (`sale_date`), completed Sales only, in the business timezone, in fixed-precision decimal arithmetic, as every other sales figure.

1. **Gross product sales**: Σ `sale_items.line_total` for the product. The price as sold, before any Sale-level discount.
2. **Allocated discount**: the product's lines' shares of their Sales' discounts, computed by the same allocator returns use (`ReturnValuation::shares`): proportional to line value, floored to the kobo, leftover kobo to the largest remainders, ties to the lowest line id.
3. **Net product sales** = gross product sales − allocated discount. Across all products this sums exactly to Σ `sales.total_amount`.
4. **Product returns (net)**: Σ `sale_return_items.return_line_value` for the product, attributed to the Sale's trading day.
5. **Net product revenue** = net product sales − product returns (net). Across all products this sums exactly to the Revenue headline.

## Expected report behaviour

- **Top Products** ranks on *net product revenue* (definition 5), so the ranked amounts reconcile to the Revenue headline and a fully returned discounted Sale contributes zero.
- **Product Performance** keeps "Gross Product Sales" and adds two columns, "Allocated discount" and "Net product sales", with matching summary cards. Quantities are unchanged. Returns stay on the Returns report.
- No other report changes. Customer, Staff and Collections reports already read Sale totals or payments, not lines.

## Implementation options

| Option | How | Exactness | Cost |
|---|---|---|---|
| **A (recommended)** | Compute allocations in PHP with `ReturnValuation::shares` for the Sales in the period, then aggregate per product | Exact; reconciles to the kobo | One pass over the period's discounted Sales and their lines. Undiscounted Sales need no allocation. Fine at current scale. |
| B | Approximate in SQL as `discount_amount × line_total / subtotal`, rounded per line | Can drift by a kobo per line; totals may not reconcile exactly | Cheapest |
| C | Persist each line's allocated discount at sale time | Exact and fast | Needs a new column and a reviewed decision on deriving values for historical lines, which are immutable snapshots |

Legacy rows: returns recorded before Phase 2 have null `gross_line_value`; their `return_line_value` is used as recorded. `inventra:check-return-valuations` reports any discounted Sale where that matters.

## Found during implementation

Both product reports summed every `sale_items` row, including lines a correction superseded, so a corrected Sale counted twice: its retired lines and its corrected ones. They now read current lines only, which is what a Sale's own `items()` relation and every other Sale figure already use.
