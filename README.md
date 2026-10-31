# Smart Mini ERP

Laravel 13 / PHP 8.4+ / MySQL 8.4 / Blade.

## المرحلة الحالية | Current phase

اكتملت المرحلة الرابعة: أوامر الشراء ومسوداتها واستلام البضاعة وربطها بالمخزون وسجل الحركات. تشمل الوظائف السابقة المستخدمين والصلاحيات والمنتجات والدفعات والعملاء والموردين والمستودع ومحرك المخزون. الواجهة عربية وإنجليزية مع RTL/LTR. تسويات الجرد والرصيد الافتتاحي اليدوي مؤجلان.

Phase 4 adds purchase drafts, exact line/document totals, supplier history and full batch receipts linked to the transactional stock ledger. A draft can be edited or cancelled; a received/cancelled document retains its history. Quantities use integer pieces, immutable conversion snapshots and first-stock metadata protection. Concurrent/repeated receipt submissions credit stock once. Sales and AI remain in later phases; no manual opening/count adjustment or generic reversal endpoint is exposed.

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
- The 23 implemented permissions include inventory and `purchases.view`, `purchases.manage`, `purchases.receive`. Receiving requires both `purchases.receive` and `inventory.post`. Generic posting/reversal permissions apply to the internal engine, not manual stock forms.
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
./scripts/php.ps1 artisan erp:check-stock
```

These scripts require a local/testing MySQL environment and process spawning.
They create tagged verification records/temporary identities for independent
connections, verify ownership and references before cleanup, and remove only their
own fixtures. Generated worker artifacts stay under ignored `.cache/mysql-stock-tests/`
and `.cache/mysql-purchase-tests/`.

The main automated suite forces an isolated SQLite database in memory. A bootstrap guard checks the resolved configuration before database migrations and refuses cached MySQL, file SQLite or URL overrides. If tests stop at this guard, run `./scripts/php.ps1 artisan config:clear` and retry. The suite covers authentication, revoked sessions, access boundaries, privilege escalation, last-administrator protection, role changes, validation, escaped output, CSRF, safe landing pages, stock posting invariants and both languages. The standalone MySQL scripts above deliberately use the configured local database without dropping tables.

Verified locally: 106 automated tests / 601 assertions, plus actual MySQL multi-process checks for competing stock issues, purchase receipts racing each other/edits/cancellation, same-key receipt replay, atomic purchase rollback, exact decimals and foreign-key history protection. An established outer REPEATABLE READ snapshot cannot hide current document/items/actor/permissions/product totals, including a draft created after that snapshot. Tagged fixtures and temporary identities were removed after verification. Full HTTP workflow load testing belongs to later stabilization; SQLite alone is not evidence of MySQL locking correctness.

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

## محرك المخزون | Stock engine integration

`App\Services\StockLedger` provides `receive`, `issue` and `reverse`. See [the phase 3 contract](docs/phase-3.md) for argument shapes and lock/integration rules. Immutable source references and UUID idempotency keys are required. Returned operations include their current transaction collection, including replays.

`PurchaseOrders` links actual order IDs to stock operations and calls the ledger
inside the same transaction as document state/items. `StockContext` shares current
actor/permission checks and lock ordering. Future sales services must follow the
same document authorization/typed-link/atomic-posting contract. Lock admin role,
main warehouse, actor/permissions, document, supplier, ordered products and then
batches/balances; retry the enclosing transaction on a deadlock. Model guards use
current locking reads where document state is an invariant.

The movement ledger is the source of truth; `inventory.quantity` is a transactionally maintained balance. Never directly edit balances or delete posted history. `./scripts/php.ps1 artisan erp:check-stock` reports mismatches without changing any quantity. No adjustment feature is supplied.

Posting timestamps are UTC. Expiration dates are valid through the end of their day in `INVENTORY_TIMEZONE` (default `Asia/Aden`). Expiry changes sellability only; it never silently writes a movement. Inactive products retain physical stock but cannot be issued. Carton factors and batch number/dates become read-only after the first movement.

## النشر | Deployment

For a future production deployment: configure the web root as `public/`, PHP 8.4+ with `pdo_mysql`, HTTPS, `APP_ENV=production`, `APP_DEBUG=false`, a correct HTTPS `APP_URL`, and `SESSION_SECURE_COOKIE=true`. Protect `.env`, keep a persistent `APP_KEY`, and make only `storage/` and `bootstrap/cache/` writable by PHP. Use a new dedicated database and back it up before schema changes. Configure a real web server; the development server is local only.

No queue worker, Redis, Node runtime, public API or AI service is required for this phase.

See [phase 1](docs/phase-1.md), [phase 2](docs/phase-2.md), [phase 3](docs/phase-3.md)
and [phase 4](docs/phase-4.md). The next phase is sales/customer pricing and stock
issues; it requires a separate implementation request.
