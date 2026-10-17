// NFR-06: the npm audit gate's own test.
import assert from 'node:assert/strict'
import test from 'node:test'
import { advisories, evaluate } from './npm-audit.mjs'

const report = {
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

test('reports allow-list entries that are no longer needed', () => {
  assert.deepEqual(evaluate({ vulnerabilities: {} }, [{ id: 'GHSA-old', review_by: '2027-01-01' }], '2026-10-17').unused, ['GHSA-old'])
})
