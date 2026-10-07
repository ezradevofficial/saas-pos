import { render, screen } from '@testing-library/react'
import { StageTracker } from './StageTracker'

const stages = ['Draft', 'Submitted', 'Approved', 'Received']

describe('StageTracker', () => {
  it('marks done, current and upcoming stages', () => {
    render(<StageTracker stages={stages} current="Approved" />)
    const items = screen.getAllByRole('listitem')
    expect(items).toHaveLength(4)
    expect(items[0]).toHaveAttribute('data-state', 'done')
    expect(items[2]).toHaveAttribute('aria-current', 'step')
    expect(items[2]).toHaveAttribute('data-state', 'current')
    expect(items[3]).toHaveAttribute('data-state', 'todo')
    expect(items[0]).toHaveTextContent('Done')
  })

  it('shows the current stage as stopped when blocked', () => {
    render(<StageTracker stages={stages} current="Submitted" blocked />)
    const items = screen.getAllByRole('listitem')
    expect(items[1]).toHaveAttribute('data-state', 'blocked')
    expect(items[1]).toHaveTextContent('Stopped')
  })
})
