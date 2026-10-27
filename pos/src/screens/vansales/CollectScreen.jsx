import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useSession } from '../../auth/session';
import { Alert } from '../../components/ds/Alert';
import { Button } from '../../components/ds/Button';
import { Dialog } from '../../components/ds/Dialog';
import { TextField } from '../../components/ds/TextField';
import { decimalsOf } from '../../lib/money';
import { parseAmount } from '../../pos/input';
import { usePosData } from '../../pos/PosProvider';
import { useServices } from '../../services/services';
import { canVan, useVanSales, VAN } from '../../vansales/context';
import { balanceIn } from '../../vansales/credit';
import { useMoneyText } from '../pos/format';
import { Choice, VanHeader } from './parts';

/**
 * VAN-17, VAN-14: collect a customer's outstanding balance in cash or mobile
 * money. The receipt number comes from the device's own range, like a sale's,
 * and the collection is saved on the device and queued. An amount above the
 * balance is allowed after a confirmation (money in is never refused).
 */
export function CollectScreen({ target, onBack }) {
  const { t } = useTranslation();
  const money = useMoneyText();
  const { user, actorProof } = useSession();
  const { database } = useServices();
  const { catalogue } = usePosData();
  const van = useVanSales();
  const [customer, setCustomer] = useState(null);
  const methods = useMemo(() => catalogue.paymentMethods.filter((method) => method.type === 'cash' || method.type === 'mobile_money'), [catalogue]);
  const [methodId, setMethodId] = useState(methods[0]?.id ?? null);
  const method = methods.find((candidate) => candidate.id === methodId) ?? null;
  const currencies = method?.currency ? [method.currency] : catalogue.cashCurrencies;
  const [currencyChoice, setCurrency] = useState(null);
  const currency = currencies.includes(currencyChoice) ? currencyChoice : currencies[0] ?? catalogue.saleCurrency;
  const [amountText, setAmountText] = useState('');
  const [reference, setReference] = useState('');
  const [position, setPosition] = useState(null);
  const [confirmAbove, setConfirmAbove] = useState(false);
  const [message, setMessage] = useState(null);
  const [busy, setBusy] = useState(false);
  const saving = useRef(false);

  useEffect(() => {
    let active = true;
    database.get('customers').find(target.customerId).then((record) => active && setCustomer(record.data), () => {});
    return () => {
      active = false;
    };
  }, [database, target.customerId]);

  useEffect(() => {
    let active = true;
    van.service.accountPosition(target.customerId, currency).then((found) => active && setPosition(found));
    return () => {
      active = false;
    };
  }, [van.service, target.customerId, currency]);

  const balance = position?.account ? balanceIn(position.account, currency) + BigInt(position.unsynced.creditMinor) - BigInt(position.unsynced.collectedMinor) : 0n;
  const amount = parseAmount(amountText, decimalsOf(currency));
  const above = amount !== null && BigInt(amount) > (balance > 0n ? balance : 0n);

  async function save() {
    if (saving.current || !amount || !method || !van.run) return;
    saving.current = true;
    setBusy(true);
    setConfirmAbove(false);
    try {
      const collection = await van.service.collect({
        run: van.run,
        visit: van.visits.find((visit) => visit.id === target.visitId) ?? null,
        customer: customer ?? { id: target.customerId, name: '' },
        method,
        currency,
        amountMinor: amount,
        reference: reference.trim().toUpperCase(),
        user,
        actorProof,
        timeZone: catalogue.timeZone,
      });
      setAmountText('');
      setReference('');
      setMessage({ tone: 'success', text: t('vansales.collect.saved', { number: collection.receipt_number, amount: money(collection.amount_minor, currency) }) });
    } catch (problem) {
      setMessage({ tone: 'danger', text: t(`vansales.errors.${problem?.code ?? 'failed'}`, { defaultValue: t('vansales.errors.failed') }) });
    } finally {
      saving.current = false;
      setBusy(false);
    }
  }

  return (
    <SafeAreaView className="flex-1 bg-surface-100">
      <VanHeader title={t('vansales.collect.title')} subtitle={customer?.name} onBack={onBack} backLabel={t('vansales.stop.back')} />
      <ScrollView contentContainerClassName="gap-4 p-4 md:px-6" keyboardShouldPersistTaps="handled">
        {message ? <Alert tone={message.tone}>{message.text}</Alert> : null}
        {!van.run ? <Alert tone="warning">{t('vansales.stop.noRun')}</Alert> : null}
        <View className="rounded-lg border border-border bg-surface-200 p-4">
          <Text className="font-sans text-body text-ink-muted">{t('vansales.collect.balance')}</Text>
          <Text className="font-sans text-amount tabular-nums text-ink">{money(String(balance > 0n ? balance : 0n), currency)}</Text>
        </View>
        <Choice options={methods.map((candidate) => ({ value: candidate.id, label: candidate.name }))} value={methodId} onChange={setMethodId} label={t('vansales.collect.method')} />
        {currencies.length > 1 ? <Choice options={currencies.map((code) => ({ value: code, label: code }))} value={currency} onChange={setCurrency} label={t('vansales.collect.currency')} /> : null}
        <TextField label={t('vansales.collect.amount')} prefix={currency} keyboardType="decimal-pad" value={amountText} onChangeText={setAmountText} error={amountText && amount === null ? t('vansales.collect.amountInvalid') : undefined} />
        {method?.type === 'mobile_money' ? <TextField label={t('vansales.collect.reference')} help={t('vansales.collect.referenceHelp')} autoCapitalize="characters" value={reference} onChangeText={setReference} /> : null}
        <Button
          variant="primary"
          loading={busy}
          disabled={!amount || !method || !van.run || !canVan(user, VAN.collectionRecord)}
          onPress={() => (above ? setConfirmAbove(true) : save())}
        >
          {amount ? t('vansales.collect.save', { amount: money(amount, currency) }) : t('vansales.collect.saveEmpty')}
        </Button>
      </ScrollView>
      <Dialog
        open={confirmAbove}
        title={t('vansales.collect.aboveTitle')}
        onClose={() => setConfirmAbove(false)}
        footer={
          <>
            <Button variant="ghost" onPress={() => setConfirmAbove(false)}>
              {t('common.cancel')}
            </Button>
            <Button variant="primary" onPress={save}>
              {t('vansales.collect.aboveConfirm')}
            </Button>
          </>
        }
      >
        <Text className="font-sans text-body-lg text-ink-muted">{t('vansales.collect.aboveBody', { balance: money(String(balance > 0n ? balance : 0n), currency) })}</Text>
      </Dialog>
    </SafeAreaView>
  );
}
