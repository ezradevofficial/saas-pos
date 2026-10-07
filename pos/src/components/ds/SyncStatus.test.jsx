import { render, screen } from '@testing-library/react-native';
import { SyncStatus } from './SyncStatus';

describe('SyncStatus', () => {
  it('says online in words', async () => {
    await render(<SyncStatus state="online" />);

    expect(screen.getByText('Online · all synced')).toBeOnTheScreen();
    expect(screen.getByTestId('sync-status')).toHaveProp('role', 'status');
  });

  it('reassures when offline and counts waiting sales', async () => {
    await render(<SyncStatus state="offline" pending={3} />);

    expect(screen.getByText('Offline · selling continues')).toHaveProp('className', expect.stringContaining('text-warning'));
    expect(screen.getByText('3 sales waiting')).toBeOnTheScreen();
  });

  it('accepts translated labels and falls back to online for unknown states', async () => {
    await render(
      <>
        <SyncStatus state="syncing" labels={{ syncing: 'Uploading' }} />
        <SyncStatus state="lost" />
      </>,
    );

    expect(screen.getByText('Uploading')).toBeOnTheScreen();
    expect(screen.getByText('Online · all synced')).toBeOnTheScreen();
  });
});
