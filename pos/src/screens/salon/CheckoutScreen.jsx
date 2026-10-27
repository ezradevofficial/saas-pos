import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Alert } from '../../components/ds/Alert';
import { Button } from '../../components/ds/Button';
import { TextField } from '../../components/ds/TextField';
import { usePosActions, usePosData } from '../../pos/PosProvider';
import { parseAmount } from '../../pos/input';
import { checkoutCart, checkoutProblem, splitTip } from '../../salon/checkout';
import { useSalon } from '../../salon/context';
import { depositLeft, packagesOf } from '../../salon/credit';
import { useServices } from '../../services/services';
import { localDate } from '../../sync/prices';
import { useMoneyText } from '../pos/format';
import { Row, VanHeader } from '../vansales/parts';

/**
 * SAL-16, SAL-17, SAL-18, SAL-22: the visit becomes a normal sale. This screen shows what will be rung (the services as
 * the server planned them, or the fee of a no-show), the deposit and package sessions the client can use, and a tip
 * shared among the staff who served; "Charge" puts the sale in the cart and opens the usual payment screen, where the
 * `salon_credit` method pays with the deposit or a session. Who earns what is the server's record of the appointment.
 */
export function CheckoutScreen({ appointmentId, fee = false, onBack, onPay }) {
  const { t } = useTranslation();
  const money = useMoneyText();
  const { database } = useServices();
  const { catalogue } = usePosData();
  const actions = usePosActions();
  const salon = useSalon();
  const view = salon.appointments.find((candidate) => candidate.id === appointmentId) ?? null;
  const currency = view?.currency ?? catalogue?.saleCurrency;
  const decimals = currency && catalogue ? catalogue.money.decimals(currency) : 2;
  const [tipText, setTipText] = useState('');
  const [tipStaff, setTipStaff] = useState(null);
  const [deposit, setDeposit] = useState(0n);
  const [packs, setPacks] = useState([]);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const servedBy = useMemo(() => [...new Map((view?.lines ?? []).map((line) => [line.staff_id, line.staff_name])).entries()], [view]);
  const chosen = tipStaff ?? servedBy.map(([staffId]) => staffId);
  const tipMinor = parseAmount(tipText, decimals) ?? '0';
  const tips = fee ? [] : splitTip(tipMinor, chosen);
  const problem = checkoutProblem(view, { fee });
  const total = fee ? BigInt(view?.fee_minor ?? 0) : (view?.lines ?? []).reduce((sum, line) => sum + BigInt(line.price_minor), 0n);

  useEffect(() => {
    let active = true;
    if (!view || fee) return undefined;
    (async () => {
      const left = await depositLeft({ database, appointmentId });
      const found = view.client_id && catalogue ? await packagesOf({ database, customerId: view.client_id, day: localDate(Date.now(), catalogue.timeZone) }) : [];
      if (active) {
        setDeposit(left);
        setPacks(found);
      }
    })().catch(() => {});
    return () => {
      active = false;
    };
  }, [database, appointmentId, view, fee, catalogue]);

  async function charge() {
    setBusy(true);
    setError(null);
    try {
      const cart = checkoutCart({ appointment: view, settings: salon.settings, catalogue, tips, fee });
      const refused = await actions.loadCart(cart);
      if (refused) throw Object.assign(new Error(refused), { code: refused });
      onPay();
    } catch (failure) {
      setError(t(`salon.errors.${failure?.code ?? 'failed'}`, { defaultValue: t('salon.errors.failed') }));
    } finally {
      setBusy(false);
    }
  }

  return (
    <SafeAreaView className="flex-1 bg-surface-100">
      <VanHeader title={fee ? t('salon.checkout.feeTitle') : t('salon.checkout.title')} subtitle={view?.client_name} onBack={onBack} backLabel={t('salon.day.title')} />
      <ScrollView contentContainerClassName="gap-4 p-4 md:px-6">
        {!view ? <Alert tone="warning">{t('salon.errors.appointment_unknown')}</Alert> : null}
        {problem && view ? <Alert tone="warning">{t(`salon.errors.${problem}`, { defaultValue: t('salon.errors.failed') })}</Alert> : null}
        {error ? <Alert tone="danger">{error}</Alert> : null}
        {view ? (
          <View className="rounded-lg border border-border bg-surface-200">
            {fee ? (
              <Row disabled>
                <Text className="min-w-0 flex-1 font-sans text-body-lg text-ink">{t(`salon.checkout.fee.${view.fee_reason ?? 'no_show'}`, { defaultValue: t('salon.checkout.fee.no_show') })}</Text>
                <Text className="font-sans text-body-lg tabular-nums text-ink">{money(view.fee_minor, currency)}</Text>
              </Row>
            ) : (
              view.lines.map((line) => (
                <Row key={line.id} disabled>
                  <View className="min-w-0 flex-1">
                    <Text className="font-sans text-body-lg text-ink">{line.name}</Text>
                    <Text className="font-sans text-caption text-ink-muted">{line.staff_name}</Text>
                  </View>
                  <Text className="font-sans text-body-lg tabular-nums text-ink">{money(line.price_minor, currency)}</Text>
                </Row>
              ))
            )}
          </View>
        ) : null}
        {!fee && view ? (
          <View className="gap-2">
            {deposit > 0n ? <Text className="font-sans text-body text-ink">{t('salon.checkout.deposit', { amount: money(String(deposit), currency) })}</Text> : null}
            {packs.map((pack) => (
              <Text key={pack.id} className="font-sans text-body text-ink">
                {t('salon.checkout.package', { name: pack.name, count: pack.sessions_left, amount: money(pack.session_value_minor, currency) })}
              </Text>
            ))}
            {deposit > 0n || packs.length ? <Text className="font-sans text-caption text-ink-muted">{t('salon.checkout.creditHelp')}</Text> : null}
            <TextField label={t('salon.checkout.tip', { currency })} value={tipText} onChangeText={setTipText} keyboardType="decimal-pad" />
            {BigInt(tipMinor) > 0n ? (
              <View className="rounded-md border border-border">
                {servedBy.map(([staffId, name]) => {
                  const on = chosen.includes(staffId);
                  return (
                    <Row key={staffId} label={name} onPress={() => setTipStaff(on ? chosen.filter((value) => value !== staffId) : [...chosen, staffId])}>
                      <Text className="min-w-0 flex-1 font-sans text-body-lg text-ink">{name}</Text>
                      <Text className="font-sans text-body tabular-nums text-ink-muted">{on ? money(tips.find((share) => share.staff_id === staffId)?.amount_minor ?? '0', currency) : t('salon.checkout.noTip')}</Text>
                    </Row>
                  );
                })}
              </View>
            ) : null}
          </View>
        ) : null}
        {view ? (
          <View className="flex-row items-center justify-between">
            <Text className="font-sans text-h3 font-semibold text-ink">{t('salon.checkout.total')}</Text>
            <Text className="font-sans text-h3 font-semibold tabular-nums text-ink">{money(String(total + BigInt(tipMinor)), currency)}</Text>
          </View>
        ) : null}
        <Button variant="pay" loading={busy} disabled={Boolean(problem) || !view || (BigInt(tipMinor) > 0n && !tips.length)} onPress={charge}>
          {t('salon.checkout.charge', { amount: money(String(total + BigInt(tipMinor)), currency) })}
        </Button>
      </ScrollView>
    </SafeAreaView>
  );
}
