// NFR-06: the secret scan's own test. Fake secrets are assembled at run
// time and written to a temporary tree, so no committed file holds one.
import assert from 'node:assert/strict'
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import test from 'node:test'
import { scan, scanText } from './secret-scan.mjs'

const fake = {
  privateKey: ['-----BEGIN ', 'RSA PRIVATE KEY-----'].join(''),
  aws: ['AKIA', 'ABCDEFGHIJKLMNOP'].join(''),
  github: ['ghp_', 'a'.repeat(36)].join(''),
  sentry: ['https://', '0123456789abcdef0123456789abcdef', '@o1.ingest.sentry.io/42'].join(''),
  appKey: ['APP_KEY=base64:', 'A'.repeat(43), '='].join(''),
  dbPassword: ['DB_PASSWORD=', 'Sup3rS3cretValue'].join(''),
}

test('finds each kind of secret', () => {
  const rules = (text) => scanText(text).map((f) => f.rule)
  assert.deepEqual(rules(fake.privateKey), ['private-key'])
  assert.deepEqual(rules(`key ${fake.aws}`), ['aws-access-key'])
  assert.deepEqual(rules(`token: ${fake.github}`), ['github-token'])
  assert.deepEqual(rules(`dsn: ${fake.sentry}`), ['sentry-dsn'])
  assert.deepEqual(rules(fake.appKey), ['laravel-app-key'])
  assert.deepEqual(rules(fake.dbPassword), ['assigned-secret'])
  assert.deepEqual(rules('HR_DIGEST_' + 'KEY=base64:' + 'c2VjcmV0LXZhbHVlLWhlcmU='), ['assigned-secret'])
})

test('leaves placeholders and local defaults alone', () => {
  for (const text of [
    'DB_PASSWORD=app',
    'DB_OWNER_PASSWORD=app_owner',
    'APP_KEY=',
    'SENTRY_AUTH_TOKEN=${{ secrets.SENTRY_AUTH_TOKEN }}',
    'HR_DIGEST_KEY=<a random 32-byte key>',
    // A documented command that generates the value, not a value.
    'echo "HR_DIGEST_KEY=base64:$(openssl rand -base64 32)"',
    'METRICS_TOKEN=null',
    'postgres://app:app@127.0.0.1:5432/app',
    "password: 'password'",
  ]) {
    assert.deepEqual(scanText(text), [], text)
  }
})

test('reports the file and line, and honours the allow-list', () => {
  const root = mkdtempSync(join(tmpdir(), 'secret-scan-'))
  try {
    mkdirSync(join(root, 'api/tests'), { recursive: true })
    writeFileSync(join(root, 'api/tests/fixture.txt'), `line one\n${fake.aws}\n`)
    writeFileSync(join(root, 'README.md'), 'nothing here\n')
    process.env.SECRET_SCAN_ROOT = root

    assert.deepEqual(scan(root, []), [{ path: 'api/tests/fixture.txt', rule: 'aws-access-key', line: 2 }])
    assert.deepEqual(scan(root, [{ path: 'api/tests/fixture.txt', rule: 'aws-access-key', reason: 'fixture' }]), [])
  } finally {
    delete process.env.SECRET_SCAN_ROOT
    rmSync(root, { recursive: true, force: true })
  }
})
