import { applyTenantTheme } from './applyTenantTheme'

describe('applyTenantTheme (BR-02)', () => {
  const root = document.documentElement
  afterEach(() => root.removeAttribute('style'))

  it('sets overridable tokens as CSS variables on :root', () => {
    applyTenantTheme({ primary: '#7c2d12', '--radius-md': '4px' })
    expect(root.style.getPropertyValue('--primary')).toBe('#7c2d12')
    expect(root.style.getPropertyValue('--radius-md')).toBe('4px')
  })

  it('ignores tokens that are never overridable', () => {
    applyTenantTheme({ success: '#ff0000', 'space-4': '99px', focus: '#ff0000' })
    expect(root.style.getPropertyValue('--success')).toBe('')
    expect(root.style.getPropertyValue('--space-4')).toBe('')
    expect(root.style.getPropertyValue('--focus')).toBe('')
  })

  it('removes earlier overrides that are no longer set', () => {
    applyTenantTheme({ primary: '#7c2d12' })
    applyTenantTheme({ accent: '#000000' })
    expect(root.style.getPropertyValue('--primary')).toBe('')
    expect(root.style.getPropertyValue('--accent')).toBe('#000000')
  })

  it('returns the tokens it applied', () => {
    expect(applyTenantTheme({ primary: '#7c2d12', success: '#ff0000' })).toEqual(['primary'])
  })
})
