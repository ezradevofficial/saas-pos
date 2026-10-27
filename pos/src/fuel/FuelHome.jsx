import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ActivityIndicator, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useSession } from '../auth/session';
import { Alert } from '../components/ds/Alert';
import { Button } from '../components/ds/Button';
import { useLocale } from '../lib/useLocale';
import { add, toFixed } from '../pos/exact';
import { usePosData } from '../pos/PosProvider';
import { PosHeader } from '../screens/pos/SellScreen';
import { formatTime, useMoneyText } from '../screens/pos/format';
import { CloseFuelShift } from './CloseFuelShift';
import { DipScreen } from './DipScreen';
import { OpenFuelShift } from './OpenFuelShift';
import { NozzleSale } from './NozzleSale';
import { ResetScreen } from './ResetScreen';
import { can, FUEL } from './permissions';
import { useFuelState } from './useFuelState';
import { Figure, Section } from './ui';
import { litresText } from './text';

/** The attendant's nozzles with their running litres and value from the sales rung on this till (FUEL-31). */
function ShiftProgress({ shift, running, userId, money, currency }) {
  const { t } = useTranslation();
  const mine = shift.nozzles.filter((row) => row.attendant_id === userId);
  const rows = mine.length ? mine : shift.nozzles;
  const sold = (row) => running?.nozzles?.[row.nozzle_id];
  const litres = rows.reduce((sum, row) => toFixed(add(sum, sold(row)?.litres ?? '0'), 3), '0');
  const value = rows.reduce((sum, row) => sum + (sold(row)?.valueMinor ?? 0n), 0n);
  return (
    <Section title={mine.length ? t('fuel.shift.myNozzles') : t('fuel.shift.allNozzles')}>
      <View className="rounded-md border border-border">
        {rows.map((row) => {
          const figures = sold(row);
          return (
            <View key={row.nozzle_id} className="min-h-12 flex-row items-center justify-between gap-4 border-b border-border px-4 py-3">
              <View className="min-w-0 flex-1">
                <Text className="font-sans text-body-lg text-ink">{row.label}</Text>
                <Text className="font-sans text-caption text-ink-muted">{t('fuel.shift.openedAt', { reading: row.opening_reading })}</Text>
              </View>
              <View className="items-end">
                <Text className="font-sans text-body-lg tabular-nums text-ink">{litresText(figures?.litres ?? '0')}</Text>
                <Text className="font-sans text-caption tabular-nums text-ink-muted">{money(String(figures?.valueMinor ?? 0n), currency)}</Text>
              </View>
            </View>
          );
        })}
      </View>
      <Figure strong label={t('fuel.shift.total')} value={`${litresText(litres)} · ${money(String(value), currency)}`} />
    </Section>
  );
}

/**
 * FUEL-09, FUEL-10, FUEL-19, FUEL-31: the forecourt home of a fuel station till (POS mode `fuel_station`). A small
 * state router: the shift in progress (or the open-shift form), the nozzle quick sale, dips, meter reset
 * requests and the close. Shop (non-fuel) items go through the normal sell screen (`onShop`); the payment
 * screens are the POS's (`onPay`).
 */
export function FuelHome({ tablet, onShop, onPay, onHeld, onCloseTill, onMenu }) {
  const { t } = useTranslation();
  const locale = useLocale();
  const { user } = useSession();
  const { catalogue } = usePosData();
  const money = useMoneyText();
  const state = useFuelState();
  const [view, setView] = useState('home');
  // The close screen shows its summary after the shift is gone from the device's open shift: keep the last one.
  const lastShift = useRef(null);
  if (state.shift) lastShift.current = state.shift;
  const home = () => {
    setView('home');
    state.reload();
  };
  const location = catalogue?.settings?.location?.name;
  const currency = state.fuel?.station?.currency ?? catalogue?.saleCurrency;

  if (!state.loaded) {
    return (
      <View className="flex-1 items-center justify-center">
        <ActivityIndicator className="text-ink-muted" />
      </View>
    );
  }
  if (!state.fuel) {
    return (
      <SafeAreaView className="flex-1">
        <PosHeader compact={!tablet} onHeld={onHeld} onCloseShift={onCloseTill} onMenu={onMenu} />
        <View className="gap-4 p-4 md:p-6">
          <Alert tone="warning" title={t('fuel.notStation.title')}>
            {t('fuel.notStation.body')}
          </Alert>
          <Button variant="secondary" onPress={onShop}>
            {t('fuel.home.shop')}
          </Button>
        </View>
      </SafeAreaView>
    );
  }

  const common = { fuel: state.fuel, shift: state.shift, services: state.services, location, onBack: home };
  if (view === 'open') return <OpenFuelShift {...common} onDone={home} />;
  if (view === 'close') return <CloseFuelShift {...common} shift={state.shift ?? lastShift.current} running={state.running} onDone={home} />;
  if (view === 'sale') return <NozzleSale {...common} onPay={onPay} />;
  if (view === 'dip') return <DipScreen {...common} onDone={home} />;
  if (view === 'reset') return <ResetScreen {...common} onDone={home} />;

  const { shift } = state;
  const canOpen = can(user, FUEL.shiftOpen);
  const canClose = can(user, FUEL.shiftClose);
  return (
    <SafeAreaView className="flex-1">
      <PosHeader compact={!tablet} onHeld={onHeld} onCloseShift={onCloseTill} onMenu={onMenu} />
      <ScrollView contentContainerClassName="items-center p-4 md:p-6" keyboardShouldPersistTaps="handled">
        <View className="w-full gap-5 rounded-lg border border-border bg-surface-200 p-5 md:w-2/3 xl:w-1/2">
          <View className="gap-1">
            <Text accessibilityRole="header" className="font-sans text-h2 font-semibold text-ink">
              {t('fuel.home.title')}
            </Text>
            <Text className="font-sans text-body-lg text-ink-muted">
              {shift ? t('fuel.home.shiftOpen', { time: formatTime(shift.opened_at, catalogue?.timeZone, locale), name: shift.opened_by_name ?? '' }) : t('fuel.home.noShift')}
            </Text>
          </View>

          {state.problems.map((problem) => (
            <Alert key={`${problem.kind}:${problem.recordId}`} tone="danger" title={t(`fuel.problems.${problem.kind === 'fuel.shifts' ? 'shift' : 'reset'}`)}>
              {problem.message ?? t('fuel.errors.failed')}
            </Alert>
          ))}

          {shift ? (
            <>
              <ShiftProgress shift={shift} running={state.running} userId={user?.id} money={money} currency={currency} />
              <Button variant="primary" block onPress={() => setView('sale')}>
                {t('fuel.home.sell')}
              </Button>
              <View className="flex-row flex-wrap gap-3">
                <Button variant="secondary" onPress={() => setView('dip')} disabled={!can(user, FUEL.dip)}>
                  {t('fuel.home.dip')}
                </Button>
                {can(user, FUEL.requestReset) ? (
                  <Button variant="secondary" onPress={() => setView('reset')}>
                    {t('fuel.home.reset')}
                  </Button>
                ) : null}
                <Button variant="secondary" onPress={onShop}>
                  {t('fuel.home.shop')}
                </Button>
                <Button variant="secondary" onPress={() => setView('close')} disabled={!canClose}>
                  {t('fuel.home.close')}
                </Button>
              </View>
              {!canClose ? <Text className="font-sans text-caption text-ink-muted">{t('fuel.errors.not_permitted')}</Text> : null}
            </>
          ) : (
            <>
              {!canOpen ? <Alert tone="warning">{t('fuel.errors.not_permitted')}</Alert> : null}
              <Button variant="primary" block onPress={() => setView('open')} disabled={!canOpen}>
                {t('fuel.home.open')}
              </Button>
              <View className="flex-row flex-wrap gap-3">
                {can(user, FUEL.requestReset) ? (
                  <Button variant="secondary" onPress={() => setView('reset')}>
                    {t('fuel.home.reset')}
                  </Button>
                ) : null}
                <Button variant="secondary" onPress={onShop}>
                  {t('fuel.home.shop')}
                </Button>
              </View>
            </>
          )}
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}
