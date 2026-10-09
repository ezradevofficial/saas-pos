import { memo, useCallback, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FlatList, Pressable, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useSession } from '../../auth/session';
import { Alert } from '../../components/ds/Alert';
import { Button } from '../../components/ds/Button';
import { Money } from '../../components/ds/Money';
import { PosTile } from '../../components/ds/PosTile';
import { SaleTotal } from '../../components/ds/SaleTotal';
import { StatusBadge } from '../../components/ds/StatusBadge';
import { SyncStatus } from '../../components/ds/SyncStatus';
import { TextField } from '../../components/ds/TextField';
import { cn } from '../../lib/cn';
import { FOCUS_RING } from '../../lib/focus';
import { useLocale } from '../../lib/useLocale';
import { filterTiles } from '../../pos/catalogue';
import { initials, maskPhone } from '../../pos/input';
import { usePosActions, usePosCart, usePosData } from '../../pos/PosProvider';
import { amountDueIn } from '../../pos/tender';
import { useSyncStatus } from '../../sync/useSyncStatus';
import { CAMERA_SCANNING, CameraScanner } from './CameraScanner';
import { formatTime, useMoneyText } from './format';

/** CUR-05: the sale total in the second currency at the current shop rate (asked rounded up), or undefined. */
export function useDualTotal(totalMinor) {
  const { catalogue } = usePosData();
  return useMemo(() => {
    if (!catalogue?.dualCurrency || !totalMinor || BigInt(totalMinor) === 0n) return undefined;
    try {
      return { amount: amountDueIn({ remaining: totalMinor, from: catalogue.saleCurrency, currency: catalogue.dualCurrency, money: catalogue.money }), currency: catalogue.dualCurrency };
    } catch {
      return undefined;
    }
  }, [catalogue, totalMinor]);
}

/** The offline note of PosPhone: sales are kept and sent to the tax authority on reconnect (POS-10). */
export function OfflineNote({ className }) {
  const { t } = useTranslation();
  const { catalogue } = usePosData();
  const sync = useSyncStatus();
  if (sync.state !== 'offline') return null;
  const country = catalogue?.settings?.company?.country;
  const key = country === 'KE' ? 'pos.offline.noteKe' : country === 'CD' ? 'pos.offline.noteCd' : 'pos.offline.note';
  return <Text className={cn('text-center font-sans text-caption text-ink-muted', className)}>{t(key)}</Text>;
}

function useReasonText() {
  const { t } = useTranslation();
  return useCallback(
    (reason) => {
      if (!reason) return null;
      // POS-03: mobile money was sent for the sale; its lines cannot change until it completes.
      if (reason === 'payment_locked') return t('pos.sale.paymentLocked');
      return t(`pos.unsellable.${reason}`, { defaultValue: t('pos.unsellable.not_sellable') });
    },
    [t],
  );
}

/** The till's top bar (Main): app name, where and who, sync state, held sales, close shift, menu. */
export function PosHeader({ compact, onHeld, onCloseShift, onMenu }) {
  const { t } = useTranslation();
  const locale = useLocale();
  const { user } = useSession();
  const { catalogue, shift, held } = usePosData();
  const sync = useSyncStatus();
  const settings = catalogue?.settings;
  const place = [settings?.location?.name, settings?.device?.name].filter(Boolean).join(' · ');
  const opened = shift ? t('pos.header.shiftOpened', { time: formatTime(shift.opened_at, catalogue?.timeZone, locale), name: user?.name ?? '' }) : null;

  if (compact) {
    return (
      <View className="gap-2 border-b border-border bg-surface-200 px-4 pb-3 pt-4">
        <View className="flex-row items-center justify-between gap-3">
          <View className="min-w-0 flex-1">
            <Text accessibilityRole="header" numberOfLines={1} className="font-sans text-h3 font-semibold text-ink">
              {settings?.company?.name ?? t('app.name')}
            </Text>
            <Text numberOfLines={1} className="font-sans text-caption text-ink-muted">
              {place}
            </Text>
          </View>
          <Button variant="secondary" onPress={onMenu} accessibilityLabel={t('pos.menu.open')}>
            {t('pos.menu.title')}
          </Button>
        </View>
        <SyncStatus state={sync.state} pending={sync.pending} />
      </View>
    );
  }

  return (
    <View className="flex-row items-center gap-4 border-b border-border bg-surface-200 px-6 py-2">
      <Text accessibilityRole="header" className="font-sans text-h3 font-semibold text-primary">
        {t('app.name')}
      </Text>
      <View className="h-6 w-px bg-border" />
      <View className="min-w-0 flex-1">
        <Text numberOfLines={1} className="font-sans text-body font-medium text-ink">
          {place}
        </Text>
        {opened ? <Text className="font-sans text-caption text-ink-muted">{opened}</Text> : null}
      </View>
      <SyncStatus state={sync.state} pending={sync.pending} />
      <Button variant="secondary" onPress={onHeld}>
        {t('pos.header.held', { count: held.length })}
      </Button>
      <Button variant="secondary" onPress={onCloseShift}>
        {t('pos.header.closeShift')}
      </Button>
      <Button variant="ghost" onPress={onMenu} accessibilityLabel={t('pos.menu.open')}>
        {t('pos.menu.title')}
      </Button>
    </View>
  );
}

const Chip = memo(function Chip({ label, selected, onPress }) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ selected }}
      aria-pressed={selected}
      onPress={onPress}
      className={cn(
        'h-12 justify-center rounded-pill border px-5',
        selected ? 'border-primary bg-primary' : 'border-border-strong bg-surface-200 hover:bg-surface-300 active:bg-surface-300',
        FOCUS_RING,
      )}
    >
      <Text className={cn('font-sans text-body font-medium', selected ? 'text-on-primary' : 'text-ink')}>{label}</Text>
    </Pressable>
  );
});

const TileCell = memo(function TileCell({ tile, reasonText, onAdd }) {
  const select = useCallback(() => onAdd(tile.id), [onAdd, tile.id]);
  return (
    <View className="flex-1 p-1">
      <PosTile name={tile.name} price={tile.price} currency={tile.currency} unavailable={tile.sellable ? undefined : reasonText(tile.reason)} onSelect={select} className="flex-1" />
    </View>
  );
});

/** Search or scan, category chips and the product grid (POS-01). */
export function CatalogueArea({ columns, onNotice }) {
  const { t } = useTranslation();
  const { catalogue } = usePosData();
  const actions = usePosActions();
  const reasonText = useReasonText();
  const [query, setQuery] = useState('');
  const [categoryId, setCategoryId] = useState(null);
  const [scanning, setScanning] = useState(false);

  const tiles = useMemo(() => (catalogue ? filterTiles(catalogue.tiles, { categoryId, query }) : []), [catalogue, categoryId, query]);

  const add = useCallback(
    (itemId) => {
      const problem = actions.addItem(itemId);
      onNotice(problem ? reasonText(problem) : null);
    },
    [actions, onNotice, reasonText],
  );

  // A hardware scanner types the code and presses Enter; so does a person typing a code.
  const submit = useCallback(() => {
    if (!query.trim()) return;
    const problem = actions.addByCode(query);
    if (!problem) {
      setQuery('');
      onNotice(null);
    } else if (problem === 'code_unknown') {
      if (!tiles.length) onNotice(t('pos.search.unknown', { code: query.trim() }));
    } else {
      setQuery('');
      onNotice(reasonText(problem));
    }
  }, [actions, onNotice, query, reasonText, t, tiles.length]);

  const renderItem = useCallback(({ item }) => <TileCell tile={item} reasonText={reasonText} onAdd={add} />, [add, reasonText]);

  return (
    <View className="min-h-0 flex-1 gap-4">
      <View className="flex-row items-center gap-2">
        <TextField
          className="flex-1"
          accessibilityLabel={t('pos.search.label')}
          placeholder={t('pos.search.placeholder')}
          value={query}
          onChangeText={setQuery}
          onSubmitEditing={submit}
          returnKeyType="search"
          autoCorrect={false}
          autoCapitalize="none"
          blurOnSubmit={false}
        />
        {CAMERA_SCANNING ? (
          <Button variant="secondary" accessibilityLabel={t('pos.scan.open')} onPress={() => setScanning(true)}>
            {t('pos.scan.button')}
          </Button>
        ) : null}
      </View>
      {scanning ? <CameraScanner onScanned={(code) => actions.addByCode(code)} onClose={() => setScanning(false)} /> : null}
      {catalogue?.categories.length ? (
        <ScrollView horizontal showsHorizontalScrollIndicator={false} className="flex-grow-0" contentContainerClassName="gap-2">
          <Chip label={t('pos.search.all')} selected={!categoryId} onPress={() => setCategoryId(null)} />
          {catalogue.categories.map((category) => (
            <Chip key={category.id} label={category.name} selected={categoryId === category.id} onPress={() => setCategoryId(category.id)} />
          ))}
        </ScrollView>
      ) : null}
      <FlatList
        key={`grid-${columns}`}
        data={tiles}
        numColumns={columns}
        keyExtractor={(tile) => tile.id}
        renderItem={renderItem}
        initialNumToRender={24}
        windowSize={7}
        removeClippedSubviews
        className="flex-1"
        contentContainerClassName="pb-4"
        ListEmptyComponent={<Text className="p-6 text-center font-sans text-body-lg text-ink-muted">{catalogue?.tiles.length ? t('pos.search.none') : t('pos.search.emptyCatalogue')}</Text>}
      />
    </View>
  );
}

const SaleLineRow = memo(function SaleLineRow({ line, money, onStep, onOpen, labels }) {
  const blocked = line.amounts.blocked;
  const whole = /^\d+$/.test(line.qty);
  const unit = `${line.qty} × ${money(line.unitPriceMinor, line.currency)}${line.uomCode ? ` · ${line.uomCode}` : ''}`;
  return (
    <View className="flex-row items-center gap-3 border-b border-border px-4 py-2">
      <Pressable accessibilityRole="button" accessibilityLabel={labels.edit(line.name)} onPress={() => onOpen(line.id)} className={cn('min-h-12 min-w-0 flex-1 justify-center', FOCUS_RING)}>
        <Text numberOfLines={2} className="font-sans text-body-lg font-medium text-ink">
          {line.name}
        </Text>
        <Text className="font-sans text-caption tabular-nums text-ink-muted">{unit}</Text>
        {line.discountMinor !== '0' ? <Text className="font-sans text-caption tabular-nums text-accent-ink">{labels.discount(money(line.discountMinor, line.currency))}</Text> : null}
        {blocked ? <StatusBadge tone="danger">{labels.blocked(blocked)}</StatusBadge> : null}
      </Pressable>
      {/* The total sits over the stepper so a narrow sale panel still leaves room for the name. */}
      <View className="items-end gap-1">
        <Text className="font-sans text-body font-medium tabular-nums text-ink">{blocked ? '—' : money(line.amounts.totalMinor, line.currency)}</Text>
        <View className="flex-row items-center gap-1">
          <Button variant="secondary" className="w-12 px-0" accessibilityLabel={labels.less} onPress={() => onStep(line.id, -1)} disabled={!whole}>
            −
          </Button>
          <Text className="w-10 text-center font-sans text-body-lg font-medium tabular-nums text-ink">{line.qty}</Text>
          <Button variant="secondary" className="w-12 px-0" accessibilityLabel={labels.more} onPress={() => onStep(line.id, 1)} disabled={!whole}>
            +
          </Button>
        </View>
      </View>
    </View>
  );
});

/** The current sale (Main's aside, or the phone's sale view). */
export function SalePanel({ onPay, onCustomer, onLine, className }) {
  const { t } = useTranslation();
  const money = useMoneyText();
  const { catalogue, nextReceipt } = usePosData();
  const { cart, computed } = usePosCart();
  const actions = usePosActions();
  const reasonText = useReasonText();
  const currency = catalogue?.saleCurrency;
  const dual = useDualTotal(computed?.totals.total_minor);

  const labels = useMemo(
    () => ({
      edit: (name) => t('pos.sale.editLine', { name }),
      less: t('pos.sale.less'),
      more: t('pos.sale.more'),
      discount: (amount) => t('pos.sale.lineDiscount', { amount }),
      blocked: (reason) => reasonText(reason),
    }),
    [reasonText, t],
  );
  const lines = useMemo(() => (computed?.lines ?? []).map((line) => ({ ...line, currency })), [computed, currency]);
  const [stepNotice, setStepNotice] = useState(null);
  // POS-07: a quantity change re-checks the discount; one the person may no longer give is cleared, and said so.
  const onStep = useCallback(async (id, delta) => setStepNotice(await actions.step(id, delta)), [actions]);
  const renderLine = useCallback(({ item }) => <SaleLineRow line={item} money={money} labels={labels} onStep={onStep} onOpen={onLine} />, [labels, money, onLine, onStep]);

  const count = computed?.itemCount ?? 0;
  const blocked = computed?.blocked.length > 0;

  return (
    <View className={cn('min-h-0 flex-1 gap-3', className)}>
      <View className="min-h-0 flex-1 rounded-lg border border-border bg-surface-200">
        <View className="flex-row items-center justify-between gap-3 border-b border-border px-4 pb-3 pt-4">
          <View className="min-w-0 flex-1">
            <Text accessibilityRole="header" className="font-sans text-body-lg font-semibold text-ink">
              {t('pos.sale.title')}
            </Text>
            <Text className="font-sans text-caption text-ink-muted">
              {nextReceipt ? t('pos.sale.summary', { count, receipt: nextReceipt }) : t('pos.sale.summaryNoNumber', { count })}
            </Text>
          </View>
          <Button variant="ghost" onPress={() => actions.hold()} disabled={!cart.lines.length}>
            {t('pos.sale.hold')}
          </Button>
          <Button variant="danger" onPress={actions.clear} disabled={!cart.lines.length} className="border-transparent">
            {t('pos.sale.clear')}
          </Button>
        </View>
        <Pressable
          accessibilityRole="button"
          onPress={onCustomer}
          className={cn('min-h-12 flex-row items-center gap-3 border-b border-border px-4 py-3', cart.customer ? 'bg-primary-tint' : 'hover:bg-surface-300 active:bg-surface-300', FOCUS_RING)}
        >
          {cart.customer ? (
            <>
              <View className="h-10 w-10 items-center justify-center rounded-pill bg-primary">
                <Text className="font-sans text-body font-semibold text-on-primary">{initials(cart.customer.name)}</Text>
              </View>
              <View className="min-w-0 flex-1">
                <Text numberOfLines={1} className="font-sans text-body-lg font-medium text-ink">
                  {cart.customer.name}
                </Text>
                {cart.customer.phone ? <Text className="font-sans text-caption text-ink-muted">{maskPhone(cart.customer.phone)}</Text> : null}
              </View>
              <Text className="font-sans text-caption text-ink-muted">{t('pos.sale.changeCustomer')}</Text>
            </>
          ) : (
            <Text className="font-sans text-body-lg text-primary">{t('pos.sale.addCustomer')}</Text>
          )}
        </Pressable>
        <FlatList
          data={lines}
          keyExtractor={(line) => line.id}
          renderItem={renderLine}
          className="flex-1"
          ListEmptyComponent={<Text className="px-6 py-12 text-center font-sans text-body-lg text-ink-muted">{t('pos.sale.empty')}</Text>}
        />
      </View>
      {blocked ? <Alert tone="danger">{t('pos.sale.blocked')}</Alert> : null}
      {stepNotice ? <Alert tone="warning">{stepNotice === 'payment_locked' ? t('pos.sale.paymentLocked') : t('pos.sale.discountCleared')}</Alert> : null}
      {currency ? (
        <SaleTotal
          currency={currency}
          subtotal={computed?.totals.subtotal_minor ?? '0'}
          discount={computed?.totals.discount_minor ?? '0'}
          tax={computed?.totals.tax_minor ?? '0'}
          total={computed?.totals.total_minor ?? '0'}
          secondary={dual}
          labels={{ tax: t('pos.sale.tax') }}
          disabled={blocked}
          onPay={onPay}
        />
      ) : null}
      <OfflineNote />
    </View>
  );
}

/** Phone (PosPhone): the collapsed sale bar under the grid. */
export function PhoneSaleBar({ onOpen, onPay }) {
  const { t } = useTranslation();
  const money = useMoneyText();
  const { catalogue } = usePosData();
  const { computed } = usePosCart();
  const total = computed?.totals.total_minor ?? '0';
  const currency = catalogue?.saleCurrency ?? '';
  const blocked = computed?.blocked.length > 0;
  return (
    <View className="gap-3 rounded-t-lg border-t border-border bg-surface-200 p-4 shadow-lg">
      <Pressable accessibilityRole="button" onPress={onOpen} className={cn('min-h-12 flex-row items-center justify-between gap-3', FOCUS_RING)}>
        <View>
          <Text className="font-sans text-body-lg font-medium text-ink">{t('pos.phone.items', { count: computed?.itemCount ?? 0 })}</Text>
          <Text className="font-sans text-caption text-ink-muted">{t('pos.phone.tapToSee')}</Text>
        </View>
        <Money amount={total} currency={currency} size="lg" />
      </Pressable>
      <Button variant="pay" block onPress={onPay} disabled={blocked || BigInt(total) === 0n}>
        {t('ds.saleTotal.pay', { amount: money(total, currency) })}
      </Button>
      <OfflineNote />
    </View>
  );
}

/** POS-01: the selling screen, tablet (Main) or phone (PosPhone) by width. */
export function SellScreen({ tablet, onPay, onOpenCart, onCustomer, onLine, onHeld, onCloseShift, onMenu }) {
  const [notice, setNotice] = useState(null);
  return (
    <SafeAreaView className="flex-1">
      <PosHeader compact={!tablet} onHeld={onHeld} onCloseShift={onCloseShift} onMenu={onMenu} />
      {tablet ? (
        <View className="min-h-0 flex-1 flex-row gap-6 px-6 py-5">
          <View className="min-w-0 flex-1 gap-3">
            {notice ? <Alert tone="warning">{notice}</Alert> : null}
            <CatalogueArea columns={4} onNotice={setNotice} />
          </View>
          <View className="w-1/3">
            <SalePanel onPay={onPay} onCustomer={onCustomer} onLine={onLine} />
          </View>
        </View>
      ) : (
        <>
          <View className="min-h-0 flex-1 gap-3 px-4 pt-3">
            {notice ? <Alert tone="warning">{notice}</Alert> : null}
            <CatalogueArea columns={2} onNotice={setNotice} />
          </View>
          <PhoneSaleBar onOpen={onOpenCart} onPay={onPay} />
        </>
      )}
    </SafeAreaView>
  );
}

/** Phone: the whole sale, with a way back to the products. */
export function PhoneCartScreen({ onBack, onPay, onCustomer, onLine }) {
  const { t } = useTranslation();
  return (
    <SafeAreaView className="flex-1">
      <View className="flex-row items-center gap-3 border-b border-border bg-surface-200 px-4 py-2">
        <Button variant="ghost" onPress={onBack}>
          {t('pos.phone.backToProducts')}
        </Button>
      </View>
      <View className="min-h-0 flex-1 p-4">
        <SalePanel onPay={onPay} onCustomer={onCustomer} onLine={onLine} />
      </View>
    </SafeAreaView>
  );
}
