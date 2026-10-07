import { fireEvent, render, screen } from '@testing-library/react-native';
import { PosTile } from './PosTile';

describe('PosTile', () => {
  it('is disabled and announces out of stock when stock is 0', async () => {
    const onSelect = jest.fn();
    await render(<PosTile name="Bio Yoghurt 250ml" price={11000} currency="KES" stock={0} onSelect={onSelect} />);

    const tile = screen.getByRole('button', { name: 'Bio Yoghurt 250ml, KES 110.00, out of stock' });
    expect(tile).toBeDisabled();
    expect(screen.getByText('Out')).toBeOnTheScreen();
    await fireEvent.press(tile);
    expect(onSelect).not.toHaveBeenCalled();
  });

  it('selects on press and shows the price', async () => {
    const onSelect = jest.fn();
    await render(<PosTile name="Coca-Cola 500ml" price={9000} currency="KES" stock={120} onSelect={onSelect} />);

    await fireEvent.press(screen.getByRole('button', { name: 'Coca-Cola 500ml, KES 90.00' }));
    expect(onSelect).toHaveBeenCalledTimes(1);
    expect(screen.getByText('KES 90.00')).toBeOnTheScreen();
    expect(screen.queryByTestId('status-dot')).toBeNull();
  });

  it('warns when stock is at or under the low-stock threshold', async () => {
    await render(<PosTile name="Kabras Sugar 1kg" price={17500} currency="KES" stock={3} />);

    expect(screen.getByRole('button', { name: 'Kabras Sugar 1kg, KES 175.00, 3 left' })).toBeEnabled();
    expect(screen.getByText('3 left')).toBeOnTheScreen();
    expect(screen.getByTestId('status-dot')).toHaveProp('className', expect.stringContaining('bg-warning'));
  });

  it('is at least 48px tall for touch', async () => {
    await render(<PosTile name="Mandazi" price={12000} currency="KES" />);

    const className = screen.getByRole('button').props.className;
    expect(className).toContain('min-h-12');
    expect(className).toContain('focus-visible:outline-focus');
  });
});
