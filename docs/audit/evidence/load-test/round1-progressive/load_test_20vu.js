import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend, Counter, Rate } from 'k6/metrics';

// Custom Metrics
const metricReadDashboard = new Trend('erp_read_dashboard', true);
const metricReadProducts = new Trend('erp_read_products', true);
const metricReadInventory = new Trend('erp_read_inventory', true);
const metricReadInventoryTx = new Trend('erp_read_inventory_tx', true);
const metricReadPurchases = new Trend('erp_read_purchases', true);
const metricReadSales = new Trend('erp_read_sales', true);
const metricReadReportSales = new Trend('erp_read_report_sales', true);
const metricReadReportPurchases = new Trend('erp_read_report_purchases', true);
const metricReadReportInventory = new Trend('erp_read_report_inventory', true);

const metricWritePoCycle = new Trend('erp_write_po_cycle', true);
const metricWriteSoCycle = new Trend('erp_write_so_cycle', true);

const countReads = new Counter('erp_total_reads');
const countWrites = new Counter('erp_total_writes');
const countErrors = new Counter('erp_total_errors');
const rateSuccess = new Rate('erp_success_rate');

export const options = {
  stages: [
    { duration: '1m', target: 2 },   // Phase 1: Warmup (1m, 2 VUs)
    { duration: '2m', target: 5 },   // Phase 2: 5 VUs (2m)
    { duration: '2m', target: 10 },  // Phase 3: 10 VUs (2m)
    { duration: '10m', target: 20 }, // Phase 4: 20 VUs (10m)
    { duration: '10s', target: 0 },  // Cooldown
  ],
  thresholds: {
    http_req_failed: ['rate<0.05'],
    erp_success_rate: ['rate>0.95'],
  },
};

const BASE_URL = 'http://127.0.0.1:8088';

const USERS = [
  // Admin 1-5
  { email: 'admin1@bench.local', role: 'admin' },
  { email: 'admin2@bench.local', role: 'admin' },
  { email: 'admin3@bench.local', role: 'admin' },
  { email: 'admin4@bench.local', role: 'admin' },
  { email: 'admin5@bench.local', role: 'admin' },
  // Purchasing 1-5
  { email: 'purchasing1@bench.local', role: 'purchasing' },
  { email: 'purchasing2@bench.local', role: 'purchasing' },
  { email: 'purchasing3@bench.local', role: 'purchasing' },
  { email: 'purchasing4@bench.local', role: 'purchasing' },
  { email: 'purchasing5@bench.local', role: 'purchasing' },
  // Sales 1-5
  { email: 'sales1@bench.local', role: 'sales' },
  { email: 'sales2@bench.local', role: 'sales' },
  { email: 'sales3@bench.local', role: 'sales' },
  { email: 'sales4@bench.local', role: 'sales' },
  { email: 'sales5@bench.local', role: 'sales' },
  // Inventory 1-5
  { email: 'inventory1@bench.local', role: 'inventory' },
  { email: 'inventory2@bench.local', role: 'inventory' },
  { email: 'inventory3@bench.local', role: 'inventory' },
  { email: 'inventory4@bench.local', role: 'inventory' },
  { email: 'inventory5@bench.local', role: 'inventory' },
];

let vuSession = null;

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

  const res = http.post(`${BASE_URL}/login`, {
    _token: csrf,
    email: user.email,
    password: __ENV.BENCH_USER_PASSWORD || 'REDACTED_PASSWORD',
  });

  const ok = check(res, {
    'login status ok': (r) => r.status === 200 || r.status === 302,
  });

  if (!ok) {
    countErrors.add(1);
    rateSuccess.add(0);
    throw new Error(`Failed to login user ${user.email}`);
  }

  let sessionCookieVal = null;
  let xsrfCookieVal = null;
  if (res.cookies['smart-mini-erp-session'] && res.cookies['smart-mini-erp-session'].length > 0) {
    sessionCookieVal = res.cookies['smart-mini-erp-session'][0].value;
  }
  if (res.cookies['XSRF-TOKEN'] && res.cookies['XSRF-TOKEN'].length > 0) {
    xsrfCookieVal = res.cookies['XSRF-TOKEN'][0].value;
  }

  vuSession = {
    user: user,
    sessionCookie: sessionCookieVal,
    xsrfCookie: xsrfCookieVal,
    csrfToken: extractCsrf(res.body) || csrf,
  };

  rateSuccess.add(1);
  return vuSession;
}

export default function () {
  const session = ensureLogin();
  const role = session.user.role;

  // 80% Read, 20% Write
  const isWrite = Math.random() < 0.20;

  if (isWrite && (role === 'purchasing' || role === 'admin')) {
    executePoCycle(session);
  } else if (isWrite && (role === 'sales' || (role === 'admin' && Math.random() < 0.5))) {
    executeSoCycle(session);
  } else {
    executeRead(session, role);
  }

  // Realistic user think time: 1-3 seconds
  sleep(Math.random() * 2 + 1);
}

function executeRead(session, role) {
  countReads.add(1);

  // Available read choices according to role
  const readTypes = ['dashboard', 'products'];
  if (role === 'purchasing' || role === 'admin') {
    readTypes.push('purchases', 'purchases_filter', 'report_purchases');
  }
  if (role === 'sales' || role === 'admin') {
    readTypes.push('sales', 'sales_filter', 'report_sales');
  }
  if (role === 'inventory' || role === 'admin') {
    readTypes.push('inventory', 'inventory_tx', 'report_inventory');
  }

  const choice = readTypes[randomInt(0, readTypes.length - 1)];

  let res;
  switch (choice) {
    case 'dashboard':
      res = http.get(`${BASE_URL}/dashboard`);
      metricReadDashboard.add(res.timings.duration);
      check(res, { 'dashboard 200': (r) => r.status === 200 && r.body.includes('Smart Mini') }) ? rateSuccess.add(1) : countErrors.add(1);
      break;

    case 'products': {
      const q = `SKU-00${randomInt(1, 9)}`;
      res = http.get(`${BASE_URL}/products?q=${q}`);
      metricReadProducts.add(res.timings.duration);
      check(res, { 'products 200': (r) => r.status === 200 && r.body.includes('SKU-') }) ? rateSuccess.add(1) : countErrors.add(1);
      break;
    }

    case 'inventory':
      res = http.get(`${BASE_URL}/inventory`);
      metricReadInventory.add(res.timings.duration);
      check(res, { 'inventory 200': (r) => r.status === 200 }) ? rateSuccess.add(1) : countErrors.add(1);
      break;

    case 'inventory_tx':
      res = http.get(`${BASE_URL}/inventory/transactions`);
      metricReadInventoryTx.add(res.timings.duration);
      check(res, { 'inventory tx 200': (r) => r.status === 200 }) ? rateSuccess.add(1) : countErrors.add(1);
      break;

    case 'purchases':
      res = http.get(`${BASE_URL}/purchases`);
      metricReadPurchases.add(res.timings.duration);
      check(res, { 'purchases 200': (r) => r.status === 200 }) ? rateSuccess.add(1) : countErrors.add(1);
      break;

    case 'purchases_filter':
      res = http.get(`${BASE_URL}/purchases?status=received`);
      metricReadPurchases.add(res.timings.duration);
      check(res, { 'purchases filter 200': (r) => r.status === 200 }) ? rateSuccess.add(1) : countErrors.add(1);
      break;

    case 'sales':
      res = http.get(`${BASE_URL}/sales`);
      metricReadSales.add(res.timings.duration);
      check(res, { 'sales 200': (r) => r.status === 200 }) ? rateSuccess.add(1) : countErrors.add(1);
      break;

    case 'sales_filter':
      res = http.get(`${BASE_URL}/sales?status=confirmed`);
      metricReadSales.add(res.timings.duration);
      check(res, { 'sales filter 200': (r) => r.status === 200 }) ? rateSuccess.add(1) : countErrors.add(1);
      break;

    case 'report_sales':
      res = http.get(`${BASE_URL}/reports/sales?from=2026-06-01&to=2026-11-15`);
      metricReadReportSales.add(res.timings.duration);
      check(res, { 'report sales 200': (r) => r.status === 200 }) ? rateSuccess.add(1) : countErrors.add(1);
      break;

    case 'report_purchases':
      res = http.get(`${BASE_URL}/reports/purchases?from=2026-01-01&to=2026-10-31`);
      metricReadReportPurchases.add(res.timings.duration);
      check(res, { 'report purchases 200': (r) => r.status === 200 }) ? rateSuccess.add(1) : countErrors.add(1);
      break;

    case 'report_inventory':
      res = http.get(`${BASE_URL}/reports/inventory`);
      metricReadReportInventory.add(res.timings.duration);
      check(res, { 'report inventory 200': (r) => r.status === 200 }) ? rateSuccess.add(1) : countErrors.add(1);
      break;
  }
}

function executePoCycle(session) {
  countWrites.add(1);
  const start = Date.now();

  const createPage = http.get(`${BASE_URL}/purchases/create`);
  const formToken = extractCsrf(createPage.body);
  const key = extractAttr(createPage.body, 'key');
  const prodId = randomInt(1, 200);

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
    console.log(`[PO CREATE FAIL] status=${postRes.status} url=${postRes.url}`);
    countErrors.add(1);
    rateSuccess.add(0);
    return;
  }

  const poId = poMatch[1];
  const receivePage = http.get(`${BASE_URL}/purchases/${poId}/receive`);
  const itemIdMatch = receivePage.body.match(/name="items\[0\]\[item_id\]"\s+value="(\d+)"/);
  const itemId = itemIdMatch ? itemIdMatch[1] : '1';

  const receiveRes = http.post(`${BASE_URL}/purchases/${poId}/receive`, {
    _token: extractCsrf(receivePage.body),
    key: extractAttr(receivePage.body, 'key'),
    version: extractAttr(receivePage.body, 'version'),
    'items[0][item_id]': itemId,
    'items[0][batch_number]': `LOAD-BATCH-${poId}-${Date.now()}`,
    'items[0][production_date]': '2026-11-17',
    'items[0][expiration_date]': '2027-11-17',
  });

  const ok = check(receiveRes, {
    'po cycle completed': (r) => r.status === 200 || r.status === 302,
  });

  if (ok) {
    metricWritePoCycle.add(Date.now() - start);
    rateSuccess.add(1);
  } else {
    countErrors.add(1);
    rateSuccess.add(0);
  }
}

function executeSoCycle(session) {
  countWrites.add(1);
  const start = Date.now();

  const createPage = http.get(`${BASE_URL}/sales/create`);
  const formToken = extractCsrf(createPage.body);
  const key = extractAttr(createPage.body, 'key');
  const prodId = randomInt(1, 200);

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
    console.log(`[SO CREATE FAIL] status=${postRes.status} url=${postRes.url}`);
    countErrors.add(1);
    rateSuccess.add(0);
    return;
  }

  const soId = soMatch[1];
  const confirmPage = http.get(`${BASE_URL}/sales/${soId}/confirm`);
  const confirmRes = http.post(`${BASE_URL}/sales/${soId}/confirm`, {
    _token: extractCsrf(confirmPage.body),
    key: extractAttr(confirmPage.body, 'key'),
    version: extractAttr(confirmPage.body, 'version'),
  });

  const ok = check(confirmRes, {
    'so cycle completed': (r) => r.status === 200 || r.status === 302,
  });

  if (ok) {
    metricWriteSoCycle.add(Date.now() - start);
    rateSuccess.add(1);
  } else {
    countErrors.add(1);
    rateSuccess.add(0);
  }
}
