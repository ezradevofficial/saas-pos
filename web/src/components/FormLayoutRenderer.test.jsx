import { fireEvent, render, screen, within } from '@testing-library/react'
import { fallbackLayout, visibleSections } from '@/lib/formLayout'
import { FormLayoutRenderer } from './FormLayoutRenderer'

const text = (id, fallback) => (props) => (
  <label>
    {props.label ?? fallback}
    <input aria-label={props.label ?? fallback} aria-description={props.help} />
  </label>
)

const FIELDS = { code: text('code', 'Code'), name: text('name', 'Name'), notes: () => null }
const custom = (fields = []) => ({ entity: 'item', fields, values: { values: {}, set: vi.fn() }, errors: {} })

describe('FormLayoutRenderer (LAY-03)', () => {
  it('draws sections in order with the layout’s titles, labels and help, and leaves out hidden and unrendered fields', () => {
    const layout = {
      tabs: [],
      sections: [
        { id: 'b', title: 'Second', columns: 1, fields: [{ id: 'name', label: 'Full name', help: 'As on the ID' }] },
        { id: 'a', title: 'First', columns: 3, fields: [{ id: 'code', hidden: true }, { id: 'notes' }, { id: 'gone' }] },
        { id: 'c', title: 'Third', columns: 2, fields: [{ id: 'code', width: 'full' }] },
      ],
    }
    const { container } = render(<FormLayoutRenderer layout={layout} fields={FIELDS} custom={custom()} />)
    const titles = screen.getAllByRole('heading').map((heading) => heading.textContent)
    // Section "First" holds only a hidden field, one rendered as nothing and one the form doesn't have: not drawn.
    expect(titles).toEqual(['Second', 'Third'])
    expect(screen.getByLabelText('Full name')).toHaveAttribute('aria-description', 'As on the ID')
    expect(container.querySelector('[data-field="code"]')).toHaveClass('col-span-full')
    expect(container.querySelector('[data-section="c"] .grid')).toHaveClass('sm:grid-cols-2')
  })

  it('shows one tab at a time when the layout has tabs', () => {
    const layout = {
      tabs: [
        { id: 'main', title: 'Main' },
        { id: 'more', title: 'More' },
      ],
      sections: [
        { id: 'a', title: 'Basics', tab: 'main', columns: 2, fields: [{ id: 'code' }] },
        { id: 'b', title: 'Extra', tab: 'more', columns: 2, fields: [{ id: 'name' }] },
      ],
    }
    const { container } = render(<FormLayoutRenderer layout={layout} fields={FIELDS} custom={custom()} />)
    expect(container.querySelector('[data-section="a"]')).toBeVisible()
    expect(container.querySelector('[data-section="b"]')).not.toBeVisible()
    fireEvent.mouseDown(screen.getByRole('tab', { name: 'More' }))
    fireEvent.click(screen.getByRole('tab', { name: 'More' }))
    expect(container.querySelector('[data-section="b"]')).toBeVisible()
  })

  it('places custom fields the user sees, relabelled, and adds missing ones to the last section (LAY-07)', () => {
    const schema = [
      { key: 'shelf', type: 'text', label: 'Shelf', readonly: false },
      { key: 'later', type: 'text', label: 'Added later', readonly: false },
    ]
    const layout = {
      tabs: [],
      sections: [
        { id: 'a', title: 'Basics', columns: 2, fields: [{ id: 'code' }, { id: 'custom.shelf', label: 'Bin' }, { id: 'custom.hidden_from_me' }] },
        { id: 'b', title: 'Last', columns: 2, fields: [{ id: 'name' }] },
      ],
    }
    render(<FormLayoutRenderer layout={layout} fields={FIELDS} custom={custom(schema)} />)
    expect(screen.getByLabelText('Bin')).toBeInTheDocument()
    expect(within(screen.getByText('Last').closest('[data-section]')).getByLabelText('Added later')).toBeInTheDocument()
  })

  it('falls back to the form’s own sections with custom fields at the end of their group', () => {
    const layout = fallbackLayout(
      [
        { id: 'details', title: 'Details', fields: ['code'] },
        { id: 'custom', title: 'More details', fields: [] },
      ],
      [{ key: 'notes', type: 'long_text' }],
    )
    expect(layout.sections[1].fields).toEqual([{ id: 'custom.notes', wide: true }])
    expect(visibleSections(layout, { customFields: [], canRender: () => true }).map((section) => section.id)).toEqual(['details'])
  })
})
