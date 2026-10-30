# Phase 3: stock engine, balances and ledger

Scope: read-only stock/batch balances and movement audit screens; transactional
internal receipt/issue/reversal services. Inventory counting adjustments are
explicitly deferred by the owner. No manual opening balance, stock-edit forms,
warehouse transfers, orders, invoices or AI are introduced in this phase.

Architecture and rules:
- Keep MySQL and existing Laravel/Blade/auth. No new dependencies.
- `inventory` is a cached balance unique by warehouse and batch; the product is
  derived through the batch. `inventory_transactions` is the append-only truth.
  `stock_operations` groups movements, actor, UTC posting time, immutable source
  reference, idempotency key/fingerprint and optional reversal relationship.
- `StockLedger::receive(User, uuid, reference, lines)` accepts batch_id, whole
  quantity and piece/carton unit per line. Ordinary expired/inactive receipts fail.
- `StockLedger::issue(User, uuid, reference, lines)` accepts product_id, whole
  quantity and unit. Allocate FEFO across eligible batches; expiry-free batches
  sort last, ties use first inventory row. Preserve input line/allocation indexes.
- `StockLedger::reverse(User, uuid, StockOperation, reason)` appends opposite
  deltas. Only one reversal per original, never reverse a reversal. Reversing a
  receipt fails if insufficient physical stock remains; issue reversals restore
  the original batches even if now expired or inactive.
- Source references are required and unique per operation kind. They are internal
  document references, not public free-form posting inputs. Phases 4/5 must add
  typed purchase/sales links and call this engine inside the SAME document-state
  transaction; until then there are no production posting endpoints.
- Share AccessManager's administrator-role anchor first, then lock the main warehouse
  as the small-ERP stock serialization anchor, then product rows
  in ID order, then batches/balances. Recheck current actor and business rules.
  Same key/payload returns the same operation; changed payload or reused document
  reference fails. Any failure rolls back movements, balances and first-post stamps.
- All posting invariants use current locking reads, including actor permissions,
  idempotent replays, reversal history and inventory totals. This remains correct
  if an enclosing document transaction already established a REPEATABLE READ
  snapshot. Returned operations eagerly include current movement allocations;
  callers should use that collection rather than issue a new snapshot-based query.
- Store quantities as integer pieces, reject overflow, freeze product carton factor
  and batch identity/dates after first posting, even if balance later reaches zero.
- Dates are valid through the end of their expiration day in Asia/Aden (configurable
  INVENTORY_TIMEZONE); posting timestamps stay UTC. Expiry never creates a movement.
  Physical stock includes expired/inactive goods; sellable stock excludes them.
- Three new permissions: inventory.view, inventory.post, inventory.reverse. Grant
  only to system administrator initially, preserving custom roles. No manual posting
  or reversal UI is exposed ahead of document workflows.
- `erp:check-stock` only reports balance/ledger mismatches; it never adjusts stock.

Tasks/acceptance:
- [x] Schema, immutable ledger records and scoped keys/foreign keys.
- [x] TDD receipt/issue/FEFO/idempotency/rollback/reversal/overflow/auth boundaries.
- [x] Serialized metadata updates freeze conversion and batch history after stock.
- [x] Arabic/English stock summary, product/batch detail, movements and operation detail.
- [x] Read-only reconciliation, full tests, additive MySQL migration and real MySQL
  concurrency verification with isolated tagged fixtures and safe cleanup.
- [x] Independent review, targeted regressions, browser verification and docs.

Review focus: duplicate requests and source reuse, overselling under concurrency,
deadlock/lock ordering, zero-stock reversals retaining history, timezone expiry,
metadata races, document integration boundaries and no hidden manual adjustments.

Verified: 87 tests / 468 assertions, additive MySQL migrations, genuine simultaneous
issues and idempotent receipts, and independent-connection nested-snapshot regressions
for replay data, actor/permission revocation and product-total overflow. Standalone
reproducible checks live in tests/MySql; fixtures were removed without altering
existing user records. Read-only reconciliation passes. Independent review's
snapshot finding was reproduced before fixing and all regressions then passed.
