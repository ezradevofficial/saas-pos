import { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FlatList, Pressable, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Alert } from '../../components/ds/Alert';
import { Button } from '../../components/ds/Button';
import { StatusBadge } from '../../components/ds/StatusBadge';
import { TextField } from '../../components/ds/TextField';
import { cn } from '../../lib/cn';
import { FOCUS_RING } from '../../lib/focus';
import { useLocale } from '../../lib/useLocale';
import { convertExact } from '../../pos/currency';
import { exact, movePoint, toFixed } from '../../pos/exact';
import { refundTender } from '../../pos/payloads';
import { usePosActions, usePosData } from '../../pos/PosProvider';
import { useServices } from '../../services/services';
import { formatTime, useMoneyText } from './format';

/** RBAC-06: a refund's value in the company's base currency, major units (4 decimals), for the limit check. */
function baseMajor(catalogue, sale, totalMinor) {
  const { money } = catalogue;
  const base = catalogue.baseCurrency ?? sale.currency;
  let minor = exact(BigInt(totalMinor));
  if (sale.currency !== base) {
    const rate = money.rateFor(sale.currency, base, Date.parse(sale.sold_at));
    if (!rate) return null;
    minor = convertExact(minor, sale.currency, base, rate, money.decimals);
  }
  return toFixed(movePoint(minor, -money.decimals(base)), 4);
}

function SaleDetail({ sale: initial, onBack }) {
  const { t } = useTranslation();
  const money = useMoneyText();
  const { posStore } = useServices();
  const { catalogue, shift } = usePosData();
  const actions = usePosActions();
  const [sale, setSale] = useState(initial);
  const [records, setRecords] = useState([]);
  const [qty, setQty] = useState({});
  const [reason, setReason] = useState('');
  const [methodKey, setMethodKey] = useState(null);
  const [quote, setQuote] = useState(null);
  const [message, setMessage] = useState(null);
  const [busy, setBusy] = useState(false);

  const reload = useCallback(async () => {
    setSale((await posStore.sale(initial.id)) ?? initial);
    setRecords(await posStore.recordsOfSale(initial.id));
  }, [initial, posStore]);
  useEffect(() => {
    reload();
  }, [reload]);

  const refunded = useMemo(() => {
    const out = {};
    for (const record of records) if (record.recordKind === 'refund') for (const line of record.lines) out[line.sale_line_id] = (out[line.sale_line_id] ?? 0) + Number(line.qty);
    return out;
  }, [records]);
  const voided = sale.local?.status === 'voided' || records.some((record) => record.recordKind === 'void');
  const hasRefunds = records.some((record) => record.recordKind === 'refund');
  const requested = Object.entries(qty)
    .filter(([, value]) => value > 0)
    .map(([saleLineId, value]) => ({ saleLineId, qty: String(value) }));

  useEffect(() => {
    let active = true;
    if (!requested.length) setQuote(null);
    else actions.refundQuote({ sale, requested }).then((result) => active && setQuote(result), () => active && setQuote(null));
    return () => {
      active = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [JSON.stringify(requested), sale, actions]);

  // Refunds are paid at the sale's own rates, in a currency the sale was paid in (H1).
  const methods = useMemo(() => {
    const currencies = [...new Set([sale.currency, ...sale.payments.map((payment) => payment.currency)])];
    const out = [];
    for (const method of catalogue.paymentMethods.filter((candidate) => candidate.type === 'cash')) {
      for (const currency of method.currency ? [method.currency] : currencies) {
        if (currencies.includes(currency)) out.push({ key: `${method.id}:${currency}`, method, currency });
      }
    }
    return out;
  }, [catalogue, sale]);
  const chosen = methods.find((option) => option.key === methodKey) ?? methods[0] ?? null;
  const tender = quote && chosen ? refundTender(sale, quote.totalMinor, chosen.currency, catalogue.money.decimals) : null;

  async function refund() {
    if (!quote || !chosen || !reason.trim()) {
      setMessage({ tone: 'warning', text: t('pos.sales.errors.refundIncomplete') });
      return;
    }
    if (!tender) {
      setMessage({ tone: 'warning', text: t('pos.sales.errors.refund_currency') });
      return;
    }
    setBusy(true);
    try {
      const record = await actions.refund({ sale, requested, method: chosen.method, currency: chosen.currency, reason: reason.trim(), baseMajor: baseMajor(catalogue, sale, quote.totalMinor) ?? '999999999' });
      if (!record) {
        setMessage({ tone: 'warning', text: t('pos.override.cancelled') });
        return;
      }
      setQty({});
      setReason('');
      setMessage({ tone: 'success', text: t('pos.sales.refunded', { number: record.receipt_number, amount: money(record.payments[0].amount_minor, record.payments[0].currency) }) });
      await reload();
    } catch (problem) {
      setMessage({ tone: 'danger', text: t(`pos.sales.errors.${problem?.code ?? 'failed'}`, { defaultValue: t('pos.sales.errors.failed') }) });
    } finally {
      setBusy(false);
    }
  }

  async function voidSale() {
    if (!reason.trim()) {
      setMessage({ tone: 'warning', text: t('pos.sales.errors.reason') });
      return;
    }
    setBusy(true);
    try {
      const record = await actions.voidSale(sale, reason.trim());
      if (!record) {
        setMessage({ tone: 'warning', text: t('pos.override.cancelled') });
        return;
      }
      setReason('');
      setMessage({ tone: 'success', text: t('pos.sales.voided', { number: sale.receipt_number }) });
      await reload();
    } catch (problem) {
      setMessage({ tone: 'danger', text: t(`pos.sales.errors.${problem?.code ?? 'failed'}`, { defaultValue: t('pos.sales.errors.failed') }) });
    } finally {
      setBusy(false);
    }
  }

  const canVoid = !voided && !hasRefunds && sale.shift_id === shift?.id;

  return (
    <ScrollView className="flex-1" contentContainerClassName="gap-4 p-4 md:p-6" keyboardShouldPersistTaps="handled">
      <View className="flex-row flex-wrap items-center justify-between gap-3">
        <View>
          <Text accessibilityRole="header" className="font-sans text-h2 font-semibold text-ink">
            {sale.receipt_number}
          </Text>
          <Text className="font-sans text-body text-ink-muted">{money(sale.totals.total_minor, sale.currency)}</Text>
        </View>
        {voided ? <StatusBadge tone="danger">{t('pos.sales.voidedBadge')}</StatusBadge> : hasRefunds ? <StatusBadge tone="warning">{t('pos.sales.refundedBadge')}</StatusBadge> : null}
        <Button variant="ghost" onPress={onBack}>
          {t('pos.sales.backToList')}
        </Button>
      </View>
      {message ? <Alert tone={message.tone}>{message.text}</Alert> : null}
      <View className="rounded-lg border border-border bg-surface-200">
        {sale.lines.map((line) => {
          const sold = Number(line.qty);
          const left = Math.max(0, sold - (refunded[line.id] ?? 0));
          const chosenQty = qty[line.id] ?? 0;
          const whole = Number.isInteger(sold);
          return (
            <View key={line.id} className="flex-row items-center gap-3 border-b border-border px-4 py-2">
              <View className="min-w-0 flex-1">
                <Text numberOfLines={1} className="font-sans text-body-lg font-medium text-ink">
                  {line.item_name}
                </Text>
                <Text className="font-sans text-caption tabular-nums text-ink-muted">{t('pos.sales.lineSold', { qty: line.qty, total: money(line.total_minor, sale.currency), left })}</Text>
              </View>
              {!voided && whole && left > 0 ? (
                <View className="flex-row items-center gap-1">
                  <Button variant="secondary" className="w-12 px-0" accessibilityLabel={t('pos.sales.returnLess', { name: line.item_name })} disabled={chosenQty <= 0} onPress={() => setQty((current) => ({ ...current, [line.id]: chosenQty - 1 }))}>
                    −
                  </Button>
                  <Text className="w-10 text-center font-sans text-body-lg tabular-nums text-ink">{chosenQty}</Text>
                  <Button variant="secondary" className="w-12 px-0" accessibilityLabel={t('pos.sales.returnMore', { name: line.item_name })} disabled={chosenQty >= left} onPress={() => setQty((current) => ({ ...current, [line.id]: chosenQty + 1 }))}>
                    +
                  </Button>
                </View>
              ) : null}
            </View>
          );
        })}
      </View>
      {!voided ? (
        <View className="gap-4 rounded-lg border border-border bg-surface-200 p-5">
          <TextField label={t('pos.sales.reason')} value={reason} onChangeText={setReason} maxLength={500} />
          {quote ? (
            <>
              <Text className="font-sans text-body-lg text-ink">{t('pos.sales.refundTotal', { amount: money(quote.totalMinor, sale.currency) })}</Text>
              <View className="flex-row flex-wrap gap-2">
                {methods.map((option) => (
                  <Button key={option.key} variant={option.key === chosen?.key ? 'primary' : 'secondary'} onPress={() => setMethodKey(option.key)} accessibilityState={{ selected: option.key === chosen?.key }}>
                    {`${option.method.name} · ${option.currency}`}
                  </Button>
                ))}
              </View>
              {tender ? <Text className="font-sans text-body text-ink-muted">{t('pos.sales.giveBack', { amount: money(tender.amountMinor, chosen.currency) })}</Text> : <Alert tone="warning">{t('pos.sales.errors.refund_currency')}</Alert>}
              <Button variant="pay" loading={busy} disabled={!tender || !reason.trim()} onPress={refund}>
                {tender ? t('pos.sales.refund', { amount: money(tender.amountMinor, chosen.currency) }) : t('pos.sales.refundShort')}
              </Button>
            </>
          ) : (
            <Text className="font-sans text-body text-ink-muted">{t('pos.sales.pickLines')}</Text>
          )}
          {canVoid ? (
            <Button variant="danger" loading={busy} disabled={!reason.trim()} onPress={voidSale}>
              {t('pos.sales.void')}
            </Button>
          ) : null}
        </View>
      ) : null}
    </ScrollView>
  );
}

/** POS-05: this till's sales (the open shift first), find by receipt number, void or refund. */
export function SalesScreen({ onBack }) {
  const { t } = useTranslation();
  const locale = useLocale();
  const money = useMoneyText();
  const { posStore } = useServices();
  const { catalogue } = usePosData();
  const [sales, setSales] = useState([]);
  const [query, setQuery] = useState('');
  const [selected, setSelected] = useState(null);
  const [notFound, setNotFound] = useState(false);

  useEffect(() => {
    posStore.recentSales(100).then(setSales);
  }, [posStore, selected]);

  async function find() {
    const sale = await posStore.saleByReceipt(query.trim());
    setNotFound(!sale);
    if (sale) setSelected(sale);
  }

  return (
    <SafeAreaView className="flex-1">
      <View className="flex-row items-center gap-4 border-b border-border bg-surface-200 px-4 py-2 md:px-6">
        <Button variant="ghost" onPress={onBack}>
          {t('pos.pay.back')}
        </Button>
        <Text accessibilityRole="header" className="font-sans text-h3 font-semibold text-ink">
          {t('pos.sales.title')}
        </Text>
      </View>
      {selected ? (
        <SaleDetail sale={selected} onBack={() => setSelected(null)} />
      ) : (
        <View className="min-h-0 flex-1 gap-4 p-4 md:p-6">
          <View className="flex-row items-end gap-3">
            <TextField className="min-w-0 flex-1" label={t('pos.sales.findLabel')} value={query} onChangeText={setQuery} onSubmitEditing={find} autoCapitalize="characters" autoCorrect={false} />
            <Button variant="secondary" onPress={find} disabled={!query.trim()}>
              {t('pos.sales.find')}
            </Button>
          </View>
          {notFound ? <Alert tone="warning">{t('pos.sales.notFound')}</Alert> : null}
          <FlatList
            data={sales}
            keyExtractor={(sale) => sale.id}
            className="flex-1 rounded-lg border border-border bg-surface-200"
            ListEmptyComponent={<Text className="p-6 text-center font-sans text-body text-ink-muted">{t('pos.sales.none')}</Text>}
            renderItem={({ item: sale }) => (
              <Pressable accessibilityRole="button" onPress={() => setSelected(sale)} className={cn('min-h-12 flex-row items-center gap-3 border-b border-border px-4 py-3 hover:bg-surface-300 active:bg-surface-300', FOCUS_RING)}>
                <View className="min-w-0 flex-1">
                  <Text className="font-sans text-body-lg font-medium text-ink">{sale.receipt_number}</Text>
                  <Text className="font-sans text-caption text-ink-muted">{[formatTime(sale.sold_at, catalogue?.timeZone, locale), sale.local?.cashier_name].filter(Boolean).join(' · ')}</Text>
                </View>
                {sale.local?.status === 'voided' ? <StatusBadge tone="danger">{t('pos.sales.voidedBadge')}</StatusBadge> : null}
                <Text className="font-sans text-body font-medium tabular-nums text-ink">{money(sale.totals.total_minor, sale.currency)}</Text>
              </Pressable>
            )}
          />
        </View>
      )}
    </SafeAreaView>
  );
}
