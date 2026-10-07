import { act, render, screen } from '@testing-library/react-native';
import { setLocale } from '../../i18n';
import { Money } from './Money';

describe('Money', () => {
  afterEach(() => act(() => setLocale('en')));

  it('formats CDF without decimals', async () => {
    await render(<Money amount={135000} currency="CDF" />);

    expect(screen.getByTestId('money-main')).toHaveTextContent('CDF 135,000');
  });

  it('formats minor units with two decimals and the currency first', async () => {
    await render(<Money amount={4850} currency="USD" />);

    expect(screen.getByTestId('money-main')).toHaveTextContent('USD 48.50');
  });

  it('follows the UI language unless given a locale', async () => {
    await act(() => setLocale('fr'));
    await render(
      <>
        <Money amount={4850} currency="USD" />
        <Money amount={4850} currency="USD" locale="en" />
      </>,
    );

    const [french, english] = screen.getAllByTestId('money-main');
    expect(french).toHaveTextContent('USD 48,50');
    expect(english).toHaveTextContent('USD 48.50');
  });

  it('shows a second currency underneath', async () => {
    await render(<Money amount={4850} currency="USD" secondary={{ amount: 135000, currency: 'CDF' }} />);

    expect(screen.getByText('≈ CDF 135,000')).toBeOnTheScreen();
  });

  it('uses the large amount size for totals', async () => {
    await render(<Money amount={4850} currency="USD" size="lg" />);

    expect(screen.getByTestId('money-main')).toHaveProp('className', expect.stringContaining('text-amount-lg'));
  });
});
