import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { ApiError } from '@/api/client'
import i18n from '@/i18n'
import { VersionBar } from './VersionBar'

const LIVE = { id: 'v2', version: 2, status: 'published', source: 'draft', published_at: '2026-10-08T09:00:00Z' }
const DRAFT = { id: 'v4', version: 4, status: 'draft', source: 'draft', published_at: null }
const HISTORY = [
  DRAFT,
  { id: 'v3', version: 3, status: 'archived', source: 'draft', published_at: null, discarded_at: '2026-10-08T10:00:00Z' },
  LIVE,
  { id: 'v1', version: 1, status: 'archived', source: 'draft', published_at: '2026-10-07T09:00:00Z' },
]
const DOCUMENT = { id: 'd1', published: LIVE, draft: DRAFT, history: HISTORY }
const TARGETS = [{ type: 'company', id: 'c2', label: 'Beta Ltd' }]

function renderBar(props = {}) {
  const handlers = {
    onPublish: vi.fn().mockResolvedValue({}),
    onDiscard: vi.fn().mockResolvedValue({}),
    onRollback: vi.fn().mockResolvedValue({}),
    onCopy: vi.fn().mockResolvedValue({}),
  }
  render(<VersionBar document={DOCUMENT} canEdit canPublish copyTargets={TARGETS} {...handlers} {...props} />)
  return handlers
}

describe('VersionBar (LAY-06)', () => {
  afterEach(() => i18n.changeLanguage('en'))

  it('shows the draft, the live version and publishes the draft as the decisive action', async () => {
    const { onPublish } = renderBar({ saveState: 'saved' })

    const bar = screen.getByRole('region', { name: 'Versions' })
    expect(within(bar).getByText('Draft v4')).toBeInTheDocument()
    expect(within(bar).getByText('v2 is live')).toBeInTheDocument()
    expect(within(bar).getByText('Draft saved')).toHaveAttribute('data-save-state', 'saved')

    const publish = screen.getByRole('button', { name: 'Publish v4' })
    expect(publish).toHaveAttribute('data-ds-variant', 'pay')
    fireEvent.click(publish)
    await waitFor(() => expect(onPublish).toHaveBeenCalledTimes(1))
  })

  it('blocks publishing while the draft has problems, and hides actions the user may not take', () => {
    renderBar({ problems: [{ path: 'columns', code: 'required', message: 'columns is missing.' }], canEdit: false })

    expect(screen.getByText('1 problem blocks publishing')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Publish v4' })).toBeDisabled()
    expect(screen.queryByRole('button', { name: 'Discard draft' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Copy to…' })).not.toBeInTheDocument()
  })

  it('shows a live version without a draft, and nothing saved yet', () => {
    const { unmount } = render(<VersionBar document={{ id: 'd1', published: LIVE, draft: null, history: [LIVE] }} canPublish />)
    expect(screen.getByText('Live v2')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Publish v3' })).toBeDisabled()
    unmount()

    render(<VersionBar document={null} />)
    expect(screen.getByText('Not saved yet')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'History' })).not.toBeInTheDocument()
  })

  it('rolls back to an earlier published version after confirming, never to a discarded draft or the live one', async () => {
    const { onRollback } = renderBar()

    fireEvent.click(screen.getByRole('button', { name: 'History' }))
    const dialog = screen.getByRole('dialog', { name: 'Version history' })
    expect(within(dialog).getByText('Discarded draft')).toBeInTheDocument()
    expect(within(dialog).getAllByRole('button', { name: /Roll back to/ }).map((b) => b.textContent)).toEqual(['Roll back to v1'])

    fireEvent.click(within(dialog).getByRole('button', { name: 'Roll back to v1' }))
    expect(within(dialog).getByText('Make v1 live again as a new version?')).toBeInTheDocument()
    fireEvent.click(within(dialog).getAllByRole('button', { name: 'Roll back to v1' })[0])

    await waitFor(() => expect(onRollback).toHaveBeenCalledWith(1))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('copies the live version or the draft to another place', async () => {
    const { onCopy } = renderBar()

    fireEvent.click(screen.getByRole('button', { name: 'Copy to…' }))
    const dialog = screen.getByRole('dialog', { name: 'Copy to another place' })
    const copy = within(dialog).getByRole('button', { name: 'Copy' })
    expect(copy).toBeDisabled()

    fireEvent.click(within(dialog).getByRole('combobox', { name: 'Copy to' }))
    fireEvent.click(await screen.findByRole('option', { name: 'Beta Ltd' }))
    fireEvent.click(copy)

    await waitFor(() => expect(onCopy).toHaveBeenCalledWith({ type: 'company', id: 'c2' }, 'published'))
  })

  it('keeps the dialog open with the reason when an action fails', async () => {
    renderBar({ onDiscard: vi.fn().mockRejectedValue(new ApiError({ status: 422, code: 'no_draft', message: 'There is no draft to use. Make a change to start one.' })) })

    fireEvent.click(screen.getByRole('button', { name: 'Discard draft' }))
    const dialog = screen.getByRole('dialog', { name: 'Discard draft v4?' })
    expect(within(dialog).getByText(/v2 stays live/)).toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Discard draft' }))

    expect(await within(dialog).findByText('There is no draft to use. Make a change to start one.')).toBeInTheDocument()
  })

  it('speaks French', async () => {
    await i18n.changeLanguage('fr')
    renderBar()

    expect(screen.getByText('Brouillon v4')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Publier la v4' })).toBeInTheDocument()
  })
})
