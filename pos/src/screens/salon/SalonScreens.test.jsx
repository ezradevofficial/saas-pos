import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import { SalonContext } from '../../salon/context';
import { actionsFor, SalonDayScreen } from './SalonDayScreen';
import { QueueScreen } from './QueueScreen';

// SAL-13, SAL-12, SAL-29: the day and the queue as the front desk sees them, from the data on the device.
// The session, the sale and the sync status are stubbed; the data is what the pulled schedule gives.

const user = { id: 'u1', name: 'Zawadi', permissions: ['salon.appointment.serve', 'salon.appointment.create', 'salon.appointment.cancel', 'salon.queue.manage'] };
jest.mock('../../auth/session', () => ({ useSession: () => ({ user: mockUser(), actorProof: null }) }));
jest.mock('../../pos/PosProvider', () => ({ usePosData: () => ({ catalogue: { timeZone: 'UTC', saleCurrency: 'KES' } }), usePosActions: () => ({}) }));
jest.mock('../../sync/useSyncStatus', () => ({ useSyncStatus: () => ({ state: 'online', pending: 0 }) }));
const mockUser = () => user;

const today = new Date().toISOString().slice(0, 10);
const at = (hour) => `${today}T${String(hour).padStart(2, '0')}:00:00.000Z`;
const view = (id, status, hour, extra = {}) => ({
  id, number: `SAL-${id}`, status, source: 'staff', client_id: null, client_name: `Client ${id}`, has_alerts: false, starts_at: at(hour), ends_at: at(hour + 1), total_minor: '150000', currency: 'KES',
  fee_minor: '0', fee_status: 'none', deposit_required_minor: '0', deposit_balance_minor: '0',
  lines: [{ id: `l${id}`, service_id: 's1', item_id: 'i1', name: 'Haircut', kind: 'service', staff_id: 'amina', staff_name: 'Amina', starts_at: at(hour), ends_at: at(hour + 1), price_minor: '150000' }],
  local: {}, ...extra,
});

const data = (patch = {}) => ({
  enabled: true,
  settings: { id: 'c1', timezone: 'UTC' },
  staff: [{ id: 'amina', name: 'Amina', kind: 'staff' }],
  resources: [],
  services: [],
  packages: [],
  appointments: [view('a', 'booked', 8, { has_alerts: true, deposit_balance_minor: '30000' }), view('b', 'no_show', 9, { fee_status: 'due', fee_minor: '75000' }), view('c', 'paid', 10)],
  queue: [{ id: 'q1', guest_name: 'Kamau', service_ids: ['s1'], joined_at: at(7), position: 1, wait_minutes: 10, local: {} }],
  rejected: [],
  service: { step: jest.fn(), retry: jest.fn(), leaveQueue: jest.fn() },
  ...patch,
});

const wrap = (value, children) => <SalonContext.Provider value={value}>{children}</SalonContext.Provider>;

describe('the salon day screen', () => {
  it('lists the day with a status word and marks for notes, deposit and fee', async () => {
    await render(wrap(data(), <SalonDayScreen onMenu={() => {}} onQueue={() => {}} onCharge={() => {}} />));
    expect(screen.getByText('Client a')).toBeTruthy();
    expect(screen.getByText('Notes to read')).toBeTruthy();
    expect(screen.getByText('Deposit held KES 300.00')).toBeTruthy();
    expect(screen.getByText('Fee due KES 750.00')).toBeTruthy();
    expect(screen.getByText('No-show')).toBeTruthy();
    expect(screen.getByText('3 appointments, 1 done')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Queue (1)' })).toBeTruthy();
  });

  it('offers only what the status and the person allow, and charges a fee only while due', () => {
    const noShow = view('b', 'no_show', 9, { fee_status: 'due', fee_minor: '75000' });
    expect(actionsFor(noShow, user)).toMatchObject({ fee: true, charge: false, checkIn: false, cancel: false });
    const booked = view('a', 'booked', 8);
    expect(actionsFor(booked, user, Date.parse(at(7)))).toMatchObject({ checkIn: true, start: true, noShow: false, cancel: true, charge: false });
    expect(actionsFor(booked, user, Date.parse(at(9)))).toMatchObject({ noShow: true, charge: true });
    expect(actionsFor(view('c', 'paid', 10), user)).toMatchObject({ checkIn: false, finish: false, charge: false, cancel: false });
    // A person without the right to serve sees no steps.
    expect(actionsFor(booked, { id: 'u2', permissions: [] }, Date.parse(at(9)))).toMatchObject({ checkIn: false, start: false, finish: false, cancel: false });
  });

  it('opens an appointment, checks the client in and shows a refused record with a way to send it again', async () => {
    const step = jest.fn().mockResolvedValue({});
    const retry = jest.fn();
    const value = data({ service: { step, retry }, rejected: [{ id: 'r1', kind: 'event', local: { error: { code: 'slot_taken' } } }] });
    await render(wrap(value, <SalonDayScreen onMenu={() => {}} onQueue={() => {}} onCharge={() => {}} />));
    expect(screen.getByText('1 records were refused by the server')).toBeTruthy();
    await fireEvent.press(screen.getByRole('button', { name: 'Send again' }));
    expect(retry).toHaveBeenCalledWith('r1');
    await fireEvent.press(screen.getByRole('button', { name: 'Client a, Booked' }));
    await fireEvent.press(await screen.findByRole('button', { name: 'Check in' }));
    await waitFor(() => expect(step).toHaveBeenCalledWith('a', 'check_in', { user, actorProof: null, reason: undefined }));
  });
});

describe('the queue screen', () => {
  it('shows who waits, in order, with the estimated wait', async () => {
    await render(wrap(data(), <QueueScreen onBack={() => {}} />));
    expect(screen.getByText('Kamau')).toBeTruthy();
    expect(screen.getByText('about 10 min')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Seat' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Add a walk-in' })).toBeTruthy();
  });
});
