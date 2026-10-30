# Phase 2: master data

Implement only the agreed phase: categories, products, piece/carton conversion,
product batches, customers, suppliers and one operational warehouse. Reuse Blade,
Arabic/English, MySQL, existing auth and per-feature permissions. No stock balances,
movement records, orders, AI, public API or new dependencies.

Decisions:
- Warehouse: expose one seeded `main` warehouse for viewing/editing; do not add
  transfers or a multi-warehouse UI. The table is not a global singleton constraint.
- Quantity base unit is piece. Carton factor is a positive whole number per product.
  No stock quantity is stored on products or batches in this phase.
- Prices are fixed decimals with six fractional digits, associated explicitly with
  the default purchase/sale unit. Accept at most nine integral digits and six decimals.
- Batch number is unique per product. Product identity is immutable on an existing
  batch. Production/expiration are optional for nonperishable goods, and ordered
  when both exist. Existing/expired batches can be recorded; future stock posting
  will decide sellability. Batch creation never adds stock.
- Product/category/contact activation is explicit. Referenced categories/products
  cannot be deleted. Future order/inventory foreign keys must retain the same
  delete restriction for contacts and batches.
- Product packing remains editable before stock movements exist; phase 3 must
  freeze conversion factors after first movement, before enabling stock posting.
- New feature permissions go to the protected administrator. Preserve existing
  custom role permissions; owners can grant new permissions through the role UI.

Tasks and acceptance:
- [x] Schema/models: constrained relationships, decimal casts, scoped batch identity,
  integer conversion including overflow rejection; test invalid dates/units/prices.
- [x] CRUD/search and deactivation: categories, products, batches, contacts; deny
  forged requests and safe deletion of referenced data; paginate lists.
- [x] Warehouse: idempotent seed, view/edit only, existing values preserved on reseed.
- [x] Navigation/permissions and bilingual forms: management-only and view-only
  users can reach exactly their authorized pages; permission forms accept catalogue growth.
- [x] Verification: full suite, formatting, additive MySQL migration/seed, MySQL
  constraint/rollback smoke checks, independent review and browser screenshots.

Review focus: existing role customization; input tampering across products/batches;
decimal accuracy; deletion races/foreign keys; singleton UI without confusing table
cardinality; no hidden stock/quantity side effects.

Verified result: 69 tests / 374 assertions. MySQL constraints, decimal persistence,
referential delete safety and scoped batch uniqueness checked in a transaction
with fixture rollback. Existing account credentials were preserved. Browser checks
covered Arabic/English products, product form prerequisites, the main warehouse,
permission translations, narrow and wide layouts. Screenshots are in docs/screenshots/.

Independent review corrections: scalar-safe validation redisplay on master forms,
literal zero search support, warehouse name uniqueness, Arabic validation messages.
Browser review also corrected nested permission translations for both old/new codes.
