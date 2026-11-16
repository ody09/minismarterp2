# ملحق تصحيح وتدقيق تقرير المراجعة الشاملة لنظام Smart Mini ERP

**تاريخ الملحق:** 10 نوفمبر 2026  
**المرجع البرمجي (Git Commit):** `fe7d3e45ebf89472ccaabcf5065824259e9230d3`  
**الهدف:** تصحيح وتدقيق الأدلة والاستنتاجات الواردة في تقرير 9 نوفمبر 2026، وإزالة التناقضات والادعاءات غير المطابقة لواقع المستودع، دون تعديل كود التطبيق أو تحديث الحزم البرمجية.

---

## 1. جدول إعادة تصنيف البنود وتحديث الأحكام الفنية

| المعرف | التصنيف في تقرير 9 نوفمبر | التصنيف المصحح بعد المراجعة | الحالة | ملخص سبب التعديل والدليل المباشر |
| :--- | :--- | :--- | :---: | :--- |
| **SEC-01** | عيب أمني عالي (ثغرات DoS في Symfony 7.2.0) | تحذيرات مكتبات خارجية مؤمنة (عولجت بالترقية إلى Symfony 8.1.11) | **معالج ومغلق (Resolved)** | تمت الترقية المحدودة للحزم الثلاث (`http-foundation`, `http-kernel`, `routing`) إلى `v8.1.11`. نتيجة `composer audit` أصبحت 0 تحذيرات برمز خروج 0. التقرير: [`sec-01-update-report.md`](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/packages/sec-01-update/sec-01-update-report.md). |
| **LOGIC-01** | عيب واجهة ومنطق (متوسط) | خطأ في مفتاح ترجمة الواجهة (عولج بإضافة واستخدام sales.confirm_cancel) | **معالج ومغلق (Resolved)** | أُضيف مفتاح `confirm_cancel` لملفي `sales.php` بالعربية والإنجليزية يذكر أمر البيع بوضوح. عُدلت واجهة `sales/show.blade.php:14`. ثُبت بنجاح الاختبار التراجعي. |
| **LOGIC-02** | عيب تحقق (منخفض) | غياب قيد تراتبية التواريخ في الفلتر (عولج بقاعدة after_or_equal:from) | **معالج ومغلق (Resolved)** | أُضيفت قاعدة `Rule::when($request->filled('from'), ['after_or_equal:from'])` لحقل `to` في `InventoryController.php:44`. تم رفض النطاق المعكوس عبر الويب وJSON (422) وتأكيد حدود التوقيت المحلي لليوم. |
| **LOGIC-03** | عيب منطق وتجربة مستخدم (منخفض) | تحسين تنقل ومسار المستخدم | **قرار منتج / تحسين تنقل** | `redirect()->back()` لا يحفظ مدخلات النماذج غير المحفوظة في المتصفح. المشكلة هي فقدان المسار والفلاتر الحالية بالانتقال الإجباري لصفحة الهبوط. |
| **DATA-01** | عيب سلامة بيانات (منخفض) | سلوك غير مقيد بمتطلب صريح | **قرار منتج** | لا يوجد في وثائق المتطلبات ما يمنع ربط منتج جديد بتصنيف معطل. التصنيفات والمنتجات تملك حقول تنشيط مستقلة حسب طبيعة التهيئة. |
| **SEC-02** | عيب أمني في الجلسات (منخفض) | سياسة أمان موثقة ومعتمدة للنظام | **مستبعد كخلل (سياسة موثقة)** | `README.md:86` يوثق صراحة أن أي تعديل للحساب يُبطل الجلسات السابقة للمستخدم (`Account updates revoke old sessions`). هذا السلوك مقصود معمارياً. |
| **PERF-01** | عيب أداء (N+1 في القائمة) | استعلامات متكررة في القالب (15-27 استعلاماً للقائمة الجانبية) | **معالج ومغلق (Resolved)** | أُنشئ View Composer خفيف (`App\View\Composers\NavigationComposer`) يجلب صلاحيات الدور دفعة واحدة للمستخدم الحالي والطلب الحالي باستعلام وحيد (Budget = 1). انخفضت استعلامات القائمة من 15 إلى 1 للمدير، ومن 27 إلى 1 للمستخدم المحدود (وإجمالي استعلامات `/account` من 17 و29 إلى 3 استعلامات). حُفظت حدود Gate وUser::hasPermission دون أي تعديل. |
| **PERF-02** | استعلام ميت ثقيل في التقارير (منخفض) | كود زائد في منشئ الاستعلام داخل الذاكرة | **مستبعد كعيب أداء بقاعدة البيانات** | السطر 137 في `BusinessReports.php` يبني شرطاً على كائن Builder في الذاكرة دون تنفيذ أي استعلام SQL. الاستبدال في سطر 146 يلغي الكائن دون أي عبء على الخادم. |
| **PERF-03** | غياب فهارس يسبب مسحاً كاملاً (Full Scan) | غير محسوم (عدم كفاية بيانات الاختبار لتعميم الأداء) | **غير محسوم** | تم فحص خطة `EXPLAIN` على MySQL 8.4، والجدول يحتوي 3 صفوف فقط؛ لا يمكن استنتاج أداء الجداول الضخمة من عينة صغيرة. الفهرس المركب تحسين مقترح. الدليل: [mysql_explain_analysis.md](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/mysql-explain/mysql_explain_analysis.md) |
| **CODE-01** | تكرار دوال الحساب العشرية (منخفض) | عدم الالتزام بمبدأ DRY في خدمة المشتريات | **معالج ومغلق (Resolved)** | استُبدلت دالتا `micros` و`decimal` المكررتان في `PurchaseOrders.php` بالاعتماد المباشر على `DecimalAmount::price` و`DecimalAmount::format`، وحُذفت الدالتان. حُفظت قواعد التحقق ومفاتيح الأخطاء وحدود المبالغ وربط أخطاء التجاوز بسعر السطر `items.*.unit_price`، وتطبيع الأسعار وبصمة الطلب ومنع تكرار الإنشاء عند إعادة الطلب بأسعار متكافئة. |
| **I18N-01** | عيب تعريب وتدويل (منخفض) | غياب ملف تخصيص محلي للحقول بالإنجليزية | **مستبعد كخلل وظيفي** | الاختبار الفعلي أثبت أن Laravel يوفر رسائل تحقق إنجليزية افتراضية سليمة ونحوية بالكامل. غياب الملف يحرم فقط من تخصيص مسميات الحقول يدوياً. |

---

## 2. تفصيل التحقق الفني والأدلة المباشرة

### أ. التحقق من البند SEC-01 وإصدارات الحزم الفعلية وسلوك طلبات HEAD
1. **قراءة `composer.lock`:**
   - الحزم المعنية مثبتة فعلياً بالإصدار **`v8.1.10`** (وليس `v7.2.0` كما زُعم سابقاً).
   - بصمة الملف SHA256: `0147037A2519DA2E35DFAB670A9787C0C3C132C978F86B5F3874662C3850E586`.
2. **نتائج فحص `composer audit --locked --format=json`:**
   - **تاريخ التنفيذ:** 2026-11-10 الساعة 09:57:43 بالتوقيت المحلي (+03:00).
   - **رمز الخروج:** `1`.
   - المخرجات الخام محفوظة بالكامل في [`docs/audit/evidence/packages/composer-audit-raw.json`](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/packages/composer-audit-raw.json).
   - التحليل الشامل للنشرات الرسمية وإثبات عدم قابلية الاستغلال منشور في [`docs/audit/evidence/packages/composer-audit-analysis.md`](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/packages/composer-audit-analysis.md).
3. **تصحيح دليل أمان طلبات HEAD مقابل GET:**
   - تم سحب الادعاء العام بأن إطار Laravel يرفض دائماً طلبات HEAD برمز 405.
   - الاختبار العملي عبر الخادم المثبت في [`docs/audit/evidence/packages/head_vs_get_comparison.json`](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/packages/head_vs_get_comparison.json) أثبت ما يلي:
     - مسارات GET تقبل طلبات HEAD وتنفذ كافة طبقات الحماية والـ Middleware كاملة، وتعيد نفس رمز الحالة (302 لغير المسجل، 403 لغير المصرح، 200 للمصرح) مع تجريد نص الاستجابة.
     - مسارات POST الصرفة فقط تعيد HTTP 405 Method Not Allowed مع ترويسة `Allow: POST`.
     - لم يثبت وجود أي قابلية استغلال عملية لتجاوز الصلاحيات أو تجاوز قواعد الأمان في هذا التطبيق عبر طلبات HEAD.

---

### ب. تصحيح تصنيفات SEC-02 و DATA-01 و LOGIC-03 و I18N-01
1. **البند SEC-02 (إبطال الجلسات عند تحديث الحساب):**
   - نص `README.md` بالسطر 86 يوثق صراحة أن أي تعديل للحساب يُبطل الجلسات السابقة للمستخدم (`Account updates revoke old sessions`). هذا السلوك مقصود معمارياً وسياسة أمان معتمدة.
2. **البند DATA-01 (ربط منتج بتصنيف غير نشط):**
   - لا يوجد في وثائق المتطلبات ما يمنع ربط منتج جديد بتصنيف معطل؛ التصنيف الصحيح للبند هو **قرار منتج**.
3. **البند LOGIC-03 (إعادة توجيه مبدل اللغة):**
   - `redirect()->back()` لا يحفظ مدخلات النماذج غير المحفوظة في المتصفح، والمشكلة تنحصر في تحسين مسار المستخدم وتجنب تصفير الفلاتر.
4. **البند I18N-01 (غياب ملف ترجمة التحقق بالإنجليزية):**
   - يوفر إطار Laravel ترجمات افتراضية دقيقة بالإنجليزية لكافة الحقول. غياب ملف محلي ليس عيباً معطلاً.

---

### ج. تصحيح تحليل الأداء (PERF-01, PERF-02, PERF-03)
1. **البند PERF-02 (الاستعلام الميت المزعوم):**
   - السطر 137 في `BusinessReports.php` يبني شرطاً على كائن Builder في ذاكرة PHP فقط دون استدعاء أي دالة تنفيذية، ثم يتم التخلص منه في سطر 146 دون إرسال أي حرف SQL إلى الخادم.
2. **البند PERF-03 (فهارس تواريخ التقارير على محرك MySQL 8.4):**
   - تم فحص خطة `EXPLAIN` على محرك MySQL Community Server `8.4.13`.
   - نظراً لأن جدول الاختبار لا يحتوي سوى 3 صفوف، قرر المحرك عمل مسح كامل لجدول المشتريات لكونه أرخص تكلفة من قراءة الفهرس.
   - **الحكم النهائي:** البند **«غير محسوم» (Inconclusive)** لعدم كفاية حجم بيانات الاختبار لاستنتاج سلوك الجداول الكبيرة. الأدلة محفوظة في [`docs/audit/evidence/mysql-explain/`](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/mysql-explain/).
3. **البند PERF-01 (استعلامات الصلاحيات المتكررة في القائمة الجانبية — معالج ومغلق):**
   - **القياس والتفكيك الدقيق قبل التعديل (`GET /account`):**
     - تم تفكيك استعلامات جدول `role_permissions` بدقة: استعلامان اثنان (2) يخصان جسم صفحة الحساب حصراً في `partials/account-access.blade.php` (استعلام عد الصلاحيات `count(*)` واستعلام جلب عناصر الصلاحيات `pivot_role_id`).
     - استعلامات القائمة الجانبية في `layouts/app.blade.php` بلغت:
       - **حساب المدير (Admin):** 15 استعلاماً (1 لرابط الشعار عبر `landingRouteName()` و14 استعلاماً فردياً عبر `@can`). الإجمالي للصفحة: 17 استعلاماً.
       - **المستخدم محدود الصلاحيات (Limited User - مثل المشتريات):** 27 استعلاماً (بسبب تقييم الفروع البديلة `@else @can` وفحص الصلاحيات الثلاث للتقارير في `@canany`). الإجمالي للصفحة: 29 استعلاماً.
   - **التنفيذ المعماري المقيد:**
     - بناء طبقة عرض مستقلة خفيفة `App\View\Composers\NavigationComposer` مسجلة في `AppServiceProvider` لـ `layouts.app`.
     - جلب صلاحيات المستخدم الحالي دفعة واحدة عبر استعلام وحيد أثناء الطلب (`$user->role->permissions()->pluck('code')->all()`) وتحويلها إلى مصفوفة بحث سريعة في الذاكرة.
     - تحديد رابط الشعار (`brand_route`) وقائمة الروابط (`items`) بحالات التحديد والترجمة والروابط البديلة (مثل مسارات الإضافة عند امتلاك `manage` دون `view`) مسبقاً قبل تصيير القالب، وتبسيط `layouts/app.blade.php` لتكرار العناصر الجاهزة دون أي `@can`.
     - **الحفاظ التام على حدود الأمان:** البيانات مقيدة بالطلب الحالي والمستخدم الحالي دون أي كاش ثابت (`static`) أو جلسة (`session`) أو Redis. لم يُمس `User::hasPermission` أو بوابات `Gate` أو Middleware المسارات أو `StockContext` أو `AccessManager` إطلاقاً.
   - **القياس بعد التعديل (`GET /account`):**
     - **استعلامات القائمة الجانبية:** استعلام وحيد فقط (1) لكل من المدير والمستخدم المحدود (انخفاض بنسبة 93.3% للمدير و96.3% للمستخدم المحدود).
     - **استعلامات جسم الصفحة:** استعلامان (2) كما هما دون تغيير.
     - **إجمالي استعلامات `role_permissions` في الطلب:** 3 استعلامات (انخفاض من 17 إلى 3 للمدير، ومن 29 إلى 3 للمستخدم المحدود).

---

### د. تدقيق دورة المتصفح الكاملة والأدلة الصارمة

أُعيد بناء وتطوير سكربت دورة الأعمال الكاملة [`docs/audit/evidence/browser/run_full_cycle_audit.mjs`](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/browser/run_full_cycle_audit.mjs) وفق أعلى معايير الصرامة البرمجية:

1. **التحقق الصارم عبر Assertions الدقيقة:**
   - منع استخدام البحث النصي السطحي في كود الصفحة HTML؛ والاعتماد حصراً على قراءة شارات الحالة (`.page-heading .pill`)، ومبالغ الإجماليات المحددة، وخلايا الجداول التفصيلية.
   - إلزام العملية بالخروج برمز غير صفري (`exit code 1`) وكتابة `status: "FAILED"` في ملف الأدلة عند أي إخفاق.

2. **التقاط وقائع HTTP الحقيقية وتفكيك مسار إعادة التوجيه:**
   - تسجيل مستمعات الاستجابة (`page.on('response')`) مسبقاً قبل الضغط على أزرار الإرسال.
   - تفكيك وتوثيق طلبات POST وإعادتها لرمز `302 Found` مع ترويسة `Location`، ثم طلب GET التالي ورمزه `200 OK`.
   - إلغاء أي قيم 200 مكتوبة يدوياً في السكربت.

3. **اختبار FEFO الدقيق وتقسيم المنتج ألف إلى دفعتين:**
   - **أمر الشراء:**
     - منتج ألف - السطر 1: 1 كرتون (12 قطعة) بسعر 10.000000 للكرتون، تاريخ انتهاء: اليوم + 60 يوماً (`BATCH-A1`).
     - منتج ألف - السطر 2: 1 كرتون (12 قطعة) بسعر 10.000000 للكرتون، تاريخ انتهاء: اليوم + 180 يوماً (`BATCH-A2`).
     - منتج باء - سطر تجريبي يتم حذفه ديناميكياً لإثبات عمل زر الحذف.
     - منتج باء - السطر 3: 3 كراتين (18 قطعة) بسعر 20.000000 للكرتون، تاريخ انتهاء: اليوم + 90 يوماً (`BATCH-B1`).
     - إجمالي أمر الشراء المحسوب مستقلاً والمطابق تماماً: `80.000000` و `42` قطعة.
   - **قاعدة التسعير:** إنشاء شريحة سعر خاصة بالعميل لمنتج ألف (10 قطع فأكثر بسعر 12.000000 للقطعة).
   - **أمر البيع:**
     - منتج ألف: 15 قطعة بسعر الشريحة 12.000000 = `180.000000`.
     - منتج باء: 1 كرتون بسعر الكتالوج 28.000000 للقطعة × 6 = `168.000000`.
     - إجمالي أمر البيع المحسوب مستقلاً والمطابق تماماً: `348.000000` و `21` قطعة.
   - **تخصيصات FEFO الفعلية عند الاعتماد (مسجلة ومطابقة في جدول توزيع الدفعات):**
     - السطر 1: صرف 12 قطعة بالكامل من الدفعة الأولى الأقرب انتهاءً (`BATCH-A1`) بكمية `-12`.
     - السطر 1: صرف باقي الكمية المطلوبة 3 قطع من الدفعة الثانية (`BATCH-A2`) بكمية `-3`.
     - السطر 2: صرف 6 قطع (كرتون واحد) من دفعة منتج باء (`BATCH-B1`) بكمية `-6`.
   - **الأرصدة المتبقية الفعلية:**
     - منتج ألف: رصيد فعلي 9، ومتاح 9. نفاد دفعة `BATCH-A1` بالكامل (0)، والمتبقي في دفعة `BATCH-A2` هو 9 قطع.
     - منتج باء: رصيد فعلي 12، ومتاح 12. المتبقي في دفعة `BATCH-B1` هو 12 قطعة.

4. **فحص ومطابقة التقارير المالية والمخزنية:**
   - تقرير المشتريات `/reports/purchases?supplier_id=...`: مبلغ `80.000000`، عدد 1، كمية `42` قطعة.
   - تقرير المبيعات `/reports/sales?customer_id=...`: مبلغ `348.000000`، عدد 1، كمية `21` قطعة.
   - تقرير المخزون `/reports/inventory?product_id=...`: مطابقة الأرصدة الفعلية والمتاحة وأرصدة الدفعات الموجبة.
   - لقطات الشاشة موثقة بأسماء متطابقة بالعربية والإنجليزية (15 لقطة شاشة في [`docs/audit/evidence/screenshots/`](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/screenshots/)).

5. **إثبات الفشل المضبوط تحت السيطرة (Controlled Failure Proof):**
   - تم تشغيل الفحص بوضع الإخفاق العمدي `--fail-intentionally` مع توقع خاطئ لإجمالي أمر البيع (999.000000 مقابل 348.000000 الفعلي).
   - رصدت بيئة الفحص الخطأ فوراً، وأطلقت `AssertionError [ERR_ASSERTION]: SO total mismatch: expected 999.000000, got 348.000000`.
   - خرجت العملية برمز غير صفري (`exit code 1`).
   - حُفظ الدليل في [`docs/audit/evidence/browser/controlled_failure_results.json`](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/browser/controlled_failure_results.json) مع تسجيل `status: "FAILED"`.
   - ثم تم تشغيل الفحص الحقيقي الكامل دون خيار الإخفاق، ونجحت جميع الفحوص، وخرجت العملية برمز `0` وحُفظت النتائج في [`docs/audit/evidence/browser/full_cycle_audit_results.json`](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/browser/full_cycle_audit_results.json) مع تسجيل `status: "COMPLETED_SUCCESSFULLY"`.

6. **إدارة الأسرار وعدم تسريب كلمات المرور:**
   - توفير سكربت الإعداد الدائم [`docs/audit/evidence/browser/setup_audit_env.php`](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/browser/setup_audit_env.php) الذي يولد كلمات مرور قوية ويحفظها في ملف الكاش غير المتتبع `.cache/audit_env.json`.
   - حذف كافة كلمات المرور المكتوبة يدوياً من سكربتات الاختبار ومستندات التدقيق.

---

## 3. تصنيف وتوصيف اختبارات `AuditFindingsTest.php`

الاختبارات في [tests/Feature/AuditFindingsTest.php](file:///c:/Users/albna/Documents/minismarterp2/tests/Feature/AuditFindingsTest.php) تم تحديثها وتطويرها لتشمل اختبارات تراجعية واختبارات ميزانية استعلامات صارمة:
1. **حزمة اختبارات PERF-01 (ميزانية استعلامات وصلاحيات القائمة الجانبية):**
   - `test_navigation_sidebar_executes_single_batch_permission_query_budget`: يثبت الالتزام بميزانية استعلام وحيد (1) لصلاحيات القائمة الجانبية مفصولاً عن استعلامي جسم الصفحة لصفحة الحساب.
   - `test_navigation_sidebar_renders_expected_links_for_admin`: يثبت ظهور كافة الروابط المستحقة للمدير وتوجيه الشعار إلى لوحة التحكم.
   - `test_navigation_sidebar_renders_only_authorized_links_for_limited_user`: يثبت قصر روابط القائمة على الصلاحيات الممنوحة فقط لمسؤول المشتريات وتوجيه الشعار للحساب وغياب كافة روابط الوحدات غير المصرح بها.
   - `test_navigation_sidebar_renders_create_or_edit_fallback_when_manage_without_view`: يثبت توجيه الروابط إلى صفحات الإضافة أو التعديل كبديل عند منح الإدارة دون العرض (مثل `users.create` و `warehouses.edit`).
   - `test_navigation_sidebar_renders_reports_link_for_single_report_permission`: يثبت ظهور رابط التقارير عند امتلاك تقرير منفرد (`reports.inventory`) واختفائه التام عند سحب الصلاحية.
   - `test_navigation_sidebar_reflects_immediate_permission_revocation_and_route_returns_403`: يثبت الاستجابة اللحظية لسحب الصلاحية باختفاء الرابط فوراً ورفض المسار الحي بـ 403 Forbidden.
   - `test_navigation_sidebar_prevents_cross_user_state_leakage`: يثبت انعدام أي تسريب لبيانات القائمة بين مستخدمين متتاليين في نفس المعالج.
   - `test_navigation_sidebar_preparation_does_not_bypass_or_alter_subsequent_gate_checks`: يثبت أن تجهيز القائمة لا يعطل أو يتجاوز فحوصات `Gate` و Middleware التالية إطلاقاً.
2. `test_inventory_transactions_rejects_inverted_date_range_with_validation_error_on_to_field` و `test_inventory_transactions_accepts_valid_date_ranges_and_enforces_timezone_boundary`:
   - تحولا إلى **اختبارين تراجعيين (Regression Tests)** للسلوك الصحيح بعد معالجة LOGIC-02.
   - يثبتان رفض `from > to` عبر طلبات الويب (إعادة توجيه مع خطأ جلسة للحقل `to`) وعبر طلبات JSON (رمز 422 مع أخطاء تحقق).
   - يثبتان قبول التباديل الصحيحة وحساب حدود اليوم بدقة في التوقيت المحلي (`Asia/Aden` = UTC+3) بشمول نهاية اليوم واستبعاد اليوم التالي.
3. `test_locale_switch_redirects_to_landing_route_instead_of_previous_url`: يثبت إعادة التوجيه لصفحة الهبوط.
4. `test_sales_cancellation_confirmation_dialog_uses_sales_key_with_explicit_order_reference`:
   - تحول إلى **اختبار تراجعي (Regression Test)** للسلوك الصحيح بعد معالجة LOGIC-01.
   - يثبت استخدام مفتاح `sales.confirm_cancel` وظهور نص صريح يذكر أمر البيع بالعربية والإنجليزية مع منع ظهور نص المشتريات أو المفاتيح غير المحلولة.
5. `test_english_validation_file_is_missing`: يثبت غياب ملف الترجمة المحلي للتحقق.
6. `test_deactivated_category_can_be_assigned_to_new_product`: يثبت إمكانية ربط منتج بتصنيف معطل (قرار منتج).
7. `test_cosmetic_name_change_increments_auth_version`: يثبت تطابق زيادة إصدار المصادقة مع سياسة النظام الموثقة.

---

## 4. مصفوفة التغطية المحدثة وحالة التحقق المعتمدة

| الوظيفة / النطاق | حالة التحقق | الدليل المباشر المحفوظ | ما بقي غير متحقق / حدود الفحص |
| :--- | :---: | :--- | :--- |
| **استعلامات الصلاحيات في القائمة (PERF-01)** | **معالج ومغلق 100%** | [`NavigationComposer.php`](file:///c:/Users/albna/Documents/minismarterp2/app/View/Composers/NavigationComposer.php)، تقليص استعلامات القائمة إلى استعلام واحد (Query Budget = 1). 8 اختبارات تراجعية في [`AuditFindingsTest.php`](file:///c:/Users/albna/Documents/minismarterp2/tests/Feature/AuditFindingsTest.php) تشمل فحص Gate اللحظي وسحب الصلاحية. | التعديل شمل طبقة العرض للقائمة الجانبية فقط؛ كود Gate ونموذج User لم يُمس لمنع أي مخاطر أمنية. |
| **إزالة تكرار التحويل العشري (CODE-01)** | **معالج ومغلق 100%** | [`PurchaseOrders.php`](file:///c:/Users/albna/Documents/minismarterp2/app/Services/PurchaseOrders.php)، حذف `micros` و`decimal` واستخدام `DecimalAmount::price` و`DecimalAmount::format`، واختبار تطبيع الأسعار المتكافئة في [`PurchaseOrdersTest.php`](file:///c:/Users/albna/Documents/minismarterp2/tests/Feature/PurchaseOrdersTest.php). | التعديل اقتصر على التحويل والتنسيق وحافظ على دقة 6 منازل وحدود المبالغ وربط أخطاء التجاوز بسعر السطر. |
| **دورة الأعمال الكاملة عبر المتصفح** | **متحقق ومثبت 100%** | [`run_full_cycle_audit.mjs`](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/browser/run_full_cycle_audit.mjs)، نتائج [`full_cycle_audit_results.json`](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/browser/full_cycle_audit_results.json)، إثبات الفشل [`controlled_failure_results.json`](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/browser/controlled_failure_results.json)، و15 لقطة شاشة في [`screenshots/`](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/screenshots/). | فُحصت على جلسة متصفح محلية؛ لا تشمل تذبذب الشبكة أو انقطاع الاتصال أثناء الإرسال. |
| **مطابقة دفتر الأستاذ والمخزون** | **متحقق ومثبت** | `artisan erp:check-stock` أرجع مطابقة تامة: `Stock balances match the movement ledger. No quantities were changed.` | الفحص يغطي الحركات المولدة محلياً؛ لا يشمل تسويات فروقات الجرد اليدوي (خارج النطاق). |
| **صلاحيات RBAC وحدود 403** | **متحقق ومثبت** | رفض وصول مسؤول المشتريات لـ `/sales` (403)، ورفض وصول مسؤول المبيعات لـ `/purchases` (403). رفض تاريخ البيع المستقبلي. | فُحصت الصلاحيات الأساسية للأدوار المجهزة؛ لم تفحص صلاحيات مستودعات متعددة. |
| **تدقيق الحزم الأمنية (SEC-01) وسلوك HEAD** | **معالج ومغلق 100%** | ترقية حزم Symfony إلى `v8.1.11` بدون أي تحذيرات متبقية (Exit Code: 0). تقرير الترقية: [`sec-01-update-report.md`](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/packages/sec-01-update/sec-01-update-report.md). الأدلة السابقة: [`composer-audit-raw.json`](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/packages/composer-audit-raw.json)، [`head_vs_get_comparison.json`](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/packages/head_vs_get_comparison.json). | أُغلقت كافة التحذيرات الست رسمياً. لا توجد أي تحذيرات أمنية متبقية في `composer audit`. |
| **أداء الاستعلامات والفهارس (PERF-03)** | **غير محسوم** | ملفات EXPLAIN في [`docs/audit/evidence/mysql-explain/`](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/mysql-explain/). | **غير محسوم** بسبب قلة حجم البيانات في جدول الاختبار (3 صفوف فقط)؛ يتطلب إعادة تقييم عند توفر بيانات بأحجام إنتاجية (10,000+ صف). |
| **اختبارات الأحمال والضغط العالي (Load Testing)** | **غير مغطى (خارج النطاق)** | لا توجد أدلة ضغط موزعة. | خارج نطاق جولة التدقيق المكتبي الحالية. |

---
**نهاية ملحق التصحيح والتدقيق المعتمد.**
