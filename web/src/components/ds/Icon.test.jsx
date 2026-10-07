import { render } from '@testing-library/react'
import { Icon } from './Icon'

describe('Icon', () => {
  it('draws a 1.5 stroke line icon hidden from assistive technology', () => {
    const { container } = render(<Icon name="cloud" />)
    const svg = container.querySelector('svg')
    expect(svg).toHaveAttribute('aria-hidden', 'true')
    expect(svg).toHaveAttribute('stroke-width', '1.5')
    expect(svg).toHaveAttribute('width', '16')
  })

  it('takes a size', () => {
    const { container } = render(<Icon name="check" size={18} />)
    expect(container.querySelector('svg')).toHaveAttribute('width', '18')
  })

  it.each(['check', 'x', 'alert', 'info', 'cloud', 'sync', 'offline', 'chevron', 'clock'])('knows %s', (name) => {
    const { container } = render(<Icon name={name} />)
    expect(container.querySelector('svg')).not.toBeNull()
  })
})
