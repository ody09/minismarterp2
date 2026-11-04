# Smart Mini ERP

Laravel 13 / PHP 8.4+ / MySQL 8.4 / Blade.

## المرحلة الحالية | Current phase

اكتملت المرحلة السادسة: لوحة ملخص الأعمال وتقارير المبيعات والمشتريات وتنبيهات المخزون الحالي، مع اختبار دورة الشراء والاستلام والبيع والصرف كاملة. تشمل المراحل السابقة أسعار العملاء وأوامر البيع والمشتريات والمستخدمين والصلاحيات والبيانات الأساسية. الواجهة عربية وإنجليزية مع RTL/LTR. تسويات الجرد والرصيد الافتتاحي اليدوي مؤجلان.

Phase6 adds a permission-scoped business dashboard and read-only sales, purchase
and stock-alert reports, exact aggregate money, local posting-day filters and full
workflow verification. Phase5 provides versioned customer prices, automatic sales pricing, editable/cancellable
sales drafts and full confirmation through a typed, atomic FEFO stock issue. Purchase
drafts/receipts, master data and stock audit screens remain available. Money uses six
decimal places and checked integers. Stock uses integer pieces and immutable unit
snapshots. Concurrent/repeated confirmation or receipt submissions post once. No
manual opening/count adjustment, generic reversal UI or AI dependency is exposed.

## التشغيل على Windows | Windows setup

ثبّت **PHP 8.4+ وComposer وMySQL 8.4+** أولًا، وأضف PHP وComposer إلى PATH
وشغّل خدمة MySQL. لا يحتاج المشروع Node/npm. بعد تحميل المشروع من GitHub،
افتح PowerShell داخل مجلده ونفّذ:

```powershell
.\setup.cmd
```

الأمر يثبت المكتبات، وينشئ `.env` عند غيابه، ويسألك عن اتصال MySQL. أدخل حساب
MySQL الموجود على جهازك؛ لا توجد كلمة مرور افتراضية. اختر اسم قاعدة بحروف
إنجليزية أو أرقام أو `_`، وراجع الهدف قبل الموافقة. ينشئ الأمر القاعدة عند غيابها
إذا كان حساب MySQL يملك صلاحية CREATE DATABASE؛ وإلا أنشئ قاعدة فارغة بنفسك
واستخدم حسابًا يملك صلاحيات إنشاء وتعديل جداولها.

بعد ذلك يُولّد مفتاح التطبيق عند غيابه ويُنشئ الجداول والصلاحيات والمستودع الأساسي،
ثم يطلب اسم المدير وبريده وكلمة مروره وتأكيدها. كلمات المرور مخفية أثناء الإدخال؛
كلمة مرور المدير 12 حرفًا على الأقل، تشمل حروفًا كبيرة وصغيرة وأرقامًا. كل جهاز
له إعداداته وحسابه؛ لا ترفع `.env` أو تشارك كلمات المرور.

للتشغيل بعد التجهيز وفي كل مرة لاحقة:

```powershell
composer dev
```

افتح [صفحة الدخول](http://127.0.0.1:8000/login). أوقف الخادم بـCtrl+C، وأعد تشغيله
بعد تغيير `.env`. إذا كان المنفذ 8000 مستخدمًا، أوقف الخادم السابق أولًا.

إعادة `.\setup.cmd` تحفظ مفتاح التطبيق وبيانات النظام وكلمة مرور المدير
الموجود وتخصيصات المستودع والأدوار، وتطبّق الترحيلات والبيانات الأساسية الجديدة.
لا تعيد إنشاء الحساب إذا وُجد مدير نظام نشط. الأمر للتطوير المحلي فقط، ويرفض
قاعدة غير فارغة لا يتعرف عليها كقاعدة Smart Mini ERP. خذ نسخة احتياطية قبل
تحديث تثبيت يحتوي بيانات مهمة؛ الترحيلات لا تُحاط كلها بمعاملة واحدة في MySQL.

لتغيير الاتصال الذي حفظته، نفّذ بعد تثبيت المكتبات:

```powershell
php scripts/setup.php install --configure
```

If a fresh setup fails before credentials are saved, rerun `.\setup.cmd`. After
settings are saved, use the `--configure` command above to change them. The
installer supports direct MySQL host/port connections; clear `DB_URL`/`DB_SOCKET`
overrides. It installs project dependencies, not PHP, Composer or the MySQL service.

On Windows, setup and `composer dev` enable a missing `pdo_mysql` DLL for their
child PHP processes using an ignored local INI file. Global PHP settings are not
edited. If the DLL cannot load, point `extension_dir` in your PHP configuration at
the matching PHP `ext` directory. Composer reports any other missing PHP extensions.
The existing `scripts/php.ps1` wrapper also enables SQLite for the isolated test
suite. No PowerShell execution-policy change is needed for `.\setup.cmd` or
`composer dev`. On other platforms enable `pdo_mysql` normally.

## الصلاحيات | Access rules

- One role per account. The initial roles are Administrator, Purchasing, Sales and Inventory.
- The 31 implemented permissions include `reports.sales/purchases/inventory`, purchases, `prices.view/manage` and
  `sales.view/manage/post`. Purchase receipt needs `purchases.receive` plus
  `inventory.post`; sales confirmation needs `sales.post` plus `inventory.post`.
  Generic posting/reversal permissions apply to the internal engine, not manual forms.
- Dashboard access alone grants no business report data. Each report permission
  controls its matching dashboard section too; report-only users do not gain document
  access. Account-only landing pages continue to show access details without metrics.
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
./scripts/php.ps1 tests/MySql/workflow-reports.php
./scripts/php.ps1 tests/MySql/install.php
./scripts/php.ps1 artisan erp:check-stock
```

These scripts require a local/testing MySQL environment and process spawning.
They create tagged verification records/temporary identities for independent
connections, verify ownership and references before cleanup, and remove only their
own fixtures. Generated worker artifacts stay under ignored `.cache/mysql-stock-tests/`
and `.cache/mysql-purchase-tests/`, `.cache/mysql-sales-tests/`.

The installer check uses separate ignored `.env` files and randomly named temporary
schemas. It verifies fresh setup, database creation, repeat setup, cancellation,
bad credentials and refusal of an unrelated nonempty database, then removes only
its owned schemas. Its MySQL account must have CREATE/DROP DATABASE permission.

The main automated suite forces an isolated SQLite database in memory. A bootstrap guard checks the resolved configuration before database migrations and refuses cached MySQL, file SQLite or URL overrides. If tests stop at this guard, run `./scripts/php.ps1 artisan config:clear` and retry. The suite covers authentication, revoked sessions, access boundaries, privilege escalation, last-administrator protection, role changes, validation, escaped output, CSRF, safe landing pages, stock posting invariants and both languages. The standalone MySQL scripts above deliberately use the configured local database without dropping tables.

Verified locally: 147 automated tests / 924 assertions, including Windows setup/
literal environment values/bootstrap checks and full purchase/receipt/
multi-line sale/FEFO/replay/reconciliation, report scope/date/pagination/stock alerts,
dashboard permission isolation, customer tier/date/
unit selection, exact conversion and rounding, overlap/version guards, sales snapshots,
FEFO confirmation/replay/rollback, stale pricing and internal/HTTP access boundaries.
MySQL multi-process checks cover competing sales, same/different confirmation keys,
edit/cancel races, overlapping prices, current catalog/customer pricing and actor/
permissions despite an older REPEATABLE READ snapshot, fresh drafts after that
snapshot, rollback/FKs and exact decimal persistence. Existing stock/purchase
concurrency harnesses are retained. Tagged fixtures and temporary identities were
removed. Full HTTP load testing remains future stabilization work.

The MySQL workflow/report check creates actual typed purchase receipts and sales,
asserts each allocation's product/line/unit, and verifies an exact report total of
10000000000067.490036, beyond 64-bit integer millionths. No floating-point report
sums are used. All tagged test identities/documents/stock are removed afterward.

## لوحة الأعمال والتقارير | Business dashboard and reports

`/dashboard` shows only report domains the account is permitted to view. Its period
defaults to the current business month through today. `/reports` links to sales,
purchases and inventory. Financial reports count confirmed sales and received
purchases by actual posting time, not commercial order date. Date endpoints are
inclusive business days in `INVENTORY_TIMEZONE` (Asia/Aden by default), translated
to half-open UTC boundaries. Customer/supplier filters apply to all totals and rows.
Money and piece totals include all filtered documents, independent of pagination;
FEFO splits never multiply document amounts. Exact sums use installed Brick Math,
already required by Laravel's decimal casts. Reads use the configured InnoDB
REPEATABLE READ transaction snapshot and never adjust stock or post operations.

Inventory reports are current main-warehouse balances, not a historical period.
Active products at or below their sellable reorder level trigger low-stock alerts.
Zero stock with reorder level0 qualifies. Expiry alerts include positive physical
stock, including inactive products; near-expiry dates span today through today+N,
inclusive (default30 days, selectable1..365). Expired is strictly before today.
No-date batches are not expiry alerts. Product/batch lists paginate separately.
Optional q/product/alert filters and report links are available in both languages.

Phase5's deferred multi-line allocation test is now automated in the full workflow
tests. Independent phase6 review found no material issue; one minor optional query
optimization remains documented in phase6 (an unused eager-loaded user relation).

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
records were removed. The earlier automated multi-line allocation coverage gap was
closed in phase6, including actual MySQL purchase-to-sale and report verification.

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
and [phase 4](docs/phase-4.md), [phase 5](docs/phase-5.md), [phase 6](docs/phase-6.md).
Broader production load/deployment checks and AI training/evaluation/integration
require separate requests; this phase does not claim production load certification.
