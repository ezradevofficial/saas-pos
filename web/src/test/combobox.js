// Test helpers for the ds Combobox and Select (searchable pickers, no native <select>).
import { fireEvent, screen, within } from '@testing-library/react'

/** The picker's trigger, from its label (string or RegExp) or the element itself. */
export function comboboxFor(labelOrElement) {
  return labelOrElement instanceof Element ? labelOrElement : screen.getByLabelText(labelOrElement)
}

/** Opens a picker and returns its option list. */
export function openCombobox(labelOrElement) {
  const trigger = comboboxFor(labelOrElement)
  if (trigger.getAttribute('aria-expanded') !== 'true') fireEvent.click(trigger)
  return screen.getByRole('listbox')
}

/** Opens a picker and clicks the option whose text matches `optionText` (string or RegExp). */
export function chooseOption(labelOrElement, optionText) {
  const list = openCombobox(labelOrElement)
  fireEvent.click(within(list).getByRole('option', { name: optionText }))
}

/** The option texts a picker offers (opens it, reads them, closes it). */
export function optionTexts(labelOrElement) {
  const list = openCombobox(labelOrElement)
  const texts = within(list)
    .queryAllByRole('option')
    .map((option) => option.textContent)
  fireEvent.keyDown(document.activeElement ?? document.body, { key: 'Escape' })
  return texts
}
