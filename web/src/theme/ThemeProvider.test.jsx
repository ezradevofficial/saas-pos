import { act, render, screen } from '@testing-library/react'
import { ThemeProvider, useTheme } from './ThemeProvider'
import { THEMES } from './themes'

function Probe() {
  const { theme, setTheme, setOverrides } = useTheme()
  return (
    <div>
      <span data-testid="theme">{theme}</span>
      <button onClick={() => setTheme('warm')}>warm</button>
      <button onClick={() => setOverrides({ primary: '#7c2d12' })}>brand</button>
    </div>
  )
}

describe('ThemeProvider (BR-01, BR-02)', () => {
  const root = document.documentElement

  beforeEach(() => {
    localStorage.clear()
    root.removeAttribute('style')
    root.removeAttribute('data-theme')
    root.classList.remove('dark')
  })

  it('ships Light, Dark, Executive and Warm', () => {
    expect(THEMES.map((theme) => theme.id)).toEqual(['light', 'dark', 'executive', 'warm'])
  })

  it('defaults to light', () => {
    render(<ThemeProvider><Probe /></ThemeProvider>)
    expect(root.dataset.theme).toBe('light')
    expect(root).not.toHaveClass('dark')
  })

  it('sets data-theme="dark" and the dark class', () => {
    render(<ThemeProvider theme="dark"><Probe /></ThemeProvider>)
    expect(root.dataset.theme).toBe('dark')
    expect(root).toHaveClass('dark')
  })

  it('switches theme at runtime and removes the dark class', () => {
    render(<ThemeProvider theme="dark"><Probe /></ThemeProvider>)
    act(() => screen.getByText('warm').click())
    expect(root.dataset.theme).toBe('warm')
    expect(root).not.toHaveClass('dark')
  })

  it('persists the chosen theme per user', () => {
    const { unmount } = render(<ThemeProvider userId="u1"><Probe /></ThemeProvider>)
    act(() => screen.getByText('warm').click())
    unmount()

    render(<ThemeProvider userId="u1"><Probe /></ThemeProvider>)
    expect(screen.getByTestId('theme')).toHaveTextContent('warm')
  })

  it('keeps users apart', () => {
    const { unmount } = render(<ThemeProvider userId="u1"><Probe /></ThemeProvider>)
    act(() => screen.getByText('warm').click())
    unmount()

    render(<ThemeProvider userId="u2"><Probe /></ThemeProvider>)
    expect(screen.getByTestId('theme')).toHaveTextContent('light')
  })

  it('still works when storage throws', () => {
    const getItem = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked')
    })
    const setItem = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked')
    })
    render(<ThemeProvider theme="executive"><Probe /></ThemeProvider>)
    act(() => screen.getByText('warm').click())
    expect(root.dataset.theme).toBe('warm')
    getItem.mockRestore()
    setItem.mockRestore()
  })

  it('applies a primary override on :root', () => {
    render(<ThemeProvider overrides={{ primary: '#7c2d12' }}><Probe /></ThemeProvider>)
    expect(root.style.getPropertyValue('--primary')).toBe('#7c2d12')
  })

  it('ignores an override of the non-overridable success token', () => {
    render(<ThemeProvider overrides={{ success: '#ff0000' }}><Probe /></ThemeProvider>)
    expect(root.style.getPropertyValue('--success')).toBe('')
  })

  it('applies overrides set at runtime', () => {
    render(<ThemeProvider><Probe /></ThemeProvider>)
    act(() => screen.getByText('brand').click())
    expect(root.style.getPropertyValue('--primary')).toBe('#7c2d12')
  })
})

describe('ThemeProvider overrides prop', () => {
  it('applies a new tenant theme passed after mount', () => {
    localStorage.clear()
    const { rerender } = render(<ThemeProvider overrides={{ primary: '#7c2d12' }}><span /></ThemeProvider>)
    rerender(<ThemeProvider overrides={{ primary: '#0d6b4f' }}><span /></ThemeProvider>)
    expect(document.documentElement.style.getPropertyValue('--primary')).toBe('#0d6b4f')
    document.documentElement.removeAttribute('style')
  })
})

describe('ThemeProvider prop changes', () => {
  beforeEach(() => {
    localStorage.clear()
    document.documentElement.removeAttribute('data-theme')
    document.documentElement.classList.remove('dark')
  })

  it('applies a later theme prop', () => {
    const { rerender } = render(<ThemeProvider theme="light"><Probe /></ThemeProvider>)
    rerender(<ThemeProvider theme="dark"><Probe /></ThemeProvider>)
    expect(document.documentElement.dataset.theme).toBe('dark')
    expect(document.documentElement).toHaveClass('dark')
  })

  it("loads the next user's saved theme when userId changes", () => {
    localStorage.setItem('ds.theme.u2', 'executive')
    const { rerender } = render(<ThemeProvider userId="u1"><Probe /></ThemeProvider>)
    expect(screen.getByTestId('theme')).toHaveTextContent('light')
    rerender(<ThemeProvider userId="u2"><Probe /></ThemeProvider>)
    expect(screen.getByTestId('theme')).toHaveTextContent('executive')
    expect(document.documentElement.dataset.theme).toBe('executive')
  })
})
