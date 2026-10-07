import { render, screen } from '@testing-library/react-native';
import App from './App';

describe('App', () => {
  it('shows the app name from EXPO_PUBLIC_APP_NAME', async () => {
    await render(<App />);

    expect(screen.getByRole('header')).toHaveTextContent('Test app');
  });
});
