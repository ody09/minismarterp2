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
- [x] Exact filtered financial summaries, posting-day boundaries, terminal-state
  inclusion, pagination and correct piece snapshots despite FEFO splits.
- [x] Current stock low/near/expired rules, disabled/zero/no-date and warehouse scope.
- [x] Bilingual dashboard/report pages with isolated permissions and no data leakage.
- [x] Automated real purchase->receipt->two-product sale->split FEFO issue with replay,
  failed oversell rollback, reconciliation and report totals; close phase5 test gap.
- [x] Actual MySQL workflow and large exact report total beyond PHP integer millionths,
  existing concurrency suites, full tests, format/Blade checks and independent review.
- [x] Browser verification and owned fixture cleanup; user data/credentials preserved.

Workspace ruling: phase5 is authorized, completed but uncommitted in the existing
checkout. Preserve it; phase6 extends the current feature branch. Pre-phase6 copies
of touched existing files are in the ignored phase6 ledger workspace for scoped review.

Verification on final tree: 140 tests / 893 assertions pass; all five standalone
MySQL checks (stock concurrency/snapshot, purchases, sales, workflow/reports) pass,
including18 workflow/report checks. Exact sum10000000000067.490036 was verified
from actual posted documents. Reconciliation reports no mismatch. Pint, Blade
compilation and whitespace checks pass. Phase5's multi-line allocation test gap is
closed in both the automated feature suite and MySQL workflow harness.

Independent read-only review: no material findings; reviewer independently ran
12 tests / 108 assertions. Minor optional optimization deferred: financial rows
eager-load a user relation unused by the report views, adding one read query per
financial section. No production-load certification is claimed.

Browser: Arabic/English dashboard, customer/day sales filters, supplier purchase
report and stock expiry threshold were verified on owned temporary documents.
UI sales82.500036/pieces36 and purchases9.469136/pieces60 matched their snapshots;
prior-day filtering produced zero. Reducing near expiry from30 to7 reduced eligible
positive batches from2 to1. Console had no errors. Test-labelled screenshots contain
now-removed disposable records; final dashboard/report-index images show actual
post-cleanup application data. No real user records or credentials were changed.
