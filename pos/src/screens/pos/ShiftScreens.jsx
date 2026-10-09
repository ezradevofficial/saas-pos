import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useSession } from '../../auth/session';
import { Alert } from '../../components/ds/Alert';
import { Button } from '../../components/ds/Button';
import { TextField } from '../../components/ds/TextField';
import { parseAmount } from '../../pos/input';
import { usePosActions, usePosData } from '../../pos/PosProvider';
import { expectedCash } from '../../pos/selling';
import { useServices } from '../../services/services';
import { AppHeader } from '../AppHeader';
import { useMoneyText } from './format';

function useAmounts(currencies) {
  const [texts, setTexts] = useState({});
  const set = (currency, text) => setTexts((current) => ({ ...current, [currency]: text }));
  return [texts, set];
}

function read(texts, currencies, decimals) {
  const rows = [];
  for (const currency of currencies) {
    const text = texts[currency] ?? '';
    const minor = text.trim() === '' ? '0' : parseAmount(text, decimals(currency));
    if (minor === null) return { error: currency };
    rows.push({ currency, amountMinor: minor });
  }
  return { rows };
}

/** POS-04: open a shift with the opening float counted per drawer currency. */
export function OpenShiftScreen({ onMenu }) {
  const { t } = useTranslation();
  const { user, switchUser } = useSession();
  const { catalogue } = usePosData();
  const actions = usePosActions();
  const currencies = catalogue?.cashCurrencies ?? [];
  const [texts, set] = useAmounts(currencies);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  async function open() {
    const parsed = read(texts, currencies, catalogue.money.decimals);
    if (parsed.error) {
      setError(t('pos.shift.errors.amount', { currency: parsed.error }));
      return;
    }
    setBusy(true);
    try {
      await actions.openShift(parsed.rows);
    } catch {
      setError(t('pos.shift.errors.failed'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <SafeAreaView className="flex-1">
      <AppHeader location={catalogue?.settings?.location?.name} />
      <ScrollView contentContainerClassName="items-center p-4 md:p-6" keyboardShouldPersistTaps="handled">
        <View className="w-full gap-5 rounded-lg border border-border bg-surface-200 p-5 md:w-1/2 xl:w-1/3">
          <View className="gap-1">
            <Text accessibilityRole="header" className="font-sans text-h2 font-semibold text-ink">
              {t('pos.shift.openTitle')}
            </Text>
            <Text className="font-sans text-body-lg text-ink-muted">{t('pos.shift.openIntro', { name: user?.name ?? '' })}</Text>
          </View>
          {currencies.map((currency) => (
            <TextField key={currency} label={t('pos.shift.float', { currency })} prefix={currency} keyboardType="decimal-pad" value={texts[currency] ?? ''} onChangeText={(text) => set(currency, text)} placeholder="0" />
          ))}
          {error ? <Alert tone="danger">{error}</Alert> : null}
          <Button variant="pay" block loading={busy} disabled={!catalogue} onPress={open}>
            {t('pos.shift.open')}
          </Button>
          <View className="flex-row flex-wrap gap-3">
            <Button variant="ghost" onPress={switchUser}>
              {t('home.switchUser')}
            </Button>
            {onMenu ? (
              <Button variant="ghost" onPress={onMenu}>
                {t('pos.menu.title')}
              </Button>
            ) : null}
          </View>
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

/**
 * POS-04: close the shift. The cashier counts the cash per currency first
 * (blind count); expected cash and the variance are shown after closing,
 * from what this till recorded. The server recomputes both from what it
 * accepts.
 */
export function CloseShiftScreen({ onBack }) {
  const { t } = useTranslation();
  const money = useMoneyText();
  const { posStore } = useServices();
  const { catalogue, shift, held } = usePosData();
  const actions = usePosActions();
  const currencies = [...new Set([...(catalogue?.cashCurrencies ?? []), ...(shift?.opening_float ?? []).map((row) => row.currency)])];
  const [texts, set] = useAmounts(currencies);
  const [note, setNote] = useState('');
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const [summary, setSummary] = useState(null);

  useEffect(() => setError(null), [texts]);

  async function close() {
    const parsed = read(texts, currencies, catalogue.money.decimals);
    if (parsed.error) {
      setError(t('pos.shift.errors.amount', { currency: parsed.error }));
      return;
    }
    setBusy(true);
    try {
      const closed = await actions.closeShift(parsed.rows, note.trim() || undefined);
      const cashMethodIds = new Set(catalogue.paymentMethods.filter((method) => method.type === 'cash').map((method) => method.id));
      const expected = expectedCash({ shift, sales: await posStore.salesOfShift(shift.id), records: await posStore.recordsOfShift(shift.id), cashMethodIds });
      setSummary(
        currencies.map((currency) => {
          const counted = BigInt(parsed.rows.find((row) => row.currency === currency)?.amountMinor ?? '0');
          const want = expected.get(currency) ?? 0n;
          return { currency, expected: String(want), counted: String(counted), variance: String(counted - want) };
        }),
      );
      return closed;
    } catch {
      setError(t('pos.shift.errors.failed'));
      return null;
    } finally {
      setBusy(false);
    }
  }

  return (
    <SafeAreaView className="flex-1">
      <AppHeader location={catalogue?.settings?.location?.name} />
      <ScrollView contentContainerClassName="items-center p-4 md:p-6" keyboardShouldPersistTaps="handled">
        <View className="w-full gap-5 rounded-lg border border-border bg-surface-200 p-5 md:w-1/2 xl:w-1/3">
          <Text accessibilityRole="header" className="font-sans text-h2 font-semibold text-ink">
            {summary ? t('pos.shift.closedTitle') : t('pos.shift.closeTitle')}
          </Text>
          {summary ? (
            <>
              {summary.map((row) => (
                <View key={row.currency} className="gap-1 rounded-md border border-border p-4">
                  <Text className="font-sans text-body-lg font-medium text-ink">{row.currency}</Text>
                  {[
                    [t('pos.shift.expected'), money(row.expected, row.currency)],
                    [t('pos.shift.counted'), money(row.counted, row.currency)],
                    [t('pos.shift.variance'), money(row.variance, row.currency)],
                  ].map(([label, value]) => (
                    <View key={label} className="flex-row justify-between gap-4">
                      <Text className="font-sans text-body text-ink-muted">{label}</Text>
                      <Text className={row.variance !== '0' && label === t('pos.shift.variance') ? 'font-sans text-body font-medium tabular-nums text-danger' : 'font-sans text-body tabular-nums text-ink'}>{value}</Text>
                    </View>
                  ))}
                </View>
              ))}
              <Text className="font-sans text-caption text-ink-muted">{t('pos.shift.serverRecounts')}</Text>
              <Button variant="primary" block onPress={actions.shiftDone}>
                {t('pos.shift.done')}
              </Button>
            </>
          ) : (
            <>
              <Text className="font-sans text-body-lg text-ink-muted">{t('pos.shift.closeIntro')}</Text>
              {held.length ? <Alert tone="warning">{t('pos.shift.heldWarning', { count: held.length })}</Alert> : null}
              {currencies.map((currency) => (
                <TextField key={currency} label={t('pos.shift.countedIn', { currency })} prefix={currency} keyboardType="decimal-pad" value={texts[currency] ?? ''} onChangeText={(text) => set(currency, text)} placeholder="0" />
              ))}
              <TextField label={t('pos.shift.note')} value={note} onChangeText={setNote} maxLength={1000} />
              {error ? <Alert tone="danger">{error}</Alert> : null}
              <Button variant="pay" block loading={busy} onPress={close}>
                {t('pos.shift.close')}
              </Button>
              <Button variant="ghost" block onPress={onBack}>
                {t('pos.shift.keepSelling')}
              </Button>
            </>
          )}
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}
