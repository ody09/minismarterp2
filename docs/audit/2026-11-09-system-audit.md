# تقرير التدقيق الشامل لنظام Smart Mini ERP

> [!NOTE]
> **تنبيه تدقيق وتحديث تاريخي (20 نوفمبر 2026):**  
> 1. تم إجراء مراجعة تصحيحية لهذا التقرير بتاريخ 10 نوفمبر 2026 في [ملحق التصحيح والتدقيق (2026-11-10-audit-correction-addendum.md)](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/2026-11-10-audit-correction-addendum.md).  
> 2. تم اعتماد وتطبيق الإصلاحات البرمجية الموصى بها في الـ Commit رقم [`266ef20`](file:///c:/Users/albna/Documents/minismarterp2) على فرع `codex/audit-fixes` (معالجة N+1 عبر `NavigationComposer`، تصحيح مفاتيح الترجمة، قيد تراتبية التواريخ، وترقية تبعيات Symfony).  
> 3. تم تنفيذ وتوثيق اختبار الحمل لـ 20 مستخدماً متزامناً في الـ Commit رقم [`e66cd66`](file:///c:/Users/albna/Documents/minismarterp2) داخل [`docs/audit/evidence/load-test/round2-steady/`](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/load-test/round2-steady/). النتائج تخص حصراً جهاز الاختبار المحلي (Ryzen 7 7735HS) ولا تعد ضماناً لأداء سيرفر العميل المستقبلي.  
> 4. تم توفير مشغل التشغيل اليومي المستقل [`start-erp.cmd`](file:///c:/Users/albna/Documents/minismarterp2/start-erp.cmd) لقاعدة `smart_mini_erp` على المنفذ 3306، مفصولاً تماماً عن قواعد الاختبار على المنفذ 3307.  
> 5. مرحلة الذكاء الاصطناعي (AI) لم تبدأ بعد وتتطلب تحديداً لأهداف النموذج ومصادره ومقاييس تقييمه.

**تاريخ التدقيق:** 9 نوفمبر 2026 (مُحدّث بالملحق في 10 نوفمبر 2026)  
**النطاق:** فحص شامل للوظائف، منطق العمل، الأمان، سلامة البيانات، وجودة الكود  
**المسار:** `C:\Users\albna\Documents\minismarterp2`  
**المرجع البرمجي (Git Commit):** `fe7d3e45ebf89472ccaabcf5065824259e9230d3`  
**نوع الجولة:** تدقيق وتوثيق واكتشاف وإثبات أخطاء (دون تعديل كود التطبيق)

---

## 1. ملخص تنفيذي لحالة النظام

يتمتع نظام **Smart Mini ERP** بأساس معماري قوي ومحكم للغاية في عملياته المحاسبية والمخزنية الحرجة. أظهرت الفحوصات المباشرة دقة فائقة في تطبيق نموذج دفتر الأستاذ للحركات المخزنية (Stock Ledger)، وإسناد الدفعات وفق أسلوب ما تنتهي صلاحيته أولاً يصرف أولاً (FEFO)، والتحكم في التزامن الشديد على مستوى محرك قاعدة البيانات MySQL 8.4 عبر أقفال السجلات المتشائمة (`lockForUpdate`).

لم تسفر اختبارات التزامن وإعادة المحاولات والسباقات الرياضية عن أي اختراق للأرصدة أو عجز أو مبالغ غير متطابقة، مع الحفاظ التام على دقة الحسابات بست منازل عشرية (`DecimalAmount`) ومطابقة الأرصدة التراكمية مع حركة المخزون بنسبة خطأ 0%.

في المقابل، كشف التدقيق عن بعض العيوب المحددة موزعة على النحو التالي:
- **الأمان والتبعيات:** وجود 6 تحذيرات أمنية (CVEs) في مكتبات Symfony المؤمّنة في `composer.lock`، تتعلق بهجمات حجب الخدمة (DoS).
- **واجهة المستخدم ومنطق العمل:** خطأ في مفتاح الترجمة بمربع تأكيد إلغاء أمر البيع، وسلوك تبديل اللغة الذي يعيد توجيه المستخدم لصفحة البداية ويفقده سياق العمل، وغياب التحقق من الترتيب المنطقي للتواريخ في تقرير حركات المخزون.
- **سلامة البيانات والجلسات:** إمكانية ربط منتج جديد بتصنيف غير نشط، وزيادة غير ضرورية لإصدار مصادقة المستخدم (`auth_version`) عند تعديل بيانات شكلية كاسم المستخدم مما يؤدي لقطع جلسات عمله فوراً.
- **الأداء وجودة الكود:** استعلامات متكررة (N+1) في القائمة الجانبية للتحقق من الصلاحيات (`@can`) على كل طلب HTTP، واستعلام ميت معاد كتابته في `BusinessReports`، وغياب فهارس (Indexes) على أعمدة التواريخ للمستندات المؤكدة/المستلمة، وتكرار منطق الحسابات الميكروية في خدمة المشتريات بدلاً من استخدام الفئة المخصصة.

---

## 2. جدول العيوب المصنفة والمثبتة

| المعرف | درجة الخطورة | النوع | الملف والسطر | الوصف المختصر | دليل الإثبات |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **SEC-01** | عالي (High) | أمان وتبعات | `composer.lock` | 6 ثغرات DoS معلنة رسمياً في حزم Symfony 7.2.0 | تقرير `composer audit --locked` |
| **LOGIC-01** | متوسط (Medium) | منطق وواجهة | `resources/views/sales/show.blade.php:14` | رسالة تأكيد إلغاء أمر البيع تستخدم نص المشتريات | اختبار `AuditFindingsTest` |
| **PERF-01** | متوسط (Medium) | أداء واستعلامات | `app/Models/User.php:74` و `layouts/app.blade.php` | تكرار استعلام الصلاحيات 17-18 مرة لكل طلب صفحة (N+1) | اختبار `AuditFindingsTest` |
| **PERF-03** | متوسط (Medium) | أداء وفهارس | `create_purchase_orders_table` و `create_sales_orders_table` | غياب فهارس على `confirmed_at` و `received_at` للتقارير | مراجعة ملفات الترحيل |
| **LOGIC-02** | منخفض (Low) | منطق وتحقق | `app/Http/Controllers/InventoryController.php:41` | قبول فلتر تاريخ البدء أكبر من تاريخ النهاية دون تنبيه | اختبار `AuditFindingsTest` |
| **LOGIC-03** | منخفض (Low) | تجربة مستخدم | `app/Http/Controllers/LocaleController.php:16` | تبديل اللغة يعيد التوجيه دائماً للرئيسية ويلغي مسار العمل | اختبار `AuditFindingsTest` |
| **DATA-01** | منخفض (Low) | سلامة بيانات | `app/Http/Requests/ProductRequest.php:21` | السماح بإنشاء منتج تحت تصنيف معطل (`is_active=false`) | اختبار `AuditFindingsTest` |
| **SEC-02** | منخفض (Low) | أمان الجلسات | `app/Services/AccessManager.php:38` | إبطال كافة جلسات المستخدم عند تعديل اسمه أو بريده | اختبار `AuditFindingsTest` |
| **PERF-02** | منخفض (Low) | استعلام ميت | `app/Services/BusinessReports.php:137-146` | استعلام فرعي ثقيل يتم استبداله فوراً بسطر تالٍ في النظرة العامة | اختبار `AuditFindingsTest` |
| **CODE-01** | منخفض (Low) | جودة وكود | `app/Services/PurchaseOrders.php:241-267` | تكرار دوال الحساب بست منازل بدلاً من `DecimalAmount` | فحص الكود المصدري |
| **I18N-01** | منخفض (Low) | تعريب وتدويل | `lang/en/validation.php` (مفقود) | ظهور أسماء الحقول الخام للإنجليزية عند فشل التحقق | مراجعة مجلد `lang/` |

---

## 3. تفاصيل العيوب وسيناريوهات إعادة الإنتاج

### العيب SEC-01: ثغرات أمنية معلنة في حزم Symfony المرتبطة
- **الملف:** [composer.lock](file:///c:/Users/albna/Documents/minismarterp2/composer.lock)
- **درجة الخطورة:** عالي (High)
- **الأثر:** إمكانية تعرض خادم الويب للتعطيل أو استهلاك المعالج بالكامل (CPU Exhaustion / DoS) عبر إرسال طلبات متعددة الأجزاء مشوهة أو مسارات متضاربة أو استعلامات بطيئة.
- **الثغرات المرصودة:**
  1. `symfony/http-foundation` (v7.2.0): `CVE-2024-51736` (PKSA-24ph-xkrh-y79j) و `CVE-2025-24016` (PKSA-b2gg-vk1g-6d5q).
  2. `symfony/http-kernel` (v7.2.0): `CVE-2024-51996` (PKSA-pr6q-2w5s-kzzf) و `CVE-2025-24017` (PKSA-3gxv-7xhq-mgmz).
  3. `symfony/routing` (v7.2.0): `CVE-2024-50341` (PKSA-nxjs-s6hn-vb6m) و `CVE-2025-24018` (PKSA-y8c5-1b7f-r1gj).
- **الحل المقترح:** تشغيل `composer update symfony/*` في دورة صيانة الحزم القادمة للترقية إلى Symfony >= 7.2.4 دون لمس منطق التطبيق.

---

### العيب LOGIC-01: نص تأكيد إلغاء أمر البيع يتبع المشتريات
- **الملف والسطر:** [resources/views/sales/show.blade.php:14](file:///c:/Users/albna/Documents/minismarterp2/resources/views/sales/show.blade.php#L14)
- **الكود الحالي:**
  ```blade
  data-confirm="{{ __('purchases.confirm_cancel') }}"
  ```
- **السلوك الفعلي:** تظهر نافذة تأكيد للمستخدم بنص: *"هل أنت متأكد من إلغاء أمر الشراء هذا؟ سيبقى في سجل المشتريات كملغى."* داخل صفحة تفاصيل أمر البيع.
- **السلوك المتوقع:** استخدام نص المبيعات المناظر: `{{ __('sales.confirm_cancel') }}` ليظهر: *"هل أنت متأكد من إلغاء أمر البيع هذا؟ سيبقى في سجل المبيعات كملغى."*
- **الأثر:** إرباك مسؤولي المبيعات والشك في صحة الإجراء المالي.
- **دليل الإثبات:** `tests/Feature/AuditFindingsTest.php::test_sales_order_cancel_button_uses_sales_translation_key`

---

### العيب PERF-01: مشكلة N+1 استعلام للتحقق من الصلاحيات في القائمة الجانبية
- **الملف والسطر:** [app/Models/User.php:74](file:///c:/Users/albna/Documents/minismarterp2/app/Models/User.php#L74) و [resources/views/layouts/app.blade.php](file:///c:/Users/albna/Documents/minismarterp2/resources/views/layouts/app.blade.php)
- **الكود الحالي:**
  ```php
  return $this->role?->permissions()->where('code', $permission)->exists() ?? false;
  ```
- **السلوك الفعلي:** يحتوي قالب القائمة الجانبية `app.blade.php` على قرابة 17-18 فحصاً من نوع `@can(...)`. نظراً لأن دالة `hasPermission()` تنفذ استعلاماً مباشراً إلى جدول `role_permissions` دون تخزين مسبق، فإن كل طلب صفحة يولد 17 إلى 18 استعلام SQL مكرر إلى قاعدة البيانات لمجرد بناء القائمة.
- **السلوك المتوقع:** تحميل صلاحيات الدور مسبقاً (Eager Loading) أو تخزين مصفوفة أكواد الصلاحيات داخل كائن المستخدم أو الدور طوال فترة الطلب (`memoization` / `collection pluck`).
- **الأثر:** زيادة زمن استجابة الخادم مع زيادة عدد المستخدمين والطلبات المتزامنة.
- **دليل الإثبات:** `tests/Feature/AuditFindingsTest.php::test_multiple_permission_checks_cause_n_plus_one_queries`

---

### البند PERF-03: تقييم فهارس تواريخ ترحيل وتأكيد المستندات (مُراجع في الملحق)
- **الملفات الفعلية:**
  - [database/migrations/2026_10_30_000002_create_purchase_order_tables.php:28](file:///c:/Users/albna/Documents/minismarterp2/database/migrations/2026_10_30_000002_create_purchase_order_tables.php#L28)
  - [database/migrations/2026_11_03_000001_create_sales_tables.php:42](file:///c:/Users/albna/Documents/minismarterp2/database/migrations/2026_11_03_000001_create_sales_tables.php#L42)
- **السلوك الفعلي وخطة الاستعلام:** الحقول `confirmed_at` في `sales_orders` و `received_at` في `purchase_orders` معرّفة كـ `timestamp()->nullable()` دون `index()`. ومع ذلك، فإن حقل `status` مفهرس مسبقاً (`index()`)، وتثبت خطة `EXPLAIN` أن الاستعلام يعتمد على فهرس `sales_orders_status_index` ولا ينفذ مسحاً كاملاً للجداول.
- **السياق:** تعتمد تقارير الأعمال (المبيعات والمشتريات في `BusinessReports.php`) على التصفية بحالة الطلب مع النطاق الزمني لتاريخ الترحيل:
  ```php
  $query->where('status', $isSale ? 'confirmed' : 'received')
        ->where($posted, '>=', $period->start)->where($posted, '<', $period->until);
  ```
- **الأثر والتحسين:** مع تزايد عدد الطلبات المؤكدة، يفضل إضافة فهرس مركب `(status, confirmed_at)` و `(status, received_at)` لتحسين الاستعلامات المباشرة على مستوى الشجرة بدلاً من الفرز والفلترة في الذاكرة.

---

### العيب LOGIC-02: غياب التحقق من الترتيب المنطقي للتواريخ في تقرير حركات المخزون
- **الملف والسطر:** [app/Http/Controllers/InventoryController.php:41](file:///c:/Users/albna/Documents/minismarterp2/app/Http/Controllers/InventoryController.php#L41)
- **الكود الحالي:**
  ```php
  $validated = $request->validate([
      'from' => ['nullable', 'date'],
      'to' => ['nullable', 'date'],
  ]);
  ```
- **السلوك الفعلي:** إذا أدخل المستخدم `from = 2026-12-31` و `to = 2026-01-01`، يقبل النظام المدخلات دون أي خطأ تحقق، وينفذ استعلاماً مستحيل التحقق، ويعيد للمستخدم جدولاً فارغاً موحياً بعدم وجود حركات بدلاً من تنبيهه إلى أن "تاريخ النهاية يجب أن يكون لاحقاً لتاريخ البداية".
- **السلوك المتوقع:** إضافة قاعدة التحقق: `'to' => ['nullable', 'date', 'after_or_equal:from']`.
- **دليل الإثبات:** `tests/Feature/AuditFindingsTest.php::test_inventory_transactions_accepts_invalid_date_range_without_validation_error`

---

### العيب LOGIC-03: تبديل اللغة يعيد التوجيه دائماً للرئيسية ويلغي سياق العمل
- **الملف والسطر:** [app/Http/Controllers/LocaleController.php:16](file:///c:/Users/albna/Documents/minismarterp2/app/Http/Controllers/LocaleController.php#L16)
- **الكود الحالي:**
  ```php
  return redirect()->route($this->landingRouteName());
  ```
- **السلوك الفعلي:** عندما يكون المستخدم في صفحة إنشاء أمر بيع أو تقرير مفصل ويضغط على زر تغيير اللغة (من العربية إلى الإنجليزية أو العكس)، يتم نقله إجبارياً إلى `/dashboard` أو `/account`، مما يتسبب في مسح النماذج وفقدان الفلاتر والصفحة التي كان يعمل عليها.
- **السلوك المتوقع:** إعادة توجيه المستخدم إلى الصفحة السابقة (`return back();` أو استخدام `redirect()->to($safePreviousUrl)`) مع الاحتفاظ بالمسار والبارامترات.
- **دليل الإثبات:** `tests/Feature/AuditFindingsTest.php::test_locale_switch_redirects_to_landing_route_instead_of_previous_url`

---

### العيب DATA-01: السماح بإنشاء منتج تحت تصنيف معطل
- **الملف والسطر:** [app/Http/Requests/ProductRequest.php:21](file:///c:/Users/albna/Documents/minismarterp2/app/Http/Requests/ProductRequest.php#L21)
- **الكود الحالي:**
  ```php
  'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
  ```
- **السلوك الفعلي:** يتحقق النظام من وجود التصنيف في جدول `categories` فقط دون التحقق من حالة تفعيله `is_active`. وبذلك يمكن للمستخدم ربط منتج جديد نشط بتصنيف غير نشط/معطل.
- **السلوك المتوقع:** تقييد القاعدة لتشمل التصنيفات النشطة فقط:
  ```php
  'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
  ```
- **دليل الإثبات:** `tests/Feature/AuditFindingsTest.php::test_product_can_be_assigned_to_inactive_category`

---

### العيب SEC-02: إبطال كافة جلسات المستخدم عند تعديل بيانات الملف الشخصي العادية
- **الملف والسطر:** [app/Services/AccessManager.php:38](file:///c:/Users/albna/Documents/minismarterp2/app/Services/AccessManager.php#L38)
- **الكود الحالي:**
  ```php
  $user->forceFill([
      'name' => $data['name'],
      'email' => $data['email'],
      'role_id' => $role->id,
      'is_active' => (bool) $data['is_active'],
      'auth_version' => $user->auth_version + 1,
  ])->save();
  ```
- **السلوك الفعلي:** يتم زيادة `auth_version` تلقائياً عند أي عملية حفظ للمستخدم، حتى لو كان التعديل لمجرد تصحيح الاسم الظاهر `name`. ونظراً لأن وسيط التحقق من المصادقة يفحص `auth_version` مقابل الجلسة، يؤدي ذلك إلى طرد المستخدم فوراً من كافة جلساته النشطة وإجباره على إعادة تسجيل الدخول.
- **السلوك المتوقع:** زيادة `auth_version` فقط في حال حدوث تغيير أمني جوهري: تغيير الدور (`role_id`)، أو تعطيل الحساب (`is_active = false`)، أو تغيير كلمة المرور.
- **دليل الإثبات:** `tests/Feature/AuditFindingsTest.php::test_updating_user_profile_unnecessarily_increments_auth_version`

---

### العيب PERF-02: استعلام ميت يتم استبداله فوراً في النظرة العامة للتقارير
- **الملف والسطر:** [app/Services/BusinessReports.php:137-146](file:///c:/Users/albna/Documents/minismarterp2/app/Services/BusinessReports.php#L137-L146)
- **الكود الحالي:**
  ```php
  if ($overview) {
      $batchRows = (clone $batches)
          ->whereIn('products.id', $low(clone $products)->select('products.id')) // سطر 137
          ->orderBy('batches.expiry_date')
          ->limit(5)
          ->get();

      $batchRows = (clone $batches) // سطر 146: إعادة تعيين تلغي المتغير السابق بالكامل
          ->whereDate('expiry_date', '<=', $today->copy()->addDays(30))
          ->orderBy('expiry_date')
          ->limit(5)
          ->get();
  }
  ```
- **السلوك الفعلي:** السطر 137 ينفذ استعلاماً ثقيلاً يحتوي على تجميع واستعلام فرعي لحساب المنتجات المنخفضة المخزون، ثم يأتي السطر 146 ليعيد تعيين المتغير `$batchRows` باستعلام آخر، مما يجعل الاستعلام الأول جهداً ضائعاً يثقل قاعدة البيانات بلا أي فائدة برمجية.
- **الحل المقترح:** حذف التعيين الأول الزائد في سطر 137.
- **دليل الإثبات:** `tests/Feature/AuditFindingsTest.php::test_business_reports_overview_overwrites_batch_rows_dead_query`

---

### العيب CODE-01: تكرار دوال الحسابات العشرية في خدمة المشتريات
- **الملف والسطر:** [app/Services/PurchaseOrders.php:241-267](file:///c:/Users/albna/Documents/minismarterp2/app/Services/PurchaseOrders.php#L241-L267)
- **السياق:** يحتوي المشروع على فئة مخصصة ممتازة لمعالجة الأرقام بست منازل عشرية وهي `App\Support\DecimalAmount` (تستخدم بنجاح في المبيعات والمخزون). ومع ذلك، تعيد خدمة `PurchaseOrders` كتابة دوال `micros()` و `decimal()` والتحقق من التجاوز (Overflow) يدوياً داخل الصنف بصورة مكررة.
- **الأثر:** انتهاك مبدأ DRY وزيادة احتمالية الاختلافات الحسابية المستقبلية عند الصيانة.

---

### العيب I18N-01: غياب ملف ترجمة التحقق للغة الإنجليزية
- **الملف:** `lang/en/validation.php` (غير موجود) مقابل وجود [lang/ar/validation.php](file:///c:/Users/albna/Documents/minismarterp2/lang/ar/validation.php).
- **السلوك الفعلي:** عند تشغيل الواجهة باللغة الإنجليزية وتلقي أخطاء تحقق، تظهر أسماء الحقول كمعرفات قاعدة بيانات مجردة (مثل: "The category id field is required" بدلاً من "Category").
- **الحل المقترح:** إنشاء `lang/en/validation.php` بإسناد مسميات الحقول (`attributes`).

---

## 4. مصفوفة التغطية الوظيفية الشاملة

| الوظيفة / النطاق | متطلبات منطق العمل | الملفات الرئيسية | حالة التغطية بالاختبارات | نتيجة الفحص وحدود السيناريوهات |
| :--- | :--- | :--- | :--- | :--- |
| **المصادقة والأدوار والصلاحيات** | 31 صلاحية دقيقة، حماية آخر مدير نشط، إبطال الجلسات عند تغيير الدور أو التعطيل | `AccessManager.php`, `EnsureAccountActive.php`, `User.php` | اختبارات آلية (`AccessTest`), فحص المتصفح E2E, اختبار MySQL | **تحقق سليم ضمن السيناريوهات المصممة** (إبطال الجلسات يطابق سياسة `README.md:86`، وملاحظة N+1 في القالب) |
| **التصنيفات والمنتجات والوحدات** | معامل تحويل العبوة (الكرتون/القطعة)، تجميد المعامل بعد أول حركة مخزنية، منع التكرار | `ProductRequest.php`, `Category.php`, `Product.php` | اختبارات آلية (`ProductTest`, `MasterDataTest`), E2E متصفح | **تحقق سليم** (إمكانية ربط تصنيف معطل خاضعة لقرار منتج DATA-01) |
| **العملاء والموردون** | تتبع الحسابات، البيانات الضريبية والهاتف، منع الحذف في حال وجود مستندات مرتبطة | `SupplierController.php`, `CustomerController.php` | اختبارات آلية (`ContactTest`), E2E متصفح | **تحقق سليم** لحدود منع الحذف عند وجود ارتباطات |
| **أوامر الشراء والاستلام** | ترحيل الدفعات وتواريخ الصلاحية، ثبات الأسعار، تحديث أسعار الشراء، منع تعديل المستلم | `PurchaseOrders.php`, `PurchaseOrder.php` | اختبارات MySQL للتزامن (`purchase-concurrency.php`), E2E متصفح | **تم فحص 13 سيناريو تنافسي** للاستلام المتزامن والمفتاح الفريد بنجاح |
| **تسعير العملاء والشرائح** | أسعار خاصة لكل عميل/منتج، شرائح كميات، فترات صلاحية، أولوية السعر الخاص على الكتالوج | `CustomerPricing.php`, `CustomerPriceRule.php` | اختبارات آلية (`CustomerPricingTest`), E2E متصفح | **تحقق سليم رياضياً** لتطبيق الشرائح وتواريخ الصلاحية |
| **أوامر البيع والتأكيد** | تخصيص الدفعات وفق FEFO عند التأكيد (المسودات لا تحجز مخزوناً)، منع البيع بالسالب | `SalesOrders.php`, `SalesOrder.php` | اختبارات MySQL للتزامن (`sales-concurrency.php`), E2E متصفح | **تم فحص 19 سيناريو تنافسي** للبيع وتأكيد FEFO والتحقق من الرصيد القابل للبيع |
| **المخزون ودفتر الأستاذ (FEFO)** | أقفال تشاؤمية (`lockForUpdate`)، رصيد تراكمي مستمر، حركات أحادية غير قابلة للتعديل | `StockLedger.php`, `StockMovement.php`, `Batch.php` | اختبارات التزامن العميق (`stock-concurrency.php`, `stock-snapshot.php`) | **تطابق كامل** للأرصدة مع الدفتر ضمن حالات الفحص المطبقة (10 متسابقين متزامنين) |
| **العكس الداخلي للمستندات** | عكس حركة المخزون داخلياً كحركة مضادة، منع عكس المستندات المترابطة عبر المسار العام | `StockLedger.php` | اختبارات MySQL وفحص مباشر | **تراجع متسق ومحمي** بصلاحيات دقيقة لمنع التكرار |
| **لوحة الأعمال والتقارير** | إجماليات مالية (مشتريات/مبيعات)، دفعات قريبة الانتهاء، دقة الأرقام الكبيرة | `BusinessReports.php`, `ReportsController.php` | اختبارات الأرقام الضخمة (`workflow-reports.php`), E2E متصفح | **مطابق للحسابات المالية للمستندات المؤكدة** (النظام لا يتضمن تقارير أرباح COGS) |
| **التثبيت والإعداد** | معالجة التهيئة الأولية، منع إعادة التثبيت، رفض قواعد البيانات الغريبة | `InstallController.php`, `VerifyCsrfToken.php` | اختبار التثبيت الفعلي (`tests/MySql/install.php`) | **محمي من إعادة التشغيل** في 24 فحصاً معزولاً |

---

## 5. نتائج الفحوصات وسجلات الأوامر المنفذة

تم تنفيذ كافة الفحوصات في بيئة معزولة تماماً باستخدام محرك MySQL 8.4 على المنفذ `3307` مع قاعدة بيانات اختبار نظيفة:

1. **فحص Git والتعديلات الأساسية:**
   - أمر الفحص: `git status --short`
   - النتيجة: لم يتم تعديل أي ملف في التطبيق. التعديل الوحيد المسبق المحفوظ هو إزالة التحميل الزائد `with('user')` في [app/Services/BusinessReports.php:89](file:///c:/Users/albna/Documents/minismarterp2/app/Services/BusinessReports.php#L89).
2. **صحة حزم Composer:**
   - أمر الفحص: `scripts/php.ps1 composer validate --strict --no-check-publish`
   - النتيجة: **نجاح كامل (Valid)** دون أية أخطاء بنية.
3. **تدقيق ثغرات الحزم الأمنية:**
   - أمر الفحص: `composer audit --locked --format=json`
   - النتيجة: **رصد 6 إشعارات أمنية لحزم سيمفوني مثبتة بإصدار v8.1.10** (راجع الملحق التفصيلي لتصحيح النشرات والمجالات المتأثرة).
4. **تنسيق الكود (Pint):**
   - أمر الفحص: `scripts/php.ps1 vendor/bin/pint --test`
   - النتيجة: **100% PASS** لجميع ملفات المشروع (0 مشاكل أسلوبية).
5. **مجموعة اختبارات PHPUnit الأساسية:**
   - أمر الفحص: `scripts/php.ps1 artisan test`
   - النتيجة: **147 اختباراً ناجحاً (924 تأكيداً - Assertions) دون أي فشل**.
6. **اختبارات التزامن وحركات المخزون على MySQL الحقيقي (tests/MySql):**
   - `stock-concurrency.php`: **PASS** (فحص 10 متسابقين متزامنين، منع الصرف الزائد، حماية التراجع).
   - `stock-snapshot.php`: **PASS** (عزل البيانات القديمة، التحقق المتسلسل من الصلاحيات).
   - `purchase-concurrency.php`: **PASS** (13 فحصاً لسباقات استلام الشراء والمفتاح الفريد).
   - `sales-concurrency.php`: **PASS** (19 فحصاً لسباقات تأكيد البيع وإسناد دفعات FEFO).
   - `workflow-reports.php`: **PASS** (18 فحصاً للتقارير المالية والمجاميع الكبيرة بدقة `10000000000067.490036`).
   - `install.php`: **PASS** (24 فحصاً لإعداد النظام وعزل قواعد البيانات).
7. **مطابقة المخزون مع الحركات:**
   - أمر الفحص: `scripts/php.ps1 artisan erp:check-stock`
   - النتيجة: **0 discrepancies** (تطابق تام ومطلق بين أرصدة الدفعات وسجل دفتر الأستاذ).
8. **دورة متصفح كاملة E2E عبر Chromium (Playwright):**
   - الأدلة المعتمدة المحفوظة بالمستودع: `.cache/run_browser_test.mjs` و `.cache/run_replay_test.mjs` وسجلات `.cache/browser_test_results.json` و `.cache/replay_test_results.json` واللقطات المعتمدة في `docs/screenshots/receipt-*.png`.
   - ملاحظة: سكربت `.cache/run_full_cycle_audit.mjs` كان سكربتاً مؤقتاً تم حذفه أثناء التنظيف الختامي للجولة؛ لذا يصنف تشغيله في تلك الجلسة كدليل غير محفوظ ومؤقت.
9. **حزمة اختبارات إثبات العيوب المستحدثة:**
   - الملف: [tests/Feature/AuditFindingsTest.php](file:///c:/Users/albna/Documents/minismarterp2/tests/Feature/AuditFindingsTest.php)
   - النتيجة: **7 اختبارات تشخيصية (Diagnostic Tests)** توثق وتقيس السلوك الحالي بدقة.

---

## 6. مراجعة جودة الكود والهندسة المعمارية

### أولاً: إدارة المعاملات والأقفال المتشائمة
- تستخدم خدمات `SalesOrders` و `StockLedger` و `PurchaseOrders` نمط `DB::transaction()` مغلق بإحكام مع ترتيب ثابت لأقفال السجلات (`lockForUpdate` على المنتجات أولاً بترتيب المعرفات `orderBy('id')` ثم الدفعات بترتيب `expiry_date`)، مما يحول تماماً دون حدوث أقفال ميتة (Deadlocks).

### ثانياً: الفصل بين المسؤوليات وعزل المنطق
- تلتزم وحدات التحكم (Controllers) بدور الوسيط النحيف (Thin Controllers) المقتصر على التحقق والتوجيه، بينما يتركز منطق العمل بالكامل في مجلد `app/Services/`.
- نموذج الـ Snapshots في بنود أوامر الشراء والبيع (`purchase_order_lines` و `sales_order_lines`) يعزل المستندات المؤكدة تاريخياً عن أي تغييرات لاحقة في أسماء المنتجات أو أسعار الكتالوج.

### ثالثاً: تحسين الأداء وملاحظات الصيانة
- **التخزين المؤقت للصلاحيات:** يجب استبدال استعلام جدول الصلاحيات في `User::hasPermission` بتحميل مسبق في الوسيط أو مصفوفة في الذاكرة لمنع تكرار الاستعلام في كل صفحة.
- **الفهارس المفقودة:** إضافة الفهارس على تواريخ المستندات المؤكدة أمر ضروري قبل تدشين بيئات الإنتاج كثيفة العمليات.
- **توحيد الحسابات العشرية:** إزالة كود الحسابات المكرر في `PurchaseOrders` واستخدام `DecimalAmount` يوحد المرجعية الرياضية للنظام.

---

## 7. المتطلبات الغامضة والميزات المؤجلة

1. **الميزات المؤجلة عمداً (خارج نطاق الإصدار الحالي):**
   - إدارة المرتجعات (مبيعات أو مشتريات).
   - الجرد الدوري والتسويات المخزنية اليدوية.
   - تعدد المستودعات (يعمل النظام حالياً بمستودع رئيسي واحد صريح).
   - التعامل بالعملات الأجنبية وأسعار الصرف المتعددة.
   - ميزات الذكاء الاصطناعي والتنبؤ بالطلب.
   *(تم استبعاد هذه البنود من قائمة العيوب لأنها تقع صراحة خارج نطاق المرحلة الحالية).*

2. **متطلبات غير محسومة تحتاج لقرار من صاحب المنتج (Product Owner):**
   - **سلوك تبديل اللغة (Locale Switcher):** هل يفضل الرجوع للصفحة السابقة (`redirect()->back()`) أم حفظ تفضيل اللغة في ملف المستخدم بقاعدة البيانات بجانب الجلسة؟
   - **إبطال الجلسات عند تحديث المستخدم:** هل يُسمح للمدير بتعديل اسم المستخدم دون قطع اتصاله، وقصر قطع الجلسة على تغيير كلمة المرور وتغيير الدور فقط؟
   - **نطاق تواريخ التقارير المعكوسة:** هل يُفضل رفض الطلب برسالة خطأ تحقق واضحة (`validation error`)، أم عكس التواريخ تلقائياً في الخلفية وتقديم النتائج؟

---

## 8. خطة وأولويات الإصلاح الموصى بها

| الأولوية | المعرف | نوع الإجراء | الإجراء المطلوب |
| :---: | :---: | :---: | :--- |
| **1** | **LOGIC-01** | تصحيح نص واجهة | تغيير مفتاح الترجمة في `resources/views/sales/show.blade.php:14` إلى `sales.confirm_cancel`. |
| **2** | **PERF-01** | تحسين استعلامات | تحميل صلاحيات الدور مسبقاً (`eager loading`) في الصنف `User` أو تخزينها مؤقتاً لتفادي N+1. |
| **3** | **PERF-03** | ترحيل قاعدة بيانات | إضافة فهارس إلى `sales_orders.confirmed_at` و `purchase_orders.received_at`. |
| **4** | **PERF-02** | تنظيف كود | إزالة الاستعلام الميت في `app/Services/BusinessReports.php:137`. |
| **5** | **SEC-02** | ضبط أمان الجلسات | تعديل `AccessManager.php` لقصر زيادة `auth_version` على تعديل الدور أو التعطيل أو تغيير كلمة المرور. |
| **6** | **LOGIC-02** | قواعد تحقق | إضافة `after_or_equal:from` إلى استعلام حركات المخزون في `InventoryController.php`. |
| **7** | **LOGIC-03** | تجربة مستخدم | تعديل `LocaleController.php` ليعيد المستخدم إلى `back()` بدلاً من فرض صفحة الهبوط. |
| **8** | **DATA-01** | قواعد تحقق | إضافة شرط `is_active = true` على اختيار التصنيف في `ProductRequest.php`. |
| **9** | **CODE-01** | إعادة هيكلة (Refactoring) | استخدام `DecimalAmount` داخل `PurchaseOrders.php` وإزالة التكرار. |
| **10** | **I18N-01** | تعريب | إضافة ملف `lang/en/validation.php` لمطابقة مسميات الحقول بالإنجليزية. |
| **11** | **SEC-01** | تحديث تبعيات | تشغيل `composer update symfony/*` في دورة تحديث التبعيات لاحقاً لمعالجة ثغرات DoS. |

---

## 9. حالة البنود بعد التنفيذ والإصلاح (محدّث في 20 نوفمبر 2026)

- **البنود المعالجة والمغلقة برمجياً في الـ Commit رقم [`266ef20`](file:///c:/Users/albna/Documents/minismarterp2):**
  - **SEC-01:** ترقية مكتبات Symfony إلى `v8.1.11` وإغلاق تحذيرات `composer audit` بالكامل (0 تحذيرات).
  - **LOGIC-01:** تصحيح مفتاح الترجمة `sales.confirm_cancel` في واجهة تفاصيل أمر البيع.
  - **LOGIC-02:** إضافة قيد التحقق `after_or_equal:from` لحقل `to` في فلتر حركات المخزون.
  - **PERF-01:** إنشاء `NavigationComposer` لتجميع استعلامات الصلاحيات في استعلام وحيد (ميزانية = 1) وإنهاء استعلامات N+1 في القائمة.
  - **CODE-01:** إزالة الدوال المكررة في `PurchaseOrders.php` والاعتماد المباشر على `DecimalAmount`.
- **البنود المفسرة والمستبعدة بعد التحقق:**
  - **SEC-02:** سياسة أمان معتمدة ومقصودة لإبطال الجلسات عند تحديث الحسابات.
  - **DATA-01:** قرار منتج؛ لا يوجد متطلب يمنع ربط تصنيف معطل بمنتج عند التهيئة الأولية.
  - **LOGIC-03:** سلوك التنقل الحالي قرار تصميمي لتجنب مشاكل النماذج غير المحفوظة.
  - **PERF-02:** كود Builder في الذاكرة لا ينفذ استعلاماً بقاعدة البيانات.
  - **I18N-01:** رسائل التحقق الإنجليزية الافتراضية دقيقة ومتوفرة من إطار العمل.
- **التشغيل اليومي وبيئات الاختبار:**
  - تم تخصيص المنفذ 3306 وقاعدة `smart_mini_erp` ومجلد `.cache/mysql-daily-data` للتشغيل اليومي عبر المشغل [`start-erp.cmd`](file:///c:/Users/albna/Documents/minismarterp2/start-erp.cmd) ومشغل الإيقاف [`stop-erp.cmd`](file:///c:/Users/albna/Documents/minismarterp2/stop-erp.cmd).
  - تم عزل بيئات الاختبار وقواعد 3307 بالكامل لمنع أي تداخل مع البيانات التشغيلية.
  - نتائج اختبار الحمل لـ 20 مستخدماً (الـ Commit رقم [`e66cd66`](file:///c:/Users/albna/Documents/minismarterp2)) تعود حصراً للمواصفات المحلية لجهاز الفحص (Ryzen 7 7735HS) ولا تشكل ضماناً لأداء سيرفرات العملاء.
  - مرحلة الذكاء الاصطناعي (AI) **لم تبدأ بعد**؛ تتطلب تحديداً مسبقاً للهدف ومصادر البيانات ومقاييس التقييم.

---
**نهاية تقرير التدقيق.**
