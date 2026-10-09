import './global.css';
import { StatusBar } from 'expo-status-bar';
import { useEffect, useMemo, useState } from 'react';
import { ActivityIndicator, View } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { createServices, ServicesContext, useServices } from './src/services/services';
import { SessionProvider, useSession } from './src/auth/session';
import './src/i18n';
import { ChangePinScreen } from './src/screens/ChangePinScreen';
import { PosProvider } from './src/pos/PosProvider';
import { PosScreen } from './src/screens/pos/PosScreen';
import { PairingScreen } from './src/screens/PairingScreen';
import { StaffSignInScreen } from './src/screens/StaffSignInScreen';
import { NETWORK } from './src/sync/engine';
import { useSyncStatus } from './src/sync/useSyncStatus';
import { useAppFonts } from './src/theme/fonts';
import { DISPLAY_QUERY } from './src/pos/customerDisplay';
import { CustomerDisplayScreen } from './src/screens/CustomerDisplayScreen';
import { TillThemeProvider, useTillTheme } from './src/theme/TillTheme';

/** Where the till sits, from the synced settings row (header text). */
function useLocation(paired) {
  const { store } = useServices();
  const { lastPulledAt } = useSyncStatus();
  const [location, setLocation] = useState(null);
  useEffect(() => {
    if (!paired) return;
    store.settings().then((settings) => setLocation(settings?.location?.name ?? null));
  }, [store, paired, lastPulledAt]);
  return location;
}

/**
 * A small state router: not paired → Pairing; nobody signed in → Staff
 * sign-in; a PIN to change while online → Change PIN; else the till
 * (PosScreen: shift, selling, payment, receipts, returns).
 */
function Root() {
  const { credentials, store, scheduler } = useServices();
  const { user, pinChange } = useSession();
  const sync = useSyncStatus();
  const [paired, setPaired] = useState(null);
  const location = useLocation(paired);

  useEffect(() => {
    let active = true;
    Promise.all([credentials.token(), store.device()]).then(([token, device]) => {
      if (active) setPaired(Boolean(token && device));
    });
    return () => {
      active = false;
    };
  }, [credentials, store]);

  useEffect(() => {
    if (!paired) return undefined;
    scheduler.start();
    return () => scheduler.stop();
  }, [paired, scheduler]);

  if (paired === null) {
    return (
      <View className="flex-1 items-center justify-center">
        <ActivityIndicator className="text-ink-muted" />
      </View>
    );
  }
  if (!paired) return <PairingScreen onPaired={() => setPaired(true)} />;
  // The sale and the shift live in PosProvider, above sign-in: switching user keeps them (AUTH-07).
  let screen = <PosScreen />;
  if (!user) screen = <StaffSignInScreen location={location} />;
  else if (pinChange && sync.network !== NETWORK.OFFLINE) screen = <ChangePinScreen location={location} />;
  return <PosProvider>{screen}</PosProvider>;
}

function ThemedStatusBar() {
  const { mode } = useTillTheme();
  return <StatusBar style={mode === 'dark' ? 'light' : 'dark'} />;
}

/** LAY-05: the web preview opened as the customer display (?display=customer): a second tab that only listens. */
const IS_CUSTOMER_DISPLAY = typeof globalThis.window?.location?.search === 'string' && globalThis.window.location.search.includes(DISPLAY_QUERY);

function TillApp({ services: injected }) {
  const services = useMemo(() => injected ?? createServices(), [injected]);
  return (
    <ServicesContext.Provider value={services}>
      {/* BR-01, BR-08: the published theme, applied at runtime from the synced settings. */}
      <TillThemeProvider>
        <SessionProvider>
          <Root />
        </SessionProvider>
        <ThemedStatusBar />
      </TillThemeProvider>
    </ServicesContext.Provider>
  );
}

export default function App({ services, display = IS_CUSTOMER_DISPLAY }) {
  const fontsReady = useAppFonts();

  if (!fontsReady) return null;

  return <SafeAreaProvider>{display ? <CustomerDisplayScreen /> : <TillApp services={services} />}</SafeAreaProvider>;
}
