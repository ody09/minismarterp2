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

  // 2. Fetch operation belonging to another document (Operation #1 is PO:1)
  const otherOpUrl = `${BASE_URL}/inventory/operations/1`;
  const otherOpRes = http.get(otherOpUrl, { jar });
  const h1Match = otherOpRes.body.match(/<h1>\s*([^<]+?)\s*<\/h1>/);
  const otherOpRef = h1Match ? h1Match[1].trim() : '';

  // Expected reference for a hypothetical SO
  const expectedSOReference = 'SO:99999';

  // DELIBERATELY ASSERT that Operation #1's reference matches SO:99999
  // Since Operation #1 is 'PO:1', this assertion MUST FAIL and prove rejection
  const pass = check(otherOpRes, {
    'mismatched_op_assert: operation reference must equal SO:99999': () => otherOpRef === expectedSOReference,
  });

  if (!pass) {
    criticalFailures.add(1);
  }
}
