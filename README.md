# Smart Mini ERP

Laravel 13 / PHP 8.4+ / MySQL 8.4 / Blade.

## المرحلة الحالية وحالة المشروع | Current phase and project status

اكتملت المرحلة السادسة بنجاح: لوحة ملخص الأعمال وتقارير المبيعات والمشتريات وتنبيهات المخزون الحالي، مع التحقق الكامل من دورة الشراء والاستلام والبيع والصرف. كما تم اعتماد وتطبيق إصلاحات التدقيق البرمجي (معالجة استعلامات N+1 في القائمة عبر `NavigationComposer`، وتصحيح التحقق من التواريخ، ومفاتيح الترجمة، وترقية تبعيات Symfony) وحفظها في الفرع `codex/audit-fixes`. وأُجري بنجاح اختبار أداء مستقر لـ 20 مستخدماً متزامناً موثقاً في `docs/audit/evidence/load-test/round2-steady/`.

### المهام المتبقية والمؤجلة | Remaining and deferred tasks
- **تسويات الجرد والرصيد الافتتاحي:** مؤجلة صراحة بقرار المالك لتبقى حركة المخزون دفتر أستاذ غير قابل للتعديل اليدوي.
- **الوظائف التجارية الموسعة:** مردودات المبيعات والمشتريات، والفواتير الضريبية، وحسابات الدفع والقبض، والتسليم الجزئي تندرج ضمن مراحل وظيفية لاحقة.
- **مرحلة الذكاء الاصطناعي (AI Phase):** **لم تبدأ بعد**. لم يُدرج أي نموذج أو ارتباط ذكاء اصطناعي في الكود حتى الآن. تتطلب هذه المرحلة تحديداً مسبقاً من أصحاب المصلحة لهدف النموذج (مثل التنبؤ بالطلب أو التجديد الآلي أو كشف الشذوذ)، وتحديد مصادر البيانات وهيكلتها، وصياغة معايير تقييم ومقاييس دقة واضحة قبل البدء في أي تدريب أو ربط برمجي.

Phase 6 is completed: permission-scoped business dashboard, read-only sales, purchase and stock-alert reports, exact aggregate money, local posting-day filters, and full workflow verification. Audited validation, navigation N+1 queries, and Symfony dependencies are resolved on `codex/audit-fixes`. A 20-VU steady-state load benchmark was validated locally.
- **Deferred Core Items:** Manual opening balances and inventory count adjustments remain explicitly deferred.
- **Future Commercial Scope:** Sales/purchase returns, tax invoices, payments/receipts, and partial fulfillments belong to future functional phases.
- **AI Phase Status:** **Not started**. No AI models or dependencies exist in the codebase. Stakeholders must formally define the model objective (e.g. demand forecasting, auto-replenishment, anomaly detection), training/inference data sources, and measurable evaluation benchmarks before implementation begins.

---

## التشغيل اليومي وإعداد البيئة | Daily operation and setup

### 1. التشغيل بضغطة واحدة | One-click daily launcher (`start-erp.cmd`)

للتشغيل اليومي السريع والمباشر على بيئة Windows دون الحاجة لكتابة أوامر في الطرفية، يتوفر ملف الدفعة:
**[`start-erp.cmd`](file:///c:/Users/albna/Documents/minismarterp2/start-erp.cmd)**

#### آلية العمل | What it does
عند النقر المزدوج على `start-erp.cmd`:
1. **تشغيل MySQL المحلي (منفذ 3306):** يفحص المنفذ 3306؛ إذا كان يعمل يعيد استخدامه فوراً، وإن كان متوقفاً يشغل محرك MySQL 8.4 المحمول بنوافذ خلفية مخفية.
2. **فحص وتجهيز الاتصال اليومي:** ينتظر جاهزية محرك قاعدة البيانات، ويتحقق من وجود قاعدة `smart_mini_erp` ومستخدم `smarterp`. إذا لم تكن مهيأة، يقوم بتهيئتها وتطبيق الترحيلات والبيانات الأساسية تلقائياً دون لمس الحسابات أو كلمات المرور أو `APP_KEY`.
3. **تشغيل Laravel (منفذ 8000):** يفحص المنفذ 8000؛ إذا كان خادم التطوير يعمل يعيد استخدامه، وإن كان متوقفاً يشغله بنوافذ خلفية مخفية عبر إعداد PHP المعتمد مع تحميل الامتداد `pdo_mysql`.
4. **التحقق من الجاهزية وفتح المتصفح:** ينتظر استجابة صفحة `/login` برمز `HTTP 200`، ثم يفتح المتصفح الافتراضي تلقائياً على الرابط: `http://127.0.0.1:8000/login`.

#### المتطلبات ومسارات البيانات الفعلية | Prerequisites and actual data paths
- **متطلبات التشغيل:** نظام Windows 64-bit، وPHP 8.4+ مضاف إلى مسار النظام (PATH)، ومكتبات Composer، وتوفر محرك MySQL 8.4 المحمول داخل مجلد المشروع: `.cache/mysql-8.4.13-winx64/bin/mysqld.exe`.
- **مسار بيانات MySQL الفعلي:** تُحفظ كافة بيانات التشغيل اليومي داخل المجلد المعزول وغير المتتبع في Git:
  `C:\Users\albna\Documents\minismarterp2\.cache\mysql-daily-data`
  وملف الإعداد المرتبط به:
  `C:\Users\albna\Documents\minismarterp2\.cache\my-daily.ini`
- **الفصل التام عن بيئات الاختبار:** بيئة التشغيل اليومي (منفذ 3306، قاعدة `smart_mini_erp`، مجلد `.cache/mysql-daily-data`) معزولة بالكامل ومستقلة عن قواعد الاختبار على منفذ 3307 (`smart_mini_erp_test` في `.cache/mysql-data` وقاعدة اختبار الحمل `smart_mini_erp_perf` في `.cache/mysql-perf-data`). لا تُستخدم قواعد الاختبار 3307 في التشغيل اليومي إطلاقاً.

#### إيقاف الخدمات | Stopping services
لإيقاف الخدمات الخلفية (MySQL على 3306 وLaravel على 8000) بشكل آمن:
- انقر نقراً مزدوجاً على مشغل الإيقاف: **[`stop-erp.cmd`](file:///c:/Users/albna/Documents/minismarterp2/stop-erp.cmd)**
- أو نفّذ الأمر يدوياً في PowerShell:
  ```powershell
  Stop-Process -Name mysqld, php -Force -ErrorAction SilentlyContinue
  ```
- أو عبر موجه أوامر Windows (CMD):
  ```cmd
  taskkill /F /IM mysqld.exe /IM php.exe
  ```

#### النسخ الاحتياطي | Backup procedure
لحفظ نسخة احتياطية من قاعدة بيانات التشغيل اليومي:
1. **طريقة تفريغ SQL (أثناء عمل الخادم):**
   ```cmd
   .cache\mysql-8.4.13-winx64\bin\mysqldump.exe -h127.0.0.1 -P3306 -usmarterp smart_mini_erp > backup_smart_mini_erp.sql
   ```
2. **النسخ الاحتياطي الفيزيائي للمجلد (يشترط إيقاف MySQL بشكل سليم أولاً):**
   > [!WARNING]
   > يُحظر نسخ مجلد البيانات `.cache/mysql-daily-data` أثناء تشغيل خادم MySQL؛ حيث يؤدي النسخ الساخن غير المتزامن إلى تلف قاعدة البيانات وعدم اتساق ملفات InnoDB Redo Log ومخازن التراجع. **يشترط إيقاف خدمات MySQL بشكل سليم وكامل أولاً** عبر تشغيل [`stop-erp.cmd`](file:///c:/Users/albna/Documents/minismarterp2/stop-erp.cmd) والتحقق من إغلاق العمليات قبل أخذ النسخة الفيزيائية.

#### معالجة تعذر الاتصال والأخطاء | Troubleshooting connection issues
- **المنفذ 3306 أو 8000 مشغول:** إذا كان هناك برنامج آخر يستخدم المنفذ، سيعرض السكربت رسالة خطأ صريحة. أوقف التطبيق المتعارض أو حرر المنفذ عبر إنهاء العملية المالكة له.
- **غياب امتداد `pdo_mysql`:** يقوم ملف `scripts/php.ps1` و`scripts/setup.php` بتوليد ملف ضبط محلي تلقائياً (`extensions.ini`) لتحميل الامتداد دون تعديل PHP العام. تأكد من وجود مجلد `ext` بجانب `php.exe`.
- **سلوك المشغل عند الفشل:** إذا فشل تشغيل MySQL أو تعذر اتصال قاعدة البيانات أو فشل Laravel، يُظهر السكربت سبب الفشل باللون الأحمر ويتوقف دون فتح المتصفح على صفحة خطأ.
- **ملاحظة التحقق الفعلي:** استجابة صفحة `/login` برمز 200 وحدها لا تثبت اتصال قاعدة البيانات؛ يتحقق سكربت التشغيل من استعلام قاعدة البيانات الفعلي وصحة الاتصال قبل إقرار الجاهزية.

---

### 2. التجهيز والتشغيل اليدوي للمطورين | Manual setup for developers

إذا كنت تفضل الإعداد والتشغيل اليدوي من سطر الأوامر:
ثبّت **PHP 8.4+ وComposer وMySQL 8.4+** أولًا، وأضف PHP وComposer إلى PATH وشغّل خدمة MySQL. بعد تنزيل المستودع، نفّذ داخل PowerShell:

```powershell
.\setup.cmd
```

الأمر يثبت المكتبات، وينشئ `.env` عند غيابه، ويسألك عن اتصال MySQL. أدخل حساب MySQL؛ لا توجد كلمة مرور افتراضية. ينشئ الأمر القاعدة عند غيابها إذا كان الحساب يملك صلاحية `CREATE DATABASE`.

بعد ذلك يُولّد مفتاح التطبيق `APP_KEY` عند غيابه ويُنشئ الجداول والصلاحيات والمستودع الأساسي، ثم يطلب اسم المدير وبريده وكلمة مروره (12 حرفاً على الأقل تشمل أرقاماً وحروفاً كبيرة وصغيرة). كلمات المرور مخفية ولا تُحفظ في ملفات نصية مكشوفة.

للتشغيل اليدوي بعد التجهيز:
```powershell
composer dev
```
افتح [صفحة الدخول](http://127.0.0.1:8000/login). أوقف الخادم بـ Ctrl+C.

لتعديل الاتصال المحفوظ:
```powershell
php scripts/setup.php install --configure
```

---

## الصلاحيات | Access rules

- دور واحد لكل حساب: الأدوار الافتراضية هي Administrator، Purchasing، Sales، Inventory.
- يشمل النظام 31 صلاحية محكمة منها `reports.sales/purchases/inventory`، والمشتريات، وإدارة وعرض الأسعار، والمبيعات. استلام المشتريات يتطلب `purchases.receive` مع `inventory.post`. اعتماد المبيعات يتطلب `sales.post` مع `inventory.post`.
- الوصول للوحة التحكم (`/dashboard`) لا يمنح بيانات التقارير تلقائياً؛ كل بطاقة تقرير محكومة بصلاحيتها الخاصة.
- يجب بقاء مدير نظام نشط واحد على الأقل. تحديث بيانات الحساب يُبطل الجلسات القديمة للمستخدم لدواعي الأمان.
- الحسابات تُعطّل ولا تُحذف.
- نقطة نهاية تسجيل الدخول محمية بحدود معدل الطلبات (Rate Limiting) ورموز CSRF.

---

## الاختبارات والتحقق البرمجي | Verification and testing

### 1. الفحوصات الآلية القياسية | Standard automated tests

```powershell
./scripts/php.ps1 artisan test
./scripts/php.ps1 vendor/bin/pint --test
composer validate --strict --no-check-publish
composer audit --locked
```

تعتمد حزمة الاختبارات الآلية الأساسية على قاعدة بيانات SQLite معزولة في الذاكرة لضمان السرعة وعزل الاختبارات، مع وجود حارس إقلاع يرفض أي تداخل مع قواعد MySQL أثناء تشغيلها.

### 2. فحوصات التزامن والتكامل على MySQL الحقيقي | Real MySQL checks

```powershell
./scripts/php.ps1 tests/MySql/stock-concurrency.php
./scripts/php.ps1 tests/MySql/stock-snapshot.php
./scripts/php.ps1 tests/MySql/purchase-concurrency.php
./scripts/php.ps1 tests/MySql/sales-concurrency.php
./scripts/php.ps1 tests/MySql/workflow-reports.php
./scripts/php.ps1 tests/MySql/install.php
./scripts/php.ps1 artisan erp:check-stock
```

تستخدم هذه السكربتات قاعدة بيانات MySQL وتتحقق من استقرار التزامن والحركات النقدية والمخزنية بدقة 6 منازل عشرية دون استخدام أرقام عائمة.

### 3. اختبار الحمل المحلي (20 مستخدماً) وإخلاء المسؤولية | Local load benchmark & disclaimer

تم تنفيذ وتوثيق قياس أداء محلي تصاعدي ومستقر لـ 20 مستخدماً متزامناً عبر أداة k6 وNginx وخادم MySQL 8.4 معزول على منفذ 3307. النتائج كاملة ومفصلة في:
[`docs/audit/evidence/load-test/round2-steady/README.md`](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/load-test/round2-steady/README.md)

**النتائج المحققة محلياً:**
- نسبة نجاح 100% عبر 9,554 طلباً دون أي فشل HTTP أو أخطاء حرجة.
- صفر استعلامات بطيئة (أقل من 0.5 ثانية).
- تطابق كامل في دفتر الأستاذ والمخزون بنسبة خطأ 0% عبر `erp:check-stock`.

> [!IMPORTANT]
> **إخلاء مسؤولية مهم حول أداء الخوادم السحابية وسيرفرات العملاء:**  
> تم تنفيذ اختبار الحمل المشار إليه أعلاه على جهاز محلي مخصص للاختبار بمواصفات محددة (معالج AMD Ryzen 7 7735HS بـ 8 أنوية و16 مساراً، وذاكرة 16 جيجابايت، ونظام Windows 11، وأقراص NVMe محلية). هذه الأرقام والمقاييس **تخص هذا الجهاز المحلي حصراً في ظروف الاختبار المعزولة**، ولا تمثل بأي حال من الأحوال ضماناً أو تعهداً تعاقدياً بمستوى الأداء أو زمن الاستجابة على خادم العميل المستقبلي؛ حيث تختلف بيئات الإنتاج في مواصفات المعالجات، ومستويات المحاكاة الافتراضية (Virtualization)، وسرعات الشبكة والكمون، وسعة الذاكرة، وعدد المستأجرين المتزامنين (Multi-tenancy).

---

## لوحة الأعمال والتقارير | Business dashboard and reports

تعرض صفحة `/dashboard` مجالات التقارير المصرح بها للحساب فقط. الفترات الزمنية الافتراضية هي من أول يوم في الشهر التجاري الحالي وحتى اليوم بالتوقيت المحلي المعتمد للمخزون (`Asia/Aden` افتراضياً).

تقارير المخزون تعكس الأرصدة الحالية في المستودع الرئيسي، وتُظهر تنبيهات النقص (Sellable <= Reorder Level) وتنبيهات الصلاحية (Near Expiry و Expired). لا يتم تعديل أي رصيد مخزني من خلال شاشات العرض أو التقارير.

---

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

## النشر في الإنتاج | Production deployment

للنشر المستقبلي في بيئة الإنتاج:
- إعداد جذر خادم الويب على المجلد `public/`.
- استخدام PHP 8.4+ مع تفعيل `pdo_mysql` و OPcache.
- تفعيل بروتوكول HTTPS وضبط `APP_ENV=production` و `APP_DEBUG=false` و `SESSION_SECURE_COOKIE=true`.
- حماية ملف `.env` والحفاظ على ثبات `APP_KEY`.
- قصر صلاحيات الكتابة لخادم الويب على مجلدي `storage/` و `bootstrap/cache/`.
- استخدام خادم قاعدة بيانات MySQL مستقل وتفعيل النسخ الاحتياطي الدوري.
- لا يتطلب النظام مشغلات طوابير (Queues) أو Redis أو خدمات AI في نطاق هذه المرحلة.

راجع وثائق المراحل التفصيلية:
[Phase 1](docs/phase-1.md) | [Phase 2](docs/phase-2.md) | [Phase 3](docs/phase-3.md) | [Phase 4](docs/phase-4.md) | [Phase 5](docs/phase-5.md) | [Phase 6](docs/phase-6.md).
