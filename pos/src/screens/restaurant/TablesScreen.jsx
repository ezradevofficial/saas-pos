import { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FlatList, Pressable, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useSession } from '../../auth/session';
import { Alert } from '../../components/ds/Alert';
import { Button } from '../../components/ds/Button';
import { Dialog } from '../../components/ds/Dialog';
import { StatusBadge } from '../../components/ds/StatusBadge';
import { TextField } from '../../components/ds/TextField';
import { cn } from '../../lib/cn';
import { FOCUS_RING } from '../../lib/focus';
import { searchCustomers } from '../../pos/catalogue';
import { usePosData } from '../../pos/PosProvider';
import { rounds, tableStatus } from '../../restaurant/orderModel';
import { can, REST } from '../../restaurant/permissions';
import { useRestaurant } from '../../restaurant/context';
import { useServices } from '../../services/services';
import { useMoneyText } from '../pos/format';
import { PosHeader } from '../pos/SellScreen';
import { orderBill, TABLE_TONE } from './floorHelpers';
import { Segmented, Stepper } from './parts';

const SHAPE = { square: 'rounded-md', rect: 'rounded-md', round: 'rounded-lg' };

/** One table on the floor: its name, seats, status as a dot and a word, and who has it. */
function TableTile({ table, status, order, total, currency, money, onPress, t }) {
  const label = [table.name, t(`restaurant.status.${status}`), order ? `${order.local.waiter_name ?? ''} ${total ? money(total, currency) : ''}`.trim() : t('restaurant.table.seats', { count: table.seats })].filter(Boolean).join(', ');
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ disabled: status === 'blocked' }}
      disabled={status === 'blocked'}
      onPress={onPress}
      className={cn('min-h-24 w-40 justify-between gap-1 border border-border bg-surface-200 p-3 hover:border-border-strong active:bg-surface-300', SHAPE[table.shape] ?? SHAPE.square, status === 'blocked' && 'opacity-50', FOCUS_RING)}
    >
      <View className="flex-row items-center justify-between gap-2">
        <Text numberOfLines={1} className="min-w-0 flex-1 font-sans text-body-lg font-medium text-ink">
          {table.name}
        </Text>
        <Text className="font-sans text-caption tabular-nums text-ink-muted">{t('restaurant.table.seats', { count: table.seats })}</Text>
      </View>
      <StatusBadge tone={TABLE_TONE[status] ?? 'neutral'}>{t(`restaurant.status.${status}`)}</StatusBadge>
      {order ? (
        <View>
          <Text numberOfLines={1} className="font-sans text-caption text-ink-muted">
            {order.local.waiter_name ?? ''}
          </Text>
          <Text className="font-sans text-caption tabular-nums text-ink">{total ? money(total, currency) : ''}</Text>
          {order.local.paid?.length ? <Text className="font-sans text-caption text-ink-muted">{t('restaurant.table.partsPaid', { paid: order.local.paid.length })}</Text> : null}
        </View>
      ) : null}
    </Pressable>
  );
}

/** REST-03: how many guests, then the table is opened (or resumed). */
function OpenTableDialog({ table, onClose, onOpen }) {
  const { t } = useTranslation();
  const [covers, setCovers] = useState(Math.min(2, table.seats || 2));
  const [busy, setBusy] = useState(false);
  return (
    <Dialog
      open
      title={t('restaurant.open.tableTitle', { name: table.name })}
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
              await onOpen(covers);
            }}
          >
            {t('restaurant.open.table')}
          </Button>
        </>
      }
    >
      <Text className="font-sans text-body-lg text-ink-muted">{t('restaurant.open.guests')}</Text>
      <Stepper value={covers} min={1} max={99} onChange={setCovers} lessLabel={t('restaurant.open.fewer')} moreLabel={t('restaurant.open.more')} label={t('restaurant.open.guests')} />
    </Dialog>
  );
}

/** REST-04: a bar tab with a name, or a customer found by name or phone. */
function OpenTabDialog({ onClose, onOpen }) {
  const { t } = useTranslation();
  const { database } = useServices();
  const [name, setName] = useState('');
  const [query, setQuery] = useState('');
  const [found, setFound] = useState([]);
  const [customer, setCustomer] = useState(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let active = true;
    if (query.trim().length < 2) return undefined;
    searchCustomers(database, query, 5).then((rows) => active && setFound(rows));
    return () => {
      active = false;
    };
  }, [database, query]);

  const results = query.trim().length >= 2 ? found : [];
  const label = (name || customer?.name || '').trim();
  return (
    <Dialog
      open
      title={t('restaurant.tabs.openTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onPress={onClose}>
            {t('common.cancel')}
          </Button>
          <Button
            variant="primary"
            disabled={!label}
            loading={busy}
            onPress={async () => {
              setBusy(true);
              await onOpen({ name: label, customer });
            }}
          >
            {t('restaurant.tabs.open')}
          </Button>
        </>
      }
    >
      <TextField label={t('restaurant.tabs.name')} placeholder={t('restaurant.tabs.namePlaceholder')} value={name} onChangeText={setName} autoCapitalize="words" />
      <TextField label={t('restaurant.tabs.customer')} placeholder={t('restaurant.tabs.customerPlaceholder')} value={query} onChangeText={setQuery} autoCorrect={false} />
      {customer ? (
        <Alert tone="info">{t('restaurant.tabs.customerChosen', { name: customer.name })}</Alert>
      ) : (
        results.map((entry) => (
          <Pressable key={entry.id} accessibilityRole="button" onPress={() => { setCustomer(entry); setQuery(''); }} className={cn('min-h-12 justify-center rounded-md border border-border px-4 hover:bg-surface-300 active:bg-surface-300', FOCUS_RING)}>
            <Text className="font-sans text-body-lg text-ink">{entry.name}</Text>
          </Pressable>
        ))
      )}
    </Dialog>
  );
}

function TabRow({ order, total, currency, money, onPress, t }) {
  const count = rounds(order).length;
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={`${order.tab_name}, ${t('restaurant.tabs.rounds', { count })}, ${total ? money(total, currency) : ''}`}
      onPress={onPress}
      className={cn('min-h-12 flex-row items-center gap-3 border-b border-border px-4 py-3 hover:bg-surface-300 active:bg-surface-300', FOCUS_RING)}
    >
      <View className="min-w-0 flex-1">
        <Text numberOfLines={1} className="font-sans text-body-lg font-medium text-ink">
          {order.tab_name}
        </Text>
        <Text numberOfLines={1} className="font-sans text-caption text-ink-muted">
          {[t('restaurant.tabs.rounds', { count }), order.local.waiter_name].filter(Boolean).join(' · ')}
        </Text>
      </View>
      {order.bill_requested_at ? <StatusBadge tone="accent">{t('restaurant.status.bill')}</StatusBadge> : null}
      <Text className="font-sans text-body font-medium tabular-nums text-ink">{total ? money(total, currency) : ''}</Text>
    </Pressable>
  );
}

/**
 * REST-02..REST-04: the home screen of the restaurant and bar modes: the
 * floor by area with each table's status, a list of open tabs, and a filter
 * for the waiter's own. A tap opens or resumes the order.
 */
export function TablesScreen({ tablet, onOpenOrder, onQuickSale, onHeld, onCloseShift, onMenu }) {
  const { t } = useTranslation();
  const money = useMoneyText();
  const { user, actorProof } = useSession();
  const { catalogue } = usePosData();
  const floor = useRestaurant();
  const [view, setView] = useState(floor.mode === 'bar' ? 'tabs' : 'tables');
  const [mine, setMine] = useState(true);
  const [search, setSearch] = useState('');
  const [opening, setOpening] = useState(null);
  const [openingTab, setOpeningTab] = useState(false);
  const [notice, setNotice] = useState(null);

  const currency = catalogue?.saleCurrency;
  const canOpen = can(user, REST.create);
  const totals = useMemo(() => new Map(floor.orders.map((order) => [order.id, orderBill(order, floor.settings, catalogue)?.total_minor ?? null])), [floor.orders, floor.settings, catalogue]);

  const byTable = useMemo(() => {
    const map = new Map();
    for (const order of floor.orders) if (order.kind === 'table' && order.table_id) map.set(order.table_id, [...(map.get(order.table_id) ?? []), order]);
    return map;
  }, [floor.orders]);

  const areas = useMemo(() => {
    const groups = new Map();
    for (const table of floor.tables) {
      const open = byTable.get(table.id) ?? [];
      // The waiter filter keeps free tables and the waiter's own; other waiters' tables are hidden.
      if (mine && open.length && !open.some((order) => order.waiter_id === user?.id)) continue;
      const key = table.area_name ?? '';
      groups.set(key, [...(groups.get(key) ?? []), { table, open }]);
    }
    return [...groups.entries()];
  }, [floor.tables, byTable, mine, user]);

  const tabs = useMemo(() => {
    const text = search.trim().toLowerCase();
    return floor.orders
      .filter((order) => order.kind === 'tab')
      .filter((order) => !mine || order.waiter_id === user?.id)
      .filter((order) => !text || `${order.tab_name ?? ''} ${order.local.customer_name ?? ''}`.toLowerCase().includes(text));
  }, [floor.orders, mine, search, user]);

  const pressTable = useCallback(
    (table, open) => {
      if (open.length) onOpenOrder(open[0].id);
      else if (canOpen) setOpening(table);
      else setNotice(t('restaurant.noPermission'));
    },
    [canOpen, onOpenOrder, t],
  );

  async function openTable(table, covers) {
    try {
      const { order } = await floor.service.openTable({ table, covers, user, actorProof, catalogue });
      setOpening(null);
      onOpenOrder(order.id);
    } catch (error) {
      setOpening(null);
      setNotice(t(`restaurant.errors.${error.code}`, { defaultValue: t('restaurant.errors.failed') }));
    }
  }

  async function openTab({ name, customer }) {
    try {
      const { order } = await floor.service.openTab({ name, customer, user, actorProof, catalogue });
      setOpeningTab(false);
      onOpenOrder(order.id);
    } catch (error) {
      setOpeningTab(false);
      setNotice(t(`restaurant.errors.${error.code}`, { defaultValue: t('restaurant.errors.failed') }));
    }
  }

  const renderTab = useCallback(({ item }) => <TabRow order={item} total={totals.get(item.id)} currency={currency} money={money} t={t} onPress={() => onOpenOrder(item.id)} />, [totals, currency, money, t, onOpenOrder]);

  return (
    <SafeAreaView className="flex-1">
      <PosHeader compact={!tablet} onHeld={onHeld} onCloseShift={onCloseShift} onMenu={onMenu} />
      <View className="gap-3 px-4 pb-2 pt-3 md:px-6">
        <View className="flex-row flex-wrap items-center justify-between gap-3">
          <Segmented
            label={t('restaurant.view.label')}
            value={view}
            onChange={setView}
            options={[
              { value: 'tables', label: t('restaurant.view.tables') },
              { value: 'tabs', label: t('restaurant.view.tabs') },
            ]}
          />
          <Segmented
            label={t('restaurant.filter.label')}
            value={mine}
            onChange={setMine}
            options={[
              { value: true, label: t('restaurant.filter.mine') },
              { value: false, label: t('restaurant.filter.all') },
            ]}
          />
          <Button variant="secondary" onPress={onQuickSale}>
            {t('restaurant.quickSale')}
          </Button>
        </View>
        {notice ? <Alert tone="warning">{notice}</Alert> : null}
      </View>

      {view === 'tables' ? (
        <ScrollView className="flex-1" contentContainerClassName="gap-5 px-4 pb-6 md:px-6">
          {areas.length ? (
            areas.map(([area, entries]) => (
              <View key={area} className="gap-2">
                {area ? (
                  <Text accessibilityRole="header" className="font-sans text-body-lg font-semibold text-ink">
                    {area}
                  </Text>
                ) : null}
                <View className="flex-row flex-wrap gap-3">
                  {entries.map(({ table, open }) => {
                    const status = tableStatus(table.status, open);
                    return <TableTile key={table.id} table={table} status={status} order={open[0]} total={open[0] ? totals.get(open[0].id) : null} currency={currency} money={money} t={t} onPress={() => pressTable(table, open)} />;
                  })}
                </View>
              </View>
            ))
          ) : (
            <Text className="py-12 text-center font-sans text-body-lg text-ink-muted">{floor.tables.length ? t('restaurant.tables.noneMine') : t('restaurant.tables.empty')}</Text>
          )}
        </ScrollView>
      ) : (
        <View className="min-h-0 flex-1 gap-3 px-4 pb-4 md:px-6">
          <View className="flex-row items-center gap-3">
            <TextField className="flex-1" accessibilityLabel={t('restaurant.tabs.search')} placeholder={t('restaurant.tabs.search')} value={search} onChangeText={setSearch} autoCorrect={false} autoCapitalize="none" />
            {canOpen ? (
              <Button variant="primary" onPress={() => setOpeningTab(true)}>
                {t('restaurant.tabs.new')}
              </Button>
            ) : null}
          </View>
          <FlatList
            data={tabs}
            keyExtractor={(order) => order.id}
            renderItem={renderTab}
            className="flex-1 rounded-lg border border-border bg-surface-200"
            ListEmptyComponent={<Text className="px-6 py-12 text-center font-sans text-body-lg text-ink-muted">{search ? t('restaurant.tabs.noMatch') : t('restaurant.tabs.empty')}</Text>}
          />
        </View>
      )}
      {opening ? <OpenTableDialog table={opening} onClose={() => setOpening(null)} onOpen={(covers) => openTable({ ...opening, status: tableStatus(opening.status, []) }, covers)} /> : null}
      {openingTab ? <OpenTabDialog onClose={() => setOpeningTab(false)} onOpen={openTab} /> : null}
    </SafeAreaView>
  );
}

