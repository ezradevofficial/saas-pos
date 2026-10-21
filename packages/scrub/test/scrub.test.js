// NFR-10: the shared fixtures, run by the JavaScript scrubber (web and POS).
// The API's ScrubberTest runs the same fixtures through the PHP scrubber.
'use strict'

const test = require('node:test')
const assert = require('node:assert/strict')
const fixtures = require('../fixtures.json')
const { scrub, scrubString, scrubEvent, scrubBreadcrumb, isSensitiveKey } = require('../src/index.js')

test('strings: secrets and personal data are replaced, ordinary text is kept', () => {
  for (const [input, expected] of fixtures.strings) {
    assert.equal(scrubString(input), expected, input)
  }
})

test('objects: sensitive keys are redacted whole, other strings scrubbed', () => {
  for (const [input, expected] of fixtures.objects) {
    assert.deepEqual(scrub(input), expected, JSON.stringify(input))
  }
})

test('keys: sensitive and plain names', () => {
  for (const key of fixtures.sensitiveKeys) assert.equal(isSensitiveKey(key), true, key)
  for (const key of fixtures.plainKeys) assert.equal(isSensitiveKey(key), false, key)
})

test('scrub never changes its input', () => {
  const input = { password: 'x', nested: { email: 'a@b.co' } }
  scrub(input)
  assert.deepEqual(input, { password: 'x', nested: { email: 'a@b.co' } })
})

test('a Sentry event keeps ids and the tenant tag, and only the user id', () => {
  const event = {
    event_id: 'abc123',
    release: 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678',
    message: 'Payment for amina@example.com failed',
    tags: { tenant_id: '0199f5a2-1111-7222-8333-444455556666', route: 'pos.sales' },
    user: { id: 'u-1', email: 'amina@example.com', username: 'Amina', ip_address: '197.0.0.1' },
    request: {
      url: 'https://app.example/api/v1/x?token=abc',
      headers: { Authorization: 'Bearer abcdefghij', 'User-Agent': 'Chrome' },
      data: { password: 'hunter22', amount_minor: 1200 },
    },
    exception: { values: [{ type: 'Error', value: 'Card 4111 1111 1111 1111 refused' }] },
  }
  const out = scrubEvent(event)
  assert.equal(out.event_id, 'abc123')
  assert.equal(out.release, event.release)
  assert.equal(out.tags.tenant_id, event.tags.tenant_id)
  assert.deepEqual(out.user, { id: 'u-1' })
  assert.equal(out.message, 'Payment for [email] failed')
  assert.equal(out.request.url, 'https://app.example/api/v1/x?token=[redacted]')
  assert.equal(out.request.headers.Authorization, '[redacted]')
  assert.equal(out.request.headers['User-Agent'], 'Chrome')
  assert.equal(out.request.data.password, '[redacted]')
  assert.equal(out.request.data.amount_minor, 1200)
  assert.equal(out.exception.values[0].value, 'Card [number] refused')
})

test('an event without a user id loses the user entirely', () => {
  assert.equal('user' in scrubEvent({ user: { email: 'a@b.co' } }), false)
})

test('breadcrumbs are scrubbed', () => {
  const crumb = scrubBreadcrumb({ category: 'fetch', data: { url: '/x?signature=abc' }, message: 'call 0712345678' })
  assert.equal(crumb.data.url, '/x?signature=[redacted]')
  assert.equal(crumb.message, 'call [phone]')
})

test('deep structures are cut off rather than walked forever', () => {
  let deep = { value: 'x' }
  for (let i = 0; i < 30; i++) deep = { child: deep }
  assert.doesNotThrow(() => scrub(deep))
})
