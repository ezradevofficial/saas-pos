import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption } from '@/test/combobox'
import { apiError, mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'
import { examplePattern, patternProblem } from './numbering/pattern'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const COMPANY = { id: 'c-1', name: 'Amani Retail', country: 'KE', base_currency: 'KES', timezone: 'Africa/Nairobi', archived_at: null }
const TYPES = [
  {
    document_type: 'pos.receipt',
    name: 'Receipt',
    place_tokens: ['BRANCH', 'LOCATION', 'DEVICE'],
    ranged: true,
    default: { pattern: 'R-{LOCATION}-{000001}', reset: 'never' },
    formats: [{ id: 'f-1', document_type: 'pos.receipt', company_id: 'c-1', branch_id: null, pattern: 'AR-{LOCATION}-{YY}-{000001}', reset: 'yearly', gapless: false }],
  },
  { document_type: 'core.credit_note', name: 'Credit note', place_tokens: ['BRANCH'], ranged: false, default: { pattern: 'CN-{00001}', reset: 'never' }, formats: [] },
]

function setup(permissions = tenantWide(['core.company.view', 'core.numbering.view', 'core.numbering.edit'])) {
  mockRoutes(
    api,
    [
      ['numbering/formats', { data: TYPES }],
      ['branches?per_page=200', { data: [{ id: 'b-1', company_id: 'c-1', name: 'Westlands', code: 'WSTL' }] }],
    ],
    { permissions, companies: [COMPANY] },
  )
}

describe('pattern helpers (NUM-01)', () => {
  it('renders an example and finds what the API would refuse', () => {
    const date = new Date(2026, 9, 9)
    expect(examplePattern('R-{LOCATION}-{YY}{MM}-{000001}', { date, counter: 42 })).toBe('R-L01-2610-000042')
    expect(examplePattern('{BRANCH}/{00001}', { codes: { BRANCH: 'WSTL' }, date })).toBe('WSTL/00001')
    expect(patternProblem('R-{LOCATION}', ['LOCATION'])).toBe('counter')
    expect(patternProblem('R-{LOCATION}-{001}-{01}', ['LOCATION'])).toBe('counter')
    expect(patternProblem('R {0001}', [])).toBe('characters')
    expect(patternProblem('R-{DEVICE}-{0001}', ['BRANCH'])).toBe('unavailable')
    expect(patternProblem('R-{WEEK}-{0001}', [])).toBe('token')
    expect(patternProblem('R-{0001}', [], 'yearly')).toBe('yearly')
    expect(patternProblem('R-{YYYY}-{0001}', [], 'yearly')).toBeNull()
  })
})

describe('Numbering (NUM-01)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists document types with their default and formats per scope', async () => {
    setup()
    renderApp('/settings/numbering')
    expect(await screen.findByRole('heading', { name: 'Receipt' })).toBeInTheDocument()
    expect(screen.getByText('Company Amani Retail')).toBeInTheDocument()
    expect(screen.getByText('AR-{LOCATION}-{YY}-{000001}')).toBeInTheDocument()
    expect(screen.getByText(/restarts every year/)).toBeInTheDocument()
    expect(screen.getByText(/Uses the default, for example CN-00001/)).toBeInTheDocument()
  })

  it('edits a pattern with token chips and a live example, and shows the API’s collision error on the field', async () => {
    setup()
    api.put.mockRejectedValueOnce(
      apiError(422, 'numbering_pattern_collision', 'Another format of this document type prints the same numbers.', { pattern: ['Another format of this document type prints the same numbers.'] }),
    )
    renderApp('/settings/numbering')
    fireEvent.click(await screen.findByRole('button', { name: 'Edit the format for Company Amani Retail' }))
    const dialog = await screen.findByRole('dialog', { name: 'Receipt: Company Amani Retail' })
    const pattern = within(dialog).getByLabelText(/Pattern/)
    fireEvent.change(pattern, { target: { value: 'AR-' } })
    pattern.setSelectionRange(3, 3)
    fireEvent.click(within(dialog).getByRole('button', { name: 'Insert {DEVICE}' }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Insert {000001}' }))
    expect(pattern).toHaveValue('AR-{DEVICE}{000001}')
    // A yearly counter needs the year: said before saving.
    expect(within(dialog).getByText('A counter that restarts every year needs {YYYY} or {YY}.')).toBeInTheDocument()
    chooseOption(within(dialog).getByLabelText(/Counter/), 'never restarts')
    expect(within(dialog).getByText('AR-T01000001')).toBeInTheDocument()
    // Till ranges are never gapless (NUM-02): no switch, an explanation.
    expect(within(dialog).queryByRole('switch')).not.toBeInTheDocument()

    fireEvent.click(within(dialog).getByRole('button', { name: 'Save format' }))
    expect(await within(dialog).findByText('Another format of this document type prints the same numbers.')).toBeInTheDocument()
    expect(api.put).toHaveBeenCalledWith('numbering/formats', {
      document_type: 'pos.receipt',
      company_id: 'c-1',
      branch_id: null,
      pattern: 'AR-{DEVICE}{000001}',
      reset: 'never',
      gapless: false,
    })
  })

  it('adds a gapless branch format where the type allows it', async () => {
    setup()
    api.put.mockResolvedValue({ data: {} })
    renderApp('/settings/numbering')
    fireEvent.click(await screen.findByRole('button', { name: 'Add a format for Credit note' }))
    const dialog = await screen.findByRole('dialog', { name: 'Add a format for Credit note' })
    chooseOption(within(dialog).getByLabelText(/Company/), 'Amani Retail')
    chooseOption(within(dialog).getByLabelText(/Branch/), 'Westlands')
    fireEvent.change(within(dialog).getByLabelText(/Pattern/), { target: { value: 'CN-{BRANCH}-{00001}' } })
    expect(within(dialog).getByText('CN-WSTL-00001')).toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('switch', { name: 'No gaps' }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save format' }))
    await waitFor(() =>
      expect(api.put).toHaveBeenCalledWith('numbering/formats', { document_type: 'core.credit_note', company_id: 'c-1', branch_id: 'b-1', pattern: 'CN-{BRANCH}-{00001}', reset: 'never', gapless: true }),
    )
  })

  it('is read-only without core.numbering.edit', async () => {
    setup(tenantWide(['core.company.view', 'core.numbering.view']))
    renderApp('/settings/numbering')
    await screen.findByRole('heading', { name: 'Receipt' })
    expect(screen.queryByRole('button', { name: /Add a format/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Edit the format/ })).not.toBeInTheDocument()
  })
})
