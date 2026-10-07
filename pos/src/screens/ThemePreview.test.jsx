import { fireEvent, render, screen } from '@testing-library/react-native';
import { ThemePreview } from './ThemePreview';

describe('ThemePreview', () => {
  it('shows sync status, product tiles and the sale total', async () => {
    await render(<ThemePreview themeKey="light" onThemeChange={() => {}} />);

    expect(screen.getByText('Online · all synced')).toBeOnTheScreen();
    expect(screen.getByRole('button', { name: 'Tusker Lager 500ml, KES 250.00' })).toBeOnTheScreen();
    expect(screen.getByRole('button', { name: /^Bio Yoghurt 250ml, KES 110\.00, out of stock$/ })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Charge KES 760.00' })).toBeOnTheScreen();
  });

  it('adds a product to the sale when its tile is pressed', async () => {
    await render(<ThemePreview themeKey="light" onThemeChange={() => {}} />);

    await fireEvent.press(screen.getByRole('button', { name: 'Coca-Cola 500ml, KES 90.00' }));
    expect(screen.getByRole('button', { name: 'Charge KES 850.00' })).toBeOnTheScreen();
  });

  it('offers five themes as 48px choices and reports the pick', async () => {
    const onThemeChange = jest.fn();
    await render(<ThemePreview themeKey="light" onThemeChange={onThemeChange} />);

    const choices = screen.getAllByRole('radio');
    expect(choices.map((choice) => choice.props.accessibilityLabel)).toEqual(['Light', 'Dark', 'Executive', 'Warm', 'Test tenant']);
    choices.forEach((choice) => expect(choice).toHaveProp('className', expect.stringContaining('h-12')));
    expect(screen.getByRole('radio', { name: 'Light' })).toBeChecked();
    expect(screen.getByRole('radio', { name: 'Light' })).toHaveProp('className', expect.stringContaining('bg-surface-200 shadow-sm'));
    expect(screen.getByRole('radio', { name: 'Dark' })).toHaveProp('className', expect.stringContaining('bg-transparent'));
    expect(screen.getByTestId('theme-switcher')).toHaveProp('className', expect.stringContaining('bg-surface-300'));

    await fireEvent.press(screen.getByRole('radio', { name: 'Test tenant' }));
    expect(onThemeChange).toHaveBeenCalledWith('tenant');
  });
});
