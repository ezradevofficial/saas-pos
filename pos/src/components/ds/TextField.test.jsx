import { fireEvent, render, screen } from '@testing-library/react-native';
import { Alert } from './Alert';
import { TextField } from './TextField';

describe('TextField', () => {
  it('labels the input and reports changes like the web field', async () => {
    const onChange = jest.fn();
    const onChangeText = jest.fn();
    await render(<TextField label="Device name" help="Shown to admins" onChange={onChange} onChangeText={onChangeText} />);

    fireEvent.changeText(screen.getByLabelText('Device name'), 'Till 2');

    expect(onChange).toHaveBeenCalledWith({ target: { value: 'Till 2' } });
    expect(onChangeText).toHaveBeenCalledWith('Till 2');
    expect(screen.getByText('Shown to admins')).toBeOnTheScreen();
  });

  it('shows the error instead of the help, with the danger outline', async () => {
    await render(<TextField label="Code" help="8 characters" error="This code is not valid." />);

    expect(screen.getByText('This code is not valid.')).toHaveProp('role', 'alert');
    expect(screen.queryByText('8 characters')).toBeNull();
    expect(screen.getByTestId('text-field-box')).toHaveProp('className', expect.stringContaining('border-danger'));
  });
});

describe('Alert', () => {
  it('uses the tint for its tone and a status role', async () => {
    await render(<Alert tone="warning" title="Offline">Selling continues.</Alert>);

    expect(screen.getByTestId('alert')).toHaveProp('className', expect.stringContaining('bg-warning-tint'));
    expect(screen.getByTestId('alert')).toHaveProp('role', 'status');
    expect(screen.getByText('Selling continues.')).toBeOnTheScreen();
  });

  it('announces danger alerts', async () => {
    await render(<Alert tone="danger" title="Access lost" />);

    expect(screen.getByTestId('alert')).toHaveProp('role', 'alert');
  });
});
