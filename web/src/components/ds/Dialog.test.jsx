import { fireEvent, render, screen } from '@testing-library/react'
import { Button } from './Button'
import { Dialog } from './Dialog'

function Page({ onClose }) {
  return (
    <div>
      <button type="button">Outside</button>
      <Dialog open title="Void this sale?" onClose={onClose} footer={<Button variant="danger">Void sale</Button>}>
        The sale is kept in the audit log.
      </Dialog>
    </div>
  )
}

describe('Dialog', () => {
  it('renders nothing when closed', () => {
    render(<Dialog open={false} title="Void this sale?" />)
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('is a labelled modal dialog with a translated close button', () => {
    render(<Page onClose={() => {}} />)
    const dialog = screen.getByRole('dialog', { name: 'Void this sale?' })
    expect(screen.getByRole('heading', { level: 2, name: 'Void this sale?' })).toHaveClass('text-h2')
    expect(screen.getByRole('button', { name: 'Close' })).toBeInTheDocument()
    expect(dialog).toHaveClass('rounded-lg', 'shadow-lg')
  })

  it('traps focus inside the dialog', () => {
    render(<Page onClose={() => {}} />)
    const dialog = screen.getByRole('dialog')
    expect(dialog).toContainElement(document.activeElement)
    screen.getByText('Outside').focus()
    expect(dialog).toContainElement(document.activeElement)
  })

  it('closes on Escape', () => {
    const onClose = vi.fn()
    render(<Page onClose={onClose} />)
    fireEvent.keyDown(document.activeElement, { key: 'Escape' })
    expect(onClose).toHaveBeenCalled()
  })

  it('closes from the close button', () => {
    const onClose = vi.fn()
    render(<Page onClose={onClose} />)
    fireEvent.click(screen.getByRole('button', { name: 'Close' }))
    expect(onClose).toHaveBeenCalled()
  })

  it('renders inline without an overlay', () => {
    render(<Dialog open inline title="Preview">Body</Dialog>)
    const dialog = screen.getByRole('dialog', { name: 'Preview' })
    expect(document.querySelector('[data-slot="dialog-overlay"]')).toBeNull()
    expect(dialog).toHaveTextContent('Body')
  })
})
