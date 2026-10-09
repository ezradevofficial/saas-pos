import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Pressable, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Alert } from '../../components/ds/Alert';
import { Button } from '../../components/ds/Button';
import { Money } from '../../components/ds/Money';
import { TextField } from '../../components/ds/TextField';
import { cn } from '../../lib/cn';
import { FOCUS_RING } from '../../lib/focus';
import { uuidv7 } from '../../lib/random';
import { useLocale } from '../../lib/useLocale';
import { parseAmount } from '../../pos/input';
import { inSaleCurrency, paymentOptions, quickAmounts } from '../../pos/payments/options';
import { isMpesaCode, STK_PUSH_ENABLED } from '../../pos/payments/stkPush';
import { usePosActions, usePosCart, usePosData } from '../../pos/PosProvider';
import { amountDueIn, calculateTender } from '../../pos/tender';
import { rateText, useMoneyText } from './format';
import { OfflineNote, useDualTotal } from './SellScreen';

const isMobile = (method) => method.type === 'mobile_money';

/**
 * POS-03, CUR-05, CUR-06, CUR-09 (PosPayment): split and multi-currency
 * payment. Tenders are added per method and currency; what is still due
 * and the change are computed live with the server's tender rules at the
 * cached shop rate; change is given in the chosen drawer currency.
 * Completing draws the receipt number, stores the sale and queues it.
 */
export function PaymentScreen({ tablet, onBack, onDone }) {
  const { t } = useTranslation();
  const locale = useLocale();
  const money = useMoneyText();
  const { catalogue, nextReceipt } = usePosData();
  const { computed } = usePosCart();
  const actions = usePosActions();
  const currency = catalogue.saleCurrency;
  const total = computed?.totals.total_minor ?? '0';
  const dual = useDualTotal(total);
  const options = useMemo(() => paymentOptions(catalogue), [catalogue]);
  const [tenders, setTenders] = useState([]);
  const [activeKey, setActiveKey] = useState(options[0]?.key ?? null);
  const [amountText, setAmountText] = useState('');
  const [reference, setReference] = useState('');
  const [changeCurrency, setChangeCurrency] = useState(currency);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const active = options.find((option) => option.key === activeKey) ?? null;
  const decimalsOf = catalogue.money.decimals;

  const result = useMemo(() => {
    try {
      return calculateTender({ due: { minor: total, currency }, tenders, changeCurrency, money: catalogue.money });
    } catch {
      return null;
    }
  }, [catalogue, changeCurrency, currency, tenders, total]);

  const askedIn = (target) => {
    if (!result) return '0';
    try {
      return amountDueIn({ remaining: result.remaining.minor, from: currency, currency: target, money: catalogue.money });
    } catch {
      return null;
    }
  };

  const changeCurrencies = useMemo(() => catalogue.cashCurrencies.filter((code) => code === currency || catalogue.money.rateFor(code, currency, Date.now())), [catalogue, currency]);
  const dualRate = catalogue.dualCurrency ? catalogue.money.rateFor(currency, catalogue.dualCurrency, Date.now()) : null;
  const remainingActive = active ? askedIn(active.currency) : null;
  const settled = Boolean(result?.settled) && BigInt(total) > 0n;

  function add(amountMinor) {
    setError(null);
    if (!active || !amountMinor || BigInt(amountMinor) <= 0n) {
      setError(t('pos.pay.errors.amount'));
      return;
    }
    if (isMobile(active.method) && !isMpesaCode(reference)) {
      setError(t('pos.pay.errors.mobileCode'));
      return;
    }
    setTenders((list) => [
      ...list,
      {
        id: uuidv7(),
        method: active.method,
        currency: active.currency,
        amountMinor: String(amountMinor),
        reference: reference.trim().toUpperCase() || undefined,
        status: isMobile(active.method) || active.method.type === 'card' ? 'confirmed' : undefined,
      },
    ]);
    setAmountText('');
    setReference('');
  }

  async function complete() {
    if (!settled || busy) return;
    setBusy(true);
    setError(null);
    try {
      const sale = await actions.complete(tenders, result.change.minor !== '0' ? changeCurrency : currency);
      onDone(sale);
    } catch (problem) {
      setError(t(`pos.pay.errors.${problem?.code ?? 'failed'}`, { defaultValue: t('pos.pay.errors.failed') }));
    } finally {
      setBusy(false);
    }
  }

  const header = (
    <View className="flex-row items-center gap-4 border-b border-border bg-surface-200 px-4 py-2 md:px-6">
      <Button variant="ghost" onPress={onBack}>
        {t('pos.pay.back')}
      </Button>
      <View className="min-w-0 flex-1 items-center">
        <Text accessibilityRole="header" className="font-sans text-h3 font-semibold text-ink">
          {t('pos.pay.title')}
        </Text>
        <Text numberOfLines={1} className="font-sans text-caption text-ink-muted">
          {[catalogue.settings?.location?.name, catalogue.settings?.device?.name, nextReceipt ? t('pos.pay.receipt', { number: nextReceipt }) : null].filter(Boolean).join(' · ')}
        </Text>
      </View>
      {dualRate ? (
        <View className="items-end">
          <Text className="font-sans text-caption text-ink-muted">{t('pos.pay.shopRate')}</Text>
          <Text className="font-sans text-body font-medium tabular-nums text-ink">{rateText(dualRate, currency, catalogue.dualCurrency, locale)}</Text>
        </View>
      ) : null}
    </View>
  );

  const summary = (
    <View className="gap-4">
      <View className="gap-1 rounded-lg border border-border bg-surface-200 p-6">
        <Text className="font-sans text-body font-medium text-ink-muted">{t('pos.pay.totalDue')}</Text>
        <Money amount={total} currency={currency} size="lg" secondary={dual} />
      </View>
      <View className="rounded-lg border border-border bg-surface-200">
        <Text className="border-b border-border p-4 font-sans text-body-lg font-semibold text-ink">{t('pos.pay.received')}</Text>
        {tenders.length ? (
          tenders.map((tender, index) => (
            <View key={tender.id} className="flex-row items-center gap-3 border-b border-border px-4 py-3">
              <View className="min-w-0 flex-1">
                <Text className="font-sans text-body font-medium text-ink">{tender.method.name}</Text>
                <Text className="font-sans text-caption text-ink-muted">{tender.reference ? t('pos.pay.reference', { reference: tender.reference }) : t(`pos.pay.detail.${tender.method.type}`, { defaultValue: '' })}</Text>
              </View>
              <View className="items-end">
                <Text className="font-sans text-body font-medium tabular-nums text-ink">{money(tender.amountMinor, tender.currency)}</Text>
                {tender.currency !== currency && result?.lines[index] ? (
                  <Text className="font-sans text-caption tabular-nums text-ink-muted">{`≈ ${money(result.lines[index].inDue.minor, currency)}`}</Text>
                ) : null}
              </View>
              <Button variant="ghost" className="px-3" accessibilityLabel={t('pos.pay.remove', { method: tender.method.name })} onPress={() => setTenders((list) => list.filter((entry) => entry.id !== tender.id))}>
                {t('pos.pay.removeShort')}
              </Button>
            </View>
          ))
        ) : (
          <Text className="px-4 py-6 text-center font-sans text-body text-ink-muted">{t('pos.pay.none')}</Text>
        )}
        <View className="gap-2 border-t border-border p-4">
          <View className="flex-row justify-between gap-4">
            <Text className="font-sans text-body-lg text-ink-muted">{t('pos.pay.remaining')}</Text>
            <Text className="font-sans text-body-lg font-medium tabular-nums text-ink">
              {[money(result?.remaining.minor ?? total, currency), catalogue.dualCurrency && askedIn(catalogue.dualCurrency) ? money(askedIn(catalogue.dualCurrency), catalogue.dualCurrency) : null].filter(Boolean).join('  ·  ')}
            </Text>
          </View>
          {result && result.change.minor !== '0' ? (
            <View className="gap-3 rounded-md bg-success-tint p-3">
              <View className="flex-row items-center justify-between gap-3">
                <Text className="font-sans text-body-lg font-medium text-ink">{t('pos.pay.change')}</Text>
                <View className="items-end">
                  <Text className="font-sans text-amount tabular-nums text-ink">{money(result.change.minor, result.change.currency)}</Text>
                  {result.change.currency !== currency ? (
                    <Text className="font-sans text-caption tabular-nums text-ink-muted">{`≈ ${money(inSaleCurrency(catalogue, result.change.minor, result.change.currency) ?? '0', currency)}`}</Text>
                  ) : null}
                </View>
              </View>
              {changeCurrencies.length > 1 ? (
                <View className="flex-row flex-wrap items-center gap-2">
                  <Text className="font-sans text-caption text-ink-muted">{t('pos.pay.changeIn')}</Text>
                  {changeCurrencies.map((code) => (
                    <Button key={code} variant={code === changeCurrency ? 'primary' : 'secondary'} onPress={() => setChangeCurrency(code)} accessibilityState={{ selected: code === changeCurrency }}>
                      {code}
                    </Button>
                  ))}
                </View>
              ) : null}
            </View>
          ) : null}
        </View>
      </View>
    </View>
  );

  const methods = (
    <View className="gap-4">
      <Text className="font-sans text-body-lg font-semibold text-ink">{t('pos.pay.method')}</Text>
      <View className="flex-row flex-wrap gap-3">
        {options.map((option) => {
          const selected = option.key === activeKey;
          return (
            <Pressable
              key={option.key}
              accessibilityRole="button"
              accessibilityState={{ selected }}
              aria-pressed={selected}
              onPress={() => {
                setActiveKey(option.key);
                setError(null);
              }}
              className={cn(
                'min-h-12 min-w-0 grow basis-1/4 justify-center gap-1 rounded-md border px-4 py-3',
                selected ? 'border-primary bg-primary-tint' : 'border-border-strong bg-surface-200 hover:bg-surface-300 active:bg-surface-300',
                FOCUS_RING,
              )}
            >
              <Text className="font-sans text-body-lg font-medium text-ink">{option.method.name}</Text>
              <Text className="font-sans text-caption text-ink-muted">{t(`pos.pay.hint.${option.method.type}`, { currency: option.currency, defaultValue: option.currency })}</Text>
            </Pressable>
          );
        })}
      </View>
      {active ? (
        <View className="gap-4 rounded-lg border border-border bg-surface-200 p-5">
          <View className="flex-row flex-wrap items-baseline justify-between gap-2">
            <Text className="font-sans text-body-lg font-semibold text-ink">{t('pos.pay.amountIn', { currency: active.currency })}</Text>
            {remainingActive ? <Text className="font-sans text-caption text-ink-muted">{t('pos.pay.left', { amount: money(remainingActive, active.currency) })}</Text> : null}
          </View>
          {active.method.type === 'cash' && remainingActive && remainingActive !== '0' ? (
            <View className="flex-row flex-wrap gap-3">
              {quickAmounts(remainingActive, catalogue.money.cashStep(active.currency)).map((amount, index) => (
                <Button key={amount} variant="secondary" className="grow" onPress={() => add(amount)}>
                  {index === 0 ? t('pos.pay.exact', { amount: money(amount, active.currency) }) : money(amount, active.currency)}
                </Button>
              ))}
            </View>
          ) : null}
          <View className="flex-row flex-wrap items-end gap-3">
            <TextField
              className="min-w-0 grow basis-1/3"
              label={t('pos.pay.amount')}
              prefix={active.currency}
              keyboardType="decimal-pad"
              value={amountText}
              onChangeText={setAmountText}
              placeholder={remainingActive ? money(remainingActive, active.currency).replace(`${active.currency} `, '') : undefined}
            />
            {isMobile(active.method) || active.method.type === 'card' ? (
              <TextField
                className="min-w-0 grow basis-1/3"
                label={isMobile(active.method) ? t('pos.pay.mobileCode') : t('pos.pay.cardReference')}
                autoCapitalize="characters"
                autoCorrect={false}
                value={reference}
                onChangeText={setReference}
              />
            ) : null}
            <Button variant="secondary" onPress={() => add(amountText ? parseAmount(amountText, decimalsOf(active.currency)) : remainingActive)}>
              {amountText ? t('pos.pay.add') : t('pos.pay.addRemaining')}
            </Button>
          </View>
          {isMobile(active.method) ? <Text className="font-sans text-caption text-ink-muted">{t('pos.pay.mobileManual')}</Text> : null}
          {isMobile(active.method) && STK_PUSH_ENABLED ? (
            // Payment intents (STK push) are switched off until the API ships (src/pos/payments/stkPush.js).
            <Alert tone="info" title={t('pos.pay.stk.title')}>
              {t('pos.pay.stk.body')}
            </Alert>
          ) : null}
        </View>
      ) : (
        <Alert tone="warning">{t('pos.pay.noMethods')}</Alert>
      )}
      {error ? <Alert tone="danger">{error}</Alert> : null}
      {!result && tenders.length ? <Alert tone="danger">{t('pos.pay.errors.rate_unavailable')}</Alert> : null}
      <View className="flex-row gap-3">
        <Button variant="secondary" className="shrink basis-1/3" onPress={async () => { await actions.hold(); onBack(); }}>
          {t('pos.pay.hold')}
        </Button>
        <Button variant="pay" className="shrink basis-2/3" loading={busy} disabled={!settled} onPress={complete}>
          {settled ? t('pos.pay.complete') : t('pos.pay.remainingButton', { amount: money(result?.remaining.minor ?? total, currency) })}
        </Button>
      </View>
      <OfflineNote />
    </View>
  );

  return (
    <SafeAreaView className="flex-1">
      {header}
      {tablet ? (
        <View className="min-h-0 flex-1 flex-row gap-6 p-6">
          <ScrollView className="w-2/5 flex-grow-0">{summary}</ScrollView>
          <ScrollView className="flex-1" keyboardShouldPersistTaps="handled">
            {methods}
          </ScrollView>
        </View>
      ) : (
        <ScrollView className="flex-1" contentContainerClassName="gap-4 p-4" keyboardShouldPersistTaps="handled">
          {summary}
          {methods}
        </ScrollView>
      )}
    </SafeAreaView>
  );
}
