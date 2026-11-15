/**
 * Smart Mini ERP - Full Cycle Browser Audit Script
 * Executes the complete real business lifecycle via Chromium Playwright:
 * Admin Login -> Master Data (Category, Supplier, Customer, Products Alpha & Beta)
 * -> Purchasing Login -> RBAC 403 Check -> PO Draft (with 2-line Alpha split & line add/delete)
 * -> PO Receipt (Batches & Dialog Accept) -> Customer Pricing Tier (by Admin)
 * -> Sales Login -> RBAC 403 Check -> Future Date Rejection -> SO Draft (Independent Math Verification)
 * -> FEFO Confirmation -> Real Allocation & Balance Assertions -> Reports Verification
 * -> Multi-locale LTR & Narrow Mobile (390x844) Viewport -> Stock Reconciliation CLI.
 *
 * Evidence output:
 * - docs/audit/evidence/browser/full_cycle_audit_results.json (or controlled_failure_results.json)
 * - docs/audit/evidence/screenshots/*.png
 */

import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import { execSync } from 'node:child_process';

const require = createRequire(import.meta.url);
const { chromium } = require('C:/Users/albna/AppData/Local/npm-cache/_npx/9833c18b2d85bc59/node_modules/playwright');

const BASE_URL = 'http://127.0.0.1:8000';
const CHROME_PATH = 'C:/Users/albna/AppData/Local/ms-playwright/chromium-1254/chrome-win64/chrome.exe';
const SCREENSHOTS_DIR = 'docs/audit/evidence/screenshots';
const ENV_CACHE_FILE = path.resolve('.cache/audit_env.json');

const CONTROLLED_FAILURE = process.env.CONTROLLED_FAILURE === 'true' || process.argv.includes('--fail-intentionally');
const RESULTS_FILE = CONTROLLED_FAILURE
    ? 'docs/audit/evidence/browser/controlled_failure_results.json'
    : 'docs/audit/evidence/browser/full_cycle_audit_results.json';

// Ensure audit credentials exist in untracked cache
if (!fs.existsSync(ENV_CACHE_FILE)) {
    console.error(`[Error] Missing ${ENV_CACHE_FILE}. Run docs/audit/evidence/browser/setup_audit_env.php first.`);
    process.exit(1);
}
const auditEnv = JSON.parse(fs.readFileSync(ENV_CACHE_FILE, 'utf-8'));

if (!fs.existsSync(SCREENSHOTS_DIR)) {
    fs.mkdirSync(SCREENSHOTS_DIR, { recursive: true });
}

const timestamp = Date.now();
const tag = `AUDIT-${timestamp}`;

function addDays(days) {
    const d = new Date();
    d.setDate(d.getDate() + days);
    return d.toISOString().split('T')[0];
}

const expiryAlpha1 = addDays(60);  // Earlier expiry
const expiryAlpha2 = addDays(180); // Later expiry
const expiryBeta = addDays(90);    // Beta expiry

const evidence = {
    tag,
    started_at: new Date().toISOString(),
    controlled_failure_mode: CONTROLLED_FAILURE,
    milestones: {},
    independent_calculations: {
        product_alpha: {
            pieces_per_carton: 12,
            po_line_1: { cartons: 1, pieces: 12, unit_price: '10.000000', subtotal: '10.000000', expiry: expiryAlpha1 },
            po_line_2: { cartons: 1, pieces: 12, unit_price: '10.000000', subtotal: '10.000000', expiry: expiryAlpha2 },
            po_total_pieces: 24,
            po_total_amount: '20.000000',
            customer_rule_min_qty: 10,
            customer_rule_price: '12.000000',
            so_pieces: 15,
            so_subtotal_expected: '180.000000',
            fefo_allocation: {
                batch_1_pieces: 12,
                batch_2_pieces: 3
            },
            stock_remaining_expected: {
                batch_1_pieces: 0,
                batch_2_pieces: 9,
                total_physical_pieces: 9,
                total_sellable_pieces: 9
            }
        },
        product_beta: {
            pieces_per_carton: 6,
            po_line_3: { cartons: 3, pieces: 18, unit_price: '20.000000', subtotal: '60.000000', expiry: expiryBeta },
            po_total_pieces: 18,
            po_total_amount: '60.000000',
            so_cartons: 1,
            so_pieces: 6,
            so_subtotal_expected: '168.000000', // 1 carton * 6 * 28.000000
            fefo_allocation: {
                batch_pieces: 6
            },
            stock_remaining_expected: {
                batch_pieces: 12,
                total_physical_pieces: 12,
                total_sellable_pieces: 12
            }
        },
        totals: {
            po_total_amount_expected: '80.000000', // 10 + 10 + 60
            po_total_pieces_expected: 42,           // 12 + 12 + 18
            so_total_amount_expected: CONTROLLED_FAILURE ? '999.000000' : '348.000000', // 180 + 168
            so_total_pieces_expected: 21            // 15 + 6
        }
    },
    http_checks: [],
    errors: []
};

async function navigateAndCapture(page, url, description) {
    const res = await page.goto(url, { waitUntil: 'load' });
    const entry = {
        method: 'GET',
        url: res ? res.url() : url,
        status: res ? res.status() : null,
        location: (res && res.headers()['location']) || null,
        description,
        timestamp: new Date().toISOString()
    };
    evidence.http_checks.push(entry);
    return res;
}

async function submitFormAndCapture(page, triggerFn, description) {
    const captured = [];
    const onResponse = (response) => {
        const req = response.request();
        const url = response.url();
        if (url.startsWith(BASE_URL)) {
            captured.push({
                method: req.method(),
                url: url,
                status: response.status(),
                location: response.headers()['location'] || null,
                description: description,
                timestamp: new Date().toISOString()
            });
        }
    };
    page.on('response', onResponse);
    try {
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'load' }),
            triggerFn()
        ]);
    } finally {
        page.off('response', onResponse);
    }
    for (const item of captured) {
        evidence.http_checks.push(item);
    }
    return captured;
}

async function loginUser(page, roleKey, description) {
    const user = auditEnv[roleKey];
    assert(user && user.email && user.password, `Credentials for ${roleKey} must exist in .cache/audit_env.json`);
    console.log(`[Auth] Logging in as ${roleKey} (${user.email})...`);
    await navigateAndCapture(page, `${BASE_URL}/login`, `Load login page for ${roleKey}`);
    await page.fill('#email', user.email);
    await page.fill('#password', user.password);
    await submitFormAndCapture(page, () => page.click('button.button.primary'), description || `Login as ${roleKey}`);
    assert.notStrictEqual(page.url(), `${BASE_URL}/login`, `Login failed for ${user.email}; still on login page`);
}

async function logoutUser(page) {
    console.log('[Auth] Logging out current user...');
    await navigateAndCapture(page, `${BASE_URL}/account`, 'Open account page for logout');
    const logoutForm = page.locator('form[action*="/logout"]');
    if (await logoutForm.count() > 0) {
        await submitFormAndCapture(page, () => logoutForm.locator('button').click(), 'User logout');
    }
}

async function run() {
    console.log(`[Full Cycle Audit] Launching Chromium from ${CHROME_PATH} (Controlled Failure: ${CONTROLLED_FAILURE})...`);
    const browser = await chromium.launch({
        executablePath: CHROME_PATH,
        headless: true,
    });

    const context = await browser.newContext({
        viewport: { width: 1280, height: 800 },
        locale: 'ar-YE',
    });

    const page = await context.newPage();

    // Dialog auto-accept
    page.on('dialog', async (dialog) => {
        console.log(`[Dialog Alert] ${dialog.type()}: "${dialog.message()}"`);
        evidence.milestones.dialog_message = dialog.message();
        await dialog.accept();
    });

    try {
        // ==========================================
        // Milestone 1: Admin Login (Arabic RTL)
        // ==========================================
        console.log('[Milestone 1] Admin Login (Arabic RTL)...');
        await loginUser(page, 'admin', 'Admin login');

        await page.waitForSelector('.page-heading h1');
        const headingText = (await page.textContent('.page-heading h1')).trim();
        console.log(`[Admin Login] Heading: "${headingText}"`);
        assert(headingText.length > 0, 'Admin dashboard heading must not be empty');

        await page.screenshot({ path: path.join(SCREENSHOTS_DIR, '01-admin-dashboard-ar.png'), fullPage: true });
        evidence.milestones.admin_login = { status: 'PASS', heading: headingText };

        // ==========================================
        // Milestone 2: Master Data Creation
        // ==========================================
        console.log('[Milestone 2] Creating Category...');
        await navigateAndCapture(page, `${BASE_URL}/categories/create`, 'Open Category create form');
        const catName = `مستلزمات عامة ${tag}`;
        await page.fill('#name', catName);
        await page.fill('#description', 'تصنيف تجريبي لتدقيق دورة الأعمال');
        await submitFormAndCapture(page, () => page.click('button.button.primary'), 'Save Category');

        console.log('[Milestone 2] Creating Supplier...');
        await navigateAndCapture(page, `${BASE_URL}/suppliers/create`, 'Open Supplier create form');
        const supName = `شركة التوريدات المتحدة ${tag}`;
        await page.fill('#name', supName);
        await page.fill('#phone', '771234567');
        await submitFormAndCapture(page, () => page.click('button.button.primary'), 'Save Supplier');

        console.log('[Milestone 2] Creating Customer...');
        await navigateAndCapture(page, `${BASE_URL}/customers/create`, 'Open Customer create form');
        const custName = `مؤسسة الأمل التجارية ${tag}`;
        await page.fill('#name', custName);
        await page.fill('#phone', '777888999');
        await submitFormAndCapture(page, () => page.click('button.button.primary'), 'Save Customer');

        console.log('[Milestone 2] Creating Product Alpha (Factor 12)...');
        await navigateAndCapture(page, `${BASE_URL}/products/create`, 'Open Product create form for Alpha');
        const catOptA = await page.evaluate((name) => {
            const opt = Array.from(document.querySelectorAll('#category_id option')).find(o => o.textContent.includes(name));
            return opt ? opt.value : null;
        }, catName);
        assert(catOptA, `Category option for ${catName} must exist`);

        const prodAName = `منتج ألف ${tag}`;
        await page.fill('#name', prodAName);
        await page.selectOption('#category_id', catOptA);
        await page.fill('#pieces_per_carton', '12');
        await page.selectOption('#purchase_unit', 'carton');
        await page.selectOption('#selling_unit', 'piece');
        await page.fill('#purchase_price', '10.000000');
        await page.fill('#selling_price', '15.000000');
        await page.fill('#reorder_level', '5');
        await submitFormAndCapture(page, () => page.click('button.button.primary'), 'Save Product Alpha');

        console.log('[Milestone 2] Creating Product Beta (Factor 6)...');
        await navigateAndCapture(page, `${BASE_URL}/products/create`, 'Open Product create form for Beta');
        const catOptB = await page.evaluate((name) => {
            const opt = Array.from(document.querySelectorAll('#category_id option')).find(o => o.textContent.includes(name));
            return opt ? opt.value : null;
        }, catName);
        const prodBName = `منتج باء ${tag}`;
        await page.fill('#name', prodBName);
        await page.selectOption('#category_id', catOptB);
        await page.fill('#pieces_per_carton', '6');
        await page.selectOption('#purchase_unit', 'carton');
        await page.selectOption('#selling_unit', 'piece');
        await page.fill('#purchase_price', '20.000000');
        await page.fill('#selling_price', '28.000000');
        await page.fill('#reorder_level', '5');
        await submitFormAndCapture(page, () => page.click('button.button.primary'), 'Save Product Beta');

        await page.screenshot({ path: path.join(SCREENSHOTS_DIR, '02-master-data-created.png'), fullPage: true });
        evidence.milestones.master_data = {
            status: 'PASS',
            category: catName,
            supplier: supName,
            customer: custName,
            product_alpha: prodAName,
            product_beta: prodBName
        };

        // Logout Admin
        await logoutUser(page);

        // ==========================================
        // Milestone 3: Purchasing Officer Cycle
        // ==========================================
        console.log('[Milestone 3] Logging in as Purchasing Officer...');
        await loginUser(page, 'purchasing', 'Purchasing officer login');

        // Test RBAC boundary: Purchasing officer attempting to access Sales
        console.log('[Milestone 3] Testing RBAC 403 boundary on /sales...');
        const rbacRes = await navigateAndCapture(page, `${BASE_URL}/sales`, 'Purchasing officer forbidden access test on /sales');
        assert.strictEqual(rbacRes.status(), 403, `Purchasing officer must receive HTTP 403 on /sales, got ${rbacRes.status()}`);
        console.log(`[RBAC Test] /sales HTTP Status for Purchasing Officer: 403 (PASS)`);
        evidence.milestones.purchasing_rbac_403 = 'PASS';

        // Create PO Draft
        console.log('[Milestone 3] Creating Purchase Order draft with 2-line Alpha split and line deletion...');
        await navigateAndCapture(page, `${BASE_URL}/purchases/create`, 'Open PO create form');
        
        // Select supplier
        const supOption = await page.evaluate((name) => {
            const opt = Array.from(document.querySelectorAll('#supplier_id option')).find(o => o.textContent.includes(name));
            return opt ? { id: opt.value, text: opt.textContent } : null;
        }, supName);
        assert(supOption && supOption.id, `Supplier option for ${supName} must exist`);
        await page.selectOption('#supplier_id', supOption.id);
        const supplierId = supOption.id;

        // Line 0: Product Alpha (1 carton @ 10.000000)
        const prodAOption = await page.evaluate((name) => {
            const opt = Array.from(document.querySelectorAll('select[data-field="product_id"] option')).find(o => o.textContent.includes(name));
            return opt ? { id: opt.value, text: opt.textContent } : null;
        }, prodAName);
        assert(prodAOption && prodAOption.id, `Product Alpha option must exist`);
        const prodAId = prodAOption.id;

        await page.selectOption('select[name="items[0][product_id]"]', prodAOption.id);
        await page.fill('input[name="items[0][quantity]"]', '1');
        await page.selectOption('select[name="items[0][unit]"]', 'carton');
        await page.fill('input[name="items[0][unit_price]"]', '10.000000');

        // Line 1: Product Alpha (1 carton @ 10.000000)
        console.log('[Milestone 3] Adding Line 1 (Alpha second carton)...');
        await page.click('button[data-add-line]');
        await page.waitForSelector('tr[data-purchase-line]:nth-child(2)');
        await page.selectOption('select[name="items[1][product_id]"]', prodAOption.id);
        await page.fill('input[name="items[1][quantity]"]', '1');
        await page.selectOption('select[name="items[1][unit]"]', 'carton');
        await page.fill('input[name="items[1][unit_price]"]', '10.000000');

        // Line 2 (dummy): Product Beta (1 carton) -> test dynamic line deletion
        console.log('[Milestone 3] Adding dummy Line 2 to test dynamic deletion...');
        await page.click('button[data-add-line]');
        await page.waitForSelector('tr[data-purchase-line]:nth-child(3)');
        const dummyRemoveBtn = page.locator('tr[data-purchase-line]:nth-child(3) button[data-remove-line]');
        await dummyRemoveBtn.click();
        console.log('[Milestone 3] Dummy Line 2 deleted.');

        // Line 2 (real): Product Beta (3 cartons @ 20.000000)
        console.log('[Milestone 3] Adding real Line 2 (Product Beta 3 cartons)...');
        await page.click('button[data-add-line]');
        await page.waitForSelector('tr[data-purchase-line]:nth-child(3)');
        const prodBOption = await page.evaluate((name) => {
            const opt = Array.from(document.querySelectorAll('select[data-field="product_id"] option')).find(o => o.textContent.includes(name));
            return opt ? { id: opt.value, text: opt.textContent } : null;
        }, prodBName);
        assert(prodBOption && prodBOption.id, `Product Beta option must exist`);
        const prodBId = prodBOption.id;

        await page.selectOption('select[name="items[2][product_id]"]', prodBOption.id);
        await page.fill('input[name="items[2][quantity]"]', '3');
        await page.selectOption('select[name="items[2][unit]"]', 'carton');
        await page.fill('input[name="items[2][unit_price]"]', '20.000000');

        await page.screenshot({ path: path.join(SCREENSHOTS_DIR, '03-po-draft-created.png'), fullPage: true });

        // Submit PO Draft
        await submitFormAndCapture(page, () => page.click('button.button.primary'), 'Save PO draft');

        // Extract PO ID
        const poUrl = page.url();
        const poMatch = poUrl.match(/\/purchases\/(\d+)/);
        assert(poMatch, `Current URL (${poUrl}) must match /purchases/{id}`);
        const poId = poMatch[1];
        console.log(`[PO Created] PO ID: ${poId}, URL: ${poUrl}`);

        // Read PO status pill and exact total amount
        const poStatusPill = (await page.locator('.page-heading .pill').textContent()).trim();
        assert.strictEqual(poStatusPill, 'مسودة', `PO status must be draft (مسودة), got: "${poStatusPill}"`);

        const poTotalAmount = (await page.locator('table tfoot td[dir="ltr"] strong').textContent()).trim();
        assert.strictEqual(poTotalAmount, '80.000000', `PO total amount must be 80.000000, got: "${poTotalAmount}"`);
        console.log(`[PO Draft Assertions] Status: "${poStatusPill}", Total: "${poTotalAmount}" (PASS)`);

        // Receive PO
        console.log('[Milestone 3] Receiving PO with Batches and Confirm Dialog...');
        await navigateAndCapture(page, `${BASE_URL}/purchases/${poId}/receive`, 'Open PO receive form');

        const batchA1 = `BATCH-A1-${tag}`;
        const batchA2 = `BATCH-A2-${tag}`;
        const batchB1 = `BATCH-B1-${tag}`;

        // Item 0: Alpha Line 1 (Expiry +60d)
        await page.fill('#batch_number_0', batchA1);
        await page.fill('#expiration_date_0', expiryAlpha1);

        // Item 1: Alpha Line 2 (Expiry +180d)
        await page.fill('#batch_number_1', batchA2);
        await page.fill('#expiration_date_1', expiryAlpha2);

        // Item 2: Beta Line 3 (Expiry +90d)
        await page.fill('#batch_number_2', batchB1);
        await page.fill('#expiration_date_2', expiryBeta);

        // Submit receive form (triggers native JS confirm dialog auto-accepted)
        await submitFormAndCapture(page, () => page.click('button.button.primary'), 'Submit PO batches receipt');

        // Assert PO received status pill
        const poReceivedStatus = (await page.locator('.page-heading .pill').textContent()).trim();
        assert.strictEqual(poReceivedStatus, 'مستلم', `PO status must be received (مستلم), got: "${poReceivedStatus}"`);
        console.log(`[PO Receipt Assertions] Status: "${poReceivedStatus}" (PASS)`);

        await page.screenshot({ path: path.join(SCREENSHOTS_DIR, '04-po-received.png'), fullPage: true });
        evidence.milestones.po_lifecycle = {
            po_id: poId,
            po_total: poTotalAmount,
            status_draft: poStatusPill,
            status_received: poReceivedStatus,
            batches: { batchA1, expiryAlpha1, batchA2, expiryAlpha2, batchB1, expiryBeta }
        };

        // Logout Purchasing
        await logoutUser(page);

        // ==========================================
        // Milestone 4: Customer Pricing Tier (by Admin)
        // ==========================================
        console.log('[Milestone 4] Admin configures Customer Pricing Rule...');
        await loginUser(page, 'admin', 'Admin login for pricing tier');
        await navigateAndCapture(page, `${BASE_URL}/prices/create`, 'Open Customer Pricing create form');

        const custOpt = await page.evaluate((name) => {
            const opt = Array.from(document.querySelectorAll('#customer_id option')).find(o => o.textContent.includes(name));
            return opt ? { id: opt.value, text: opt.textContent } : null;
        }, custName);
        assert(custOpt && custOpt.id, `Customer option must exist`);
        await page.selectOption('#customer_id', custOpt.id);
        const customerId = custOpt.id;

        await page.selectOption('#product_id', prodAId);
        await page.selectOption('#unit', 'piece');
        await page.fill('#min_quantity', '10');
        await page.fill('#unit_price', '12.000000'); // discounted tier price

        await submitFormAndCapture(page, () => page.click('button.button.primary'), 'Save customer pricing rule');
        console.log('[Customer Pricing] Tier price rule created: 10+ pcs @ 12.000000 (PASS)');
        await page.screenshot({ path: path.join(SCREENSHOTS_DIR, '05-customer-pricing-tier.png'), fullPage: true });

        // Logout Admin
        await logoutUser(page);

        // ==========================================
        // Milestone 5: Sales Officer Cycle & FEFO
        // ==========================================
        console.log('[Milestone 5] Logging in as Sales Officer...');
        await loginUser(page, 'sales', 'Sales officer login');

        // Test RBAC boundary: Sales officer attempting to access purchases
        console.log('[Milestone 5] Testing RBAC 403 boundary on /purchases...');
        const salesRbac = await navigateAndCapture(page, `${BASE_URL}/purchases`, 'Sales officer forbidden access test on /purchases');
        assert.strictEqual(salesRbac.status(), 403, `Sales officer must receive HTTP 403 on /purchases, got ${salesRbac.status()}`);
        console.log(`[RBAC Test] /purchases HTTP Status for Sales Officer: 403 (PASS)`);
        evidence.milestones.sales_rbac_403 = 'PASS';

        // Test Future Date Rejection on Sales Order
        console.log('[Milestone 5] Testing Future Order Date rejection...');
        await navigateAndCapture(page, `${BASE_URL}/sales/create`, 'Open SO create form for future date test');
        await page.selectOption('#customer_id', customerId);
        await page.evaluate(() => document.getElementById('order_date').removeAttribute('max'));
        await page.fill('#order_date', addDays(1)); // Tomorrow
        await page.selectOption('select[name="items[0][product_id]"]', prodAId);
        await page.fill('input[name="items[0][quantity]"]', '1');
        await page.selectOption('select[name="items[0][unit]"]', 'piece');
        
        await submitFormAndCapture(page, () => page.click('button.button.primary'), 'Submit SO with future date');
        const errorAlert = await page.locator('.notice.error');
        const hasError = await errorAlert.count() > 0;
        assert(hasError, 'Backend must reject future order date with validation error');
        console.log('[Future Date Test] Future order date correctly rejected (PASS)');

        // Now create valid SO Draft
        console.log('[Milestone 5] Creating valid Sales Order draft...');
        await navigateAndCapture(page, `${BASE_URL}/sales/create`, 'Open SO create form');
        await page.selectOption('#customer_id', customerId);

        // Line 0: Product Alpha, 15 pieces (tier pricing 12.000000 => 180.000000)
        await page.selectOption('select[name="items[0][product_id]"]', prodAId);
        await page.fill('input[name="items[0][quantity]"]', '15');
        await page.selectOption('select[name="items[0][unit]"]', 'piece');

        // Line 1: Product Beta, 1 carton (catalog price 28.000000 * 6 = 168.000000)
        await page.click('button[data-add-line]');
        await page.waitForSelector('tr[data-purchase-line]:nth-child(2)');
        await page.selectOption('select[name="items[1][product_id]"]', prodBId);
        await page.fill('input[name="items[1][quantity]"]', '1');
        await page.selectOption('select[name="items[1][unit]"]', 'carton');

        await page.screenshot({ path: path.join(SCREENSHOTS_DIR, '06-so-draft-pricing.png'), fullPage: true });

        // Submit SO Draft
        await submitFormAndCapture(page, () => page.click('button.button.primary'), 'Save SO draft');

        const soUrl = page.url();
        const soMatch = soUrl.match(/\/sales\/(\d+)/);
        assert(soMatch, `Current URL (${soUrl}) must match /sales/{id}`);
        const soId = soMatch[1];
        console.log(`[SO Created] SO ID: ${soId}, URL: ${soUrl}`);

        // Read SO status pill and lines subtotals & total
        const soStatusPill = (await page.locator('.page-heading .pill').textContent()).trim();
        assert.strictEqual(soStatusPill, 'مسودة', `SO status must be draft (مسودة), got: "${soStatusPill}"`);

        const line1Subtotal = (await page.locator('#sale-line-1 td:nth-child(7)').textContent()).trim();
        assert.strictEqual(line1Subtotal, '180.000000', `Alpha line subtotal must be 180.000000, got: "${line1Subtotal}"`);

        const line2Subtotal = (await page.locator('#sale-line-2 td:nth-child(7)').textContent()).trim();
        assert.strictEqual(line2Subtotal, '168.000000', `Beta line subtotal must be 168.000000, got: "${line2Subtotal}"`);

        const soTotalAmount = (await page.locator('table tfoot td[dir="ltr"] strong').textContent()).trim();
        const expectedSoTotal = CONTROLLED_FAILURE ? '999.000000' : '348.000000';
        console.log(`[SO Math Check] Expecting: "${expectedSoTotal}", In DOM: "${soTotalAmount}" (Controlled Failure: ${CONTROLLED_FAILURE})`);
        assert.strictEqual(soTotalAmount, expectedSoTotal, `SO total mismatch: expected ${expectedSoTotal}, got ${soTotalAmount}`);
        console.log(`[SO Draft Assertions] Status: "${soStatusPill}", Total: "${soTotalAmount}" (PASS)`);

        // Review & Confirm Sales Order (FEFO allocation)
        console.log('[Milestone 5] Reviewing and Confirming Sales Order with FEFO allocation...');
        await navigateAndCapture(page, `${BASE_URL}/sales/${soId}/confirm`, 'Open SO confirm review page');
        
        await submitFormAndCapture(page, () => page.click('button.button.primary'), 'Confirm Sales Order with FEFO');

        const soConfirmedStatus = (await page.locator('.page-heading .pill').textContent()).trim();
        assert.strictEqual(soConfirmedStatus, 'معتمد', `SO status must be confirmed (معتمد), got: "${soConfirmedStatus}"`);

        // Verify FEFO Allocations table on sales.show
        console.log('[Milestone 5] Asserting FEFO Allocations table on sales.show...');
        const allocationRows = await page.locator('section.card:has(h2:has-text("توزيع الدفعات"), h2:has-text("Batch allocations")) table tbody tr').all();
        console.log(`[FEFO Check] Total allocation rows found: ${allocationRows.length}`);
        assert.strictEqual(allocationRows.length, 3, `Expected exactly 3 FEFO allocation rows, got ${allocationRows.length}`);

        // Allocation 1: Line 1, Batch A1 (earlier expiry), 12 pieces
        const alloc0Line = (await allocationRows[0].locator('td:nth-child(1)').textContent()).trim();
        const alloc0Batch = (await allocationRows[0].locator('td:nth-child(2)').textContent()).trim();
        const alloc0Qty = (await allocationRows[0].locator('td:nth-child(3)').textContent()).trim();
        assert.strictEqual(alloc0Line, '1', `Allocation 0 line must be 1`);
        assert.strictEqual(alloc0Batch, batchA1, `Allocation 0 must use earliest expiring batch ${batchA1}`);
        assert.strictEqual(alloc0Qty, '-12', `Allocation 0 quantity must be -12`);

        // Allocation 2: Line 1, Batch A2 (later expiry), 3 pieces
        const alloc1Line = (await allocationRows[1].locator('td:nth-child(1)').textContent()).trim();
        const alloc1Batch = (await allocationRows[1].locator('td:nth-child(2)').textContent()).trim();
        const alloc1Qty = (await allocationRows[1].locator('td:nth-child(3)').textContent()).trim();
        assert.strictEqual(alloc1Line, '1', `Allocation 1 line must be 1`);
        assert.strictEqual(alloc1Batch, batchA2, `Allocation 1 must take remainder from ${batchA2}`);
        assert.strictEqual(alloc1Qty, '-3', `Allocation 1 quantity must be -3`);

        // Allocation 3: Line 2, Batch B1, 6 pieces (1 carton)
        const alloc2Line = (await allocationRows[2].locator('td:nth-child(1)').textContent()).trim();
        const alloc2Batch = (await allocationRows[2].locator('td:nth-child(2)').textContent()).trim();
        const alloc2Qty = (await allocationRows[2].locator('td:nth-child(3)').textContent()).trim();
        assert.strictEqual(alloc2Line, '2', `Allocation 2 line must be 2`);
        assert.strictEqual(alloc2Batch, batchB1, `Allocation 2 must use ${batchB1}`);
        assert.strictEqual(alloc2Qty, '-6', `Allocation 2 quantity must be -6`);
        console.log('[FEFO Allocations] Line 1 -> Batch A1 (-12) & Batch A2 (-3); Line 2 -> Batch B1 (-6) (PASS)');

        await page.screenshot({ path: path.join(SCREENSHOTS_DIR, '07-so-confirmed-fefo.png'), fullPage: true });
        evidence.milestones.so_lifecycle = {
            so_id: soId,
            so_total: soTotalAmount,
            status_draft: soStatusPill,
            status_confirmed: soConfirmedStatus,
            allocations: [
                { line: alloc0Line, batch: alloc0Batch, qty: alloc0Qty },
                { line: alloc1Line, batch: alloc1Batch, qty: alloc1Qty },
                { line: alloc2Line, batch: alloc2Batch, qty: alloc2Qty }
            ]
        };

        // Logout Sales Officer
        await logoutUser(page);

        // ==========================================
        // Milestone 6: Reports Verification (Admin)
        // ==========================================
        console.log('[Milestone 6] Logging in as Admin for Reports Verification...');
        await loginUser(page, 'admin', 'Admin login for reports');

        // 1. Purchases Report
        console.log('[Milestone 6] Checking Purchases Report for current supplier...');
        await navigateAndCapture(page, `${BASE_URL}/reports/purchases?supplier_id=${supplierId}`, 'Open Purchases report');
        const poRepAmount = (await page.locator('.stats-grid .stat:nth-child(1) strong').textContent()).trim();
        const poRepCount = (await page.locator('.stats-grid .stat:nth-child(2) strong').textContent()).trim();
        const poRepPieces = (await page.locator('.stats-grid .stat:nth-child(3) strong').textContent()).trim();
        assert.strictEqual(poRepAmount, '80.000000', `Purchases report amount must be 80.000000, got: "${poRepAmount}"`);
        assert.strictEqual(poRepCount, '1', `Purchases report count must be 1, got: "${poRepCount}"`);
        assert.strictEqual(poRepPieces, '42', `Purchases report pieces must be 42, got: "${poRepPieces}"`);
        await page.screenshot({ path: path.join(SCREENSHOTS_DIR, 'reports-purchases-ar.png'), fullPage: true });
        console.log(`[Purchases Report] Amount: ${poRepAmount}, Count: ${poRepCount}, Pieces: ${poRepPieces} (PASS)`);

        // 2. Sales Report
        console.log('[Milestone 6] Checking Sales Report for current customer...');
        await navigateAndCapture(page, `${BASE_URL}/reports/sales?customer_id=${customerId}`, 'Open Sales report');
        const soRepAmount = (await page.locator('.stats-grid .stat:nth-child(1) strong').textContent()).trim();
        const soRepCount = (await page.locator('.stats-grid .stat:nth-child(2) strong').textContent()).trim();
        const soRepPieces = (await page.locator('.stats-grid .stat:nth-child(3) strong').textContent()).trim();
        assert.strictEqual(soRepAmount, '348.000000', `Sales report amount must be 348.000000, got: "${soRepAmount}"`);
        assert.strictEqual(soRepCount, '1', `Sales report count must be 1, got: "${soRepCount}"`);
        assert.strictEqual(soRepPieces, '21', `Sales report pieces must be 21, got: "${soRepPieces}"`);
        await page.screenshot({ path: path.join(SCREENSHOTS_DIR, 'reports-sales-ar.png'), fullPage: true });
        console.log(`[Sales Report] Amount: ${soRepAmount}, Count: ${soRepCount}, Pieces: ${soRepPieces} (PASS)`);

        // 3. Inventory Report for Product Alpha
        console.log('[Milestone 6] Checking Inventory Report for Product Alpha...');
        await navigateAndCapture(page, `${BASE_URL}/reports/inventory?product_id=${prodAId}`, 'Open Inventory report for Alpha');
        const prodARow = page.locator('section.card:has(h2:has-text("أرصدة المنتجات"), h2:has-text("Product balances")) table tbody tr:first-child');
        const alphaPhysical = (await prodARow.locator('td:nth-child(2)').textContent()).trim();
        const alphaSellable = (await prodARow.locator('td:nth-child(3)').textContent()).trim();
        assert.strictEqual(alphaPhysical, '9', `Product Alpha physical must be 9, got: "${alphaPhysical}"`);
        assert.strictEqual(alphaSellable, '9', `Product Alpha sellable must be 9, got: "${alphaSellable}"`);

        // Check Alpha batch rows (positive batch balances table only lists batches > 0)
        const alphaBatchRows = await page.locator('section.card:has(h2:has-text("أرصدة الدفعات الموجبة"), h2:has-text("Positive batch balances")) table tbody tr').all();
        assert.strictEqual(alphaBatchRows.length, 1, `Alpha should have exactly 1 positive batch remaining (Batch A2), got: ${alphaBatchRows.length}`);
        const alphaRemainingBatchNumber = (await alphaBatchRows[0].locator('td:nth-child(2)').textContent()).trim();
        const alphaRemainingBatchQty = (await alphaBatchRows[0].locator('td:nth-child(4)').textContent()).trim();
        assert.strictEqual(alphaRemainingBatchNumber, batchA2, `Remaining positive batch must be Batch A2 (${batchA2})`);
        assert.strictEqual(alphaRemainingBatchQty, '9', `Batch A2 must have 9 pieces remaining`);
        await page.screenshot({ path: path.join(SCREENSHOTS_DIR, 'reports-inventory-ar.png'), fullPage: true });
        console.log(`[Inventory Alpha] Physical: ${alphaPhysical}, Sellable: ${alphaSellable}, Batch A1 fully consumed (0), Batch A2 remaining: ${alphaRemainingBatchQty} (PASS)`);

        // 4. Inventory Report for Product Beta
        console.log('[Milestone 6] Checking Inventory Report for Product Beta...');
        await navigateAndCapture(page, `${BASE_URL}/reports/inventory?product_id=${prodBId}`, 'Open Inventory report for Beta');
        const prodBRow = page.locator('section.card:has(h2:has-text("أرصدة المنتجات"), h2:has-text("Product balances")) table tbody tr:first-child');
        const betaPhysical = (await prodBRow.locator('td:nth-child(2)').textContent()).trim();
        const betaSellable = (await prodBRow.locator('td:nth-child(3)').textContent()).trim();
        assert.strictEqual(betaPhysical, '12', `Product Beta physical must be 12, got: "${betaPhysical}"`);
        assert.strictEqual(betaSellable, '12', `Product Beta sellable must be 12, got: "${betaSellable}"`);

        const betaBatchRows = await page.locator('section.card:has(h2:has-text("أرصدة الدفعات الموجبة"), h2:has-text("Positive batch balances")) table tbody tr').all();
        assert.strictEqual(betaBatchRows.length, 1, `Beta should have exactly 1 positive batch row, got: ${betaBatchRows.length}`);
        const betaRemainingBatchNumber = (await betaBatchRows[0].locator('td:nth-child(2)').textContent()).trim();
        const betaRemainingBatchQty = (await betaBatchRows[0].locator('td:nth-child(4)').textContent()).trim();
        assert.strictEqual(betaRemainingBatchNumber, batchB1, `Batch B1 must match ${batchB1}`);
        assert.strictEqual(betaRemainingBatchQty, '12', `Batch B1 must have 12 pieces remaining`);
        console.log(`[Inventory Beta] Physical: ${betaPhysical}, Sellable: ${betaSellable}, Batch B1: ${betaRemainingBatchQty} (PASS)`);

        // ==========================================
        // Milestone 7: English LTR & Mobile Viewport
        // ==========================================
        console.log('[Milestone 7] Testing English LTR Locale Switch & Mobile Viewport...');
        // Switch locale to English via topbar button
        const enBtn = page.locator('form.language-switch button[value="en"]');
        assert(await enBtn.count() > 0, 'English locale button must exist');
        await submitFormAndCapture(page, () => enBtn.click(), 'Switch to English locale');

        // English Reports Screenshots
        await navigateAndCapture(page, `${BASE_URL}/reports/purchases?supplier_id=${supplierId}`, 'Purchases report English');
        await page.screenshot({ path: path.join(SCREENSHOTS_DIR, 'reports-purchases-en.png'), fullPage: true });

        await navigateAndCapture(page, `${BASE_URL}/reports/sales?customer_id=${customerId}`, 'Sales report English');
        await page.screenshot({ path: path.join(SCREENSHOTS_DIR, 'reports-sales-en.png'), fullPage: true });

        await navigateAndCapture(page, `${BASE_URL}/reports/inventory?product_id=${prodAId}`, 'Inventory report English');
        await page.screenshot({ path: path.join(SCREENSHOTS_DIR, 'reports-inventory-en.png'), fullPage: true });

        // Narrow Mobile Viewport (390x844)
        console.log('[Milestone 7] Setting Narrow Viewport (390x844)...');
        await page.setViewportSize({ width: 390, height: 844 });
        await navigateAndCapture(page, `${BASE_URL}/reports/sales?customer_id=${customerId}`, 'Sales report English on 390x844');
        const mobileSoAmount = (await page.locator('.stats-grid .stat:nth-child(1) strong').textContent()).trim();
        assert.strictEqual(mobileSoAmount, '348.000000', `Mobile sales report amount must be 348.000000`);
        await page.screenshot({ path: path.join(SCREENSHOTS_DIR, 'reports-sales-mobile-en.png'), fullPage: true });
        console.log('[Milestone 7] English LTR and Mobile Viewport screenshots captured (PASS)');

        // ==========================================
        // Milestone 8: Stock Ledger Reconciliation CLI
        // ==========================================
        console.log('[Milestone 8] Running artisan erp:check-stock...');
        const stockCheckOutput = execSync('powershell -ExecutionPolicy Bypass -File scripts/php.ps1 artisan erp:check-stock').toString().trim();
        console.log(`[Stock Reconciliation Output]:\n${stockCheckOutput}`);
        assert(stockCheckOutput.includes('Stock balances match the movement ledger.'), `Stock check output did not confirm balance: ${stockCheckOutput}`);
        evidence.milestones.stock_reconciliation = {
            output: stockCheckOutput,
            status: 'PASS'
        };

        evidence.finished_at = new Date().toISOString();
        evidence.status = 'COMPLETED_SUCCESSFULLY';
        console.log('\n>>> AUDIT SUITE COMPLETED SUCCESSFULLY WITH ALL RIGOROUS ASSERTIONS PASSING! <<<\n');

    } catch (err) {
        console.error('\n>>> AUDIT FAILED WITH ERROR / ASSERTION FAILURE <<<');
        console.error(err);
        evidence.errors.push(err.message || String(err));
        evidence.finished_at = new Date().toISOString();
        evidence.status = 'FAILED';
    } finally {
        await browser.close();
        fs.writeFileSync(RESULTS_FILE, JSON.stringify(evidence, null, 2), 'utf-8');
        console.log(`[Results Saved] Saved audit evidence to ${RESULTS_FILE}`);
        if (evidence.status !== 'COMPLETED_SUCCESSFULLY') {
            process.exit(1);
        }
    }
}

run();
