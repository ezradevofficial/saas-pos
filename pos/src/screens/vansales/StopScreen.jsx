import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Alert } from '../../components/ds/Alert';
import { Button } from '../../components/ds/Button';
import { Dialog } from '../../components/ds/Dialog';
import { StatusBadge } from '../../components/ds/StatusBadge';
import { TextField } from '../../components/ds/TextField';
import { usePosActions, usePosCart, usePosData } from '../../pos/PosProvider';
import { useServices } from '../../services/services';
import { askCheckInPermission, checkInOn, checkInPermission, loadLocation, readOnePosition } from '../../vansales/checkin';
import { useVanSales } from '../../vansales/context';
import { creditPosition } from '../../vansales/credit';
import { NO_SALE_REASONS } from '../../vansales/plan';
import { useMoneyText } from '../pos/format';
import { Choice, VanHeader, VISIT_TONE } from './parts';

// The explanation is shown once per app session; the phone remembers its own answer.
let explained = false;

/** VAN-09: why nothing was sold. */
function NoSaleDialog({ onClose, onSave }) {
  const { t } = useTranslation();
  const [reason, setReason] = useState('closed');
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);
  return (
    <Dialog
      open
      title={t('vansales.stop.noSaleTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onPress={onClose}>
            {t('common.cancel')}
          </Button>
          <Button
            variant="primary"
            loading={busy}
            onPress={async () => {
              setBusy(true);
              await onSave(reason, note.trim());
            }}
          >
            {t('vansales.stop.noSaleSave')}
          </Button>
        </>
      }
    >
      <Choice options={NO_SALE_REASONS.map((value) => ({ value, label: t(`vansales.noSale.${value}`) }))} value={reason} onChange={setReason} label={t('vansales.stop.noSaleReason')} />
      <TextField label={t('vansales.stop.note')} value={note} onChangeText={setNote} maxLength={500} />
    </Dialog>
  );
}

/** VAN-16: a return is a POS refund of a van sale; an exchange is that refund plus a new sale naming the original. */
function ReturnDialog({ run, customerId, onClose, onReturn, onExchange }) {
  const { t } = useTranslation();
  const money = useMoneyText();
  const van = useVanSales();
  const [sales, setSales] = useState(null);
  useEffect(() => {
    let active = true;
    van.service.records.salesSince(Date.parse(run.started_at) || 0).then((rows) => {
      if (active) setSales(rows.filter((sale) => sale.extensions?.van_sales?.run_id === run.id && sale.customer_id === customerId && sale.local?.status !== 'voided'));
    });
    return () => {
      active = false;
    };
  }, [van.service, run, customerId]);
  return (
    <Dialog open title={t('vansales.stop.returnTitle')} onClose={onClose}>
      <Text className="font-sans text-body text-ink-muted">{t('vansales.stop.returnHelp')}</Text>
      {sales && !sales.length ? <Text className="font-sans text-body text-ink">{t('vansales.stop.returnNone')}</Text> : null}
      <View className="rounded-md border border-border">
        {(sales ?? []).map((sale) => (
          <View key={sale.id} className="gap-2 border-b border-border p-3">
            <Text className="font-sans text-body-lg font-medium text-ink">{`${sale.receipt_number} · ${money(sale.totals.total_minor, sale.currency)}`}</Text>
            <View className="flex-row flex-wrap gap-2">
              <Button variant="secondary" onPress={() => onReturn(sale)}>
                {t('vansales.stop.returnItems')}
              </Button>
              <Button variant="secondary" onPress={() => onExchange(sale)}>
                {t('vansales.stop.exchange')}
              </Button>
            </View>
          </View>
        ))}
      </View>
    </Dialog>
  );
}

/** VAN-24: the explanation, before the phone's own question. */
function CheckInDialog({ onAllow, onNotNow }) {
  const { t } = useTranslation();
  return (
    <Dialog
      open
      title={t('vansales.checkin.title')}
      onClose={onNotNow}
      footer={
        <>
          <Button variant="ghost" onPress={onNotNow}>
            {t('vansales.checkin.notNow')}
          </Button>
          <Button variant="primary" onPress={onAllow}>
            {t('vansales.checkin.allow')}
          </Button>
        </>
      }
    >
      <Text className="font-sans text-body-lg text-ink-muted">{t('vansales.checkin.body')}</Text>
    </Dialog>
  );
}

/**
 * VAN-09..VAN-11, VAN-16, VAN-17: one stop. The customer, their credit
 * (limit, balance and what this device has put on credit or collected since
 * the last sync), and what the rep can do: sell, collect, return or
 * exchange, record no sale, skip.
 */
export function StopScreen({ stopRef, onBack, onSell, onCollect, onReturns }) {
  const { t } = useTranslation();
  const money = useMoneyText();
  const { database } = useServices();
  const { catalogue } = usePosData();
  const actions = usePosActions();
  const { cart } = usePosCart();
  const van = useVanSales();
  const [customer, setCustomer] = useState(null);
  const [position, setPosition] = useState(null);
  const [dialog, setDialog] = useState(null);
  const [message, setMessage] = useState(null);
  const run = van.run;
  const visit = van.visits.find((entry) => entry.id === stopRef.visitId) ?? null;
  const name = customer?.name ?? stopRef.stop?.customer_name ?? '';
  const phone = customer?.phones?.[0]?.number ?? stopRef.stop?.phone ?? null;
  const currency = position?.account?.credit_limit_currency ?? catalogue.saleCurrency;

  useEffect(() => {
    let active = true;
    database.get('customers').find(stopRef.customerId).then((record) => active && setCustomer(record.data), () => {});
    return () => {
      active = false;
    };
  }, [database, stopRef.customerId]);

  useEffect(() => {
    let active = true;
    van.service.accountPosition(stopRef.customerId, currency).then((found) => active && setPosition(found));
    return () => {
      active = false;
    };
  }, [van.service, stopRef.customerId, currency, van.visits]);

  // VAN-24: one position at arrival, only when the company asks for it, and the rep may refuse.
  const takePosition = useCallback(
    async (location) => {
      const position_ = await readOnePosition(location);
      if (position_ && visit) await van.service.checkIn(visit.id, position_);
    },
    [van.service, visit],
  );
  const wantsCheckIn = checkInOn(van.van) && visit && !visit.check_in;
  useEffect(() => {
    if (!wantsCheckIn) return;
    const location = loadLocation();
    checkInPermission(location).then((state) => {
      if (state === 'granted') takePosition(location);
      else if (state === 'explain' && !explained) setDialog({ kind: 'checkin' });
    });
    // One attempt per visit.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [wantsCheckIn, visit?.id]);

  async function allowCheckIn() {
    explained = true;
    setDialog(null);
    const location = loadLocation();
    if ((await askCheckInPermission(location)) === 'granted') await takePosition(location);
  }

  const limit = position?.account ? creditPosition({ account: position.account, currency, unsynced: position.unsynced }) : null;

  async function startSale(extra = {}) {
    if (!run || !visit) return;
    // A sale in progress for someone else is parked, never mixed.
    if (cart.lines.length && cart.ext?.van_sales?.visit_id !== visit.id) await actions.hold();
    if (customer) actions.setCustomer(customer);
    else actions.setCustomer({ id: stopRef.customerId, name, phones: phone ? [{ number: phone }] : [] });
    actions.setExtension('van_sales', { run_id: run.id, visit_id: visit.id, ...extra });
    onSell();
  }

  const done = async (promise) => {
    try {
      await promise;
      onBack();
    } catch (problem) {
      setMessage({ tone: 'danger', text: t(`vansales.errors.${problem?.code ?? 'failed'}`, { defaultValue: t('vansales.errors.failed') }) });
    }
  };

  return (
    <SafeAreaView className="flex-1 bg-surface-100">
      <VanHeader title={name} subtitle={phone} onBack={onBack} backLabel={t('vansales.day.title')} />
      <ScrollView contentContainerClassName="gap-4 p-4 md:px-6">
        {message ? <Alert tone={message.tone}>{message.text}</Alert> : null}
        {!run ? <Alert tone="warning">{t('vansales.stop.noRun')}</Alert> : null}
        <View className="flex-row items-center justify-between gap-3">
          <Text className="font-sans text-body text-ink-muted">{t('vansales.stop.status')}</Text>
          <StatusBadge tone={VISIT_TONE[visit?.status ?? 'pending']}>{t(`vansales.status.${visit?.status ?? 'pending'}`)}</StatusBadge>
        </View>
        <View testID="credit-card" className="gap-2 rounded-lg border border-border bg-surface-200 p-4">
          <Text className="font-sans text-h3 font-semibold text-ink">{t('vansales.stop.credit')}</Text>
          {position && !position.account ? <Text className="font-sans text-body text-ink-muted">{t('vansales.stop.noAccount')}</Text> : null}
          {limit?.code === 'no_credit_limit' ? <Text className="font-sans text-body text-ink-muted">{t('vansales.stop.noLimit')}</Text> : null}
          {limit && limit.code !== 'no_credit_limit' ? (
            <>
              <Text className="font-sans text-body-lg tabular-nums text-ink">{t('vansales.stop.limit', { amount: money(String(position.account.credit_limit_minor), position.account.credit_limit_currency) })}</Text>
              {limit.code === 'limit_currency_differs' ? (
                <Text className="font-sans text-body text-ink-muted">{t('vansales.errors.limit_currency_differs', { currency: limit.limitCurrency })}</Text>
              ) : (
                <>
                  <Text className="font-sans text-body-lg tabular-nums text-ink">{t('vansales.stop.balance', { amount: money(String(limit.balance), currency) })}</Text>
                  <Text className="font-sans text-body-lg font-medium tabular-nums text-ink">{t('vansales.stop.available', { amount: money(String(limit.available), currency) })}</Text>
                </>
              )}
            </>
          ) : null}
        </View>
        <View className="gap-3">
          <Button variant="primary" disabled={!run || !visit} onPress={() => startSale()}>
            {t('vansales.stop.sell')}
          </Button>
          <Button variant="secondary" disabled={!run} onPress={() => onCollect({ customerId: stopRef.customerId, visitId: visit?.id ?? null })}>
            {t('vansales.stop.collect')}
          </Button>
          <Button variant="secondary" disabled={!run} onPress={() => setDialog({ kind: 'return' })}>
            {t('vansales.stop.return')}
          </Button>
          <View className="flex-row flex-wrap gap-3">
            <Button variant="secondary" disabled={!visit} onPress={() => setDialog({ kind: 'nosale' })}>
              {t('vansales.stop.noSale')}
            </Button>
            <Button variant="ghost" disabled={!visit} onPress={() => done(van.service.skip(visit.id))}>
              {t('vansales.stop.skip')}
            </Button>
          </View>
        </View>
      </ScrollView>
      {dialog?.kind === 'nosale' ? (
        <NoSaleDialog
          onClose={() => setDialog(null)}
          onSave={(reason, note) => {
            setDialog(null);
            return done(van.service.noSale(visit.id, { reason, note }));
          }}
        />
      ) : null}
      {dialog?.kind === 'return' ? (
        <ReturnDialog
          run={run}
          customerId={stopRef.customerId}
          onClose={() => setDialog(null)}
          onReturn={() => {
            setDialog(null);
            onReturns();
          }}
          onExchange={(sale) => {
            setDialog(null);
            startSale({ exchange_for_sale_id: sale.id });
          }}
        />
      ) : null}
      {dialog?.kind === 'checkin' ? <CheckInDialog onAllow={allowCheckIn} onNotNow={() => { explained = true; setDialog(null); }} /> : null}
    </SafeAreaView>
  );
}
