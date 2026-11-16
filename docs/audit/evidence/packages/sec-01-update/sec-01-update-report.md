# تقرير المعالجة الأمنية المحدودة للبند SEC-01

**تاريخ التحديث:** 10 نوفمبر 2026  
**نطاق التحديث:** معالجة تحذيرات الأمان الخارجية الخاصة بحزم Symfony عبر أصغر ترقية أمنية متوافقة فقط.  
**ملف القفل (`composer.lock`):**
- البصمة قبل التحديث (SHA256): `0147037A2519DA2E35DFAB670A9787C0C3C132C978F86B5F3874662C3850E586`
- البصمة بعد التحديث (SHA256): `13B5C7A989564DAD8BB9DBB04D180215FFDE8864B94A07E33BFEF7696ABC902D`
- حالة `composer.json`: لم يتغير إطلاقاً (مطابق تماماً لـ Git).

---

## 1. جدول الحزم المحدثة ومبررات التغيير

| الحزمة | الإصدار السابق | الإصدار المحدث | نطاق الإصدارات المتأثرة سابقاً | النشرات الأمنية المغلقة بالتحديث | سبب ومبرر التحديث |
| :--- | :---: | :---: | :---: | :--- | :--- |
| **`symfony/http-foundation`** | `v8.1.10` | **`v8.1.11`** | `<8.1.11` | 1. CVE-2026-118514 (`PKSA-24ph-xkrh-y79j`)<br>2. GHSA-3h27-hjv7-fqjx (`PKSA-pr6q-2w5s-kzzf`)<br>3. GHSA-xg2q-4rrq-657q (`PKSA-nxjs-s6hn-vb6m`)<br>4. GHSA-5mx8-mwrp-5693 (`PKSA-b2gg-vk1g-6d5q`) | معالجة الثغرات الأمنية المعلنة رسمياً في 4 نوفمبر 2026 دون تجاوز قيود المنصة أو تغيير تبعيات أخرى. |
| **`symfony/http-kernel`** | `v8.1.10` | **`v8.1.11`** | `<8.1.11` | 5. CVE-2026-123339 (`PKSA-3gxv-7xhq-mgmz`) | إغلاق إمكانية تأثير ترويسات التوجيه غير الموثوقة على الروابط المولدة للأجزاء الداخلية. |
| **`symfony/routing`** | `v8.1.10` | **`v8.1.11`** | `<8.1.11` | 6. CVE-2026-118516 (`PKSA-y8c5-1b7f-r1gj`) | إغلاق ثغرة مطابقة متغيرات المضيف مع بداية المسار في الموجهات المترجمة. |

> [!NOTE]
> لم يتم تثبيت أو ترقية أو إزالة أي حزمة أخرى (Zero extra packages). الترقية اقتصرت حصراً على التحديث الفرعي (Patch version `8.1.10 => 8.1.11`) المتوافق مع متطلبات بيئة PHP 8.4 وإطار Laravel.

---

## 2. نتائج التدقيق الأمني (Composer Audit) قبل وبعد التحديث

| المرحلة | الأمر المنفذ | رمز الخروج | عدد التحذيرات | مخرجات الفحص |
| :--- | :--- | :---: | :---: | :--- |
| **قبل التحديث** | `composer audit --locked --format=json` | `1` | 6 تحذيرات (3 حزم) | محفوظة في [`composer-audit-pre-update-raw.json`](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/packages/sec-01-update/composer-audit-pre-update-raw.json) |
| **بعد التحديث** | `composer audit --locked --format=json` | `0` | **0 تحذيرات (نظيف تماماً)** | محفوظة في [`composer-audit-post-update-raw.json`](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/packages/sec-01-update/composer-audit-post-update-raw.json) |

---

## 3. نتائج التحقق والامتثال الشامل

1. **صلاحية ملف الإعدادات والمنصة:**
   - `composer validate --strict`: نجاح تام (Exit Code: 0، `./composer.json is valid`).
   - `composer check-platform-reqs`: نجاح تام لجميع الامتدادات وإصدار PHP 8.4.27 (Exit Code: 0).

2. **حزمة الاختبارات الآلية الكاملة:**
   - `powershell -ExecutionPolicy Bypass -File scripts/php.ps1 artisan test`
   - **النتيجة:** 154 اختباراً ناجحاً بالكامل (938 assertions) دون أي فشل أو استثناء (المدة: 13.65 ثانية).

3. **دورة المتصفح الحقيقية على قاعدة MySQL المعزولة (المنفذ 3307):**
   - تم تشغيل `node docs/audit/evidence/browser/run_full_cycle_audit.mjs`.
   - **النتيجة:** نجاح 100% بكافة مراحل التدقيق الصارمة (Admin Login -> Master Data -> Purchasing RBAC -> PO Draft & Dynamic Line Deletion -> Receipt with Batches -> Customer Pricing -> Sales RBAC -> Future Date Rejection -> SO Pricing Math -> FEFO Confirmation Allocations -> Purchases/Sales/Inventory Reports -> Multi-locale LTR Mobile 390x844).
   - رمز خروج السكربت: `0`.
   - النتائج المحدثة محفوظة في [`docs/audit/evidence/browser/full_cycle_audit_results.json`](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/browser/full_cycle_audit_results.json).

4. **مطابقة دفتر الأستاذ والمخزون:**
   - `powershell -ExecutionPolicy Bypass -File scripts/php.ps1 artisan erp:check-stock`
   - **النتيجة:** `Stock balances match the movement ledger. No quantities were changed.` (0 فروقات).

---

## 4. حالة استعادة البيئة وأمان البيانات

- **إيقاف خوادم الخلفية:** تم إيقاف عمليتي `mysqld.exe` (المنفذ 3307) و `artisan serve` (المنفذ 8000) فور انتهاء الفحص.
- **استعادة ملف `.env`:** تمت استعادة الملف من النسخة الاحتياطية المسبقة `.cache/.env.backup`.
- **التحقق من البصمة الرقمية لـ `.env`:**
  - بصمة `.env` الحالية: `2E095E5AFABF8EBF492647275AD79F7C4315C3997F5F49E526C6713D21527C2A`
  - بصمة النسخة الاحتياطية `.cache/.env.backup`: `2E095E5AFABF8EBF492647275AD79F7C4315C3997F5F49E526C6713D21527C2A`
  - **الحالة:** متطابقة بنسبة 100%.
- **سلامة كود التطبيق والتغييرات المحفوظة:**
  - التعديل السابق في [`app/Services/BusinessReports.php`](file:///c:/Users/albna/Documents/minismarterp2/app/Services/BusinessReports.php) محفوظ وسليم تماماً.
  - لم يُعدل أي سطر في كود التطبيق الأساسي خارج نطاق `composer.lock` وأدلة التدقيق.
  - لم يتم إجراء أي `git commit` أو `git push`.

---

## 5. الخلاصة وتحديث حالة SEC-01

- **الحالة السابقة:** تحذيرات مكتبات خارجية مؤمنة (Symfony 8.1.10) - بحاجة لصيانة.
- **الحالة الحالية المعتمدة:** **مغلق ومعالج بالكامل (Resolved & Closed)** عبر ترقية الحزم إلى `v8.1.11` الرسمية المعتمدة الخالية من أي تحذيرات أمنية.
