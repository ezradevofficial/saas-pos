import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FlatList, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useSession } from '../../auth/session';
import { Alert } from '../../components/ds/Alert';
import { Button } from '../../components/ds/Button';
import { Dialog } from '../../components/ds/Dialog';
import { StatusBadge } from '../../components/ds/StatusBadge';
import { TextField } from '../../components/ds/TextField';
import { searchCustomers } from '../../pos/catalogue';
import { usePosData } from '../../pos/PosProvider';
import { useServices } from '../../services/services';
import { canVan, useVanSales, VAN } from '../../vansales/context';
import { dayPlan, planSummary, routesDueOn } from '../../vansales/plan';
import { isStale } from '../../vansales/runs';
import { Choice, Row, VanHeader, VISIT_TONE } from './parts';

/** VAN-09: a customer who is not on the route (a visit to anyone is allowed). */
function OtherCustomerDialog({ onClose, onPick }) {
  const { t } = useTranslation();
  const { database } = useServices();
  const [query, setQuery] = useState('');
  const [found, setFound] = useState([]);
  useEffect(() => {
    let active = true;
    if (query.trim().length < 2) return undefined;
    searchCustomers(database, query, 8).then((rows) => active && setFound(rows));
    return () => {
      active = false;
    };
  }, [database, query]);
  const results = query.trim().length >= 2 ? found : [];
  return (
    <Dialog open title={t('vansales.day.otherTitle')} onClose={onClose}>
      <TextField label={t('vansales.day.otherSearch')} value={query} onChangeText={setQuery} autoCorrect={false} />
      <View className="rounded-md border border-border">
        {results.map((customer) => (
          <Row key={customer.id} onPress={() => onPick(customer)}>
            <Text className="min-w-0 flex-1 font-sans text-body-lg text-ink">{customer.name}</Text>
          </Row>
        ))}
      </View>
    </Dialog>
  );
}

/** VAN-06: choose the route (the ones due today first) and start the day. Works offline. */
function StartDay({ onStarted }) {
  const { t } = useTranslation();
  const { user } = useSession();
  const { catalogue } = usePosData();
  const van = useVanSales();
  const timeZone = catalogue?.timeZone;
  const due = useMemo(() => routesDueOn(van.routes, Date.now(), timeZone), [van.routes, timeZone]);
  const [routeId, setRouteId] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const chosen = routeId ?? due[0]?.id ?? null;
  const options = [...van.routes.map((route) => ({ value: route.id, label: due.includes(route) ? route.name : t('vansales.day.routeNotToday', { name: route.name }) })), { value: 'none', label: t('vansales.day.noRoute') }];

  async function start() {
    setBusy(true);
    setError(null);
    try {
      await van.service.startDay({ user, routeId: chosen && chosen !== 'none' ? chosen : null, timeZone });
      onStarted?.();
    } catch (problem) {
      setError(t(`vansales.errors.${problem?.code ?? 'failed'}`, { defaultValue: t('vansales.errors.failed') }));
    } finally {
      setBusy(false);
    }
  }

  return (
    <View className="gap-3 rounded-lg border border-border bg-surface-200 p-4">
      <Text className="font-sans text-h3 font-semibold text-ink">{t('vansales.day.startTitle')}</Text>
      <Text className="font-sans text-body text-ink-muted">{due.length ? t('vansales.day.startHelp') : t('vansales.day.noneToday')}</Text>
      {error ? <Alert tone="danger">{error}</Alert> : null}
      <Choice options={options} value={chosen ?? 'none'} onChange={setRouteId} label={t('vansales.day.route')} />
      <Button variant="primary" loading={busy} disabled={!canVan(user, VAN.runStart)} onPress={start}>
        {t('vansales.day.start')}
      </Button>
    </View>
  );
}

/**
 * VAN-05, VAN-06, VAN-27: the rep's day. Today's stops come from the pulled
 * routes and the company's weekday, in order, each with a dot and a word; no
 * connection is needed to start the day or work the route.
 */
export function DayScreen({ onStop, onLoad, onCount, onMenu }) {
  const { t } = useTranslation();
  const { user } = useSession();
  const { catalogue } = usePosData();
  const van = useVanSales();
  const [other, setOther] = useState(false);
  const timeZone = catalogue?.timeZone;
  const run = van.run;
  const route = run ? van.routes.find((candidate) => candidate.id === run.route_id) ?? null : null;
  const plan = useMemo(() => (run && route ? dayPlan({ route, visits: van.visits, at: Date.now(), timeZone }) : []), [run, route, van.visits, timeZone]);
  const summary = planSummary(plan);
  const open = van.loads.filter((load) => load.status === 'released');
  const unconfirmed = run && !van.loads.some((load) => load.status === 'confirmed');
  const last = van.runs.find((view) => view.status === 'counting' || (view.status !== 'open' && view.count.status !== 'none'));
  const counting = van.runs.find((view) => view.status === 'counting');

  async function pickOther(customer) {
    setOther(false);
    const visit = await van.service.openVisit({ run, customer, user });
    onStop({ customerId: customer.id, visitId: visit.id, stop: null });
  }

  const header = (
    <View className="gap-3">
      {van.rejected.length ? (
        <Alert
          tone="warning"
          title={t('vansales.day.rejectedTitle', { count: van.rejected.length })}
          action={
            <Button variant="ghost" onPress={() => van.rejected.forEach((record) => van.service.retry(record.id))}>
              {t('vansales.day.rejectedRetry')}
            </Button>
          }
        >
          {van.rejected.map((record) => `${t(`vansales.kinds.${record.kind}`)}: ${record.local.error?.code ?? ''}`).join(' · ')}
        </Alert>
      ) : null}
      {run && isStale(run, Date.now(), timeZone) ? <Alert tone="warning">{t('vansales.day.stale')}</Alert> : null}
      {!run && counting ? <Alert tone="info">{t(`vansales.count.outcome.${counting.count.status}`, { defaultValue: t('vansales.count.outcome.queued') })}</Alert> : null}
      {!run && !counting && last ? <Alert tone={last.count.status === 'rejected' ? 'warning' : 'info'}>{t(`vansales.count.outcome.${last.count.status}`, { defaultValue: '' })}</Alert> : null}
      {run && unconfirmed ? <Alert tone="warning">{t('vansales.day.noLoad')}</Alert> : null}
      {!run ? <StartDay /> : null}
      {run ? (
        <View className="flex-row flex-wrap gap-3">
          <Button variant="secondary" onPress={onLoad}>
            {open.length ? t('vansales.day.confirmLoadCount', { count: open.length }) : t('vansales.day.loads')}
          </Button>
          <Button variant="secondary" onPress={() => setOther(true)}>
            {t('vansales.day.visitOther')}
          </Button>
          <Button variant="primary" onPress={onCount}>
            {t('vansales.day.endDay')}
          </Button>
        </View>
      ) : (
        <View className="flex-row flex-wrap gap-3">
          <Button variant="secondary" onPress={onLoad}>
            {t('vansales.day.loads')}
          </Button>
        </View>
      )}
      {run ? (
        <Text className="font-sans text-body text-ink-muted">{t('vansales.day.summary', { done: summary.done, total: summary.total, sold: summary.sold })}</Text>
      ) : null}
    </View>
  );

  return (
    <SafeAreaView className="flex-1 bg-surface-100">
      <VanHeader title={t('vansales.day.title')} subtitle={[van.van?.name, van.van?.plate, user?.name].filter(Boolean).join(' · ')} onBack={onMenu} backLabel={t('pos.menu.title')} />
      <FlatList
        data={plan}
        keyExtractor={(entry) => entry.stop.id}
        contentContainerClassName="gap-3 p-4 md:px-6"
        ListHeaderComponent={header}
        ListEmptyComponent={run ? <Text className="font-sans text-body text-ink-muted">{route ? t('vansales.day.noStopsToday') : t('vansales.day.noRouteRun')}</Text> : null}
        renderItem={({ item }) => (
          <View className="rounded-lg border border-border bg-surface-200">
            <Row
              label={`${item.stop.customer_name}, ${t(`vansales.status.${item.status}`)}`}
              onPress={async () => {
                const visit = item.visit ?? (await van.service.openVisit({ run, customer: { id: item.stop.customer_id, name: item.stop.customer_name }, stop: item.stop, user }));
                onStop({ customerId: item.stop.customer_id, visitId: visit.id, stop: item.stop });
              }}
              className="border-b-0"
            >
              <Text className="w-8 font-sans text-body tabular-nums text-ink-muted">{item.stop.position}</Text>
              <View className="min-w-0 flex-1">
                <Text numberOfLines={1} className="font-sans text-body-lg font-medium text-ink">
                  {item.stop.customer_name}
                </Text>
                {item.stop.phone ? <Text className="font-sans text-caption text-ink-muted">{item.stop.phone}</Text> : null}
              </View>
              <StatusBadge tone={VISIT_TONE[item.status]}>{t(`vansales.status.${item.status}`)}</StatusBadge>
            </Row>
          </View>
        )}
      />
      {other ? <OtherCustomerDialog onClose={() => setOther(false)} onPick={pickOther} /> : null}
    </SafeAreaView>
  );
}
