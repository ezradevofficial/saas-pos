import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Pressable, Text, View } from 'react-native';
import { useSession } from '../../auth/session';
import { Alert } from '../../components/ds/Alert';
import { Button } from '../../components/ds/Button';
import { Dialog } from '../../components/ds/Dialog';
import { TextField } from '../../components/ds/TextField';
import { cn } from '../../lib/cn';
import { FOCUS_RING } from '../../lib/focus';
import { useLocale } from '../../lib/useLocale';
import { searchCustomers } from '../../pos/catalogue';
import { maskPhone, parseAmount, parseQuantity } from '../../pos/input';
import { usePosActions, usePosCart, usePosData } from '../../pos/PosProvider';
import { extend } from '../../pos/tax';
import { useServices } from '../../services/services';
import { formatTime, useMoneyText } from './format';

const ListRow = ({ onPress, children, label }) => (
  <Pressable accessibilityRole="button" accessibilityLabel={label} onPress={onPress} className={cn('min-h-12 flex-row items-center gap-3 border-b border-border px-4 py-3 hover:bg-surface-300 active:bg-surface-300', FOCUS_RING)}>
    {children}
  </Pressable>
);

/** POS-07: quantity, line discount and price of one line, or remove it. */
export function LineDialog({ lineId, onClose }) {
  const { t } = useTranslation();
  const money = useMoneyText();
  const { catalogue } = usePosData();
  const { cart } = usePosCart();
  const actions = usePosActions();
  const line = cart.lines.find((entry) => entry.id === lineId);
  const currency = catalogue?.saleCurrency;
  const decimals = currency ? catalogue.money.decimals(currency) : 2;
  const [qtyText, setQtyText] = useState('');
  const [discountText, setDiscountText] = useState('');
  const [priceText, setPriceText] = useState('');
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (!line) return;
    setQtyText(line.qty);
    setDiscountText(line.discountMinor === '0' ? '' : money(line.discountMinor, currency).replace(`${currency} `, ''));
    setPriceText(money(line.unitPriceMinor, currency).replace(`${currency} `, ''));
    setError(null);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lineId]);

  if (!line) return null;

  async function save() {
    setError(null);
    const qty = parseQuantity(qtyText);
    if (!qty) {
      setError(t('pos.line.errors.qty'));
      return;
    }
    const price = parseAmount(priceText, decimals);
    if (price === null) {
      setError(t('pos.line.errors.price'));
      return;
    }
    const discount = discountText.trim() ? parseAmount(discountText, decimals) : '0';
    if (discount === null || BigInt(discount) > extend(price, qty)) {
      setError(t('pos.line.errors.discount'));
      return;
    }
    setBusy(true);
    try {
      // One edit from the new values: the discount limit is checked on the new quantity and price.
      const result = await actions.editLine(line.id, { qty, unitPriceMinor: price, discountMinor: discount });
      if (!result.ok) {
        setError(result.reason === 'discount_above_price' ? t('pos.line.errors.discount') : result.reason === 'payment_locked' ? t('pos.sale.paymentLocked') : t('pos.override.cancelled'));
        return;
      }
      onClose();
    } finally {
      setBusy(false);
    }
  }

  return (
    <Dialog
      open
      title={line.name}
      onClose={onClose}
      footer={
        <>
          <Button
            variant="danger"
            onPress={() => {
              if (actions.remove(line.id)) setError(t('pos.sale.paymentLocked'));
              else onClose();
            }}
          >
            {t('pos.line.remove')}
          </Button>
          <Button variant="primary" loading={busy} onPress={save}>
            {t('pos.line.save')}
          </Button>
        </>
      }
    >
      <TextField label={t('pos.line.qty')} keyboardType="decimal-pad" value={qtyText} onChangeText={setQtyText} />
      <TextField label={t('pos.line.price')} prefix={currency} keyboardType="decimal-pad" value={priceText} onChangeText={setPriceText} help={line.listPriceMinor ? t('pos.line.listPrice', { amount: money(line.listPriceMinor, currency) }) : undefined} />
      <TextField label={t('pos.line.discount')} prefix={currency} keyboardType="decimal-pad" value={discountText} onChangeText={setDiscountText} placeholder="0" help={t('pos.line.discountHelp')} />
      {error ? <Alert tone="warning">{error}</Alert> : null}
    </Dialog>
  );
}

/** POS-08: name a customer (synced customers, by name or phone). */
export function CustomerDialog({ onClose }) {
  const { t } = useTranslation();
  const { database } = useServices();
  const { cart } = usePosCart();
  const actions = usePosActions();
  const [query, setQuery] = useState('');
  const [results, setResults] = useState([]);
  const [error, setError] = useState(null);
  // POS-03: a sale with mobile money sent keeps its customer (setCustomer resolves 'payment_locked').
  const choose = (customer) => {
    if (actions.setCustomer(customer)) setError(t('pos.sale.paymentLocked'));
    else onClose();
  };

  useEffect(() => {
    let active = true;
    searchCustomers(database, query).then((rows) => active && setResults(rows));
    return () => {
      active = false;
    };
  }, [database, query]);

  return (
    <Dialog
      open
      title={t('pos.customer.title')}
      onClose={onClose}
      footer={
        cart.customer ? (
          <Button variant="secondary" onPress={() => choose(null)}>
            {t('pos.customer.remove')}
          </Button>
        ) : null
      }
    >
      <TextField label={t('pos.customer.search')} value={query} onChangeText={setQuery} autoCorrect={false} />
      <View className="rounded-md border border-border">
        {results.length ? (
          results.map((customer) => (
            <ListRow key={customer.id} onPress={() => choose(customer)}>
              <View className="min-w-0 flex-1">
                <Text className="font-sans text-body-lg text-ink">{customer.name}</Text>
                {customer.phones?.[0]?.number ? <Text className="font-sans text-caption text-ink-muted">{maskPhone(customer.phones[0].number)}</Text> : null}
              </View>
            </ListRow>
          ))
        ) : (
          <Text className="p-4 text-center font-sans text-body text-ink-muted">{t('pos.customer.none')}</Text>
        )}
      </View>
      {error ? <Alert tone="warning">{error}</Alert> : null}
    </Dialog>
  );
}

/** POS-02: parked sales on this till; resume one (the current sale is parked in its place). */
export function HeldDialog({ onClose }) {
  const { t } = useTranslation();
  const locale = useLocale();
  const { held, catalogue } = usePosData();
  const actions = usePosActions();
  const [error, setError] = useState(null);
  return (
    <Dialog open title={t('pos.held.title')} onClose={onClose}>
      {error ? <Alert tone="warning">{error}</Alert> : null}
      {held.length ? (
        <View className="rounded-md border border-border">
          {held.map((entry) => (
            <View key={entry.id} className="flex-row items-center gap-3 border-b border-border px-4 py-3">
              <View className="min-w-0 flex-1">
                <Text className="font-sans text-body-lg text-ink">{entry.customer?.name ?? t('pos.held.noCustomer')}</Text>
                <Text className="font-sans text-caption text-ink-muted">{t('pos.held.summary', { count: entry.lines.length, time: formatTime(entry.heldAt, catalogue?.timeZone, locale) })}</Text>
              </View>
              <Button variant="ghost" onPress={() => actions.discard(entry.id)}>
                {t('pos.held.discard')}
              </Button>
              <Button variant="secondary" onPress={async () => {
                  if (await actions.resume(entry.id)) setError(t('pos.sale.mobilePaid'));
                  else onClose();
                }}>
                {t('pos.held.resume')}
              </Button>
            </View>
          ))}
        </View>
      ) : (
        <Text className="font-sans text-body-lg text-ink-muted">{t('pos.held.none')}</Text>
      )}
    </Dialog>
  );
}

/** POS-04: pay cash in or out of the drawer, with a reason (a pay-out needs pos.cash.move or an override). */
export function CashDialog({ onClose }) {
  const { t } = useTranslation();
  const money = useMoneyText();
  const { catalogue } = usePosData();
  const actions = usePosActions();
  const currencies = catalogue?.cashCurrencies ?? [];
  const [kind, setKind] = useState('pay_out');
  const [currency, setCurrency] = useState(currencies[0] ?? null);
  const [amountText, setAmountText] = useState('');
  const [reason, setReason] = useState('');
  const [message, setMessage] = useState(null);
  const [busy, setBusy] = useState(false);
  // POS-04, NFR-04: one movement at a time; a second press before the first resolves records nothing.
  const recording = useRef(false);

  async function record() {
    if (recording.current) return;
    const amount = currency ? parseAmount(amountText, catalogue.money.decimals(currency)) : null;
    if (!amount || amount === '0' || !reason.trim()) {
      setMessage({ tone: 'warning', text: t('pos.cash.errors.incomplete') });
      return;
    }
    recording.current = true;
    setBusy(true);
    try {
      const movement = await actions.cashMovement({ kind, currency, amountMinor: amount, reason: reason.trim() });
      if (!movement) {
        setMessage({ tone: 'warning', text: t('pos.override.cancelled') });
        return;
      }
      setMessage({ tone: 'success', text: t(`pos.cash.recorded.${kind}`, { amount: money(amount, currency) }) });
      setAmountText('');
      setReason('');
    } catch {
      setMessage({ tone: 'danger', text: t('pos.cash.errors.failed') });
    } finally {
      recording.current = false;
      setBusy(false);
    }
  }

  return (
    <Dialog
      open
      title={t('pos.cash.title')}
      onClose={onClose}
      footer={
        <Button variant="primary" loading={busy} onPress={record}>
          {t(`pos.cash.record.${kind}`)}
        </Button>
      }
    >
      <View className="flex-row gap-2">
        {['pay_in', 'pay_out'].map((value) => (
          <Button key={value} variant={kind === value ? 'primary' : 'secondary'} accessibilityState={{ selected: kind === value }} onPress={() => setKind(value)}>
            {t(`pos.cash.kind.${value}`)}
          </Button>
        ))}
      </View>
      {currencies.length > 1 ? (
        <View className="flex-row flex-wrap gap-2">
          {currencies.map((code) => (
            <Button key={code} variant={currency === code ? 'primary' : 'secondary'} accessibilityState={{ selected: currency === code }} onPress={() => setCurrency(code)}>
              {code}
            </Button>
          ))}
        </View>
      ) : null}
      <TextField label={t('pos.cash.amount')} prefix={currency ?? undefined} keyboardType="decimal-pad" value={amountText} onChangeText={setAmountText} />
      <TextField label={t('pos.cash.reason')} value={reason} onChangeText={setReason} maxLength={500} />
      {message ? <Alert tone={message.tone}>{message.text}</Alert> : null}
    </Dialog>
  );
}

/** The till's menu: sales and returns, cash, held sales and closing (phone), switch user, sync. */
export function MenuDialog({ onClose, onNavigate, compact }) {
  const { t } = useTranslation();
  const { scheduler } = useServices();
  const { switchUser } = useSession();
  const { held, shift } = usePosData();
  const go = (route) => {
    onClose();
    onNavigate(route);
  };
  const items = [
    shift && ['sales', t('pos.menu.sales')],
    shift && ['cash', t('pos.menu.cash')],
    shift && compact && ['held', t('pos.header.held', { count: held.length })],
    shift && compact && ['close', t('pos.header.closeShift')],
  ].filter(Boolean);
  return (
    <Dialog open title={t('pos.menu.title')} onClose={onClose}>
      <View className="rounded-md border border-border">
        {items.map(([route, label]) => (
          <ListRow key={route} onPress={() => go(route)}>
            <Text className="font-sans text-body-lg text-ink">{label}</Text>
          </ListRow>
        ))}
        <ListRow onPress={() => { onClose(); scheduler.syncNow(); }}>
          <Text className="font-sans text-body-lg text-ink">{t('common.syncNow')}</Text>
        </ListRow>
        <ListRow onPress={() => { onClose(); switchUser(); }}>
          <Text className="font-sans text-body-lg text-ink">{t('home.switchUser')}</Text>
        </ListRow>
      </View>
    </Dialog>
  );
}
