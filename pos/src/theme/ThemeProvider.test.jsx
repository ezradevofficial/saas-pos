import { render, screen } from '@testing-library/react-native';
import { Text } from 'react-native';
import { themes } from '@app/tokens';
import { ThemeProvider } from './ThemeProvider';
import { PREVIEW_THEMES, themeVariables } from './themes';
import { useTheme } from './useTheme';

// NativeWind's vars() returns an opaque style object on native; the identity
// version lets the test read which variables reach the root view.
jest.mock('nativewind', () => ({ vars: (variables) => ({ ...variables }) }));

function ThemeName() {
  const { theme } = useTheme();
  return <Text>{theme}</Text>;
}

describe('ThemeProvider', () => {
  it('passes the dark --surface-100 value in its style vars', async () => {
    await render(
      <ThemeProvider theme="dark">
        <ThemeName />
      </ThemeProvider>,
    );

    const root = screen.getByTestId('theme-root');
    expect(root).toHaveStyle({ '--surface-100': themes.dark['--surface-100'] });
    expect(themes.dark['--surface-100']).not.toBe(themes.light['--surface-100']);
    expect(screen.getByText('dark')).toBeOnTheScreen();
  });

  it('falls back to light for an unknown theme', async () => {
    await render(<ThemeProvider theme="neon" />);

    expect(screen.getByTestId('theme-root')).toHaveStyle({ '--surface-100': themes.light['--surface-100'] });
  });

  it('applies overridable tokens and ignores the rest', async () => {
    await render(
      <ThemeProvider theme="light" overrides={{ '--primary': '#7c2d5b', accent: '#1f2a44', '--success': '#ff0000', '--space-4': '99px' }} />,
    );

    const root = screen.getByTestId('theme-root');
    expect(root).toHaveStyle({ '--primary': '#7c2d5b', '--accent': '#1f2a44' });
    expect(root).toHaveStyle({ '--success': themes.light['--success'], '--space-4': themes.light['--space-4'] });
  });
});

describe('themeVariables', () => {
  it('uses the first family of the font stack on native, where stacks are not supported', () => {
    expect(themeVariables('light', {}, 'ios')['--font-sans']).toBe('Geist');
    expect(themeVariables('light', {}, 'web')['--font-sans']).toBe(themes.light['--font-sans']);
  });

  it('builds the test tenant from light with a derived brand pair', () => {
    const tenant = PREVIEW_THEMES.find((option) => option.key === 'tenant');
    const variables = themeVariables(tenant.theme, tenant.overrides);

    expect(tenant.theme).toBe('light');
    expect(variables['--primary']).not.toBe(themes.light['--primary']);
    expect(variables['--on-primary']).toMatch(/^#[0-9a-f]{6}$/);
    expect(variables['--surface-100']).toBe(themes.light['--surface-100']);
  });

  it('offers the five preview themes', () => {
    expect(PREVIEW_THEMES.map((option) => option.key)).toEqual(['light', 'dark', 'executive', 'warm', 'tenant']);
  });
});
