# Smart Mini ERP

Laravel 13 / PHP 8.4+ / MySQL 8.4 / Blade.

## المرحلة الحالية | Current phase

اكتملت المرحلة الخامسة: أسعار العملاء حسب الوحدة والكمية والتاريخ، وأوامر البيع واعتمادها مع الصرف من المخزون وتتبع الدفعات. تشمل المراحل السابقة المشتريات والاستلام والمستخدمين والصلاحيات والمنتجات والدفعات والعملاء والموردين والمستودع. الواجهة عربية وإنجليزية مع RTL/LTR. تسويات الجرد والرصيد الافتتاحي اليدوي مؤجلان.

Phase 5 adds versioned customer prices, automatic sales pricing, editable/cancellable
sales drafts and full confirmation through a typed, atomic FEFO stock issue. Purchase
drafts/receipts, master data and stock audit screens remain available. Money uses six
decimal places and checked integers. Stock uses integer pieces and immutable unit
snapshots. Concurrent/repeated confirmation or receipt submissions post once. No
manual opening/count adjustment, generic reversal UI or AI dependency is exposed.

## التشغيل على Windows | Windows setup

1. Run `composer install` in this folder. Node/npm and a frontend build are not required.
2. On a fresh checkout only, copy `.env.example` to `.env` if it does not already exist, then run `./scripts/php.ps1 artisan key:generate`. Do not replace an existing `.env` or regenerate a deployed application's key.
3. Create a new empty MySQL database named `smart_mini_erp` and a dedicated database user. Set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` in `.env`. Keep credentials out of Git and chat.
4. Run `./scripts/php.ps1 artisan migrate --seed` after confirming that `.env` points at that new project database. This creates the access-control schema and permission catalogue. Seeding creates no user and no default password.
5. Run `./scripts/php.ps1 artisan erp:create-admin owner@example.com --name="Owner"`. Enter and confirm a password at the hidden prompts. Use at least 12 characters with uppercase/lowercase letters and numbers. Passwords are never command-line arguments.
6. Run `./scripts/php.ps1 artisan serve --host=127.0.0.1 --port=8000 --no-reload`, then visit http://127.0.0.1:8000/login. Restart the server after changing `.env`.

The Windows wrapper enables missing PDO MySQL/SQLite extensions for this process and its children using an ignored local INI file. It does not edit global PHP settings. If PHP cannot find the DLLs, configure its `extension_dir` normally. On other platforms enable `pdo_mysql` and `pdo_sqlite`, then use `php` directly.

## الصلاحيات | Access rules

- One role per account. The initial roles are Administrator, Purchasing, Sales and Inventory.
- The 28 implemented permissions include purchases, `prices.view/manage` and
  `sales.view/manage/post`. Purchase receipt needs `purchases.receive` plus
  `inventory.post`; sales confirmation needs `sales.post` plus `inventory.post`.
  Generic posting/reversal permissions apply to the internal engine, not manual forms.
- New permissions are granted to the protected Administrator role. Existing customized roles are preserved; assign their new permissions explicitly in the roles screen.
- Custom roles can use any subset of the existing permission catalogue; no public registration endpoint exists.
- A manager cannot grant a role or permission exceeding their own access, or change a more privileged account. The system administrator role is protected.
- At least one active administrator must remain. Account updates revoke old sessions; a user editing their own account keeps the current session only if the resulting account is active.
- Accounts are disabled, not deleted. Unused custom roles can be deleted. Assigned and system roles cannot.
- The login endpoint is rate limited by account/IP and by IP. Passwords are hashed; forms require CSRF tokens.

## الاختبارات | Verification

```powershell
./scripts/php.ps1 artisan test
./scripts/php.ps1 vendor/bin/pint --test
composer validate --strict --no-check-publish
composer audit --locked
```

Real MySQL regression checks are separate from the in-memory suite:

```powershell
./scripts/php.ps1 tests/MySql/stock-concurrency.php
./scripts/php.ps1 tests/MySql/stock-snapshot.php
./scripts/php.ps1 tests/MySql/purchase-concurrency.php
./scripts/php.ps1 tests/MySql/sales-concurrency.php
./scripts/php.ps1 artisan erp:check-stock
```

These scripts require a local/testing MySQL environment and process spawning.
They create tagged verification records/temporary identities for independent
connections, verify ownership and references before cleanup, and remove only their
own fixtures. Generated worker artifacts stay under ignored `.cache/mysql-stock-tests/`
and `.cache/mysql-purchase-tests/`, `.cache/mysql-sales-tests/`.

The main automated suite forces an isolated SQLite database in memory. A bootstrap guard checks the resolved configuration before database migrations and refuses cached MySQL, file SQLite or URL overrides. If tests stop at this guard, run `./scripts/php.ps1 artisan config:clear` and retry. The suite covers authentication, revoked sessions, access boundaries, privilege escalation, last-administrator protection, role changes, validation, escaped output, CSRF, safe landing pages, stock posting invariants and both languages. The standalone MySQL scripts above deliberately use the configured local database without dropping tables.

Verified locally: 128 automated tests / 785 assertions, including customer tier/date/
unit selection, exact conversion and rounding, overlap/version guards, sales snapshots,
FEFO confirmation/replay/rollback, stale pricing and internal/HTTP access boundaries.
MySQL multi-process checks cover competing sales, same/different confirmation keys,
edit/cancel races, overlapping prices, current catalog/customer pricing and actor/
permissions despite an older REPEATABLE READ snapshot, fresh drafts after that
snapshot, rollback/FKs and exact decimal persistence. Existing stock/purchase
concurrency harnesses are retained. Tagged fixtures and temporary identities were
removed. Full HTTP load testing remains future stabilization work.

## المشتريات | Purchases

Open `/purchases` to search by order number/supplier and filter supplier, status or
order dates. Supplier rows link to their purchase history. Create a draft with an
active supplier and whole piece/carton quantities. Prices apply to the selected
unit; changing a product suggests its catalog purchase unit and price, which may
then be edited. Changing only the unit requires reviewing its price. Totals are
calculated on save using integer millionths (six decimals), never floats.

On Receive goods, assign one existing batch or a new batch number/optional dates
per saved line. Use separate lines to receive a product into several batches.
Receipt adds all quantities to the main warehouse and records its actor, time,
batch allocations and a unique stock-operation link in the same transaction.
Changed packing requires re-saving the draft before receiving. Stale forms fail
with a reload instruction; repeat receipt keys return the original result.

Received/cancelled documents cannot be edited or deleted. A received purchase
cannot be cancelled as a draft or reversed through the generic stock service.
Partial receipts, payments, taxes and purchase returns are not supplied in this
phase. Prices max 999999999.999999; line/order totals max 999999999999.999999.

## المبيعات وأسعار العملاء | Sales and customer pricing

Use `/prices` for customer/product/unit quantity tiers. Minimum quantity is in the
selected unit. Validity endpoints are inclusive; blank dates are open. Active
periods for the same customer/product/unit/minimum cannot overlap. Deactivate rules
instead of deleting them. Highest eligible minimum quantity wins per item.

Use `/sales` to prepare a draft with customer, non-future order date, products and
whole quantities. Duplicate product/unit rows must be combined. Saving calculates
prices and totals on the server; client prices/totals are ignored. A matching
customer price applies to its exact unit. Otherwise selling_price belongs to the
product's selling_unit and is converted to the requested unit, with half-up rounding
to six decimals for division. Conversion exceeding the supported price limit fails.

Pricing uses the order date. Each item stores its name/unit/conversion/price/source
snapshot and rule version; editing/saving explicitly re-prices the draft. Confirmation
rechecks current pricing, active data and packing. A changed source/value/rule version
requires reviewing and saving the draft; saved totals are never silently changed.
Drafts do not reserve stock. Confirm sale opens a review page and posts a full FEFO
issue using actual posting-day expiry. Its header, status, movements and balances
commit or roll back together; repeated requests issue once. Batch allocations are
shown by actual sale line and linked to the stock operation. Customer rows link to
their sales history. Confirmed/cancelled documents remain immutable. Generic stock
reversal also refuses sales-linked issues; returns need a future document workflow.

Both locales were checked in the browser, including a real tagged two-line sale:
3 pieces at customer price 1.250001 plus 1 carton at catalog price 60.000024, total
63.750027; allocations were line1=-3 early, line2=-7 early/-17 later. These temporary
records were removed. Independent review found no material defects; a minor future
coverage improvement is an automated multi-line allocation assertion (the current
browser check covers it; automated FEFO cases use one line).

## محرك المخزون | Stock engine integration

`App\Services\StockLedger` provides `receive`, `issue` and `reverse`. See [the phase 3 contract](docs/phase-3.md) for argument shapes and lock/integration rules. Immutable source references and UUID idempotency keys are required. Returned operations include their current transaction collection, including replays.

`PurchaseOrders` and `SalesOrders` link actual order IDs to stock operations and call the ledger
inside the same transaction as document state/items. `StockContext` shares current
actor/permission checks and lock ordering. Lock admin role,
main warehouse, actor/permissions, document, supplier/customer, ordered products,
customer price rows and then batches/balances; retry the enclosing transaction on a deadlock. Model guards use
current locking reads where document state is an invariant.

The movement ledger is the source of truth; `inventory.quantity` is a transactionally maintained balance. Never directly edit balances or delete posted history. `./scripts/php.ps1 artisan erp:check-stock` reports mismatches without changing any quantity. No adjustment feature is supplied.

Posting timestamps are UTC. Expiration dates are valid through the end of their day in `INVENTORY_TIMEZONE` (default `Asia/Aden`). Expiry changes sellability only; it never silently writes a movement. Inactive products retain physical stock but cannot be issued. Carton factors and batch number/dates become read-only after the first movement.

## النشر | Deployment

For a future production deployment: configure the web root as `public/`, PHP 8.4+ with `pdo_mysql`, HTTPS, `APP_ENV=production`, `APP_DEBUG=false`, a correct HTTPS `APP_URL`, and `SESSION_SECURE_COOKIE=true`. Protect `.env`, keep a persistent `APP_KEY`, and make only `storage/` and `bootstrap/cache/` writable by PHP. Use a new dedicated database and back it up before schema changes. Configure a real web server; the development server is local only.

No queue worker, Redis, Node runtime, public API or AI service is required for this phase.

See [phase 1](docs/phase-1.md), [phase 2](docs/phase-2.md), [phase 3](docs/phase-3.md)
and [phase 4](docs/phase-4.md), [phase 5](docs/phase-5.md). Later stabilization,
reporting and AI work require separate implementation requests.
