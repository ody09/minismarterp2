# توثيق وتحليل خطة الاستعلام (EXPLAIN) على محرك MySQL 8.4 (PERF-03)

**تاريخ التوثيق:** 10 نوفمبر 2026  
**محرك قاعدة البيانات:** MySQL Community Server `8.4.13` (معزول على المنفذ `3307`)  
**قاعدة البيانات:** `smart_mini_erp_test`  
**الملفات الخام:**
- [docs/audit/evidence/mysql-explain/purchase_orders_explain.json](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/mysql-explain/purchase_orders_explain.json)
- [docs/audit/evidence/mysql-explain/sales_orders_explain.json](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/mysql-explain/sales_orders_explain.json)

---

## 1. الاستعلامات الفعلية المفحوصة
الاستعلامات المستخرجة من خدمة التقارير المالية [app/Services/BusinessReports.php:70-90](file:///c:/Users/albna/Documents/minismarterp2/app/Services/BusinessReports.php#L70-L90):

### أ. استعلام أوامر الشراء المستلمة:
```sql
SELECT id, total_amount 
FROM purchase_orders 
WHERE status = 'received' 
  AND received_at >= '2026-11-01 00:00:00' 
  AND received_at < '2026-11-30 23:59:59' 
ORDER BY received_at DESC, id DESC;
```

### ب. استعلام أوامر البيع المؤكدة:
```sql
SELECT id, total_amount 
FROM sales_orders 
WHERE status = 'confirmed' 
  AND confirmed_at >= '2026-11-01 00:00:00' 
  AND confirmed_at < '2026-11-30 23:59:59' 
ORDER BY confirmed_at DESC, id DESC;
```

---

## 2. بيانات الجداول والبيئة اللحظية للفحص
- **حجم جدول `purchase_orders`:** 3 صفوف (جميعها بحالة `received`).
- **حجم جدول `sales_orders`:** 0 صفوف لحظة الفحص.
- **الفهارس الموجودة مسبقاً في الجدولين:**
  - `PRIMARY KEY (id)`
  - فهارس المفاتيح الفريدة (`creation_key`, `receipt_key`, `confirmation_key`, `stock_operation_id`)
  - فهرس التاريخ التجاري: `order_date_index (order_date)`
  - فهرس الحالة: `status_index (status)`
  - **ملاحظة:** الحقول `received_at` و `confirmed_at` غير مفهرسة بمفردها أو ضمن فهرس مركب.

---

## 3. نتائج خطة التنفيذ (EXPLAIN Analysis)

### جدول `purchase_orders`:
- **نوع الوصول (access_type):** `ALL` (مسح كامل للجدول).
- **المفاتيح المتاحة (possible_keys):** `purchase_orders_status_index`.
- **المفتاح المستخدم (key):** `NULL`.
- **الصفوف المقدر فحصها (rows_examined_per_scan):** 3 صفوف.
- **النسبة المتبقية بعد الفلترة (filtered):** 33.33%.
- **العمليات الإضافية (Extra):** `Using where; Using filesort`.

### جدول `sales_orders`:
- **نوع الوصول (access_type):** `ref`.
- **المفاتيح المتاحة (possible_keys):** `sales_orders_status_index`.
- **المفتاح المستخدم (key):** `sales_orders_status_index` (طول المفتاح: 1).
- **الصفوف المقدر فحصها (rows_examined_per_scan):** 1 صف.
- **العمليات الإضافية (Extra):** `Using where; Using filesort`.

---

## 4. التحليل التقني وتفسير سلوك مُحسّن استعلامات MySQL 8.4

1. **سبب اختيار `type: ALL` في جدول المشتريات:**
   - يتبع محرك MySQL نظام التكلفة الحسابية (`Cost-based Optimizer`). عندما يكون عدد الصفوف في الجدول صغيراً جداً (3 صفوف فقط)، تكون كلفة قراءة الصفحات كاملة من الذاكرة أقل بكثير من كلفة البحث في شجرة الفهرس ثم الرجوع لصفحات الجدول (`read_cost: 1.20`).
2. **ظهور `Using filesort` في كلا الاستعلامين:**
   - يرجع ذلك إلى أن شرط الفرز هو `ORDER BY received_at DESC, id DESC` و `ORDER BY confirmed_at DESC, id DESC`. بما أن حقول التواريخ غير مفهرسة مع حقل `status`، لا يمكن لمحرك MySQL استرجاع الصفوف مرتبة مسبقاً من الفهرس، مما يضطره لإجراء ترتيب في الذاكرة (`filesort`).
3. **محدودية الاستنتاج على البيانات الكبيرة (Scale Limitation):**
   - سلوك محرك MySQL 8.4 على جدول يحتوي على 3 صفوف أو جدول فارغ **لا يصلح كدليل رياضي** للجزم بسلوك المحرك عند وجود 50,000 أو 100,000 صف. في الجداول الضخمة، قد يرجح المحرك استخدام الفهرس أو يتحول للمسح الكامل بحسب نسبة توزيع الحالات (`selectivity`).

---

## 5. القرار الفني المعتمد للبند PERF-03

- **الحكم:** **«غير محسوم» (Inconclusive)**.
- **السبب:** البيانات المتوفرة في بيئة الاختبار الحالية محدودة جداً (3 صفوف فقط)، ومحرك MySQL يغير خطة التنفيذ جذرياً بناءً على إحصائيات حجم الجدول وتوزع الحالات. لا يمكن إثبات وجود أزمة أداء أو الجزم بغيابها دون بيانات بالحجم التشغيلي المستهدف.
- **الالتزام:** تم الحفاظ التام على ملفات الترحيل وقاعدة البيانات دون إضافة أي فهارس في هذه الجولة.
