# Phase 4: purchase orders and full receipts

Owner authorization: implement the purchase workflow described in the conversation:
supplier, piece/carton lines and exact prices; editable/cancellable drafts; one full
receipt linked to real stock. Keep Laravel/Blade, Arabic/English and MySQL. Inventory
count adjustments and manual opening balances remain deferred.

Design decisions (implementation defaults):
- Draft -> received, or draft -> cancelled. No partial receipts, payments, taxes,
  received-document reversal UI, returns, sales or AI in this phase.
- Draft items store product, product-name snapshot, whole input quantity, unit,
  carton conversion snapshot, unit price and subtotal. Receipt assigns one batch
  per item, either existing or created with number/optional ordered dates. Multiple
  batches for one product use separate lines. New batches are created atomically
  as part of the authorized receipt, not a general master-data editing permission.
- Prices have up to six decimals, max 999999999.999999. Line/document totals max
  999999999999.999999. Calculate with checked integer millionths, never floats.
- Server derives totals and stock quantities. Active supplier/product required on
  save and receipt. Changed draft carton factor requires re-saving the draft.
- Store immutable supplier/product names and conversion/price snapshots; changing
  catalog prices never changes an existing order. Require draft version on every
  transition to reject stale forms.
- UUID/fingerprint protect duplicate create/receive submissions. Receipt locks the
  shared admin-role/warehouse anchor, rechecks current permissions and document
  state with locking reads, then writes batches, stock operation, item batch links
  and received status in ONE transaction with deadlock retries.
- Order has a unique typed FK to its stock receipt. Purchase-linked receipts cannot
  be reversed through the generic internal ledger; a future document workflow must
  own such reversals. No order deletion route. Cancelled/received history is retained.
- New purchases.view/manage/receive permissions; receive also requires inventory.post.
  Initially grant new permissions to protected administrator only. Preserve other roles.
- Read screens: searchable/filterable list (supplier/status/date), order detail,
  draft form, receipt form, supplier purchase history through a filtered list.

Acceptance:
- [x] Exact draft totals, repeat-submit protection and optimistic version checks.
- [x] Full atomic receipt with correct batch/product/expiry and conversion validation.
- [x] Failed receipt rolls back all document, batch and stock writes; retries credit once.
- [x] Posted/cancelled edits and invalid transitions fail; permissions hold internally/HTTP.
- [x] Arabic/English screens, escaped output and robust malformed-input redisplay.
- [x] Safe MySQL additive migration; real independent-connection receipt concurrency
  and nested-snapshot checks; stock reconciliation; existing suite and fresh review.

Verification: 106 tests / 601 assertions passed; Pint and Blade compilation passed.
Real MySQL purchase workers cover different-key and identical-key races, receipt
versus edit/cancel, current authorization after an older snapshot, replay with
current items, a draft created after the snapshot, full rollback on second-movement
failure, exact decimals and restrictive supplier FK. Existing stock concurrency and
snapshot harnesses still pass. Read-only reconciliation reports no mismatches.

Independent review found a nonlocking item-model parent-status read; the added
two-connection regression failed before changing that guard to a locking read and
passed after. Valid zero batch numbers/notes are also preserved with explicit blank
checks. No remaining material review findings or deferred minor findings.

Browser checks: created a tagged two-line draft through the Arabic form, verified
catalog defaults and dynamic add-line behavior, exact total 37.237036, receipt form,
and received document/list in both languages. The browser automation's JavaScript-
confirm handling stalled in its test tab; that specific button-confirm round trip
was not claimed as a browser pass. The owned receipt was posted through the real
service, while full HTTP receipt flow is covered by the automated suite. All tagged
UI/MySQL records were removed; real user records/credentials preserved. Screenshots
named received-test contain temporary test fixtures, not live inventory.
