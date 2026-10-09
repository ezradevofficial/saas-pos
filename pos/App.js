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
import { ThemeProvider } from './src/theme/ThemeProvider';

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

export default function App({ services: injected }) {
  const fontsReady = useAppFonts();
  const services = useMemo(() => injected ?? createServices(), [injected]);

  if (!fontsReady) return null;

  return (
    <SafeAreaProvider>
      <ThemeProvider theme="light">
        <ServicesContext.Provider value={services}>
          <SessionProvider>
            <Root />
          </SessionProvider>
        </ServicesContext.Provider>
        <StatusBar style="dark" />
      </ThemeProvider>
    </SafeAreaProvider>
  );
}
