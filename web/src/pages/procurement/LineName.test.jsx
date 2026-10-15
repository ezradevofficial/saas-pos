// Stage 7B walkthrough: an item line shows its item's name once; a
// description is shown only when it says something else.
import { render, screen } from '@testing-library/react'
import { LineName } from './ProcurementParts'

const FLOUR = { id: 'i-1', code: 'FLOUR', name: 'Maize flour 2 kg' }

describe('LineName', () => {
  it('does not repeat the item name as the description', () => {
    render(<LineName line={{ item: FLOUR, description: 'Maize flour 2 kg' }} />)
    expect(screen.getAllByText('Maize flour 2 kg')).toHaveLength(1)
  })

  it('shows a description that adds something', () => {
    render(<LineName line={{ item: FLOUR, description: 'Bags of 24' }} />)
    expect(screen.getByText('Maize flour 2 kg')).toBeInTheDocument()
    expect(screen.getByText('Bags of 24')).toBeInTheDocument()
  })

  it('names a free-text line by its description', () => {
    render(<LineName line={{ item: null, description: 'Delivery to Kisumu' }} />)
    expect(screen.getByText('Delivery to Kisumu')).toBeInTheDocument()
  })
})
