import { fireEvent, render, screen } from '@testing-library/react-native';
import { PharmacyCartPanel } from './PharmacyCartPanel';

// PHA-07, PHA-08, PHA-16, PHA-17, PHA-20: the sale panel for a pharmacy sale: the batches a
// line takes (and a way to choose), the prescription form and the pharmacist's approval.

const mockActions = { setExtension: jest.fn(), authorize: jest.fn() };
let mockCart;
let mockUser;

jest.mock('../pos/PosProvider', () => ({
  usePosActions: () => mockActions,
  usePosCart: () => ({ cart: mockCart }),
  usePosData: () => ({ catalogue: mockCatalogue }),
}));
jest.mock('../auth/session', () => ({ useSession: () => ({ user: mockUser }) }));

const lot = (lotId, batch, expires, qty) => ({ id: `b-${lotId}`, item_id: 'item-1', lot_id: lotId, batch_number: batch, expires_on: expires, qty, blocked: null });
const mockCatalogue = {
  day: '2026-10-13',
  dayAt: () => '2026-10-13',
  itemById: new Map([['item-1', { id: 'item-1', base_uom_id: 'u', uoms: [] }]]),
  ext: {
    pharmacy: {
      settings: { near_expiry_days: 90, min_sale_days: 0, prescription_classes: ['prescription_only'], pharmacist_classes: ['prescription_only'], prescription_valid_days: 30 },
      profiles: new Map([['item-1', { item_id: 'item-1', class: 'prescription_only', batch_tracked: true, restricted_sale: false, usage_notes: null }]]),
      lots: new Map([['item-1', [lot('l1', 'B-SOON', '2027-01-10', '2'), lot('l2', 'B-LATE', '2027-06-01', '9'), lot('l0', 'B-OLD', '2026-10-01', '4')]]]),
      used: new Map(),
    },
  },
};

const baseCart = (ext = {}) => ({ id: 'sale-1', customer: null, lines: [{ id: 'line-1', itemId: 'item-1', uomId: 'u', qty: '1', name: 'Amoxicillin' }], ext: { pharmacy: ext } });

beforeEach(() => {
  mockActions.setExtension.mockReset();
  mockActions.authorize.mockReset();
  mockUser = { id: 'u1', permissions: ['pos.sale.create'], limits: {} };
  mockCart = baseCart();
});

describe('PharmacyCartPanel', () => {
  it('shows nothing while the sale holds no profiled item', async () => {
    mockCart = { ...baseCart(), lines: [{ id: 'x', itemId: 'other', uomId: 'u', qty: '1', name: 'Soap' }] };
    await render(<PharmacyCartPanel />);
    expect(screen.queryByTestId('pharmacy-panel')).toBeNull();
  });

  it('shows the batch it takes first, with the near-expiry warning, and what is still missing', async () => {
    await render(<PharmacyCartPanel />);
    expect(screen.getByText('Batch B-SOON · expires 2027-01-10 · 1 · Near expiry')).toBeOnTheScreen();
    expect(screen.getByText('Prescription needed')).toBeOnTheScreen();
    expect(screen.getByText('This sale needs a prescription.')).toBeOnTheScreen();
    expect(screen.getByText('A pharmacist must approve this sale.')).toBeOnTheScreen();
  });

  it('lets the person choose another batch, never an expired one', async () => {
    await render(<PharmacyCartPanel />);
    await fireEvent.press(screen.getByRole('button', { name: 'Choose batch for Amoxicillin' }));
    expect(screen.getByText('Batch B-OLD · expires 2026-10-01 · 4 in stock')).toBeOnTheScreen();
    expect(screen.getByText('Batch expired')).toBeOnTheScreen();
    expect(screen.getByRole('button', { name: 'Use batch B-OLD' })).toBeDisabled();
    await fireEvent.press(screen.getByRole('button', { name: 'Use batch B-LATE' }));
    expect(mockActions.setExtension).toHaveBeenCalledWith('pharmacy', { lots: { 'line-1': [{ lot_id: 'l2', qty: '1' }] } });
  });

  it('takes the prescription down and keeps it on the sale', async () => {
    await render(<PharmacyCartPanel />);
    await fireEvent.press(screen.getByRole('button', { name: 'Add prescription' }));
    expect(screen.getByRole('button', { name: 'Save prescription' })).toBeDisabled();
    await fireEvent.changeText(screen.getByLabelText(/Patient name/), 'Amina');
    await fireEvent.changeText(screen.getByLabelText(/Prescriber name/), 'Dr Otieno');
    await fireEvent.changeText(screen.getByLabelText(/Prescriber licence number/), 'LIC-1');
    await fireEvent.changeText(screen.getByLabelText(/Facility/), 'Westlands Clinic');
    await fireEvent.press(screen.getByRole('button', { name: 'Save prescription' }));
    const [key, value] = mockActions.setExtension.mock.calls[0];
    expect(key).toBe('pharmacy');
    expect(value.prescription).toMatchObject({ patient_name: 'Amina', prescriber_name: 'Dr Otieno', prescriber_licence: 'LIC-1', facility: 'Westlands Clinic', prescribed_on: '2026-10-13' });
    expect(value.prescription.id).toMatch(/^[0-9a-f-]{36}$/);
  });

  it('asks a pharmacist to approve with their PIN, for this sale', async () => {
    mockActions.authorize.mockResolvedValue({ override: { manager_id: 'pharmacist-1' }, approvedBy: 'pharmacist-1' });
    await render(<PharmacyCartPanel />);
    await fireEvent.press(screen.getByRole('button', { name: 'Ask a pharmacist to approve' }));
    expect(mockActions.authorize).toHaveBeenCalledWith('dispense', null, 'sale-1');
    await screen.findByRole('button', { name: 'Ask a pharmacist to approve' });
    expect(mockActions.setExtension).toHaveBeenCalledWith('pharmacy', { approval: { manager_id: 'pharmacist-1' } });
  });

  it('does not ask a pharmacist to approve their own sale', async () => {
    mockUser = { id: 'p1', permissions: ['pos.sale.create', 'pharmacy.dispense.authorise'], limits: {} };
    await render(<PharmacyCartPanel />);
    expect(screen.queryByRole('button', { name: 'Ask a pharmacist to approve' })).toBeNull();
  });
});
