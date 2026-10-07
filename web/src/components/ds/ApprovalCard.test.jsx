import { fireEvent, render, screen } from '@testing-library/react'
import { ApprovalCard } from './ApprovalCard'

const base = { docType: 'Purchase order', number: 'PO-00231', title: 'Office chairs', requester: 'Amina Otieno' }

describe('ApprovalCard', () => {
  it('shows the document summary, amount and translated actions', () => {
    const onApprove = vi.fn()
    const onReject = vi.fn()
    const onReturn = vi.fn()
    render(<ApprovalCard {...base} amount={1245000} currency="KES" branch="Westlands" due="Due today 17:00"
      onApprove={onApprove} onReject={onReject} onReturn={onReturn} />)
    expect(screen.getByRole('article')).toHaveTextContent('Purchase order · PO-00231')
    expect(screen.getByText('Office chairs')).toBeInTheDocument()
    expect(screen.getByRole('article')).toHaveTextContent('KES 12,450.00')
    expect(screen.getByText('Requested by')).toBeInTheDocument()
    expect(screen.getByText('Westlands')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Approve' }))
    fireEvent.click(screen.getByRole('button', { name: 'Reject' }))
    fireEvent.click(screen.getByRole('button', { name: 'Return' }))
    expect(onApprove).toHaveBeenCalled()
    expect(onReject).toHaveBeenCalled()
    expect(onReturn).toHaveBeenCalled()
  })

  it('omits the amount for documents without a value', () => {
    render(<ApprovalCard {...base} docType="Leave" />)
    expect(screen.getByRole('article')).not.toHaveTextContent('KES')
  })

  it('shows delegation and overdue escalation', () => {
    render(<ApprovalCard {...base} onBehalfOf="Peter Mwangi" overdue escalatesIn="in 2 h" />)
    expect(screen.getByText('Delegated from')).toBeInTheDocument()
    expect(screen.getByText('Overdue · escalates in 2 h')).toBeInTheDocument()
    expect(screen.getByRole('article')).toHaveClass('border-danger')
  })
})
