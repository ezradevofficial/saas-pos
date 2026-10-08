import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { useState } from 'react'
import { chooseOption, openCombobox } from '@/test/combobox'
import { Combobox } from './Combobox'

const COMPANIES = [
  { value: 'c-1', label: 'Société Kin Market' },
  { value: 'c-2', label: 'Amani Retail' },
  { value: 'c-3', label: 'Old Branch (archived)', disabled: true },
  { value: 'c-4', label: 'Westlands' },
]

function Harness({ initial = '', onChange, ...props }) {
  const [value, setValue] = useState(initial)
  return (
    <>
      <span id="lbl">Company</span>
      <Combobox
        aria-labelledby="lbl"
        options={COMPANIES}
        placeholder="Choose a company"
        value={value}
        onValueChange={(next) => {
          setValue(next)
          onChange?.(next)
        }}
        {...props}
      />
    </>
  )
}

describe('Combobox (BR-01)', () => {
  it('is a labelled combobox showing the placeholder until a value is chosen', () => {
    render(<Harness />)
    const trigger = screen.getByRole('combobox', { name: 'Company' })
    expect(trigger).toHaveAttribute('aria-expanded', 'false')
    expect(trigger).toHaveTextContent('Choose a company')
  })

  it('opens a list with a search box and every option', () => {
    render(<Harness />)
    const list = openCombobox(screen.getByRole('combobox', { name: 'Company' }))
    expect(screen.getByRole('combobox', { name: 'Company', hidden: true })).toHaveAttribute('aria-expanded', 'true')
    expect(screen.getByPlaceholderText('Search')).toBeInTheDocument()
    expect(within(list).getAllByRole('option')).toHaveLength(4)
  })

  it('filters by label, ignoring case and accents', () => {
    render(<Harness />)
    const list = openCombobox(screen.getByRole('combobox', { name: 'Company' }))
    fireEvent.change(screen.getByPlaceholderText('Search'), { target: { value: 'SOCIETE' } })
    expect(within(list).getAllByRole('option').map((option) => option.textContent)).toEqual(['Société Kin Market'])
  })

  it('says when nothing matches', () => {
    render(<Harness />)
    openCombobox(screen.getByRole('combobox', { name: 'Company' }))
    fireEvent.change(screen.getByPlaceholderText('Search'), { target: { value: 'zzz' } })
    expect(screen.getByText('No matches')).toBeInTheDocument()
  })

  it('picks with a click, shows the label and marks the chosen option', () => {
    const onChange = vi.fn()
    render(<Harness onChange={onChange} />)
    chooseOption(screen.getByRole('combobox', { name: 'Company' }), 'Amani Retail')
    expect(onChange).toHaveBeenCalledWith('c-2')
    const trigger = screen.getByRole('combobox', { name: 'Company' })
    expect(trigger).toHaveTextContent('Amani Retail')
    expect(trigger).toHaveValue('c-2')
    const list = openCombobox(trigger)
    expect(within(list).getByRole('option', { name: 'Amani Retail' })).toHaveAttribute('data-checked', 'true')
  })

  it('opens with ArrowDown, filters by typing and picks with Enter', () => {
    const onChange = vi.fn()
    render(<Harness onChange={onChange} />)
    fireEvent.keyDown(screen.getByRole('combobox', { name: 'Company' }), { key: 'ArrowDown' })
    const search = screen.getByPlaceholderText('Search')
    fireEvent.change(search, { target: { value: 'west' } })
    fireEvent.keyDown(search, { key: 'Enter' })
    expect(onChange).toHaveBeenCalledWith('c-4')
  })

  it('moves with the arrow keys and skips disabled options', () => {
    const onChange = vi.fn()
    render(<Harness initial="c-2" onChange={onChange} />)
    fireEvent.keyDown(screen.getByRole('combobox', { name: 'Company' }), { key: 'ArrowDown' })
    const search = screen.getByPlaceholderText('Search')
    // Starts on the chosen option (Amani Retail); the next enabled one is Westlands.
    fireEvent.keyDown(search, { key: 'ArrowDown' })
    fireEvent.keyDown(search, { key: 'Enter' })
    expect(onChange).toHaveBeenCalledWith('c-4')
  })

  it('starts the search from a letter typed on the closed trigger', () => {
    render(<Harness />)
    fireEvent.keyDown(screen.getByRole('combobox', { name: 'Company' }), { key: 'w' })
    expect(screen.getByPlaceholderText('Search')).toHaveValue('w')
    expect(within(screen.getByRole('listbox')).getAllByRole('option').map((option) => option.textContent)).toEqual(['Westlands'])
  })

  it('does not pick a disabled option', () => {
    const onChange = vi.fn()
    render(<Harness onChange={onChange} />)
    const list = openCombobox(screen.getByRole('combobox', { name: 'Company' }))
    const archived = within(list).getByRole('option', { name: 'Old Branch (archived)' })
    expect(archived).toHaveAttribute('aria-disabled', 'true')
    fireEvent.click(archived)
    expect(onChange).not.toHaveBeenCalled()
  })

  it('closes on Escape and returns focus to the trigger', async () => {
    render(<Harness />)
    const trigger = screen.getByRole('combobox', { name: 'Company' })
    trigger.focus()
    openCombobox(trigger)
    fireEvent.keyDown(screen.getByPlaceholderText('Search'), { key: 'Escape' })
    await waitFor(() => expect(screen.queryByRole('listbox')).not.toBeInTheDocument())
    expect(trigger).toHaveAttribute('aria-expanded', 'false')
    await waitFor(() => expect(trigger).toHaveFocus())
  })

  it('does not open when disabled', () => {
    render(<Harness disabled />)
    const trigger = screen.getByRole('combobox', { name: 'Company' })
    expect(trigger).toBeDisabled()
    fireEvent.keyDown(trigger, { key: 'ArrowDown' })
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
  })

  it('carries the value in a hidden input when named', () => {
    const { container } = render(<Harness initial="c-1" name="company_id" />)
    expect(container.querySelector('input[type="hidden"][name="company_id"]')).toHaveValue('c-1')
  })
})
