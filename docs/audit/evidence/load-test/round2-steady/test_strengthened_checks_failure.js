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

export default function () {
  const user = USERS[0];
  const jar = http.cookieJar();

  // 1. Login
  const loginPage = http.get(`${BASE_URL}/login`, { jar });
  const csrf = extractCsrf(loginPage.body);
  http.post(`${BASE_URL}/login`, {
    _token: csrf,
    email: user.email,
    password: user.password,
  }, { jar, redirects: 2 });

  // 2. PROVE FAILURE: Known search deliberately checking for a bogus product inside tbody
  {
    const targetSku = 'SKU-0010';
    const res = http.get(`${BASE_URL}/products?q=${targetSku}`, { jar });
    const tbodyMatch = res.body.match(/<tbody>([\s\S]*?)<\/tbody>/);
    const tbody = tbodyMatch ? tbodyMatch[1] : '';

    // Deliberately check for BOGUS_SKU_NONEXISTENT inside tbody
    const hasProductRowInTbody = tbody.includes('Product BOGUS_SKU_NONEXISTENT');

    const pass = check(res, {
      'deliberate_fail_search: tbody contains bogus product': () => hasProductRowInTbody,
    });
    if (!pass) criticalFailures.add(1);
  }

  // 3. PROVE FAILURE: Check status badge looking for wrong text
  {
    const res = http.get(`${BASE_URL}/sales`, { jar });
    const pillBadgeMatch = res.body.match(/<span class="pill[^"]*">([\s\S]*?)<\/span>/);
    const pillBadgeText = pillBadgeMatch ? pillBadgeMatch[1].trim() : '';

    // Deliberately check for impossible pill text
    const isBadgeImpossible = pillBadgeText === 'INVALID_STATUS_NEVER_EXISTS';

    const pass = check(res, {
      'deliberate_fail_badge: status pill shows impossible value': () => isBadgeImpossible,
    });
    if (!pass) criticalFailures.add(1);
  }
}
