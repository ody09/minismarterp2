# Phase 6: business dashboard, reports and workflow stabilization

Owner authorized the proposed phase: actual business dashboard; period/counterparty
sales and purchase reports; current stock alerts; full purchase-to-sale verification
and the deferred automated multi-line FEFO assertion. Existing Laravel/Blade/MySQL,
Arabic/English, no new dependencies, no AI or deferred stock adjustments.

Implementation defaults:
- Sales metrics include confirmed orders only, purchases received orders only.
  Period filters use actual confirmed_at/received_at UTC timestamps interpreted as
  inclusive business days in Asia/Aden. Commercial order dates remain visible.
  Default from first day of current business month through today. Customer/supplier
  filters apply to both totals and rows. No header-to-allocation joins that multiply
  money when FEFO splits an item. Quantities use saved quantity*conversion_factor.
- Exact totals may exceed per-order limits or 64-bit millionths. Stream matching
  headers/items with installed Brick Math (already used by Eloquent) and render fixed
  six-decimal money/integer piece strings. No float sums or extra package/extension.
- Wrap each report/dashboard read in a consistent transaction; never write stock,
  documents or fake sample data. Financial pages paginate documents and show
  total count, amount and pieces for all matching records, not only the current page.
- Current inventory report: main warehouse, physical/sellable/expired/near-expiry
  pieces by product; low-stock active products use sellable<=reorder_level (zero
  threshold/zero stock qualifies). Inactive products keep physical/expired balances
  but have zero sellable. Near expiry includes positive physical batches expiring
  today through today+expiry_days, inclusive; default30, range1..365. Expired is
  strictly before today. No-date batches are never expiry alerts.
- Inventory filters q/product/alert (all,low,near,expired). Display positive batch
  details with product, expiry/status and quantities; separate pagination for products
  and batches. Stock is current, not a reconstructed historical balance.
- Dashboard has permission-scoped period sales/purchases summaries, current stock
  alert counts and short actual lists, report links and existing account/access panel.
  No sales/purchase/stock data is fetched or displayed without its report permission.
  Account-only fallback remains available and exposes no business reports.
- New reports.sales, reports.purchases, reports.inventory permissions; administrator
  receives new grants only. Existing customized roles remain intact. Report-only
  users may view their report, while document/audit links require original permissions.
- No exports, profit/margin/valuation, taxes, payments, returns, partial fulfilment,
  accounting, AI, opening balances or stock count adjustments are added.

Acceptance:
- [ ] Exact filtered financial summaries, posting-day boundaries, terminal-state
  inclusion, pagination and correct piece snapshots despite FEFO splits.
- [ ] Current stock low/near/expired rules, disabled/zero/no-date and warehouse scope.
- [ ] Bilingual dashboard/report pages with isolated permissions and no data leakage.
- [ ] Automated real purchase->receipt->two-product sale->split FEFO issue with replay,
  failed oversell rollback, reconciliation and report totals; close phase5 test gap.
- [ ] Actual MySQL workflow and large exact report total beyond PHP integer millionths,
  existing concurrency suites, full tests, format/Blade checks and independent review.
- [ ] Browser verification and owned fixture cleanup; user data/credentials preserved.

Workspace ruling: phase5 is authorized, completed but uncommitted in the existing
checkout. Preserve it; phase6 extends the current feature branch. Pre-phase6 copies
of touched existing files are in the ignored phase6 ledger workspace for scoped review.
