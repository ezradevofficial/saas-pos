// NFR-10: a tab left open across a deploy reloads once for the new build.
import { isStaleChunkError, reloadForNewBuild } from './staleChunks'

function memoryStorage() {
  const data = new Map()
  return { getItem: (k) => data.get(k) ?? null, setItem: (k, v) => data.set(k, v) }
}

describe('stale build chunks', () => {
  it('recognises the browsers\' missing-chunk errors and nothing else', () => {
    expect(isStaleChunkError(new TypeError('Failed to fetch dynamically imported module: https://app.example/assets/Employees-CpTGrhMu.js'))).toBe(true)
    expect(isStaleChunkError(new TypeError('Importing a module script failed.'))).toBe(true)
    expect(isStaleChunkError(new Error('error loading dynamically imported module'))).toBe(true)
    expect(isStaleChunkError(new TypeError("Cannot read properties of undefined (reading 'includes')"))).toBe(false)
    expect(isStaleChunkError(null)).toBe(false)
  })

  it('reloads once, then lets a second failure within a minute show as an error', () => {
    const storage = memoryStorage()
    const reload = vi.fn()
    expect(reloadForNewBuild(storage, reload, 1_000_000)).toBe(true)
    expect(reloadForNewBuild(storage, reload, 1_030_000)).toBe(false)
    expect(reload).toHaveBeenCalledTimes(1)
    expect(reloadForNewBuild(storage, reload, 1_070_001)).toBe(true)
  })

  it('still reloads when storage is blocked', () => {
    const blocked = { getItem: () => { throw new Error('blocked') }, setItem: () => { throw new Error('blocked') } }
    const reload = vi.fn()
    expect(reloadForNewBuild(blocked, reload, 10_000_000)).toBe(true)
    expect(reload).toHaveBeenCalledOnce()
  })
})
