import { render, screen } from '@testing-library/react'
import { SyncStatus } from './SyncStatus'

describe('SyncStatus', () => {
  it('shows the translated online label', () => {
    render(<SyncStatus state="online" />)
    expect(screen.getByRole('status')).toHaveTextContent('Online · all synced')
  })

  it('reassures that selling continues offline and counts waiting sales', () => {
    render(<SyncStatus state="offline" pending={3} />)
    expect(screen.getByRole('status')).toHaveTextContent('Offline · selling continues')
    expect(screen.getByText('3 sales waiting')).toBeInTheDocument()
  })

  it('uses the singular for one sale', () => {
    render(<SyncStatus state="syncing" pending={1} />)
    expect(screen.getByText('1 sale waiting')).toBeInTheDocument()
  })

  it('accepts label overrides', () => {
    render(<SyncStatus state="online" labels={{ online: 'Connected' }} />)
    expect(screen.getByRole('status')).toHaveTextContent('Connected')
  })
})
