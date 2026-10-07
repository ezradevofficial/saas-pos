import { act, fireEvent, render, screen } from '@testing-library/react-native';
import { setLocale } from '../../i18n';
import { SaleTotal } from './SaleTotal';

describe('SaleTotal', () => {
  afterEach(() => act(() => setLocale('en')));

  it('shows the translated charge label with the total', async () => {
    const onPay = jest.fn();
    await render(<SaleTotal currency="KES" subtotal={65517} tax={10483} total={76000} onPay={onPay} />);

    const pay = screen.getByRole('button', { name: 'Charge KES 760.00' });
    expect(pay).toHaveProp('className', expect.stringContaining('bg-accent'));
    await fireEvent.press(pay);
    expect(onPay).toHaveBeenCalledTimes(1);
    expect(screen.getByText('Subtotal')).toBeOnTheScreen();
    expect(screen.getByText('VAT')).toBeOnTheScreen();
    expect(screen.getByText('KES 655.17')).toBeOnTheScreen();
  });

  it('translates the charge label into French', async () => {
    await act(() => setLocale('fr'));
    await render(<SaleTotal currency="USD" subtotal={4181} tax={669} total={4850} />);

    expect(screen.getByRole('button', { name: 'Encaisser USD 48,50' })).toBeOnTheScreen();
    expect(screen.getByText('TVA')).toBeOnTheScreen();
  });

  it('shows a discount only when there is one, and disables charging an empty sale', async () => {
    await render(<SaleTotal currency="KES" subtotal={0} tax={0} total={0} discount={500} />);

    expect(screen.getByText('Discount')).toBeOnTheScreen();
    expect(screen.getByText('KES -5.00')).toBeOnTheScreen();
    expect(screen.getByRole('button')).toBeDisabled();
  });

  it('uses consumer labels when given', async () => {
    await render(<SaleTotal currency="KES" subtotal={100} tax={0} total={100} labels={{ pay: 'Pay', tax: 'Tax' }} />);

    expect(screen.getByRole('button', { name: 'Pay KES 1.00' })).toBeOnTheScreen();
    expect(screen.getByText('Tax')).toBeOnTheScreen();
  });
});
