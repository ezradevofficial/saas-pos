import { fireEvent, render, screen } from '@testing-library/react'
import { useState } from 'react'
import { Switch } from './Switch'

function Controlled() {
  const [on, setOn] = useState(false)
  return <Switch checked={on} onChange={setOn} label="Sell offline" />
}

describe('Switch', () => {
  it('toggles aria-checked', () => {
    render(<Controlled />)
    const toggle = screen.getByRole('switch', { name: 'Sell offline' })
    expect(toggle).toHaveAttribute('aria-checked', 'false')
    fireEvent.click(toggle)
    expect(toggle).toHaveAttribute('aria-checked', 'true')
    fireEvent.click(toggle)
    expect(toggle).toHaveAttribute('aria-checked', 'false')
  })

  it('calls onChange with the next value', () => {
    const onChange = vi.fn()
    render(<Switch checked onChange={onChange} label="Sell offline" />)
    fireEvent.click(screen.getByRole('switch'))
    expect(onChange).toHaveBeenCalledWith(false)
  })

  it('toggles from its label', () => {
    render(<Controlled />)
    fireEvent.click(screen.getByText('Sell offline'))
    expect(screen.getByRole('switch')).toHaveAttribute('aria-checked', 'true')
  })

  it('does nothing when disabled', () => {
    const onChange = vi.fn()
    render(<Switch checked={false} onChange={onChange} disabled label="Sell offline" />)
    fireEvent.click(screen.getByRole('switch'))
    expect(onChange).not.toHaveBeenCalled()
    expect(screen.getByRole('switch')).toBeDisabled()
  })
})
