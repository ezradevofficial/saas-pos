// Test helpers for a list's Filters drawer (ListView, ListFilters).
import { fireEvent, screen, waitFor } from '@testing-library/react'

/** The toolbar's Filters button (its name also carries the active count). */
// hidden: the open drawer hides the page (and the button) from the accessibility tree.
export const filtersButton = (name = /^(Filters|Filtres)/) => screen.getByRole('button', { name, hidden: true })

/** Opens the Filters drawer and returns it. */
export function openFilters(name) {
  const button = filtersButton(name)
  if (button.getAttribute('aria-expanded') !== 'true') fireEvent.click(button)
  return screen.getByRole('dialog', { name: /^(Filters|Filtres)$/ })
}

/** Closes the drawer as Escape would and waits for it to go. */
export async function closeFilters() {
  fireEvent.keyDown(screen.getByRole('dialog', { name: /^(Filters|Filtres)$/ }), { key: 'Escape' })
  await waitFor(() => expect(screen.queryByRole('dialog', { name: /^(Filters|Filtres)$/ })).not.toBeInTheDocument())
}
