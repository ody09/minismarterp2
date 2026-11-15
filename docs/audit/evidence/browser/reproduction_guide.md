# دليل إعادة تشغيل دورة المتصفح الكاملة وتوثيق الأدلة

**تاريخ التوثيق:** 10 نوفمبر 2026  
**ملف السكربت:** [`docs/audit/evidence/browser/run_full_cycle_audit.mjs`](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/browser/run_full_cycle_audit.mjs)  
**ملف الإعداد الدائم للمستخدمين:** [`docs/audit/evidence/browser/setup_audit_env.php`](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/browser/setup_audit_env.php)  
**ملف النتائج الحقيقية:** [`docs/audit/evidence/browser/full_cycle_audit_results.json`](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/browser/full_cycle_audit_results.json)  
**ملف إثبات الفشل المضبوط:** [`docs/audit/evidence/browser/controlled_failure_results.json`](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/browser/controlled_failure_results.json)  
**مجلد لقطات الشاشة:** [`docs/audit/evidence/screenshots/`](file:///c:/Users/albna/Documents/minismarterp2/docs/audit/evidence/screenshots/)

---

## 1. مواصفات بيئة التشغيل

- **نظام التشغيل:** Windows 11 (Architecture: x64).
- **محرك Chromium:** إصدار `1254` (المسار: `C:/Users/albna/AppData/Local/ms-playwright/chromium-1254/chrome-win64/chrome.exe`).
- **مكتبة الأتمتة:** Playwright المثبتة محلياً عبر npx cache.
- **محرك Node.js:** `v26.13.0`.
- **محرك PHP:** PHP 8.4 (عبر `scripts/php.ps1`).
- **محرك قاعدة البيانات:** MySQL Community Server `8.4.13` معزول على المنفذ `3307` (قاعدة: `smart_mini_erp_test`).
- **خادم الويب:** `artisan serve` على `http://127.0.0.1:8000`.

---

## 2. إدارة الأسرار وتهيئة البيئة الدائمة

لضمان عدم تسريب أو تضمين كلمات المرور داخل السكربتات الموجهة للمستودع:
1. يتم تشغيل سكربت الإعداد الدائم المستقل:
   ```powershell
   powershell -ExecutionPolicy Bypass -File scripts/php.ps1 docs/audit/evidence/browser/setup_audit_env.php
   ```
2. يقوم السكربت بإنشاء الأدوار وتعيين الصلاحيات الصارمة لكل دور:
   - دور مدير النظام (`admin`): كافة الصلاحيات.
   - دور مسؤول المشتريات (`purchasing_officer`): مشتريات ومخزون وتقارير المشتريات (دون مبيعات).
   - دور مسؤول المبيعات (`sales_officer`): مبيعات وتسعير ومخزون وتقارير المبيعات والمخزون (دون مشتريات).
3. يتم توليد كلمات مرور معقدة وحفظها في ملف كاش غير متتبع: `.cache/audit_env.json`.
4. يقرأ سكربت المتصفح هذه البيانات ديناميكياً ويفشل فوراً برمز خطأ غير صفري إذا غاب الملف.

---

## 3. خطوات التشغيل وإثبات الفشل المضبوط

### أ. إثبات الفشل المضبوط (Controlled Negative Test)
لإثبات أن بيئة الفحص ترصد الخلل بدقة وتخرج برمز خروج غير صفري (`exit code 1`) عند وجود توقع خاطئ:
```powershell
node docs/audit/evidence/browser/run_full_cycle_audit.mjs --fail-intentionally
```
- **النتيجة:** يفشل الفحص عمداً عند مقارنة إجمالي أمر البيع (متوقع 999.000000 مقابل 348.000000 الفعلي في DOM).
- **رمز الخروج:** `1`.
- **ملف الأدلة الناتج:** `docs/audit/evidence/browser/controlled_failure_results.json` متضمناً `status: "FAILED"`.

### ب. تشغيل الفحص الحقيقي الكامل (True Run)
```powershell
node docs/audit/evidence/browser/run_full_cycle_audit.mjs
```
- **النتيجة:** تمرير كافة الفحوص والمطابقات الصارمة بنجاح تام.
- **رمز الخروج:** `0`.
- **ملف الأدلة الناتج:** `docs/audit/evidence/browser/full_cycle_audit_results.json` متضمناً `status: "COMPLETED_SUCCESSFULLY"`.

---

## 4. سيناريو التدقيق والتحقق الحسابي المستقل

| المرحلة | الإجراء المنفذ | التوقع المستقل الصارم | التحقق الفعلي بالـ Assertions | الدليل الموثق |
| :---: | :--- | :--- | :--- | :--- |
| **1** | دخول المدير (العربية RTL) | تحميل لوحة التحكم | عنوان الصفحة "ملخص الأعمال" | `01-admin-dashboard-ar.png` |
| **2** | إنشاء البيانات الأساسية | تصنيف، مورد، عميل، منتج أ (معامل 12)، منتج ب (معامل 6) | تأكيد الحفظ وعودة المعرفات | `02-master-data-created.png` |
| **3** | فحص صلاحيات مسؤول المشتريات | حظر الوصول إلى `/sales` | HTTP 403 Forbidden حقيقي | مسجل في `http_checks` |
| **4** | أمر شراء مسودة مع تقسيم أ وحذف بند تجريبي | أ: سطر 1 (1 كرتون @ 10)، سطر 2 (1 كرتون @ 10). ب: سطر تجريبي يُحذف، ثم سطر حقيقي (3 كراتين @ 20). إجمالي: 80.000000 و42 قطعة | حالة المسودة: `مسودة`، والإجمالي: `80.000000` | `03-po-draft-created.png` |
| **5** | استلام أمر الشراء مع دفعات الصلاحية وتأكيد التنبيه | أ سطر 1: انتهاء اليوم+60، أ سطر 2: انتهاء اليوم+180، ب: انتهاء اليوم+90 | قبول تنبيه JS Confirm، تحول الحالة إلى `مستلم` | `04-po-received.png` |
| **6** | قاعدة تسعير خاصة للعميل | شراء 10 قطع فأكثر من منتج أ بسعر 12.000000 للقطعة | تأكيد حفظ القاعدة | `05-customer-pricing-tier.png` |
| **7** | فحص صلاحيات مسؤول المبيعات | حظر الوصول إلى `/purchases` | HTTP 403 Forbidden حقيقي | مسجل في `http_checks` |
| **8** | رفض تاريخ بيع مستقبلي | إرسال أمر بيع بتاريخ الغد | رفض من الخادم وعرض خطأ التحقق | مسجل بالـ Assertion |
| **9** | أمر بيع مسودة بالأسعار الخاصة والكتالوج | أ: 15 قطعة بالخصم (180.000000)، ب: 1 كرتون (168.000000). الإجمالي: 348.000000 و21 قطعة | حالة المسودة: `مسودة`، إجمالي السطرين والإجمالي الكلي: `348.000000` | `06-so-draft-pricing.png` |
| **10** | مراجعة واعتماد أمر البيع بمنطق FEFO | صرف أ: 12 من الدفعة الأولى الأقرب انتهاءً، و3 من الثانية. صرف ب: 6 من دفعة ب | حالة `معتمد`، وجدول التوزيع يتضمن 3 بنود بالدفعات والكميات السالبة المطابقة | `07-so-confirmed-fefo.png` |
| **11** | تقرير المشتريات `/reports/purchases` | مورد الاختبار الحالي: عدد 1، مبلغ 80.000000، كمية 42 قطعة | قراءة الإحصائيات وجدول الوثائق ومطابقتها تماماً | `reports-purchases-ar.png` |
| **12** | تقرير المبيعات `/reports/sales` | عميل الاختبار الحالي: عدد 1، مبلغ 348.000000، كمية 21 قطعة | قراءة الإحصائيات وجدول الوثائق ومطابقتها تماماً | `reports-sales-ar.png` |
| **13** | تقرير المخزون `/reports/inventory` (أ) | منتج أ: فعلي 9، متاح 9. دفعة أ1 نفدت (0)، دفعة أ2 متبقي 9 | قراءة رصيد المنتج وجدول الأرصدة الموجبة | `reports-inventory-ar.png` |
| **14** | تقرير المخزون `/reports/inventory` (ب) | منتج ب: فعلي 12، متاح 12. دفعة ب1 متبقي 12 | قراءة رصيد المنتج وجدول الأرصدة الموجبة | مطابقة تامة |
| **15** | التبديل للإنجليزية وعرض الموبايل (390x844) | توثيق التقارير بالإنجليزية LTR وتأكيد الاستجابة لشاشات الموبايل | حفظ لقطات شاشة متطابقة بالإنجليزية ومطابقة إجمالي المبيعات | `reports-purchases-en.png`<br>`reports-sales-en.png`<br>`reports-inventory-en.png`<br>`reports-sales-mobile-en.png` |
| **16** | مطابقة دفتر حركة المخزون | تشغيل أداة الفحص `artisan erp:check-stock` | مطابقة تامة بدون أي فروقات: `Stock balances match the movement ledger.` | مسجل بالمخرجات المعتمدة |
