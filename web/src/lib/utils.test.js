import { cn } from './utils'

describe('cn', () => {
  it('keeps a token text style next to a token text colour', () => {
    expect(cn('text-label', 'text-ink')).toBe('text-label text-ink')
  })

  it('lets a later token class replace an earlier one of the same group', () => {
    expect(cn('rounded-lg text-sm h-8', 'rounded-md text-label h-control')).toBe('rounded-md text-label h-control')
    expect(cn('rounded-4xl', 'rounded-pill')).toBe('rounded-pill')
    expect(cn('bg-primary', 'bg-accent')).toBe('bg-accent')
  })

  it('drops falsy values', () => {
    const hidden = false
    expect(cn('a', hidden && 'b', null, undefined, 'c')).toBe('a c')
  })
})
