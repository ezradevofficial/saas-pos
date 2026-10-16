import { fireEvent, render, screen, waitFor, within } from '@testing-library/react-native';
import App from '../../../App';
import { memoryBackend } from '../../device/credentials';
import { createServices } from '../../services/services';
import { NetworkError } from '../../sync/api';
import { fakeServer } from '../../test/fakeServer';
import { testDatabase } from '../../test/testDatabase';

// ECOM-28, ECOM-29, NFR-04: the rider screen. A rider signs in as themselves from the
// pairing screen, sees their deliveries, and marks them picked up and delivered with the
// customer's code; offline the changes wait on the phone and go up when it is back.

const DELIVERY = { id: 'd-1', status: 'assigned', order_number: 'WEB-A-2026-00001', customer_name: 'Wanjiku', customer_phone: '+254712345678', address: { line: 'Mpaka Road 12', area: 'Westlands' }, currency: 'KES', collect_minor: '136000' };

function riderServer() {
  const server = {
    offline: false,
    updates: [],
    async get(path) {
      if (server.offline) throw new NetworkError(new Error('offline'));
      return path === 'ecommerce/rider/deliveries' ? { status: 200, body: { data: [DELIVERY] } } : { status: 404, body: null };
    },
    async post(path, body) {
      if (server.offline) throw new NetworkError(new Error('offline'));
      if (path === 'auth/sign-in') return { status: 200, body: { token: 'user-token', user: { id: 'u-1', name: 'Juma Rider' } } };
      if (path === 'ecommerce/rider/updates') {
        server.updates.push(...body.updates);
        return { status: 200, body: { results: body.updates.map((u) => ({ id: u.id, status: 'stored' })) } };
      }
      return { status: 204, body: null };
    },
  };
  return server;
}

async function setup() {
  const rider = riderServer();
  const services = createServices({ database: testDatabase(), api: fakeServer(), riderApi: rider, credentialsBackend: memoryBackend(), netInfo: null });
  await render(<App services={services} display={false} />);
  return { rider, services };
}

async function signIn() {
  await fireEvent.press(await screen.findByRole('button', { name: 'Sign in as a rider' }));
  await fireEvent.changeText(await screen.findByLabelText('Email or phone'), 'juma@example.test');
  await fireEvent.changeText(screen.getByLabelText('Password'), 'secret-password');
  await fireEvent.press(screen.getByRole('button', { name: 'Sign in' }));
  return screen.findByTestId('delivery-d-1');
}

describe('Rider screen (ECOM-28, ECOM-29)', () => {
  it('shows the rider’s delivery with the cash to collect, currency first', async () => {
    await setup();
    const card = await signIn();
    expect(within(card).getByText('WEB-A-2026-00001')).toBeTruthy();
    expect(within(card).getByText('Assigned')).toBeTruthy();
    expect(within(card).getByText('Mpaka Road 12, Westlands')).toBeTruthy();
    expect(within(card).getByText('Cash to collect')).toBeTruthy();
    expect(within(card).getByText(/1,360\.00/)).toBeTruthy();
    expect(screen.getByText('Juma Rider')).toBeTruthy();
  });

  it('keeps a pick-up made offline on the phone and sends it when the connection is back', async () => {
    const { rider, services } = await setup();
    await signIn();
    rider.offline = true;
    await fireEvent.press(screen.getByRole('button', { name: 'Picked up' }));
    expect(await screen.findByText('1 update waiting')).toBeTruthy();
    expect(screen.getByText('Saved on this phone. It is sent when the connection is back.')).toBeTruthy();
    expect(screen.getByText('On the way')).toBeTruthy();
    expect(rider.updates).toEqual([]);

    rider.offline = false;
    await fireEvent.press(screen.getByRole('button', { name: 'Refresh deliveries' }));
    await waitFor(() => expect(rider.updates).toHaveLength(1));
    expect(rider.updates[0]).toMatchObject({ delivery_id: 'd-1', kind: 'status', status: 'picked_up' });
    expect((await services.store.meta()).riderQueue).toEqual([]);
  });

  it('asks for the 6-digit code and the cash collected before confirming a delivery', async () => {
    const { rider } = await setup();
    await signIn();
    await fireEvent.press(screen.getByRole('button', { name: 'Picked up' }));
    await waitFor(() => expect(rider.updates).toHaveLength(1));
    await fireEvent.press(await screen.findByRole('button', { name: 'Delivered' }));
    const confirm = await screen.findByRole('button', { name: 'Confirm delivery' });
    expect(confirm.props.accessibilityState.disabled).toBe(true);
    await fireEvent.changeText(screen.getByLabelText(/Delivery code/), '12ab3456');
    await fireEvent.press(confirm);
    await waitFor(() => expect(rider.updates).toHaveLength(2));
    expect(rider.updates[1]).toMatchObject({ status: 'delivered', code: '123456', collected_minor: '136000' });
  });
});
