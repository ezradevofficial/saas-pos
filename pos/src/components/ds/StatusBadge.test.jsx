import { render, screen } from '@testing-library/react-native';
import { StatusBadge } from './StatusBadge';

describe('StatusBadge', () => {
  it('is a dot plus a word, never colour alone', async () => {
    await render(<StatusBadge tone="danger">Void</StatusBadge>);

    expect(screen.getByText('Void')).toHaveProp('className', expect.stringContaining('text-danger'));
    expect(screen.getByTestId('status-dot')).toHaveProp('className', expect.stringContaining('bg-danger'));
    expect(screen.getByTestId('status-dot')).toHaveProp('className', expect.not.stringContaining('px-'));
  });

  it('keeps ink text for warning and a neutral dot by default', async () => {
    await render(
      <>
        <StatusBadge tone="warning">Pending</StatusBadge>
        <StatusBadge>Draft</StatusBadge>
      </>,
    );

    expect(screen.getByText('Pending')).toHaveProp('className', expect.stringContaining('text-ink'));
    const [warning, neutral] = screen.getAllByTestId('status-dot');
    expect(warning).toHaveProp('className', expect.stringContaining('bg-warning'));
    expect(neutral).toHaveProp('className', expect.stringContaining('bg-neutral-dot'));
  });
});
