import { fireEvent, render, screen } from '@testing-library/react-native';
import { SessionProvider } from '../auth/session';
import { resetDisplayChannel } from '../pos/customerDisplay';
import { ServicesContext } from '../services/services';
import { CustomerDisplayScreen } from './CustomerDisplayScreen';

// LAY-05, AUTH-06: leaving the in-app customer display takes the PIN of the staff member signed in on the till.

const CASHIER = { id: 'u-1', name: 'Amina Otieno' };

async function renderDisplay({ signIn, user = CASHIER } = {}) {
  const onClose = jest.fn();
  const pinGate = { signIn: jest.fn(signIn) };
  const channel = resetDisplayChannel();
  await render(
    <ServicesContext.Provider value={{ pinGate }}>
      <SessionProvider initialUser={user}>
        <CustomerDisplayScreen channel={channel} onClose={onClose} />
      </SessionProvider>
    </ServicesContext.Provider>,
  );
  return { onClose, pinGate };
}

async function typePin(pin) {
  for (const digit of pin) await fireEvent.press(screen.getByRole('button', { name: digit }));
}

describe('closing the customer display', () => {
  it('asks for the signed-in staff member’s PIN and stays open on a wrong one', async () => {
    const { onClose, pinGate } = await renderDisplay({ signIn: async () => ({ ok: false, reason: 'incorrect', attemptsLeft: 4 }) });

    await fireEvent.press(screen.getByRole('button', { name: 'Close customer display' }));
    expect(onClose).not.toHaveBeenCalled();
    expect(await screen.findByText('Amina Otieno, type your PIN to close the customer display.')).toBeOnTheScreen();

    await typePin('1111');
    await fireEvent.press(screen.getAllByRole('button', { name: 'Close customer display' }).at(-1));
    expect(pinGate.signIn).toHaveBeenCalledWith({ userId: 'u-1', kind: 'pin', input: '1111' });
    expect(await screen.findByText(/Wrong PIN\. 4 tries left/)).toBeOnTheScreen();
    expect(onClose).not.toHaveBeenCalled();
  });

  it('closes once the PIN is right', async () => {
    const { onClose } = await renderDisplay({ signIn: async () => ({ ok: true, user: CASHIER }) });

    await fireEvent.press(screen.getByRole('button', { name: 'Close customer display' }));
    await typePin('274915');
    await fireEvent.press(screen.getAllByRole('button', { name: 'Close customer display' }).at(-1));
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('stays open when Cancel is pressed', async () => {
    const { onClose } = await renderDisplay({ signIn: async () => ({ ok: true }) });

    await fireEvent.press(screen.getByRole('button', { name: 'Close customer display' }));
    await fireEvent.press(await screen.findByRole('button', { name: 'Cancel' }));
    expect(screen.queryByText('Amina Otieno, type your PIN to close the customer display.')).toBeNull();
    expect(onClose).not.toHaveBeenCalled();
  });
});
