// NFR-06: the npm audit gate's own test.
import assert from 'node:assert/strict'
import test from 'node:test'
import { advisories, completed, evaluate } from './npm-audit.mjs'

const report = {
  metadata: { vulnerabilities: { high: 1, critical: 1 } },
  vulnerabilities: {
    braces: { via: [{ source: 1, name: 'braces', severity: 'high', title: 'DoS', url: 'https://github.com/advisories/GHSA-aaaa' }] },
    micromatch: { via: ['braces'] },
    uuid: { via: [{ source: 2, name: 'uuid', severity: 'moderate', title: 'Bounds', url: 'https://github.com/advisories/GHSA-bbbb' }] },
    forge: { via: [{ source: 3, name: 'node-forge', severity: 'critical', title: 'Sig', url: 'https://github.com/advisories/GHSA-cccc' }] },
  },
}

test('keeps high and critical advisories only, once each', () => {
  assert.deepEqual(advisories(report).map((a) => a.id), ['GHSA-aaaa', 'GHSA-cccc'])
})

test('fails on advisories not allowed, passes allowed ones until their review date', () => {
  const allowlist = [{ id: 'GHSA-aaaa', review_by: '2027-01-15' }]
  const result = evaluate(report, allowlist, '2026-10-17')
  assert.deepEqual(result.failing.map((a) => a.id), ['GHSA-cccc'])
  assert.deepEqual(result.allowed.map((a) => a.id), ['GHSA-aaaa'])

  const later = evaluate(report, allowlist, '2027-02-01')
  assert.deepEqual(later.failing.map((a) => a.id), ['GHSA-aaaa', 'GHSA-cccc'])
  assert.deepEqual(later.expired, ['GHSA-aaaa'])
})

test('ignores an exception reviewed more than 90 days out (review low)', () => {
  const result = evaluate(report, [{ id: 'GHSA-aaaa', review_by: '2027-06-01' }], '2026-10-21')
  assert.deepEqual(result.failing.map((a) => a.id), ['GHSA-aaaa', 'GHSA-cccc'])
  assert.deepEqual(result.tooFar, ['GHSA-aaaa'])
  assert.deepEqual(evaluate(report, [{ id: 'GHSA-aaaa', review_by: '2027-01-19' }], '2026-10-21').failing.map((a) => a.id), ['GHSA-cccc'])
})

test('reports allow-list entries that are no longer needed', () => {
  assert.deepEqual(evaluate({ vulnerabilities: {} }, [{ id: 'GHSA-old', review_by: '2027-01-01' }], '2026-10-17').unused, ['GHSA-old'])
})

test('an audit that did not complete is not a pass (registry timeout)', () => {
  assert.equal(completed(report), true)
  assert.equal(completed({ error: { code: 'ENOAUDIT', summary: 'audit endpoint returned an error' } }), false)
  assert.equal(completed({}), false)
  assert.equal(completed(null), false)
})
