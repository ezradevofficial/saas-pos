import { fireEvent, render, screen, waitFor, within } from '@testing-library/react-native';
import * as Location from 'expo-location';
import App from '../../../App';
import { memoryBackend } from '../../device/credentials';
import { createServices } from '../../services/services';
import { NetworkError } from '../../sync/api';
import { fakeServer } from '../../test/fakeServer';
import { testDatabase } from '../../test/testDatabase';

// ECOM-28, ECOM-29, NFR-04: the rider screen. A rider signs in as themselves from the
// pairing screen, sees their deliveries, and marks them picked up and delivered with the
// customer's code; offline the changes wait on the phone and go up when it is back.

// The native location and camera modules, as a phone would answer.
jest.mock('expo-location', () => ({
  Accuracy: { Balanced: 3 },
  getForegroundPermissionsAsync: jest.fn(),
  requestForegroundPermissionsAsync: jest.fn(),
  getCurrentPositionAsync: jest.fn(),
}));

const mockTakePicture = jest.fn(async () => ({ uri: 'file:///cache/Camera/proof-1.jpg', width: 1280, height: 720 }));
jest.mock('expo-camera', () => {
  const React = require('react');
  const { View } = require('react-native');
  const CameraView = React.forwardRef(function CameraView({ onCameraReady }, ref) {
    React.useImperativeHandle(ref, () => ({ takePictureAsync: mockTakePicture, getAvailablePictureSizesAsync: async () => ['1920x1080', '1280x720'] }));
    React.useEffect(() => {
      onCameraReady?.();
    }, [onCameraReady]);
    return React.createElement(View, { testID: 'camera' });
  });
  return { CameraView, useCameraPermissions: () => [{ granted: true }, jest.fn()] };
});

beforeEach(() => {
  jest.clearAllMocks();
  Location.getForegroundPermissionsAsync.mockResolvedValue({ status: 'undetermined', granted: false, canAskAgain: true });
  Location.requestForegroundPermissionsAsync.mockResolvedValue({ status: 'granted', granted: true, canAskAgain: true });
  Location.getCurrentPositionAsync.mockResolvedValue({ coords: { latitude: -1.2648, longitude: 36.8025 }, timestamp: Date.parse('2026-10-22T09:00:00Z') });
});

const DELIVERY = { id: 'd-1', status: 'assigned', order_number: 'WEB-A-2026-00001', customer_name: 'Wanjiku', customer_phone: '+254712345678', address: { line: 'Mpaka Road 12', area: 'Westlands' }, currency: 'KES', collect_minor: '136000' };

function riderServer({ sms } = {}) {
  const server = {
    offline: false,
    updates: [],
    asked: [],
    async get(path, options) {
      if (server.offline) throw new NetworkError(new Error('offline'));
      // AUTH-01: the platform's public settings, asked without the rider's token.
      if (path === 'public/branding' && sms !== undefined) {
        server.asked.push(options);
        return { status: 200, body: { data: null, meta: { sms_available: sms } } };
      }
      return path === 'ecommerce/rider/deliveries' ? { status: 200, body: { data: [DELIVERY] } } : { status: 404, body: null };
    },
    async post(path, body) {
      if (server.offline) throw new NetworkError(new Error('offline'));
      if (path === 'auth/sign-in') return { status: 200, body: { token: 'user-token', user: { id: 'u-1', name: 'Juma Rider' } } };
      if (path === 'ecommerce/rider/updates') {
        server.calls.push(path);
        server.updates.push(...body.updates);
        return { status: 200, body: { results: body.updates.map((u) => ({ id: u.id, status: 'stored' })) } };
      }
      return { status: 204, body: null };
    },
    calls: [],
    photos: [],
    async upload(path, form) {
      if (server.offline) throw new NetworkError(new Error('offline'));
      server.calls.push(path);
      server.photos.push({ path, form });
      return { status: 201, body: { data: { id: 'proof-1', proof_kind: 'photo' } } };
    },
  };
  return server;
}

const statusUpdates = (rider) => rider.updates.filter((update) => update.kind === 'status');

async function setup(options) {
  const rider = riderServer(options);
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

describe('Rider sign-in in email-only mode (AUTH-01)', () => {
  it('asks for an email address when text messages aren’t available', async () => {
    const { rider } = await setup({ sms: false });
    await fireEvent.press(await screen.findByRole('button', { name: 'Sign in as a rider' }));

    expect(await screen.findByLabelText('Email address')).toBeTruthy();
    expect(screen.queryByLabelText('Email or phone')).toBeNull();
    expect(screen.getByText(/Text messages aren’t available yet/)).toBeTruthy();
    expect(rider.asked).toEqual([{ auth: false }]);
  });

  it('keeps email or phone while text messages are available', async () => {
    const { rider } = await setup({ sms: true });
    await fireEvent.press(await screen.findByRole('button', { name: 'Sign in as a rider' }));

    await waitFor(() => expect(rider.asked).toHaveLength(1));
    expect(await screen.findByLabelText('Email or phone')).toBeTruthy();
    expect(screen.queryByLabelText('Email address')).toBeNull();
  });
});

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
    expect((await services.store.meta())['rider:u-1'].queue).toEqual([]);
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

describe('Rider location (ECOM-29, KE DPA minimum data)', () => {
  it('explains the sharing before the phone asks, then shares the position of the active delivery', async () => {
    const { rider } = await setup();
    await signIn();
    const consent = await screen.findByTestId('location-consent');
    expect(within(consent).getByText(/Only the shop’s staff and the customer of that order see it, and it is cleared when the delivery ends/)).toBeTruthy();
    // The phone's own prompt has not been shown yet.
    expect(Location.requestForegroundPermissionsAsync).not.toHaveBeenCalled();
    expect(Location.getCurrentPositionAsync).not.toHaveBeenCalled();

    await fireEvent.press(within(consent).getByRole('button', { name: 'Share my location' }));
    expect(Location.requestForegroundPermissionsAsync).toHaveBeenCalledTimes(1);
    await waitFor(() => expect(rider.updates.some((update) => update.kind === 'location')).toBe(true));
    expect(rider.updates.find((update) => update.kind === 'location')).toMatchObject({ delivery_id: 'd-1', latitude: -1.2648, longitude: 36.8025, occurred_at: '2026-10-22T09:00:00.000Z' });
    expect(Location.getCurrentPositionAsync).toHaveBeenCalledWith({ accuracy: 3 });
    expect(await screen.findByText('Your location is shared while a delivery is in progress.')).toBeTruthy();
    expect(screen.queryByTestId('location-consent')).toBeNull();
  });

  it('leaves the rider working after "Not now", with a quiet way to ask again', async () => {
    const { rider } = await setup();
    await signIn();
    await fireEvent.press(within(await screen.findByTestId('location-consent')).getByRole('button', { name: 'Not now' }));
    expect(Location.requestForegroundPermissionsAsync).not.toHaveBeenCalled();
    expect(await screen.findByText(/Location sharing is off. You can still deliver/)).toBeTruthy();
    expect(screen.queryByTestId('location-consent')).toBeNull();

    await fireEvent.press(screen.getByRole('button', { name: 'Picked up' }));
    await waitFor(() => expect(statusUpdates(rider)).toHaveLength(1));
    expect(rider.updates.some((update) => update.kind === 'location')).toBe(false);
    expect(Location.getCurrentPositionAsync).not.toHaveBeenCalled();

    // Asking again goes to the phone's prompt; a refusal keeps the rider working.
    Location.requestForegroundPermissionsAsync.mockResolvedValue({ status: 'denied', granted: false, canAskAgain: true });
    await fireEvent.press(screen.getByRole('button', { name: 'Share my location' }));
    expect(Location.requestForegroundPermissionsAsync).toHaveBeenCalledTimes(1);
    expect(await screen.findByText(/Location sharing is off/)).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Delivered' })).toBeTruthy();
  });
});

describe('Rider photo proof (ECOM-28)', () => {
  it('takes a photo, uploads it, then sends the delivered update without a code', async () => {
    const { rider, services } = await setup();
    await signIn();
    await fireEvent.press(screen.getByRole('button', { name: 'Picked up' }));
    await waitFor(() => expect(statusUpdates(rider)).toHaveLength(1));
    await fireEvent.press(await screen.findByRole('button', { name: 'Delivered' }));
    const confirm = await screen.findByRole('button', { name: 'Confirm delivery' });

    await fireEvent.press(screen.getByRole('radio', { name: 'Photo of the parcel' }));
    expect(await screen.findByTestId('camera')).toBeTruthy();
    expect(confirm.props.accessibilityState.disabled).toBe(true);
    await fireEvent.press(screen.getByRole('button', { name: 'Take photo' }));
    expect(mockTakePicture).toHaveBeenCalledWith({ quality: 0.5, exif: false });
    expect(await screen.findByLabelText('Delivery photo')).toBeTruthy();

    // Retake goes back to the camera; the second photo is used.
    await fireEvent.press(screen.getByRole('button', { name: 'Retake' }));
    await fireEvent.press(await screen.findByRole('button', { name: 'Take photo' }));
    await fireEvent.press(await screen.findByRole('button', { name: 'Use photo' }));
    expect(await screen.findByText('Photo ready. It is sent with the delivery.')).toBeTruthy();

    rider.offline = true;
    await fireEvent.press(screen.getByRole('button', { name: 'Confirm delivery' }));
    expect(await screen.findByText('2 updates waiting')).toBeTruthy();
    const kept = (await services.store.meta())['rider:u-1'];
    expect(kept.photos).toEqual([expect.objectContaining({ delivery_id: 'd-1', uri: 'file:///cache/Camera/proof-1.jpg' })]);

    rider.offline = false;
    rider.calls = [];
    await fireEvent.press(screen.getByRole('button', { name: 'Refresh deliveries' }));
    await waitFor(() => expect(statusUpdates(rider)).toHaveLength(2));
    expect(rider.calls.slice(0, 2)).toEqual(['ecommerce/rider/deliveries/d-1/photo', 'ecommerce/rider/updates']);
    const delivered = statusUpdates(rider)[1];
    expect(delivered).toMatchObject({ status: 'delivered', collected_minor: '136000' });
    expect(delivered.code).toBeUndefined();
    expect((await services.store.meta())['rider:u-1'].photos).toEqual([]);
  });
});
