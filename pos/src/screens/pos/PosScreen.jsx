import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ActivityIndicator, Platform, Text, useWindowDimensions, View } from 'react-native';
import { useSession } from '../../auth/session';
import { Alert } from '../../components/ds/Alert';
import { Button } from '../../components/ds/Button';
import { Dialog } from '../../components/ds/Dialog';
import { DISPLAY_QUERY } from '../../pos/customerDisplay';
import { usePosActions, usePosData } from '../../pos/PosProvider';
import { APPEARANCES, useTillTheme } from '../../theme/TillTheme';
import { CustomerDisplayScreen } from '../CustomerDisplayScreen';
import { useCustomerDisplayFeed } from './useCustomerDisplayFeed';
import { useServices } from '../../services/services';
import { AUTH } from '../../sync/engine';
import { useSyncStatus } from '../../sync/useSyncStatus';
import { CashDialog, CustomerDialog, HeldDialog, LineDialog, MenuDialog } from './Dialogs';
import { OverrideDialog } from './OverrideDialog';
import { PaymentScreen } from './PaymentScreen';
import { ReceiptScreen } from './ReceiptScreen';
import { SalesScreen } from './SalesScreen';
import { PhoneCartScreen, SellScreen } from './SellScreen';
import { CloseShiftScreen, OpenShiftScreen } from './ShiftScreens';

/** Tablets and wider get Main's two-column layout; phones get PosPhone's. */
export const TABLET_MIN_WIDTH = 768;

/** Problems that stop the till from uploading, said in words (from the sync engine). */
function SyncProblems() {
  const { t } = useTranslation();
  const { scheduler } = useServices();
  const { pinChange } = useSession();
  const sync = useSyncStatus();
  const alerts = [];
  if (sync.auth === AUTH.LOST)
    alerts.push(
      <Alert key="lost" tone="danger" title={t('home.accessLostTitle')} action={<Button onPress={scheduler.syncNow}>{t('common.retry')}</Button>}>
        {t('home.accessLost')}
      </Alert>,
    );
  if (sync.secretMissing) alerts.push(<Alert key="secret" tone="warning">{t('home.secretMissing')}</Alert>);
  if (sync.moduleInactive) alerts.push(<Alert key="module" tone="warning">{t('home.moduleInactive')}</Alert>);
  if (pinChange) alerts.push(<Alert key="pin" tone="warning" title={t('home.pinChangeTitle')}>{t('home.pinChangeOffline')}</Alert>);
  if (sync.failed) alerts.push(<Alert key="failed" tone="warning">{t('home.failed', { count: sync.failed })}</Alert>);
  if (!alerts.length) return null;
  return <View className="gap-2 px-4 pt-3 md:px-6">{alerts}</View>;
}

/** The device's appearance: as the device, light or dark (kept on this till only). */
function AppearanceDialog({ onClose }) {
  const { t } = useTranslation();
  const { appearance, setAppearance } = useTillTheme();
  return (
    <Dialog open title={t('pos.appearance.title')} onClose={onClose}>
      <View className="gap-2">
        <Text className="font-sans text-body text-ink-muted">{t('pos.appearance.help')}</Text>
        {APPEARANCES.map((value) => (
          <Button key={value} variant={appearance === value ? 'primary' : 'secondary'} accessibilityState={{ selected: appearance === value }} onPress={() => setAppearance(value).then(onClose)}>
            {t(`pos.appearance.${value}`)}
          </Button>
        ))}
      </View>
    </Dialog>
  );
}

/** LAY-05: a second browser tab for the customer (web preview), else the in-app display. */
function openCustomerDisplay(setRoute) {
  const location = globalThis.window?.location;
  if (Platform.OS === 'web' && location && typeof globalThis.window.open === 'function') {
    globalThis.window.open(`${location.origin}${location.pathname}?${DISPLAY_QUERY}`, '_blank', 'noopener');
  } else {
    setRoute('display');
  }
}

/**
 * POS-01..POS-06: the till's screens after sign-in. A small state router:
 * no shift → open one; then sell, pay, receipt; sales and returns; close
 * shift. Dialogs (line, customer, held sales, cash, menu, manager
 * override) sit on top.
 */
export function PosScreen() {
  const { width } = useWindowDimensions();
  const tablet = width >= TABLET_MIN_WIDTH;
  const { catalogue, shift } = usePosData();
  const actions = usePosActions();
  const [route, setRoute] = useState('sell');
  // LAY-05: the customer display follows the current sale.
  useCustomerDisplayFeed();
  const [dialog, setDialog] = useState(null);
  const [receipt, setReceipt] = useState(null);

  // A new shift (or none) starts on the selling screen.
  useEffect(() => setRoute('sell'), [shift?.id]);

  const close = useCallback(() => setDialog(null), []);
  const openMenu = useCallback(() => setDialog({ kind: 'menu' }), []);
  const navigate = useCallback((next) => {
    if (next === 'cash' || next === 'held' || next === 'appearance') setDialog({ kind: next });
    else if (next === 'display') openCustomerDisplay(setRoute);
    else setRoute(next);
  }, []);
  // LAY-05: what the layout's quick action buttons do.
  const onAction = useCallback(
    (action) => {
      if (action === 'hold') actions.hold();
      else if (action === 'customer') setDialog({ kind: 'customer' });
      else if (action === 'cash_in') setDialog({ kind: 'cash', initialKind: 'pay_in' });
      else if (action === 'cash_out') setDialog({ kind: 'cash', initialKind: 'pay_out' });
      else if (action === 'sales') setRoute('sales');
    },
    [actions],
  );
  const openLine = useCallback((lineId) => setDialog({ kind: 'line', lineId }), []);
  const openCustomer = useCallback(() => setDialog({ kind: 'customer' }), []);
  const openHeld = useCallback(() => setDialog({ kind: 'held' }), []);
  const toPay = useCallback(() => setRoute('pay'), []);
  const toSell = useCallback(() => setRoute('sell'), []);

  if (!catalogue || shift === undefined) {
    return (
      <View className="flex-1 items-center justify-center">
        <ActivityIndicator className="text-ink-muted" />
      </View>
    );
  }

  let screen;
  if (!shift) screen = <OpenShiftScreen onMenu={openMenu} />;
  else if (route === 'pay') screen = <PaymentScreen tablet={tablet} onBack={toSell} onDone={(sale) => { setReceipt(sale); setRoute('receipt'); }} />;
  else if (route === 'receipt' && receipt) screen = <ReceiptScreen sale={receipt} onNewSale={toSell} />;
  else if (route === 'sales') screen = <SalesScreen onBack={toSell} />;
  else if (route === 'close') screen = <CloseShiftScreen onBack={toSell} />;
  else if (route === 'display') screen = <CustomerDisplayScreen onClose={toSell} />;
  else if (route === 'cart' && !tablet) screen = <PhoneCartScreen onBack={toSell} onPay={toPay} onCustomer={openCustomer} onLine={openLine} />;
  else
    screen = (
      <SellScreen
        tablet={tablet}
        onPay={toPay}
        onOpenCart={() => setRoute('cart')}
        onCustomer={openCustomer}
        onLine={openLine}
        onHeld={openHeld}
        onCloseShift={() => setRoute('close')}
        onMenu={openMenu}
        onAction={onAction}
      />
    );

  return (
    <View className="flex-1">
      <SyncProblems />
      {screen}
      {dialog?.kind === 'menu' ? <MenuDialog onClose={close} onNavigate={navigate} compact={!tablet} /> : null}
      {dialog?.kind === 'line' ? <LineDialog lineId={dialog.lineId} onClose={close} /> : null}
      {dialog?.kind === 'customer' ? <CustomerDialog onClose={close} /> : null}
      {dialog?.kind === 'held' ? <HeldDialog onClose={close} /> : null}
      {dialog?.kind === 'cash' ? <CashDialog onClose={close} initialKind={dialog.initialKind} /> : null}
      {dialog?.kind === 'appearance' ? <AppearanceDialog onClose={close} /> : null}
      <OverrideDialog />
    </View>
  );
}
