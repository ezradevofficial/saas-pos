// NFR-02: the pre-release load test for standard reads (docs/performance.md).
// Run with k6 (installed on the tester's machine, not a project dependency):
//
//   k6 run -e API=https://api.staging.example -e TOKEN=<a staging user's bearer token> scripts/load/k6-reads.js
//
// Fails (exit code) when the p95 of standard reads is 500 ms or more, or
// more than 1% of requests fail. Use a staging tenant with realistic data,
// never production.
import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  scenarios: {
    back_office: { executor: 'ramping-vus', startVUs: 0, stages: [{ duration: '1m', target: 50 }, { duration: '5m', target: 50 }, { duration: '1m', target: 0 }] },
  },
  thresholds: {
    'http_req_duration{kind:read}': ['p(95)<500'],
    http_req_failed: ['rate<0.01'],
  },
};

const READS = ['me', 'me/permissions', 'companies?per_page=25', 'parties?per_page=25', 'items?per_page=25', 'approvals?status=waiting&per_page=25', 'notifications/unread-count'];

export default function () {
  const headers = { Authorization: `Bearer ${__ENV.TOKEN}`, Accept: 'application/json' };
  for (const path of READS) {
    const response = http.get(`${__ENV.API}/api/v1/${path}`, { headers, tags: { kind: 'read', name: path.split('?')[0] } });
    check(response, { 'status is 200': (r) => r.status === 200 });
  }
  sleep(1);
}
