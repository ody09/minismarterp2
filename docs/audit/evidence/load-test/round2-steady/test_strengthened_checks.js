import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';

const USERS = JSON.parse(open('./bench_users.json'));
const BASE_URL = 'http://127.0.0.1:8088';

const criticalFailures = new Counter('erp_critical_failures');

export const options = {
  vus: 1,
  iterations: 1,
  thresholds: {
    erp_critical_failures: ['count==0'],
  },
};

function extractCsrf(html) {
  if (!html) return null;
  const match = html.match(/name="_token"\s+value="([^"]+)"/);
  return match ? match[1] : null;
}

function extractAttr(html, name) {
  if (!html) return null;
  const regex = new RegExp(`name="${name}"\\s+value="([^"]+)"`, 'i');
  const match = html.match(regex);
  return match ? match[1] : null;
}

export default function () {
  // Use Admin user (User #1)
  const user = USERS[0];
  const jar = http.cookieJar();

  // 1. Login
  const loginPage = http.get(`${BASE_URL}/login`, { jar });
  const csrf = extractCsrf(loginPage.body);
  if (!csrf) {
    criticalFailures.add(1);
    throw new Error('Failed to extract CSRF token on login');
  }

  const loginRes = http.post(`${BASE_URL}/login`, {
    _token: csrf,
    email: user.email,
    password: user.password,
  }, { jar, redirects: 2 });

  if (loginRes.status !== 200) {
    criticalFailures.add(1);
    throw new Error(`Login failed with status ${loginRes.status}`);
  }

  // 2. CHECK 1: Known Product Search (Examine tbody only)
  {
    const targetSku = 'SKU-0010';
    const res = http.get(`${BASE_URL}/products?q=${targetSku}`, { jar });
    const tbodyMatch = res.body.match(/<tbody>([\s\S]*?)<\/tbody>/);
    const tbody = tbodyMatch ? tbodyMatch[1] : '';

    const hasProductRowInTbody = tbody.includes(`Product ${targetSku}`) && tbody.includes('<strong>');
    const noEmptyStateInTbody = !tbody.includes('class="empty-state"');

    const pass = check(res, {
      'known_search: status is 200': (r) => r.status === 200,
      'known_search: tbody contains product row': () => hasProductRowInTbody,
      'known_search: tbody has no empty-state': () => noEmptyStateInTbody,
    });
    if (!pass) criticalFailures.add(1);
  }

  // 3. CHECK 2: Empty Product Search (Examine tbody empty-state)
  {
    const nonExistent = 'NONEXISTENT_ITEM_XYZ_999';
    const res = http.get(`${BASE_URL}/products?q=${nonExistent}`, { jar });
    const tbodyMatch = res.body.match(/<tbody>([\s\S]*?)<\/tbody>/);
    const tbody = tbodyMatch ? tbodyMatch[1] : '';

    const hasEmptyState = tbody.includes('class="empty-state"');
    const noProductRows = !tbody.includes('<strong>Product SKU-');

    const pass = check(res, {
      'empty_search: status is 200': (r) => r.status === 200,
      'empty_search: tbody has empty-state': () => hasEmptyState,
      'empty_search: tbody has no product rows': () => noProductRows,
    });
    if (!pass) criticalFailures.add(1);
  }

  // 4. Create and Confirm Sales Order to test Status Badge and Stock Operation Reference
  {
    // Load create form
    const createPage = http.get(`${BASE_URL}/sales/create`, { jar });
    const formToken = extractCsrf(createPage.body);
    const formKey = extractAttr(createPage.body, 'key');

    const postPayload = {
      _token: formToken,
      key: formKey,
      customer_id: '1',
      order_date: '2026-11-17',
      notes: 'Strict reference validation experiment',
      'items[0][product_id]': '10', // Product SKU-0010
      'items[0][quantity]': '1',
      'items[0][unit]': 'carton',
    };

    const createRes = http.post(`${BASE_URL}/sales`, postPayload, { jar, redirects: 2 });
    const soMatch = createRes.url.match(/\/sales\/(\d+)/);
    if (!soMatch) {
      criticalFailures.add(1);
      throw new Error(`Failed to create SO, redirected to ${createRes.url}`);
    }
    const soId = soMatch[1];
    const expectedReference = `SO:${soId}`;

    // Load confirm form
    const confirmPage = http.get(`${BASE_URL}/sales/${soId}/confirm`, { jar });
    const confirmToken = extractCsrf(confirmPage.body);
    const confirmKey = extractAttr(confirmPage.body, 'key');
    const confirmVersion = extractAttr(confirmPage.body, 'version');

    const confirmRes = http.post(`${BASE_URL}/sales/${soId}/confirm`, {
      _token: confirmToken,
      key: confirmKey,
      version: confirmVersion,
    }, { jar, redirects: 2 });

    // Load show page to verify document state
    const showPage = http.get(`${BASE_URL}/sales/${soId}`, { jar });

    // CHECK 3: Document Status Badge (Examine pill badge specifically)
    const pillBadgeMatch = showPage.body.match(/<span class="pill[^"]*">([\s\S]*?)<\/span>/);
    const pillBadgeText = pillBadgeMatch ? pillBadgeMatch[1].trim() : '';
    const isBadgeConfirmed = pillBadgeText === 'معتمد' || pillBadgeText === 'Confirmed';

    const badgePass = check(showPage, {
      'so_confirm: status is 200': (r) => r.status === 200,
      'so_confirm: status pill specifically shows confirmed': () => isBadgeConfirmed,
    });
    if (!badgePass) criticalFailures.add(1);

    // CHECK 4: Strict Literal Reference Verification on the Linked Stock Operation
    const stockLinkMatch = showPage.body.match(/href="([^"]*\/inventory\/operations\/(\d+))"/);
    const stockOpUrl = stockLinkMatch ? stockLinkMatch[1] : null;
    const stockOpId = stockLinkMatch ? stockLinkMatch[2] : null;

    let validOpRefMatchesStrictly = false;
    let actualValidOpRef = '';

    if (stockOpUrl && stockOpId) {
      const opRes = http.get(stockOpUrl, { jar });
      const h1Match = opRes.body.match(/<h1>\s*([^<]+?)\s*<\/h1>/);
      actualValidOpRef = h1Match ? h1Match[1].trim() : '';

      // Literal strict equality: must equal "SO:{soId}" exactly, no fallback
      validOpRefMatchesStrictly = (opRes.status === 200) && (actualValidOpRef === expectedReference);
    }

    const validOpPass = check(showPage, {
      'so_confirm: stock operation link exists': () => stockOpUrl !== null && stockOpId !== null,
      'so_confirm: operation dedicated reference matches SO:{soId} literally': () => validOpRefMatchesStrictly,
    });
    if (!validOpPass) criticalFailures.add(1);

    // CHECK 5: Verify that an Operation Belonging to Another Document is REJECTED
    // We fetch operation ID 1 (which belongs to a different document, PO:1)
    const otherOpUrl = `${BASE_URL}/inventory/operations/1`;
    const otherOpRes = http.get(otherOpUrl, { jar });
    const otherH1Match = otherOpRes.body.match(/<h1>\s*([^<]+?)\s*<\/h1>/);
    const otherOpRef = otherH1Match ? otherH1Match[1].trim() : '';

    // Verify otherOpRef does NOT match expectedReference (proves rejection of mismatched doc)
    const isMismatchedRejected = (otherOpRef !== expectedReference);

    const mismatchPass = check(otherOpRes, {
      'other_op: correctly rejects mismatched document reference': () => isMismatchedRejected,
    });
    if (!mismatchPass) criticalFailures.add(1);
  }
}
