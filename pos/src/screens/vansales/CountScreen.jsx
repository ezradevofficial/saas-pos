import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useSession } from '../../auth/session';
import { uuidv7 } from '../../lib/random';
import { Alert } from '../../components/ds/Alert';
import { Button } from '../../components/ds/Button';
import { StatusBadge } from '../../components/ds/StatusBadge';
import { TextField } from '../../components/ds/TextField';
import { decimalsOf } from '../../lib/money';
import { parseAmount } from '../../pos/input';
import { canVan, useVanSales, VAN } from '../../vansales/context';
import { parseCountQty, stockEquation, tenderVariances } from '../../vansales/reconcile';
import { useMoneyText } from '../pos/format';
import { VanHeader } from './parts';

/** VAN-21: the server's answer to the count, from the pulled run row (or the upload's own answer until then). */
function Outcome({ outcome }) {
  const { t } = useTranslation();
  if (!outcome || outcome.status === 'none') return null;
  const tone = { queued: 'info', parked: 'danger', balanced: 'success', approved: 'success', pending: 'warning', rejected: 'danger' }[outcome.status] ?? 'info';
  return (
    <Alert tone={tone} title={t(`vansales.count.outcome.${outcome.status}`, { defaultValue: outcome.status })}>
      {outcome.status === 'parked' ? t('vansales.count.parked', { code: outcome.code }) : outcome.status === 'rejected' ? t('vansales.count.recount') : undefined}
    </Alert>
  );
}

/**
 * VAN-18..VAN-21: the end-of-day count. Per item the rep enters what is
 * returned (good) and damaged; the screen shows load = sold + returned +
 * damaged + variance live. Cash and mobile money are counted per currency
 * against what the device expects. Submitting queues the count (offline is
 * fine); the server's decision shows once pulled.
 */
export function CountScreen({ onBack }) {
  const { t } = useTranslation();
  const money = useMoneyText();
  const { user, actorProof } = useSession();
  const van = useVanSales();
  const run = van.run ?? van.runs.find((view) => view.status === 'counting') ?? van.runs[0] ?? null;
  const editable = van.run != null;
  const [figures, setFigures] = useState(null);
  const [returned, setReturned] = useState({});
  const [damaged, setDamaged] = useState({});
  const [counted, setCounted] = useState({});
  const [note, setNote] = useState('');
  const [message, setMessage] = useState(null);
  const [busy, setBusy] = useState(false);
  // The count's id is made once per attempt, so pressing twice stores one count.
  const countId = useRef(uuidv7());

  useEffect(() => {
    let active = true;
    if (run) van.service.dayFigures(run).then((found) => active && setFigures(found));
    return () => {
      active = false;
    };
  }, [van.service, run, van.visits]);

  const rows = useMemo(
    () =>
      (figures?.items ?? []).map((item) => {
        const r = parseCountQty(returned[item.item_id]);
        const d = parseCountQty(damaged[item.item_id]);
        return { item, r, d, equation: r !== null && d !== null ? stockEquation({ ...item, returned_qty: r, damaged_qty: d }) : null };
      }),
    [figures, returned, damaged],
  );
  const parsedTenders = (figures?.expected ?? []).map((row) => ({ ...row, key: `${row.kind}:${row.currency}`, minor: parseAmount(counted[`${row.kind}:${row.currency}`], decimalsOf(row.currency)) }));
  const variances = tenderVariances(figures?.expected ?? [], Object.fromEntries(parsedTenders.filter((row) => row.minor !== null).map((row) => [row.key, row.minor])));
  const ready = editable && figures && rows.every((row) => row.equation) && parsedTenders.every((row) => row.minor !== null) && canVan(user, VAN.countSubmit);

  async function submit() {
    setBusy(true);
    setMessage(null);
    try {
      await van.service.submitCount({
        id: countId.current,
        run,
        user,
        actorProof,
        lines: rows.map((row) => ({ item_id: row.item.item_id, returned_qty: row.r, damaged_qty: row.d })),
        tenders: parsedTenders.map((row) => ({ kind: row.kind, currency: row.currency, counted_minor: row.minor })),
        note: note.trim(),
      });
      countId.current = uuidv7();
      setMessage({ tone: 'success', text: t('vansales.count.queued') });
    } catch (problem) {
      setMessage({ tone: 'danger', text: t(`vansales.errors.${problem?.code ?? 'failed'}`, { defaultValue: t('vansales.errors.failed') }) });
    } finally {
      setBusy(false);
    }
  }

  return (
    <SafeAreaView className="flex-1 bg-surface-100">
      <VanHeader title={t('vansales.count.title')} onBack={onBack} backLabel={t('vansales.day.title')} />
      <ScrollView contentContainerClassName="gap-4 p-4 md:px-6" keyboardShouldPersistTaps="handled">
        {message ? <Alert tone={message.tone}>{message.text}</Alert> : null}
        {run ? <Outcome outcome={run.count} /> : <Text className="font-sans text-body text-ink-muted">{t('vansales.count.noRun')}</Text>}
        {run && !(figures?.loads ?? []).length ? <Alert tone="warning">{t('vansales.day.noLoad')}</Alert> : null}
        {editable ? (
          <>
            <Text className="font-sans text-h3 font-semibold text-ink">{t('vansales.count.stock')}</Text>
            <Text className="font-sans text-caption text-ink-muted">{t('vansales.count.equation')}</Text>
            {rows.map(({ item, equation }) => (
              <View key={item.item_id} className="gap-2 rounded-lg border border-border bg-surface-200 p-4">
                <Text className="font-sans text-body-lg font-medium text-ink">{item.item_name}</Text>
                <Text className="font-sans text-caption tabular-nums text-ink-muted">{t('vansales.count.figures', { load: item.load_qty, sold: item.sold_qty })}</Text>
                <View className="flex-row gap-3">
                  <TextField className="flex-1" label={t('vansales.count.returned')} keyboardType="decimal-pad" value={returned[item.item_id] ?? ''} onChangeText={(text) => setReturned((current) => ({ ...current, [item.item_id]: text }))} />
                  <TextField className="flex-1" label={t('vansales.count.damaged')} keyboardType="decimal-pad" value={damaged[item.item_id] ?? ''} onChangeText={(text) => setDamaged((current) => ({ ...current, [item.item_id]: text }))} />
                </View>
                {equation ? (
                  <View className="flex-row items-center justify-between gap-3">
                    <Text testID={`equation-${item.item_id}`} className="font-sans text-body tabular-nums text-ink">
                      {t('vansales.count.line', equation)}
                    </Text>
                    <StatusBadge tone={equation.balanced ? 'success' : 'warning'}>{equation.balanced ? t('vansales.count.balanced') : t('vansales.count.variance')}</StatusBadge>
                  </View>
                ) : (
                  <Text className="font-sans text-caption text-danger">{t('vansales.count.qtyInvalid')}</Text>
                )}
              </View>
            ))}
            {!rows.length ? <Text className="font-sans text-body text-ink-muted">{t('vansales.count.noItems')}</Text> : null}
            <Text className="font-sans text-h3 font-semibold text-ink">{t('vansales.count.money')}</Text>
            {variances.map((row) => (
              <View key={`${row.kind}:${row.currency}`} className="gap-2 rounded-lg border border-border bg-surface-200 p-4">
                <Text className="font-sans text-body-lg font-medium text-ink">{t(`vansales.count.kind.${row.kind}`, { currency: row.currency })}</Text>
                <Text className="font-sans text-caption tabular-nums text-ink-muted">{t('vansales.count.expected', { amount: money(row.expected_minor, row.currency) })}</Text>
                <TextField label={t('vansales.count.counted')} prefix={row.currency} keyboardType="decimal-pad" value={counted[`${row.kind}:${row.currency}`] ?? ''} onChangeText={(text) => setCounted((current) => ({ ...current, [`${row.kind}:${row.currency}`]: text }))} />
                {row.variance_minor !== null ? (
                  <StatusBadge tone={BigInt(row.variance_minor) === 0n ? 'success' : 'warning'}>{t('vansales.count.moneyVariance', { amount: money(row.variance_minor, row.currency) })}</StatusBadge>
                ) : null}
              </View>
            ))}
            {!variances.length ? <Text className="font-sans text-body text-ink-muted">{t('vansales.count.noMoney')}</Text> : null}
            <TextField label={t('vansales.stop.note')} value={note} onChangeText={setNote} maxLength={500} />
            <Button variant="pay" loading={busy} disabled={!ready} onPress={submit}>
              {t('vansales.count.submit')}
            </Button>
          </>
        ) : null}
      </ScrollView>
    </SafeAreaView>
  );
}
