# Purchase Orders Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Purchase drafts become one fully received, auditable stock receipt.
**Architecture:** Extend the existing monolith with PurchaseOrder/Item and a
PurchaseOrders service. Share the current stock lock/authorization context. Preserve
integer stock and fixed-six-decimal monetary snapshots in the same transaction.
**Tech Stack:** Laravel 13, PHP 8.4, MySQL 8.4, Blade, static JS/CSS.
**Spec:** docs/phase-4.md (owner authorized implementation in conversation).

## Global Constraints
- Existing MySQL, Arabic/English RTL/LTR, no new dependencies or global PHP changes.
- No count adjustments/manual opening, partial receipts, payments, taxes, returns, AI.
- Never drop existing tables or modify user credentials/real business records for tests.
- Shared lock order: admin role -> main warehouse -> current actor/permissions ->
  order -> products in ID order -> batches/balances. Current locking reads for invariants.
- Price/total limits and six decimal places are defined in the spec. No float money.

## Review Focus
- Concurrent receive and stale draft updates must create one receipt with current items.
- Old MySQL REPEATABLE READ snapshots must not hide received state or revoked access.
- Product/batch substitution, inactive data and carton changes must fail atomically.
- Malformed arrays/extra items and excessive monetary totals must not crash or post stock.
- A failure after the first movement must roll back batches, history, totals and state.

### Task 1: Draft orders and money
**Files:** migration create_purchase_order_tables; Models/PurchaseOrder, PurchaseOrderItem;
Services/PurchaseOrders, StockContext; Models/Permission; tests/Feature/PurchaseOrdersTest.
**Interfaces:** save(User, array, ?PurchaseOrder): PurchaseOrder; cancel(User, PurchaseOrder, int): PurchaseOrder.
- [x] Write/run failing behavioral tests: two lines 3 x 12.345678 + 2 x 0.100001 =
  37.237036, client totals ignored, same create UUID replays once, changed payload
  rejected, stale update rejected, inactive supplier/product rejected, cancellation
  preserves history, oversized amount rejected before write.
- [x] Implement schema, exact money, shared current authorization, immutable terminal
  documents, draft save/version/cancel and four draft/read permission boundaries.
- [x] Run focused tests and verify existing stock behavior survives shared context.

### Task 2: Atomic document receipts
**Files:** Services/PurchaseOrders, StockLedger; Models/StockOperation; same feature tests.
**Interface:** receive(User, PurchaseOrder, array): PurchaseOrder; data key/version/items.
- [x] Write/run failing tests: ten cartons of24 ->240 pieces, typed receipt link,
  repeat request same operation, another request rejected, batch mismatch/expired or
  edited conversion rejected, missing/duplicate item rejected, second movement failure
  rolls back new batches and document, received/cancelled edit/cancel rejected,
  generic linked reversal rejected, post permission required in addition to receive.
- [x] Implement full receipt with locked current items, batch assignment/create,
  fingerprint/replay and ledger call in the enclosing transaction.
- [x] Focused tests green; run full suite.

### Task 3: Bilingual purchase UI
**Files:** PurchaseOrderController (shared service validation), routes/web.php;
views/purchases/{index,form,show,receive}; lang/{ar,en}/purchases.php; layout/sidebar,
supplier history links, stock operation backlink; public/js/purchase-lines.js, CSS.
- [x] Write/run failing HTTP tests for authorization, create/update/receive/cancel,
  filters, both locales, escaping, malformed old input and no destructive routes.
- [x] Implement using existing Blade patterns; JS adds/removes indexed lines and
  suggests catalog unit/price on explicit product selection; totals server calculated.
- [x] Verify HTTP tests and full suite, Pint, compile Blade.

### Task 4: MySQL, review and delivery
**Files:** tests/MySql/purchase-worker.php, purchase-concurrency.php; README, phase docs.
- [x] Add repeatable tagged-fixture MySQL tests: concurrent same/different receipt
  keys, receipt vs stale edit/cancel, stale outer snapshot replay/access, decimal
  persistence/FKs, failure rollback. Cleanup only verified owned fixture rows.
- [x] Apply additive migration/seed; run MySQL checks + existing stock harnesses and
  erp:check-stock, full suite and Pint. No resetting existing database.
- [x] One independent review of all phase4 changes; reproduce/fix material findings.
- [x] Browser verify Arabic/English empty/create/detail flow safely; remove own test
  fixtures, update docs with actual evidence and deliver working purchase route.
