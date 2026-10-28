import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { UsageBanner } from './UsageBanner'

const usage = vi.hoisted(() => ({ current: null }))
vi.mock('@/lib/usage', async (importOriginal) => ({
  ...(await importOriginal()),
  useUsage: () => ({ usage: usage.current }),
}))

const outlets = { unit: 'outlet', count: 2, allowed: 1, over: 1, state: 'over', overage: null }

function setup(extra) {
  usage.current = { units: [outlets], extra_cost: null, upgrade_url: '/settings/subscription', ...extra }
  render(
    <MemoryRouter>
      <UsageBanner />
    </MemoryRouter>,
  )
}

describe('UsageBanner (LIM-02, ONB-04)', () => {
  it('says the trial allows the extra outlet until its end date, never "0 days left"', () => {
    setup({ trial: { state: 'trial', ends_at: '2026-11-20T09:00:00Z', days_left: 23 } })
    expect(screen.getByText('Allowed during your trial, until 20 Nov 2026')).toBeInTheDocument()
    expect(screen.queryByText(/0 days left/)).not.toBeInTheDocument()
    expect(screen.getByText(/2 of 1, 1 over/)).toBeInTheDocument()
  })

  it('does not invent days left before an overage window exists', () => {
    setup({ trial: { state: 'none', ends_at: null, days_left: 0 } })
    expect(screen.queryByText(/0 days left/)).not.toBeInTheDocument()
    expect(screen.getByText('Over plan')).toBeInTheDocument()
  })
})
