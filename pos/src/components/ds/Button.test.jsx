import { fireEvent, render, screen } from '@testing-library/react-native';
import { Button } from './Button';

// Jest cannot compute NativeWind styles, so these tests assert the token
// classes each variant passes (see babel.config.js, test env).
describe('Button', () => {
  it('renders the pay variant with the accent fill', async () => {
    await render(<Button variant="pay">Charge</Button>);

    const button = screen.getByRole('button', { name: 'Charge' });
    expect(button).toHaveProp('className', expect.stringContaining('bg-accent'));
    expect(screen.getByText('Charge')).toHaveProp('className', expect.stringContaining('text-on-accent'));
  });

  it('defaults to the 48px secondary button for touch screens', async () => {
    await render(<Button>Hold</Button>);

    const button = screen.getByRole('button', { name: 'Hold' });
    expect(button).toHaveProp('className', expect.stringContaining('h-12'));
    expect(button).toHaveProp('className', expect.stringContaining('bg-surface-200'));
  });

  it('calls onPress, or onClick like the web button', async () => {
    const onPress = jest.fn();
    const onClick = jest.fn();
    await render(
      <>
        <Button onPress={onPress}>One</Button>
        <Button onClick={onClick}>Two</Button>
      </>,
    );

    await fireEvent.press(screen.getByRole('button', { name: 'One' }));
    await fireEvent.press(screen.getByRole('button', { name: 'Two' }));
    expect(onPress).toHaveBeenCalledTimes(1);
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  it('is disabled and busy while loading, with a translated label', async () => {
    const onPress = jest.fn();
    await render(
      <Button loading onPress={onPress}>
        Save
      </Button>,
    );

    const button = screen.getByRole('button');
    expect(button).toBeDisabled();
    expect(button).toBeBusy();
    expect(screen.getByLabelText('Working')).toBeOnTheScreen();
    await fireEvent.press(button);
    expect(onPress).not.toHaveBeenCalled();
  });

  it('is always 48px on the POS, even when given the web md size', async () => {
    await render(<Button size="md">Small</Button>);

    const button = screen.getByRole('button', { name: 'Small' });
    expect(button).toHaveProp('className', expect.stringContaining('h-12'));
    expect(button).toHaveProp('className', expect.not.stringContaining('h-10'));
  });

  it('shows the focus ring on keyboard focus', async () => {
    await render(<Button>Focus</Button>);

    const className = screen.getByRole('button').props.className;
    expect(className).toContain('focus-visible:outline-2');
    expect(className).toContain('focus-visible:outline-offset-2');
    expect(className).toContain('focus-visible:outline-focus');
  });

  it('stretches when block', async () => {
    await render(<Button block>Wide</Button>);

    expect(screen.getByRole('button')).toHaveProp('className', expect.stringContaining('w-full'));
  });
});
