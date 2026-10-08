// Test helpers for the ds Combobox and Select (searchable pickers, no native <select>).
import { fireEvent, screen, waitFor, within } from '@testing-library/react'

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

/** Closes the open picker as Escape would. */
export function closeCombobox() {
  fireEvent.keyDown(document.activeElement ?? document.body, { key: 'Escape' })
}

/** Opens a picker and clicks the option whose text matches `optionText` (string or RegExp). */
export function chooseOption(labelOrElement, optionText) {
  const list = openCombobox(labelOrElement)
  fireEvent.click(within(list).getByRole('option', { name: optionText }))
}

/**
 * Waits until a picker offers `optionText` (for options that load from the API),
 * then closes it. Opens the picker once and waits inside the open list: calling
 * optionTexts inside waitFor would re-run on its own DOM changes forever.
 */
export async function waitForOption(labelOrElement, optionText) {
  await waitFor(() => expect(comboboxFor(labelOrElement)).toBeEnabled())
  const list = openCombobox(labelOrElement)
  await within(list).findByRole('option', { name: optionText })
  closeCombobox()
}

/** The option texts a picker offers (opens it, reads them, closes it). Not for use inside waitFor. */
export function optionTexts(labelOrElement) {
  const list = openCombobox(labelOrElement)
  const texts = within(list)
    .queryAllByRole('option')
    .map((option) => option.textContent)
  closeCombobox()
  return texts
}
