// ECOM-08: past a few sign-in codes from one connection the server asks for a
// challenge (428); the storefront solves it in the browser and asks once more.
import { createHash } from 'node:crypto'
import { requestCode } from './shopClient'

function json(body, status) {
  return Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }))
}

describe('sign-in code challenge (ECOM-08)', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('solves the challenge and asks again with its answer', async () => {
    const bodies = []
    vi.stubGlobal('fetch', vi.fn((url, init) => {
      bodies.push(JSON.parse(init.body))
      if (bodies.length === 1) return json({ message: 'Check first', code: 'challenge_required', challenge: { id: 'c-1', nonce: 'abc', difficulty: 2 } }, 428)
      return json({ data: { challenge_id: 'code-1', channel: 'sms', destination: '+2547******78' } }, 201)
    }))

    const result = await requestCode('duka', '0712345678')
    expect(result.data.challenge_id).toBe('code-1')
    expect(bodies[0]).toEqual({ contact: '0712345678' })
    const { id, solution } = bodies[1].challenge
    expect(id).toBe('c-1')
    expect(createHash('sha256').update(`abc:${solution}`).digest('hex').startsWith('00')).toBe(true)
  })

  it('passes other refusals on', async () => {
    vi.stubGlobal('fetch', vi.fn(() => json({ message: 'Slow down', code: 'too_many_requests' }, 429)))
    await expect(requestCode('duka', '0712345678')).rejects.toMatchObject({ status: 429 })
  })
})
