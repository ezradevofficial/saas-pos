import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Alert } from '../../components/ds/Alert';
import { Button } from '../../components/ds/Button';
import { Money } from '../../components/ds/Money';
import { StatusBadge } from '../../components/ds/StatusBadge';
import { TextField } from '../../components/ds/TextField';
import { cn } from '../../lib/cn';
import { computeCart } from '../../pos/cart';
import { parseAmount } from '../../pos/input';
import { usePosActions, usePosData } from '../../pos/PosProvider';
import { scaled } from '../../restaurant/bill';
import { buildPlan, closingProblem, partCart, unpaidParts } from '../../restaurant/closing';
import { liveLines } from '../../restaurant/orderModel';
import { useOrder, useRestaurant } from '../../restaurant/context';
import { useMoneyText } from '../pos/format';
import { orderBill } from './floorHelpers';
import { Segmented, Stepper } from './parts';

const MODES = ['full', 'item', 'seat', 'equal'];
const MAX_GROUPS = 6;

/** The tip a percentage of the net amount comes to, rounded half up to the minor unit. */
function tipFor(netMinor, percent) {
  const rate = scaled(String(percent), 4);
  return String((BigInt(netMinor) * rate * 2n + 1000000n) / 2000000n);
}

/**
 * REST-13, REST-14, REST-16: the bill. Whole, or split by item (lines put
 * in groups), by seat or equally; the service charge from the company's
 * settings and a tip (a preset or an amount). Each part is built as a
 * normal POS sale (the same cart, tender and receipt path) with
 * extensions.restaurant; paying all the parts closes the order. A split is
 * fixed once a part is paid.
 */
export function BillScreen({ orderId, onBack, onPay, onDone }) {
  const { t } = useTranslation();
  const money = useMoneyText();
  const { catalogue } = usePosData();
  const actions = usePosActions();
  const floor = useRestaurant();
  const order = useOrder(orderId);
  const [mode, setMode] = useState('full');
  const [count, setCount] = useState(2);
  const [groupCount, setGroupCount] = useState(2);
  const [assigned, setAssigned] = useState({});
  const [tip, setTip] = useState({ kind: 'none' });
  const [tipText, setTipText] = useState('');
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const { settings, service } = floor;

  const currency = order?.currency ?? catalogue?.saleCurrency;
  const decimals = currency && catalogue ? catalogue.money.decimals(currency) : 2;
  const locked = Boolean(order?.local.paid?.length);
  const live = useMemo(() => (order ? liveLines(order) : []), [order]);
  const net = useMemo(() => (order ? (orderBill(order, settings, catalogue, 0)?.net_minor ?? '0') : '0'), [order, settings, catalogue]);

  const tipMinor = useMemo(() => {
    if (tip.kind === 'preset') return tipFor(net, tip.value);
    if (tip.kind === 'custom') return parseAmount(tipText, decimals) ?? '0';
    return '0';
  }, [tip, tipText, net, decimals]);

  const built = useMemo(() => {
    if (!order || locked || !live.length) return { plan: null, problem: null };
    try {
      const options = {};
      if (mode === 'equal') options.parts = count;
      if (mode === 'item') {
        options.groups = Array.from({ length: groupCount }, (_, index) => live.filter((line) => (assigned[line.id] ?? 0) === index).map((line) => line.id)).filter((group) => group.length);
      }
      return { plan: buildPlan(order, { settings, catalogue, mode, options, tipMinor }), problem: null };
    } catch (failure) {
      return { plan: null, problem: failure.message || 'split_invalid' };
    }
  }, [order, locked, live, mode, count, groupCount, assigned, settings, catalogue, tipMinor]);

  const plan = locked ? order?.local.plan : built.plan;

  // What each part will really charge: the sale's total through the normal cart maths.
  const shown = useMemo(() => {
    if (!order || !plan) return [];
    return plan.parts.map((part, index) => {
      try {
        const cart = partCart({ ...order, local: { ...order.local, plan } }, plan, index, { settings, catalogue });
        const computed = computeCart(cart, { taxCodes: catalogue.taxCodes, day: catalogue.day, catalogue });
        return { total: computed.totals.total_minor, blocked: computed.blocked.length > 0 };
      } catch {
        return { total: part.total_minor, blocked: true };
      }
    });
  }, [order, plan, settings, catalogue]);

  if (!order || !catalogue) return null;

  const paidIds = new Set((order.local.paid ?? []).map((entry) => entry.sale_id));
  const problem = closingProblem(order, settings, catalogue, { tipMinor });
  const open = unpaidParts(order);
  const firstUnpaid = plan ? plan.parts.findIndex((part) => !paidIds.has(part.saleId)) : -1;
  const closed = order.local.closed === 'paid';
  const whole = orderBill(order, settings, catalogue, tipMinor);
  const seatCount = new Set(live.filter((line) => line.seat).map((line) => line.seat)).size;

  async function pay(index) {
    setError(null);
    if (problem) {
      setError(t(`restaurant.bill.problems.${problem}`));
      return;
    }
    setBusy(true);
    try {
      await service.savePlan({ orderId, plan });
      const fresh = await service.orders.get(orderId);
      const cart = partCart(fresh, fresh.local.plan, index, { settings, catalogue });
      const refused = await actions.loadCart(cart);
      if (refused) setError(t('pos.sale.mobilePaid'));
      else onPay();
    } catch (failure) {
      setError(t(`restaurant.bill.problems.${failure.message}`, { defaultValue: t('restaurant.errors.failed') }));
    } finally {
      setBusy(false);
    }
  }

  const partTitle = (part, index) => {
    if (plan.mode === 'full') return t('restaurant.bill.wholeBill');
    if (plan.mode === 'seat') return t('restaurant.bill.partSeat', { part: index + 1, parts: plan.parts.length });
    return t('restaurant.bill.part', { part: index + 1, parts: plan.parts.length });
  };

  return (
    <SafeAreaView className="flex-1">
      <View className="flex-row items-center gap-3 border-b border-border bg-surface-200 px-4 py-3 md:px-6">
        <Button variant="ghost" onPress={onBack}>
          {t('restaurant.bill.back')}
        </Button>
        <Text accessibilityRole="header" numberOfLines={1} className="min-w-0 flex-1 font-sans text-h3 font-semibold text-ink">
          {t('restaurant.bill.title', { name: order.kind === 'tab' ? order.tab_name : (order.local.table_name ?? '') })}
        </Text>
        {whole ? <Money amount={whole.total_minor} currency={currency} size="lg" /> : null}
      </View>
      <ScrollView className="flex-1" contentContainerClassName="gap-4 px-4 py-4 md:px-6">
        {closed ? (
          <Alert tone="success" title={t('restaurant.bill.paid')}>
            {t('restaurant.bill.paidHelp')}
          </Alert>
        ) : null}
        {error ? <Alert tone="warning">{error}</Alert> : null}
        {problem && !closed ? <Alert tone="warning">{t(`restaurant.bill.problems.${problem}`)}</Alert> : null}

        {!closed ? (
          <>
            <View className="gap-2">
              <Text accessibilityRole="header" className="font-sans text-body-lg font-medium text-ink">
                {t('restaurant.bill.split')}
              </Text>
              <Segmented label={t('restaurant.bill.split')} value={locked ? plan?.mode : mode} onChange={locked ? () => {} : setMode} options={MODES.map((value) => ({ value, label: t(`restaurant.bill.modes.${value}`) }))} />
              {locked ? <Text className="font-sans text-caption text-ink-muted">{t('restaurant.bill.locked')}</Text> : null}
            </View>

            {!locked && mode === 'equal' ? (
              <View className="gap-2">
                <Text className="font-sans text-label text-ink">{t('restaurant.bill.howMany')}</Text>
                <Stepper value={count} min={2} max={20} onChange={setCount} lessLabel={t('restaurant.bill.fewer')} moreLabel={t('restaurant.bill.more')} label={t('restaurant.bill.howMany')} />
              </View>
            ) : null}

            {!locked && mode === 'seat' ? <Text className="font-sans text-body text-ink-muted">{seatCount >= 2 ? t('restaurant.bill.seatHelp', { count: seatCount }) : t('restaurant.bill.seatNeeds')}</Text> : null}

            {!locked && mode === 'item' ? (
              <View className="gap-3">
                <Text className="font-sans text-label text-ink">{t('restaurant.bill.groups')}</Text>
                <Stepper value={groupCount} min={2} max={MAX_GROUPS} onChange={setGroupCount} lessLabel={t('restaurant.bill.fewer')} moreLabel={t('restaurant.bill.more')} label={t('restaurant.bill.groups')} />
                <View className="rounded-lg border border-border bg-surface-200">
                  {live.map((line) => (
                    <View key={line.id} className="gap-2 border-b border-border px-4 py-3">
                      <Text className="font-sans text-body-lg text-ink">{`${line.qty} × ${line.name}`}</Text>
                      <Segmented
                        label={t('restaurant.bill.groupOf', { name: line.name })}
                        value={assigned[line.id] ?? 0}
                        onChange={(value) => setAssigned((current) => ({ ...current, [line.id]: value }))}
                        options={Array.from({ length: groupCount }, (_, index) => ({ value: index, label: String(index + 1), accessibilityLabel: t('restaurant.bill.groupN', { group: index + 1, name: line.name }) }))}
                      />
                    </View>
                  ))}
                </View>
              </View>
            ) : null}

            {built.problem && !locked ? <Alert tone="warning">{t('restaurant.bill.splitInvalid')}</Alert> : null}

            {!locked ? (
              <View className="gap-2">
                <Text accessibilityRole="header" className="font-sans text-body-lg font-medium text-ink">
                  {t('restaurant.bill.tip')}
                </Text>
                <Segmented
                  label={t('restaurant.bill.tip')}
                  value={tip.kind === 'preset' ? `p${tip.value}` : tip.kind}
                  onChange={(value) => {
                    if (value === 'none' || value === 'custom') setTip({ kind: value });
                    else setTip({ kind: 'preset', value: Number(value.slice(1)) });
                  }}
                  options={[{ value: 'none', label: t('restaurant.bill.noTip') }, ...settings.tipPresets.map((percent) => ({ value: `p${percent}`, label: `${percent}%` })), { value: 'custom', label: t('restaurant.bill.customTip') }]}
                />
                {tip.kind === 'custom' ? <TextField label={t('restaurant.bill.tipAmount', { currency })} value={tipText} onChangeText={setTipText} keyboardType="decimal-pad" /> : null}
              </View>
            ) : null}

            {whole ? (
              <View className="gap-1 rounded-lg border border-border bg-surface-200 p-4">
                <View className="flex-row justify-between">
                  <Text className="font-sans text-body text-ink-muted">{t('restaurant.bill.lines')}</Text>
                  <Text className="font-sans text-body tabular-nums text-ink">{money(whole.lines_minor, currency)}</Text>
                </View>
                {BigInt(whole.service_charge_minor) > 0n ? (
                  <View className="flex-row justify-between">
                    <Text className="font-sans text-body text-ink-muted">{t('restaurant.bill.service', { percent: Number(settings.percent) })}</Text>
                    <Text className="font-sans text-body tabular-nums text-ink">{money(String(BigInt(whole.service_charge_minor) + BigInt(whole.service_tax_minor)), currency)}</Text>
                  </View>
                ) : null}
                {BigInt(whole.tip_minor) > 0n ? (
                  <View className="flex-row justify-between">
                    <Text className="font-sans text-body text-ink-muted">{t('restaurant.bill.tip')}</Text>
                    <Text className="font-sans text-body tabular-nums text-ink">{money(whole.tip_minor, currency)}</Text>
                  </View>
                ) : null}
              </View>
            ) : null}
          </>
        ) : null}

        {plan ? (
          <View className="gap-2">
            <Text accessibilityRole="header" className="font-sans text-body-lg font-medium text-ink">
              {t('restaurant.bill.parts')}
            </Text>
            <View className="rounded-lg border border-border bg-surface-200">
              {plan.parts.map((part, index) => {
                const paid = paidIds.has(part.saleId);
                const amount = shown[index]?.total ?? part.total_minor;
                return (
                  <View key={part.saleId} testID={`part-${index + 1}`} className={cn('flex-row flex-wrap items-center gap-3 border-b border-border px-4 py-3')}>
                    <View className="min-w-0 flex-1">
                      <Text className="font-sans text-body-lg font-medium text-ink">{partTitle(part, index)}</Text>
                      <Text className="font-sans text-body tabular-nums text-ink-muted">{money(amount, currency)}</Text>
                    </View>
                    {paid ? (
                      <StatusBadge tone="success">{t('restaurant.bill.partPaid')}</StatusBadge>
                    ) : (
                      <Button variant={index === firstUnpaid ? 'pay' : 'secondary'} loading={busy} disabled={Boolean(shown[index]?.blocked) || BigInt(amount) <= 0n} onPress={() => pay(index)}>
                        {t('restaurant.bill.charge', { amount: money(amount, currency) })}
                      </Button>
                    )}
                  </View>
                );
              })}
            </View>
            {open.length === 0 && plan.parts.length ? <Text className="font-sans text-caption text-ink-muted">{t('restaurant.bill.allPaid')}</Text> : null}
          </View>
        ) : null}

        {closed ? (
          <Button variant="primary" onPress={onDone}>
            {t('restaurant.bill.done')}
          </Button>
        ) : null}
      </ScrollView>
    </SafeAreaView>
  );
}
