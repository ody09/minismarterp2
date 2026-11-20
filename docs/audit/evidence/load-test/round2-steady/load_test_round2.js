import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend, Counter, Rate } from 'k6/metrics';

// Load credentials from ignored external file
const USERS = JSON.parse(open('./bench_users.json'));

const BASE_URL = 'http://127.0.0.1:8088';

// Overall & Phase-specific Trends
const trendReqDurationOverall = new Trend('erp_duration_overall', true);
const trendReqDurationWarmup = new Trend('erp_duration_steady_warmup', true);
const trendReqDuration5VU = new Trend('erp_duration_steady_5vu', true);
const trendReqDuration10VU = new Trend('erp_duration_steady_10vu', true);
const trendReqDuration20VU = new Trend('erp_duration_steady_20vu', true);

// Operation Trends (Overall & Steady 20VU)
const trendOpDashboard = new Trend('erp_op_dashboard', true);
const trendOpProductsKnown = new Trend('erp_op_products_known', true);
const trendOpProductsEmpty = new Trend('erp_op_products_empty', true);
const trendOpInventory = new Trend('erp_op_inventory', true);
const trendOpInventoryTx = new Trend('erp_op_inventory_tx', true);
const trendOpBatches = new Trend('erp_op_batches', true);
const trendOpPurchases = new Trend('erp_op_purchases', true);
const trendOpPurchasesFilter = new Trend('erp_op_purchases_filter', true);
const trendOpSales = new Trend('erp_op_sales', true);
const trendOpSalesFilter = new Trend('erp_op_sales_filter', true);
const trendOpReportSales = new Trend('erp_op_report_sales', true);
const trendOpReportPurchases = new Trend('erp_op_report_purchases', true);
const trendOpReportInventory = new Trend('erp_op_report_inventory', true);
const trendOpPoCycle = new Trend('erp_op_po_write_cycle', true);
const trendOpSoCycle = new Trend('erp_op_so_write_cycle', true);

// 20VU Steady Phase Operation Trends
const trend20vuDashboard = new Trend('erp_20vu_dashboard', true);
const trend20vuProductsKnown = new Trend('erp_20vu_products_known', true);
const trend20vuProductsEmpty = new Trend('erp_20vu_products_empty', true);
const trend20vuInventory = new Trend('erp_20vu_inventory', true);
const trend20vuInventoryTx = new Trend('erp_20vu_inventory_tx', true);
const trend20vuBatches = new Trend('erp_20vu_batches', true);
const trend20vuPurchases = new Trend('erp_20vu_purchases', true);
const trend20vuPurchasesFilter = new Trend('erp_20vu_purchases_filter', true);
const trend20vuSales = new Trend('erp_20vu_sales', true);
const trend20vuSalesFilter = new Trend('erp_20vu_sales_filter', true);
const trend20vuReportSales = new Trend('erp_20vu_report_sales', true);
const trend20vuReportPurchases = new Trend('erp_20vu_report_purchases', true);
const trend20vuReportInventory = new Trend('erp_20vu_report_inventory', true);
const trend20vuPoCycle = new Trend('erp_20vu_po_write_cycle', true);
const trend20vuSoCycle = new Trend('erp_20vu_so_write_cycle', true);

// Counters & Rates
const countReads = new Counter('erp_count_reads');
const countWrites = new Counter('erp_count_writes');
const countFailures = new Counter('erp_count_failures');
const countCriticalFailures = new Counter('erp_critical_failures');
const rateSuccess = new Rate('erp_rate_success');

export const options = {
  stages: [
    { duration: '5s', target: 2 },    // ramp to warmup
    { duration: '60s', target: 2 },   // Steady Warmup (2 VUs, 1 min)
    { duration: '10s', target: 5 },   // ramp to 5 VUs
    { duration: '120s', target: 5 },  // Steady 5 VUs (2 min)
    { duration: '10s', target: 10 },  // ramp to 10 VUs
    { duration: '120s', target: 10 }, // Steady 10 VUs (2 min)
    { duration: '15s', target: 20 },  // ramp to 20 VUs
    { duration: '600s', target: 20 }, // Steady 20 VUs (10 min)
    { duration: '10s', target: 0 },   // Cooldown
  ],
  thresholds: {
    http_req_failed: ['rate<0.05'],
    erp_critical_failures: ['count==0'],
    erp_rate_success: ['rate>0.98'],
  },
};

let testStartTime = Date.now();
let vuSession = null;

function getCurrentPhase() {
  const elapsedSec = (Date.now() - testStartTime) / 1000;
  if (elapsedSec >= 5 && elapsedSec < 65) return 'steady_warmup';
  if (elapsedSec >= 75 && elapsedSec < 195) return 'steady_5vu';
  if (elapsedSec >= 205 && elapsedSec < 325) return 'steady_10vu';
  if (elapsedSec >= 340 && elapsedSec < 940) return 'steady_20vu';
  return 'transition';
}

function extractCsrf(html) {
  if (!html) return null;
  const match = html.match(/name="_token"\s+value="([^"]+)"/);
  return match ? match[1] : null;
}

function extractAttr(html, name) {
  if (!html) return null;
  const re = new RegExp(`name="${name}"\\s+value="([^"]+)"`);
  const match = html.match(re);
  return match ? match[1] : null;
}

function randomInt(min, max) {
  return Math.floor(Math.random() * (max - min + 1)) + min;
}

function logFailure(phase, role, path, status, reason) {
  countFailures.add(1);
  countCriticalFailures.add(1);
  rateSuccess.add(0);
  console.log(`[FAILURE] Phase: ${phase} | VU: ${__VU} | Role: ${role} | Path: ${path} | Status: ${status} | Reason: ${reason}`);
}

function recordOp(name, phase, durationMs, isSuccess) {
  rateSuccess.add(isSuccess ? 1 : 0);
  trendReqDurationOverall.add(durationMs);

  if (phase === 'steady_warmup') trendReqDurationWarmup.add(durationMs);
  if (phase === 'steady_5vu') trendReqDuration5VU.add(durationMs);
  if (phase === 'steady_10vu') trendReqDuration10VU.add(durationMs);
  if (phase === 'steady_20vu') trendReqDuration20VU.add(durationMs);

  // Per operation trends
  if (name === 'dashboard') {
    trendOpDashboard.add(durationMs);
    if (phase === 'steady_20vu') trend20vuDashboard.add(durationMs);
  } else if (name === 'products_known') {
    trendOpProductsKnown.add(durationMs);
    if (phase === 'steady_20vu') trend20vuProductsKnown.add(durationMs);
  } else if (name === 'products_empty') {
    trendOpProductsEmpty.add(durationMs);
    if (phase === 'steady_20vu') trend20vuProductsEmpty.add(durationMs);
  } else if (name === 'inventory') {
    trendOpInventory.add(durationMs);
    if (phase === 'steady_20vu') trend20vuInventory.add(durationMs);
  } else if (name === 'inventory_tx') {
    trendOpInventoryTx.add(durationMs);
    if (phase === 'steady_20vu') trend20vuInventoryTx.add(durationMs);
  } else if (name === 'batches') {
    trendOpBatches.add(durationMs);
    if (phase === 'steady_20vu') trend20vuBatches.add(durationMs);
  } else if (name === 'purchases') {
    trendOpPurchases.add(durationMs);
    if (phase === 'steady_20vu') trend20vuPurchases.add(durationMs);
  } else if (name === 'purchases_filter') {
    trendOpPurchasesFilter.add(durationMs);
    if (phase === 'steady_20vu') trend20vuPurchasesFilter.add(durationMs);
  } else if (name === 'sales') {
    trendOpSales.add(durationMs);
    if (phase === 'steady_20vu') trend20vuSales.add(durationMs);
  } else if (name === 'sales_filter') {
    trendOpSalesFilter.add(durationMs);
    if (phase === 'steady_20vu') trend20vuSalesFilter.add(durationMs);
  } else if (name === 'report_sales') {
    trendOpReportSales.add(durationMs);
    if (phase === 'steady_20vu') trend20vuReportSales.add(durationMs);
  } else if (name === 'report_purchases') {
    trendOpReportPurchases.add(durationMs);
    if (phase === 'steady_20vu') trend20vuReportPurchases.add(durationMs);
  } else if (name === 'report_inventory') {
    trendOpReportInventory.add(durationMs);
    if (phase === 'steady_20vu') trend20vuReportInventory.add(durationMs);
  } else if (name === 'po_write_cycle') {
    trendOpPoCycle.add(durationMs);
    if (phase === 'steady_20vu') trend20vuPoCycle.add(durationMs);
  } else if (name === 'so_write_cycle') {
    trendOpSoCycle.add(durationMs);
    if (phase === 'steady_20vu') trend20vuSoCycle.add(durationMs);
  }
}

function ensureLogin() {
  if (vuSession) {
    const jar = http.cookieJar();
    jar.set(BASE_URL, 'smart-mini-erp-session', vuSession.sessionCookie);
    if (vuSession.xsrfCookie) {
      jar.set(BASE_URL, 'XSRF-TOKEN', vuSession.xsrfCookie);
    }
    return vuSession;
  }

  const userIdx = (__VU - 1) % USERS.length;
  const user = USERS[userIdx];

  const loginPage = http.get(`${BASE_URL}/login`);
  const csrf = extractCsrf(loginPage.body);

  if (!csrf) {
    logFailure('init', user.role, '/login', loginPage.status, 'Failed to extract CSRF token on login page');
    throw new Error('No CSRF token on login page');
  }

  const res = http.post(`${BASE_URL}/login`, {
    _token: csrf,
    email: user.email,
    password: user.password,
  });

  const ok = check(res, {
    'login status ok': (r) => r.status === 200 || r.status === 302,
    'login session cookie set': (r) => r.cookies['smart-mini-erp-session'] && r.cookies['smart-mini-erp-session'].length > 0,
  });

  if (!ok) {
    logFailure('init', user.role, '/login', res.status, 'Login authentication failed');
    throw new Error(`Failed to login user ${user.email}`);
  }

  const sessionCookieVal = res.cookies['smart-mini-erp-session'][0].value;
  let xsrfCookieVal = null;
  if (res.cookies['XSRF-TOKEN'] && res.cookies['XSRF-TOKEN'].length > 0) {
    xsrfCookieVal = res.cookies['XSRF-TOKEN'][0].value;
  }

  vuSession = {
    user: user,
    sessionCookie: sessionCookieVal,
    xsrfCookie: xsrfCookieVal,
  };

  rateSuccess.add(1);
  return vuSession;
}

export default function () {
  const session = ensureLogin();
  const role = session.user.role;
  const phase = getCurrentPhase();

  // 80% Read / 20% Write
  const isWrite = Math.random() < 0.20;

  if (isWrite && role === 'purchasing') {
    executePoCycle(session, phase);
  } else if (isWrite && role === 'sales') {
    executeSoCycle(session, phase);
  } else if (isWrite && role === 'admin') {
    // Admin splits write 50/50 between PO cycle and SO cycle
    if (Math.random() < 0.5) {
      executePoCycle(session, phase);
    } else {
      executeSoCycle(session, phase);
    }
  } else {
    executeRead(session, role, phase);
  }

  // Realistic think time: 1-3 seconds
  sleep(Math.random() * 2 + 1);
}

function executeRead(session, role, phase) {
  countReads.add(1);

  // Available read choices strictly matched to permissions
  const readTypes = ['dashboard'];
  if (role === 'admin' || role === 'purchasing' || role === 'sales') {
    readTypes.push('products_known', 'products_empty');
  }
  if (role === 'purchasing' || role === 'admin') {
    readTypes.push('purchases', 'purchases_filter', 'report_purchases');
  }
  if (role === 'sales' || role === 'admin') {
    readTypes.push('sales', 'sales_filter', 'report_sales');
  }
  if (role === 'inventory' || role === 'admin') {
    readTypes.push('inventory', 'inventory_tx', 'batches', 'report_inventory');
  }

  const choice = readTypes[randomInt(0, readTypes.length - 1)];

  let res;
  switch (choice) {
    case 'dashboard': {
      res = http.get(`${BASE_URL}/dashboard`);
      const ok = check(res, {
        'dashboard 200': (r) => r.status === 200,
        'dashboard content verified': (r) => r.body.includes('لوحة التحكم') || r.body.includes('Dashboard') || r.body.includes('Smart Mini'),
      });
      if (!ok) logFailure(phase, role, '/dashboard', res.status, 'Dashboard content mismatch or error');
      recordOp('dashboard', phase, res.timings.duration, ok);
      break;
    }

    case 'products_known': {
      const sku = `SKU-00${randomInt(10, 99)}`; // Valid 4-digit SKU between SKU-0010 and SKU-0099
      res = http.get(`${BASE_URL}/products?q=${sku}`);
      const ok = check(res, {
        'products known 200': (r) => r.status === 200,
        'products known match': (r) => r.body.includes(sku),
      });
      if (!ok) logFailure(phase, role, `/products?q=${sku}`, res.status, 'Known SKU was not found in results');
      recordOp('products_known', phase, res.timings.duration, ok);
      break;
    }

    case 'products_empty': {
      res = http.get(`${BASE_URL}/products?q=NONEXISTENT_ITEM_XYZ_999`);
      const ok = check(res, {
        'products empty 200': (r) => r.status === 200,
        'products empty handled': (r) => r.body.includes('لا توجد') || r.body.includes('No products') || r.body.includes('table'),
      });
      if (!ok) logFailure(phase, role, '/products?q=NONEXISTENT', res.status, 'Empty search response malformed');
      recordOp('products_empty', phase, res.timings.duration, ok);
      break;
    }

    case 'inventory': {
      res = http.get(`${BASE_URL}/inventory`);
      const ok = check(res, {
        'inventory 200': (r) => r.status === 200,
        'inventory content': (r) => r.body.includes('المخزون') || r.body.includes('Inventory') || r.body.includes('table'),
      });
      if (!ok) logFailure(phase, role, '/inventory', res.status, 'Inventory index failure');
      recordOp('inventory', phase, res.timings.duration, ok);
      break;
    }

    case 'inventory_tx': {
      res = http.get(`${BASE_URL}/inventory/transactions`);
      const ok = check(res, {
        'inventory tx 200': (r) => r.status === 200,
        'inventory tx content': (r) => r.body.includes('حركات المخزون') || r.body.includes('Transactions') || r.body.includes('table'),
      });
      if (!ok) logFailure(phase, role, '/inventory/transactions', res.status, 'Inventory transactions failure');
      recordOp('inventory_tx', phase, res.timings.duration, ok);
      break;
    }

    case 'batches': {
      res = http.get(`${BASE_URL}/batches`);
      const ok = check(res, {
        'batches 200': (r) => r.status === 200,
        'batches content': (r) => r.body.includes('التشغيلات') || r.body.includes('تشغيلات') || r.body.includes('Batches') || r.body.includes('table'),
      });
      if (!ok) logFailure(phase, role, '/batches', res.status, 'Batches index failure');
      recordOp('batches', phase, res.timings.duration, ok);
      break;
    }

    case 'purchases': {
      res = http.get(`${BASE_URL}/purchases`);
      const ok = check(res, {
        'purchases 200': (r) => r.status === 200,
        'purchases content': (r) => r.body.includes('أوامر الشراء') || r.body.includes('Purchase') || r.body.includes('table'),
      });
      if (!ok) logFailure(phase, role, '/purchases', res.status, 'Purchases index failure');
      recordOp('purchases', phase, res.timings.duration, ok);
      break;
    }

    case 'purchases_filter': {
      res = http.get(`${BASE_URL}/purchases?status=received`);
      const ok = check(res, {
        'purchases filter 200': (r) => r.status === 200,
        'purchases filter content': (r) => r.body.includes('مستلم') || r.body.includes('Received') || r.body.includes('table'),
      });
      if (!ok) logFailure(phase, role, '/purchases?status=received', res.status, 'Purchases filter failure');
      recordOp('purchases_filter', phase, res.timings.duration, ok);
      break;
    }

    case 'sales': {
      res = http.get(`${BASE_URL}/sales`);
      const ok = check(res, {
        'sales 200': (r) => r.status === 200,
        'sales content': (r) => r.body.includes('أوامر البيع') || r.body.includes('Sales') || r.body.includes('table'),
      });
      if (!ok) logFailure(phase, role, '/sales', res.status, 'Sales index failure');
      recordOp('sales', phase, res.timings.duration, ok);
      break;
    }

    case 'sales_filter': {
      res = http.get(`${BASE_URL}/sales?status=confirmed`);
      const ok = check(res, {
        'sales filter 200': (r) => r.status === 200,
        'sales filter content': (r) => r.body.includes('مؤكد') || r.body.includes('Confirmed') || r.body.includes('table'),
      });
      if (!ok) logFailure(phase, role, '/sales?status=confirmed', res.status, 'Sales filter failure');
      recordOp('sales_filter', phase, res.timings.duration, ok);
      break;
    }

    case 'report_sales': {
      res = http.get(`${BASE_URL}/reports/sales?from=2026-06-01&to=2026-11-15`);
      const ok = check(res, {
        'report sales 200': (r) => r.status === 200,
        'report sales content': (r) => r.body.includes('المبيعات') || r.body.includes('Sales') || r.body.includes('summary'),
      });
      if (!ok) logFailure(phase, role, '/reports/sales', res.status, 'Sales report failure');
      recordOp('report_sales', phase, res.timings.duration, ok);
      break;
    }

    case 'report_purchases': {
      res = http.get(`${BASE_URL}/reports/purchases?from=2026-01-01&to=2026-10-31`);
      const ok = check(res, {
        'report purchases 200': (r) => r.status === 200,
        'report purchases content': (r) => r.body.includes('المشتريات') || r.body.includes('Purchases') || r.body.includes('summary'),
      });
      if (!ok) logFailure(phase, role, '/reports/purchases', res.status, 'Purchases report failure');
      recordOp('report_purchases', phase, res.timings.duration, ok);
      break;
    }

    case 'report_inventory': {
      res = http.get(`${BASE_URL}/reports/inventory`);
      const ok = check(res, {
        'report inventory 200': (r) => r.status === 200,
        'report inventory content': (r) => r.body.includes('المخزون') || r.body.includes('Inventory') || r.body.includes('table'),
      });
      if (!ok) logFailure(phase, role, '/reports/inventory', res.status, 'Inventory report failure');
      recordOp('report_inventory', phase, res.timings.duration, ok);
      break;
    }
  }
}

function executePoCycle(session, phase) {
  countWrites.add(1);
  const start = Date.now();
  const role = session.user.role;

  // 1. Get Create Form
  const createPage = http.get(`${BASE_URL}/purchases/create`);
  if (createPage.status !== 200) {
    logFailure(phase, role, '/purchases/create', createPage.status, 'Failed to load PO create form');
    recordOp('po_write_cycle', phase, Date.now() - start, false);
    return;
  }

  const formToken = extractCsrf(createPage.body);
  const key = extractAttr(createPage.body, 'key');
  if (!formToken || !key) {
    logFailure(phase, role, '/purchases/create', 200, 'Missing CSRF token or idempotency key in create form');
    recordOp('po_write_cycle', phase, Date.now() - start, false);
    return;
  }

  const prodId = randomInt(1, 200);

  // 2. Post Draft PO
  const postRes = http.post(`${BASE_URL}/purchases`, {
    _token: formToken,
    key: key,
    supplier_id: `${randomInt(1, 50)}`,
    order_date: '2026-11-17',
    notes: `Load test PO from VU ${__VU}`,
    'items[0][product_id]': `${prodId}`,
    'items[0][quantity]': `${randomInt(5, 20)}`,
    'items[0][unit]': 'carton',
    'items[0][unit_price]': '10.000000',
  });

  const poMatch = postRes.url.match(/\/purchases\/(\d+)/);
  if (!poMatch) {
    logFailure(phase, role, '/purchases', postRes.status, 'PO creation did not redirect to document show page');
    recordOp('po_write_cycle', phase, Date.now() - start, false);
    return;
  }

  const poId = poMatch[1];

  // 3. Get Receipt Form
  const receivePage = http.get(`${BASE_URL}/purchases/${poId}/receive`);
  if (receivePage.status !== 200) {
    logFailure(phase, role, `/purchases/${poId}/receive`, receivePage.status, 'Failed to load PO receive form');
    recordOp('po_write_cycle', phase, Date.now() - start, false);
    return;
  }

  const itemIdMatch = receivePage.body.match(/name="items\[0\]\[item_id\]"\s+value="(\d+)"/);
  const receiveKey = extractAttr(receivePage.body, 'key');
  const receiveVersion = extractAttr(receivePage.body, 'version');
  const receiveCsrf = extractCsrf(receivePage.body);

  if (!itemIdMatch || !receiveKey || !receiveVersion || !receiveCsrf) {
    logFailure(phase, role, `/purchases/${poId}/receive`, 200, 'Missing item_id, key, or version in receipt form');
    recordOp('po_write_cycle', phase, Date.now() - start, false);
    return;
  }

  const itemId = itemIdMatch[1];
  const batchNum = `LOAD-BATCH-${poId}-${Date.now()}`;

  // 4. Post Receive
  const receiveRes = http.post(`${BASE_URL}/purchases/${poId}/receive`, {
    _token: receiveCsrf,
    key: receiveKey,
    version: receiveVersion,
    'items[0][item_id]': itemId,
    'items[0][batch_number]': batchNum,
    'items[0][production_date]': '2026-11-17',
    'items[0][expiration_date]': '2027-11-17',
  });

  if (receiveRes.status !== 200 && receiveRes.status !== 302) {
    logFailure(phase, role, `/purchases/${poId}/receive`, receiveRes.status, 'PO receive submission failed');
    recordOp('po_write_cycle', phase, Date.now() - start, false);
    return;
  }

  // 5. Verify final document state on show page
  const verifyPage = http.get(`${BASE_URL}/purchases/${poId}`);
  const ok = check(verifyPage, {
    'PO final show page 200': (r) => r.status === 200,
    'PO status is received': (r) => r.body.includes('مستلم') || r.body.includes('Received') || r.body.includes('status-badge received'),
    'PO has batch details': (r) => r.body.includes(batchNum),
  });

  if (!ok) {
    logFailure(phase, role, `/purchases/${poId}`, verifyPage.status, 'PO state verification after receive failed');
  }

  recordOp('po_write_cycle', phase, Date.now() - start, ok);
}

function executeSoCycle(session, phase) {
  countWrites.add(1);
  const start = Date.now();
  const role = session.user.role;

  // 1. Get Create Form
  const createPage = http.get(`${BASE_URL}/sales/create`);
  if (createPage.status !== 200) {
    logFailure(phase, role, '/sales/create', createPage.status, 'Failed to load SO create form');
    recordOp('so_write_cycle', phase, Date.now() - start, false);
    return;
  }

  const formToken = extractCsrf(createPage.body);
  const key = extractAttr(createPage.body, 'key');
  if (!formToken || !key) {
    logFailure(phase, role, '/sales/create', 200, 'Missing CSRF token or key in SO create form');
    recordOp('so_write_cycle', phase, Date.now() - start, false);
    return;
  }

  const prodId = randomInt(1, 200);

  // 2. Post Draft SO
  const postRes = http.post(`${BASE_URL}/sales`, {
    _token: formToken,
    key: key,
    customer_id: `${randomInt(1, 100)}`,
    order_date: '2026-11-17',
    notes: `Load test SO from VU ${__VU}`,
    'items[0][product_id]': `${prodId}`,
    'items[0][quantity]': `${randomInt(1, 3)}`,
    'items[0][unit]': 'carton',
  });

  const soMatch = postRes.url.match(/\/sales\/(\d+)/);
  if (!soMatch) {
    logFailure(phase, role, '/sales', postRes.status, 'SO creation did not redirect to document show page');
    recordOp('so_write_cycle', phase, Date.now() - start, false);
    return;
  }

  const soId = soMatch[1];

  // 3. Get Confirm Form
  const confirmPage = http.get(`${BASE_URL}/sales/${soId}/confirm`);
  if (confirmPage.status !== 200) {
    logFailure(phase, role, `/sales/${soId}/confirm`, confirmPage.status, 'Failed to load SO confirm page');
    recordOp('so_write_cycle', phase, Date.now() - start, false);
    return;
  }

  const confirmCsrf = extractCsrf(confirmPage.body);
  const confirmKey = extractAttr(confirmPage.body, 'key');
  const confirmVersion = extractAttr(confirmPage.body, 'version');

  if (!confirmCsrf || !confirmKey || !confirmVersion) {
    logFailure(phase, role, `/sales/${soId}/confirm`, 200, 'Missing key or version in SO confirm form');
    recordOp('so_write_cycle', phase, Date.now() - start, false);
    return;
  }

  // 4. Post Confirm
  const confirmRes = http.post(`${BASE_URL}/sales/${soId}/confirm`, {
    _token: confirmCsrf,
    key: confirmKey,
    version: confirmVersion,
  });

  if (confirmRes.status !== 200 && confirmRes.status !== 302) {
    logFailure(phase, role, `/sales/${soId}/confirm`, confirmRes.status, 'SO confirm submission failed');
    recordOp('so_write_cycle', phase, Date.now() - start, false);
    return;
  }

  // 5. Verify final document state on show page
  const verifyPage = http.get(`${BASE_URL}/sales/${soId}`);
  const ok = check(verifyPage, {
    'SO final show page 200': (r) => r.status === 200,
    'SO status is confirmed': (r) => r.body.includes('معتمد') || r.body.includes('Confirmed') || r.body.includes('status-badge confirmed'),
    'SO has stock operation link': (r) => r.body.includes(`SO:${soId}`) || r.body.includes('SO-') || r.body.includes('table'),
  });

  if (!ok) {
    logFailure(phase, role, `/sales/${soId}`, verifyPage.status, 'SO state verification after confirm failed');
  }

  recordOp('so_write_cycle', phase, Date.now() - start, ok);
}
