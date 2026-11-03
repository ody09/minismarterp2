# Phase 5: sales and customer product prices

Owner authorized implementation after the described sales/pricing phase. Keep the
existing Laravel/Blade, Arabic/English and MySQL app. No AI, accounting, payments,
taxes, stock counts/opening adjustments, returns, partial fulfilment or posted-order
reversal UI. Current branch base d9385d8, existing checkout reused.

Implementation decisions:
- Customer prices: customer/product, piece/carton unit, minimum whole quantity in
  that unit, six-decimal unit price, optional inclusive valid-from/to, active flag,
  optimistic version. No deletion route; deactivate to preserve references.
- Reject overlapping active periods for the same customer/product/unit/minimum
  quantity; different quantity tiers may overlap. Highest eligible minimum wins.
  Resolve per item, using the sale's order date; duplicate product/unit pairs must
  be consolidated. Dates may be open-ended. Zero prices are valid.
- Customer price matches its exact unit only. Fallback catalog selling_price belongs
  to selling_unit: multiply piece->carton, divide carton->piece and round half up to
  six decimals with checked integers. Price max 999999999.999999; line/order total
  max 999999999999.999999. Never calculate money with floats or client totals.
- Draft saves derive prices and snapshot source/rule ID+version, product name,
  conversion, quantity, unit, price/subtotal and customer name. Sales date cannot
  be in the future (Asia/Aden business date). Editing a draft explicitly re-prices it.
- Draft -> confirmed (full issue) or draft -> cancelled. Require current version on
  edits/transitions. Confirm checks current active customer/products, conversion and
  re-resolved commercial pricing; stale price/source/version requires draft review.
  Catalog edits do not silently modify saved totals. Date-based pricing uses order
  date; stock expiry always uses actual business posting day.
- Confirmation UUID/hash and create UUID/hash provide idempotency. In one DB
  transaction lock shared admin-role/warehouse/current actor anchor, order, customer,
  ordered products, prices, then call StockLedger::issue. Link a unique typed sales
  document FK to its stock operation. FEFO allocations may split an item over batches;
  keep these in inventory_transactions, not a fabricated single sales-item batch.
- Any failure rolls back header/items/movements/balances. Posted/cancelled documents
  cannot be edited/deleted. Generic ledger reversal refuses sales-linked issues.
- Permissions: prices.view/manage, sales.view/manage/post; confirm additionally
  requires inventory.post. Seed new grants only to protected administrator.
- Screens: price list/create/edit with customer/product filters and version guard;
  sales list/create/edit/detail and explicit confirmation review page (regular form,
  no JavaScript confirm dependency), customer sales history and stock audit links.
- Explain pricing/date/unit and full issue behavior in both locales; scalar-safe old
  input redisplay, escaped fields, accessible dynamic item rows. No manual sale price
  override; server applies the defined price source.

Acceptance:
- [x] Customer tiers/date endpoints/units, overlap and stale-edit protections.
- [x] Exact automatic prices, unit conversion/rounding, checked totals and snapshots.
- [x] Versioned draft/edit/cancel and idempotent atomic full confirmation with FEFO.
- [x] Insufficient/expired/inactive goods and stale price/conversion reject atomically.
- [x] Permission checks internally/HTTP, immutable history and typed audit links.
- [x] Arabic/English UI, malformed input and filters, safe MySQL migration/seed.
- [x] Full suite, real MySQL races/snapshots/rollback, reconciliation, fresh review.

Verified on final tree: 128 tests / 785 assertions pass; Pint and Blade compilation
pass. All four standalone MySQL harnesses (stock concurrency, stock snapshot,
purchasing and sales) pass sequentially; read-only reconciliation reports no mismatch.
Sales has19 actual MySQL checks, covering overselling, exact prices, FEFO, duplicate
confirmation keys, edit/cancel races, overlapping price writes and current reads
under old RR snapshots, including new drafts and changed catalog/customer prices.

Independent read-only review: no material findings. Minor deferred coverage item:
add an automated two-line confirmation assertion tying each allocation to its line
and product (existing automated cases use one sale line). Current code ordering is
correct and the actual two-line behavior was exercised through the browser:
3 pieces at1.250001 plus1 carton at60.000024 =63.750027; allocations line1=-3early,
line2=-7early/-17late. A price rule and the sale were created and confirmed through
the real Arabic UI, English detail/audit links inspected, console had no errors.

All disposable tagged UI/MySQL records were cleaned with identity/reference guards.
No real user credentials or catalog records changed. Test screenshot in
docs/screenshots/phase5-confirmed-test-en.png contains these now-removed fixtures.
Final Arabic/English lists are screenshots of the actual application after cleanup.
