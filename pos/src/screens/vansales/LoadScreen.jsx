import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useSession } from '../../auth/session';
import { Alert } from '../../components/ds/Alert';
import { Button } from '../../components/ds/Button';
import { StatusBadge } from '../../components/ds/StatusBadge';
import { TextField } from '../../components/ds/TextField';
import { useSyncStatus } from '../../sync/useSyncStatus';
import { canVan, useVanSales, VAN } from '../../vansales/context';
import { parseCountQty } from '../../vansales/reconcile';
import { VanHeader } from './parts';

const TONE = { released: 'warning', confirmed: 'success', approved: 'info', awaiting_approval: 'neutral', rejected: 'danger', cancelled: 'neutral' };

/** VAN-08: the rep counts each line of a released load sheet and confirms what arrived. */
function LoadSheet({ load }) {
  const { t } = useTranslation();
  const { user } = useSession();
  const van = useVanSales();
  const sync = useSyncStatus();
  const [received, setReceived] = useState({});
  const [message, setMessage] = useState(null);
  const [busy, setBusy] = useState(false);
  const editable = load.status === 'released';
  const valueOf = (line) => received[line.id] ?? String(line.qty);
  const invalid = load.lines.some((line) => parseCountQty(valueOf(line)) === null);

  async function confirm() {
    // VAN-08: confirming is an online action; offline, the sheet stays as it is.
    if (sync.network === 'offline') {
      setMessage({ tone: 'warning', text: t('vansales.load.offline') });
      return;
    }
    setBusy(true);
    setMessage(null);
    try {
      const counted = Object.fromEntries(load.lines.map((line) => [line.id, parseCountQty(valueOf(line))]));
      await van.service.confirmLoad({ load, received: counted, user });
      setMessage({ tone: 'success', text: t('vansales.load.confirmed', { number: load.number }) });
      van.reload();
    } catch (problem) {
      setMessage({ tone: 'danger', text: t(`vansales.errors.${problem?.code ?? 'failed'}`, { defaultValue: t('vansales.errors.failed') }) });
    } finally {
      setBusy(false);
    }
  }

  return (
    <View className="gap-3 rounded-lg border border-border bg-surface-200 p-4">
      <View className="flex-row items-center justify-between gap-3">
        <Text className="font-sans text-body-lg font-medium text-ink">{t('vansales.load.sheet', { number: load.number })}</Text>
        <StatusBadge tone={TONE[load.status] ?? 'neutral'}>{t(`vansales.load.status.${load.status}`, { defaultValue: load.status })}</StatusBadge>
      </View>
      {message ? <Alert tone={message.tone}>{message.text}</Alert> : null}
      {load.lines.map((line) => (
        <View key={line.id} className="flex-row items-center gap-3">
          <View className="min-w-0 flex-1">
            <Text numberOfLines={2} className="font-sans text-body-lg text-ink">
              {line.item_name}
            </Text>
            <Text className="font-sans text-caption tabular-nums text-ink-muted">{t('vansales.load.released', { qty: line.qty })}</Text>
          </View>
          {editable ? (
            <TextField className="w-28" label={t('vansales.load.received')} keyboardType="decimal-pad" value={valueOf(line)} onChangeText={(text) => setReceived((current) => ({ ...current, [line.id]: text }))} />
          ) : (
            <Text className="font-sans text-body-lg tabular-nums text-ink">{line.qty_confirmed ?? '-'}</Text>
          )}
        </View>
      ))}
      {editable ? (
        <Button variant="primary" loading={busy} disabled={invalid || !canVan(user, VAN.loadConfirm)} onPress={confirm}>
          {t('vansales.load.confirm')}
        </Button>
      ) : null}
    </View>
  );
}

export function LoadScreen({ onBack }) {
  const { t } = useTranslation();
  const van = useVanSales();
  const loads = [...van.loads].sort((a, b) => String(b.number).localeCompare(String(a.number)));
  return (
    <SafeAreaView className="flex-1 bg-surface-100">
      <VanHeader title={t('vansales.load.title')} onBack={onBack} backLabel={t('vansales.day.title')} />
      <ScrollView contentContainerClassName="gap-3 p-4 md:px-6">
        {loads.length ? loads.map((load) => <LoadSheet key={load.id} load={load} />) : <Text className="font-sans text-body text-ink-muted">{t('vansales.load.none')}</Text>}
      </ScrollView>
    </SafeAreaView>
  );
}
