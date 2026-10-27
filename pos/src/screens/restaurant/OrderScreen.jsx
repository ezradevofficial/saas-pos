import { memo, useCallback, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FlatList, Pressable, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useSession } from '../../auth/session';
import { Alert } from '../../components/ds/Alert';
import { Button } from '../../components/ds/Button';
import { Money } from '../../components/ds/Money';
import { PosTile } from '../../components/ds/PosTile';
import { StatusBadge } from '../../components/ds/StatusBadge';
import { TextField } from '../../components/ds/TextField';
import { cn } from '../../lib/cn';
import { FOCUS_RING } from '../../lib/focus';
import { uuidv7 } from '../../lib/random';
import { filterTiles } from '../../pos/catalogue';
import { usePosActions, usePosData } from '../../pos/PosProvider';
import { lineAmounts } from '../../restaurant/bill';
import { groupsFor, priceItem } from '../../restaurant/menu';
import { isRemoved, liveLines, nextCourse, rejectedLines, rounds, sentLines, unsentLines } from '../../restaurant/orderModel';
import { can, REST } from '../../restaurant/permissions';
import { useOrder, useRestaurant } from '../../restaurant/context';
import { isStubPrinter } from '../../restaurant/tickets';
import { lineBaseMajor } from '../../restaurant/voidValue';
import { useMoneyText } from '../pos/format';
import { ModifierDialog } from './ModifierDialog';
import { LineEditDialog, MergeDialog, ResendDialog, TransferDialog, VoidDialog } from './OrderDialogs';
import { orderBill, TICKET_TONE } from './floorHelpers';
import { Segmented, Stepper } from './parts';

const COURSES = [1, 2, 3, 4, 5, 6, 7, 8, 9];

const TileCell = memo(function TileCell({ tile, size, reasonText, onAdd }) {
  const select = useCallback(() => onAdd(tile), [onAdd, tile]);
  return (
    <View className="flex-1 p-1">
      <PosTile name={tile.name} price={tile.price} currency={tile.currency} color={tile.color} size={size} unavailable={tile.sellable ? undefined : reasonText(tile.reason)} onSelect={select} className="flex-1" />
    </View>
  );
});

/** REST-09: each station's ticket for the order, with its state; a waiter marks one served. */
export function TicketStrip({ order, stations, onServed }) {
  const { t } = useTranslation();
  const tickets = order.local.tickets ?? [];
  if (!tickets.length) return null;
  const nameOf = (id) => stations.find((station) => station.id === id)?.name ?? t('restaurant.ticket.kitchen');
  return (
    <View testID="ticket-strip" className="gap-2 rounded-lg border border-border bg-surface-200 p-3">
      <Text accessibilityRole="header" className="font-sans text-label text-ink-muted">
        {t('restaurant.tickets.title')}
      </Text>
      {tickets.map((ticket) => (
        <View key={ticket.id} className="min-h-12 flex-row items-center gap-3">
          <Text className="min-w-0 flex-1 font-sans text-body text-ink" numberOfLines={1}>
            {`#${ticket.number ?? ''} ${nameOf(ticket.station_id)}${ticket.kind && ticket.kind !== 'order' ? ` · ${t(`restaurant.tickets.kind.${ticket.kind}`)}` : ''}`}
          </Text>
          <StatusBadge tone={TICKET_TONE[ticket.status] ?? 'neutral'}>{t(`restaurant.tickets.status.${ticket.status}`)}</StatusBadge>
          {ticket.status !== 'served' ? (
            <Button variant="secondary" accessibilityLabel={t('restaurant.tickets.markServedFor', { number: ticket.number ?? '' })} onPress={() => onServed(ticket)}>
              {t('restaurant.tickets.markServed')}
            </Button>
          ) : null}
        </View>
      ))}
    </View>
  );
}

function LineRow({ line, currency, money, onPress, t }) {
  const removed = isRemoved(line);
  const amounts = lineAmounts(line);
  const meta = [t('restaurant.line.courseN', { course: line.course }), line.seat ? t('restaurant.line.seatN', { seat: line.seat }) : null, line.ticket_status && !removed ? t(`restaurant.tickets.status.${line.ticket_status}`) : null].filter(Boolean).join(' · ');
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={`${line.qty} ${line.name}${removed ? `, ${t('restaurant.line.voided')}` : ''}`}
      onPress={onPress}
      className={cn('min-h-12 flex-row items-start gap-3 border-b border-border px-4 py-3 hover:bg-surface-300 active:bg-surface-300', FOCUS_RING)}
    >
      <View className="min-w-0 flex-1">
        <Text numberOfLines={2} className={cn('font-sans text-body-lg font-medium', removed ? 'text-ink-muted line-through' : 'text-ink')}>
          {`${line.qty} × ${line.name}`}
        </Text>
        {(line.modifiers ?? []).length ? <Text className="font-sans text-caption text-ink-muted">{line.modifiers.map((modifier) => `+ ${modifier.name}`).join(', ')}</Text> : null}
        {line.note ? <Text className="font-sans text-caption text-ink-muted">{`“${line.note}”`}</Text> : null}
        <Text className="font-sans text-caption text-ink-muted">{removed ? `${t('restaurant.line.voided')}: ${line.removed.reason}` : meta}</Text>
      </View>
      <Text className={cn('font-sans text-body font-medium tabular-nums', removed ? 'text-ink-muted' : 'text-ink')}>{money(String(amounts.gross), currency)}</Text>
    </Pressable>
  );
}

/**
 * REST-03..REST-11: one order: the menu to add from (modifiers, a note, a
 * course and a seat per line), the lines by round, the tickets of the
 * stations, and what the waiter does with the table: send, fire the next
 * course, re-send, void after send, transfer, merge, ask for the bill.
 * Everything is saved on the till first (it works with no connection).
 */
export function OrderScreen({ orderId, tablet, onBack, onBill }) {
  const { t } = useTranslation();
  const money = useMoneyText();
  const { user, actorProof } = useSession();
  const { catalogue } = usePosData();
  const actions = usePosActions();
  const floor = useRestaurant();
  const order = useOrder(orderId);
  const [query, setQuery] = useState('');
  const [categoryId, setCategoryId] = useState(null);
  const [course, setCourse] = useState(1);
  const [seat, setSeat] = useState(0);
  const [view, setView] = useState('items');
  const [pick, setPick] = useState(null);
  const [editing, setEditing] = useState(null);
  const [voiding, setVoiding] = useState(null);
  const [dialog, setDialog] = useState(null);
  const [notice, setNotice] = useState(null);
  const service = floor.service;

  const currency = order?.currency ?? catalogue?.saleCurrency;
  const tiles = useMemo(() => (catalogue ? filterTiles(catalogue.tiles, { categoryId, query }).filter((tile) => !isServiceItem(tile.id, floor.settings)) : []), [catalogue, categoryId, query, floor.settings]);
  const reasonText = useCallback((reason) => t(`pos.unsellable.${reason}`, { defaultValue: t('pos.unsellable.not_sellable') }), [t]);
  const bill = useMemo(() => (order ? orderBill(order, floor.settings, catalogue) : null), [order, floor.settings, catalogue]);

  const say = useCallback((tone, text) => setNotice({ tone, text }), []);
  const fail = useCallback((error) => say('warning', t(`restaurant.errors.${error?.code}`, { defaultValue: t(`pos.unsellable.${error?.code}`, { defaultValue: t('restaurant.errors.failed') }) })), [say, t]);

  const addNow = useCallback(
    async (itemId, { modifiers = [], note = null } = {}) => {
      try {
        // The same plain item again adds one to its unsent line.
        const same = !modifiers.length && !note ? order.lines.find((line) => !line.sent_at && !isRemoved(line) && line.item_id === itemId && line.course === course && (line.seat ?? 0) === seat && !line.note && !(line.modifiers ?? []).length && /^\d+$/.test(line.qty)) : null;
        if (same) await service.editLine({ orderId, lineId: same.id, patch: { qty: String(Number(same.qty) + 1) } });
        else await service.addItem({ orderId, itemId, catalogue, user, course, seat: seat || null, modifiers, note });
        setNotice(null);
      } catch (error) {
        fail(error);
      }
    },
    [order, course, seat, service, orderId, catalogue, user, fail],
  );

  const onTile = useCallback(
    (tile) => {
      if (!order) return;
      const item = catalogue.itemById.get(tile.id);
      const groups = item ? groupsFor(floor.groups, item) : [];
      if (!groups.length) {
        addNow(tile.id);
        return;
      }
      try {
        setPick({ item, groups, unit: priceItem(catalogue, tile.id).unitPriceMinor });
      } catch (error) {
        fail(error);
      }
    },
    [order, catalogue, floor.groups, addNow, fail],
  );
  const renderTile = useCallback(({ item }) => <TileCell tile={item} size={catalogue?.layout?.grid.tile_size ?? 'standard'} reasonText={reasonText} onAdd={onTile} />, [catalogue, reasonText, onTile]);

  if (!order || !catalogue) return null;

  const label = order.kind === 'tab' ? t('restaurant.order.tabTitle', { name: order.tab_name }) : t('restaurant.order.tableTitle', { name: order.local.table_name ?? '' });
  const unsent = unsentLines(order);
  const toSend = unsent.filter((line) => line.course <= (order.local.fired ?? 1));
  const held = unsent.length - toSend.length;
  const next = nextCourse(order);
  const sent = sentLines(order);
  const roundList = rounds(order);
  const live = liveLines(order);
  const readOnly = !can(user, REST.create);
  const closedElsewhere = order.local.rejected?.order;
  const lost = rejectedLines(order);
  const others = order.kind === 'table' ? floor.orders.filter((other) => other.id !== order.id && other.kind === 'table') : [];
  const lineById = new Map(order.lines.map((line) => [line.id, line]));
  const sections = [
    { key: 'held', title: t('restaurant.order.notSent'), lines: unsent },
    ...roundList.map((round) => ({ key: `r${round}`, title: t('restaurant.order.round', { round }), lines: order.lines.filter((line) => line.round === round && !isRemoved(line)) })),
    { key: 'void', title: t('restaurant.order.voided'), lines: order.lines.filter(isRemoved) },
  ].filter((section) => section.lines.length);

  async function run(task) {
    try {
      return await task();
    } catch (error) {
      fail(error);
      return null;
    }
  }

  const send = () =>
    run(async () => {
      const result = await service.send({ orderId, user, catalogue });
      if (!result.lines.length) say('info', t('restaurant.send.nothing'));
      else say('success', `${t('restaurant.send.done', { count: result.lines.length, round: result.round })}${isStubPrinter() ? ` ${t('restaurant.send.noPrinter')}` : ''}`);
    });

  const fire = () =>
    run(async () => {
      const result = await service.fireNext({ orderId, user, catalogue });
      if (result.lines.length) say('success', `${t('restaurant.send.fired', { course: result.course, count: result.lines.length })}${isStubPrinter() ? ` ${t('restaurant.send.noPrinter')}` : ''}`);
    });

  const resend = (lineIds) =>
    run(async () => {
      setDialog(null);
      setVoiding(null);
      const result = await service.resend({ orderId, lineIds, id: uuidv7(), user, catalogue });
      if (result.resend) say('success', `${t('restaurant.resend.done', { count: result.resend.line_ids.length })}${isStubPrinter() ? ` ${t('restaurant.send.noPrinter')}` : ''}`);
    });

  async function voidLine(line, reason) {
    const value = lineBaseMajor(line, order.currency, catalogue);
    const approval = await actions.authorize('void_line', value, line.id);
    if (!approval) return 'refused';
    try {
      await service.voidLine({ orderId, lineId: line.id, reason, approval, user, actorProof, catalogue });
      setVoiding(null);
      say('success', t('restaurant.void.done', { name: line.name }));
      return null;
    } catch (error) {
      return error.code ?? 'failed';
    }
  }

  const editingLine = editing ? lineById.get(editing.lineId) : null;

  const tileArea = (
    <View className="min-h-0 flex-1 gap-3">
      <TextField accessibilityLabel={t('pos.search.label')} placeholder={t('pos.search.placeholder')} value={query} onChangeText={setQuery} autoCorrect={false} autoCapitalize="none" />
      {catalogue.categories.length ? (
        <ScrollView horizontal showsHorizontalScrollIndicator={false} className="flex-grow-0" contentContainerClassName="gap-2">
          <Segmented label={t('restaurant.order.categories')} value={categoryId} onChange={setCategoryId} options={[{ value: null, label: t('pos.search.all') }, ...catalogue.categories.map((category) => ({ value: category.id, label: category.name }))]} className="flex-nowrap" />
        </ScrollView>
      ) : null}
      <FlatList
        key={`grid-${tablet ? 3 : 2}`}
        data={tiles}
        numColumns={tablet ? 3 : 2}
        keyExtractor={(tile) => tile.id}
        renderItem={renderTile}
        initialNumToRender={18}
        windowSize={7}
        className="flex-1"
        contentContainerClassName="pb-4"
        ListEmptyComponent={<Text className="p-6 text-center font-sans text-body-lg text-ink-muted">{catalogue.tiles.length ? t('pos.search.none') : t('pos.search.emptyCatalogue')}</Text>}
      />
    </View>
  );

  const panel = (
    <ScrollView className="min-h-0 flex-1" contentContainerClassName="gap-3 pb-4">
      {order.local.flags?.includes('table_shared') ? <Alert tone="warning">{t('restaurant.flags.table_shared')}</Alert> : null}
      {order.local.flags?.includes('transfer_unauthorised') ? <Alert tone="warning">{t('restaurant.flags.transfer_unauthorised')}</Alert> : null}
      {closedElsewhere ? (
        <Alert
          tone="danger"
          title={t('restaurant.rejected.title')}
          action={
            lost.length ? (
              <Button
                variant="secondary"
                onPress={() =>
                  run(async () => {
                    const fresh = await service.ringAsNew({ orderId, user });
                    say('success', t('restaurant.rejected.done', { count: fresh.lines.length }));
                    onBack(fresh.id);
                  })
                }
              >
                {t('restaurant.rejected.ringAgain')}
              </Button>
            ) : null
          }
        >
          {t(`restaurant.rejected.${closedElsewhere}`, { defaultValue: t('restaurant.rejected.other') })}
        </Alert>
      ) : null}
      {notice ? <Alert tone={notice.tone}>{notice.text}</Alert> : null}

      <View className="rounded-lg border border-border bg-surface-200">
        {sections.length ? (
          sections.map((section) => (
            <View key={section.key}>
              <Text accessibilityRole="header" className="bg-surface-300 px-4 py-2 font-sans text-label text-ink-muted">
                {section.title}
              </Text>
              {section.lines.map((line) => (
                <LineRow
                  key={line.id}
                  line={line}
                  currency={currency}
                  money={money}
                  t={t}
                  onPress={() => {
                    if (isRemoved(line)) return;
                    if (line.sent_at) setVoiding(line.id);
                    else if (!readOnly) setEditing({ lineId: line.id });
                  }}
                />
              ))}
            </View>
          ))
        ) : (
          <Text className="px-6 py-10 text-center font-sans text-body-lg text-ink-muted">{t('restaurant.order.empty')}</Text>
        )}
      </View>

      <TicketStrip order={order} stations={floor.stations} onServed={(ticket) => run(() => service.markServed({ orderId, ticketId: ticket.id, user }))} />

      {bill ? (
        <View className="gap-2 rounded-lg border border-border bg-surface-200 p-4">
          <View className="flex-row justify-between">
            <Text className="font-sans text-body text-ink-muted">{t('restaurant.bill.lines')}</Text>
            <Text className="font-sans text-body tabular-nums text-ink">{money(bill.lines_minor, currency)}</Text>
          </View>
          {BigInt(bill.service_charge_minor) > 0n ? (
            <View className="flex-row justify-between">
              <Text className="font-sans text-body text-ink-muted">{t('restaurant.bill.service', { percent: Number(floor.settings.percent) })}</Text>
              <Text className="font-sans text-body tabular-nums text-ink">{money(String(BigInt(bill.service_charge_minor) + BigInt(bill.service_tax_minor)), currency)}</Text>
            </View>
          ) : null}
          <View className="flex-row items-center justify-between border-t border-border pt-2">
            <Text className="font-sans text-body-lg font-medium text-ink">{t('restaurant.bill.total')}</Text>
            <Money amount={bill.total_minor} currency={currency} size="lg" />
          </View>
        </View>
      ) : null}

      <View className="flex-row flex-wrap gap-2">
        {can(user, REST.send) ? (
          <Button variant="primary" disabled={!toSend.length} onPress={send}>
            {t('restaurant.send.button', { count: toSend.length })}
          </Button>
        ) : null}
        {can(user, REST.send) && next !== null ? (
          <Button variant="secondary" onPress={fire}>
            {t('restaurant.send.fire', { course: next })}
          </Button>
        ) : null}
        {can(user, REST.send) && sent.length ? (
          <Button variant="secondary" onPress={() => setDialog('resend')}>
            {t('restaurant.resend.button')}
          </Button>
        ) : null}
        {can(user, REST.edit) && live.length && !order.bill_requested_at ? (
          <Button variant="secondary" onPress={() => run(() => service.requestBill({ orderId, user }))}>
            {t('restaurant.bill.request')}
          </Button>
        ) : null}
        {can(user, REST.transfer) ? (
          <Button variant="secondary" onPress={() => setDialog('transfer')}>
            {t('restaurant.transfer.button')}
          </Button>
        ) : null}
        {can(user, REST.merge) && others.length ? (
          <Button variant="secondary" onPress={() => setDialog('merge')}>
            {t('restaurant.merge.button')}
          </Button>
        ) : null}
        {!sent.length && !(order.local.paid ?? []).length && can(user, REST.create) ? (
          <Button variant="ghost" onPress={() => run(async () => { await service.cancel({ orderId, user }); onBack(); })}>
            {t('restaurant.order.cancel')}
          </Button>
        ) : null}
      </View>
      {held > 0 ? <Text className="font-sans text-caption text-ink-muted">{t('restaurant.order.heldCourses', { count: held })}</Text> : null}
      {can(user, REST.close) && live.length ? (
        <Button variant="pay" block onPress={onBill}>
          {t('restaurant.bill.open')}
        </Button>
      ) : null}
    </ScrollView>
  );

  return (
    <SafeAreaView className="flex-1">
      <View className="gap-3 border-b border-border bg-surface-200 px-4 py-3 md:px-6">
        <View className="flex-row flex-wrap items-center gap-3">
          <Button variant="ghost" onPress={() => onBack()}>
            {t('restaurant.order.back')}
          </Button>
          <View className="min-w-0 flex-1">
            <Text accessibilityRole="header" numberOfLines={1} className="font-sans text-h3 font-semibold text-ink">
              {label}
            </Text>
            <Text numberOfLines={1} className="font-sans text-caption text-ink-muted">
              {[order.local.waiter_name, order.local.customer_name].filter(Boolean).join(' · ')}
            </Text>
          </View>
          {order.kind === 'table' ? (
            <View className="items-end gap-1">
              <Text className="font-sans text-caption text-ink-muted">{t('restaurant.open.guests')}</Text>
              {can(user, REST.edit) ? (
                <Stepper value={order.covers} min={1} max={99} onChange={(covers) => run(() => service.setCovers({ orderId, covers, user }))} lessLabel={t('restaurant.open.fewer')} moreLabel={t('restaurant.open.more')} label={t('restaurant.open.guests')} />
              ) : (
                <Text className="font-sans text-body-lg tabular-nums text-ink">{order.covers}</Text>
              )}
            </View>
          ) : null}
        </View>
        {!readOnly ? (
          <View className="gap-2">
            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerClassName="items-center gap-2">
              <Text className="font-sans text-label text-ink-muted">{t('restaurant.line.course')}</Text>
              <Segmented label={t('restaurant.line.course')} value={course} onChange={setCourse} options={COURSES.map((value) => ({ value, label: String(value), accessibilityLabel: t('restaurant.line.courseN', { course: value }) }))} className="flex-nowrap" />
            </ScrollView>
            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerClassName="items-center gap-2">
              <Text className="font-sans text-label text-ink-muted">{t('restaurant.line.seat')}</Text>
              <Segmented
                label={t('restaurant.line.seat')}
                value={seat}
                onChange={setSeat}
                options={[{ value: 0, label: t('restaurant.line.noSeat') }, ...Array.from({ length: Math.min(12, Math.max(order.covers, 1)) }, (_, index) => ({ value: index + 1, label: String(index + 1), accessibilityLabel: t('restaurant.line.seatN', { seat: index + 1 }) }))]}
                className="flex-nowrap"
              />
            </ScrollView>
          </View>
        ) : null}
      </View>

      {tablet ? (
        <View className="min-h-0 flex-1 flex-row gap-6 px-6 py-4">
          {readOnly ? null : <View className="min-w-0 flex-1">{tileArea}</View>}
          <View className="w-1/3 min-w-0 flex-grow">{panel}</View>
        </View>
      ) : (
        <View className="min-h-0 flex-1 gap-3 px-4 py-3">
          {readOnly ? null : (
            <Segmented
              label={t('restaurant.order.view')}
              value={view}
              onChange={setView}
              options={[
                { value: 'items', label: t('restaurant.order.items') },
                { value: 'order', label: t('restaurant.order.lines', { count: live.length }) },
              ]}
            />
          )}
          {readOnly || view === 'order' ? panel : tileArea}
        </View>
      )}

      {pick ? (
        <ModifierDialog
          item={pick.item}
          groups={pick.groups}
          currency={currency}
          unitPriceMinor={pick.unit}
          onClose={() => setPick(null)}
          onConfirm={({ modifiers, note }) => {
            const itemId = pick.item.id;
            setPick(null);
            addNow(itemId, { modifiers, note });
          }}
        />
      ) : null}

      {editingLine && editing.mode !== 'options' ? (
        <LineEditDialog
          line={editingLine}
          covers={order.covers}
          hasOptions={groupsFor(floor.groups, catalogue.itemById.get(editingLine.item_id) ?? { id: editingLine.item_id }).length > 0}
          onClose={() => setEditing(null)}
          onOptions={() => setEditing({ lineId: editingLine.id, mode: 'options' })}
          onRemove={() =>
            run(async () => {
              setEditing(null);
              await service.removeLine({ orderId, lineId: editingLine.id, user });
            })
          }
          onSave={(patch) =>
            run(async () => {
              setEditing(null);
              await service.editLine({ orderId, lineId: editingLine.id, patch });
            })
          }
        />
      ) : null}
      {editingLine && editing.mode === 'options' ? (
        <ModifierDialog
          item={{ name: editingLine.name }}
          groups={groupsFor(floor.groups, catalogue.itemById.get(editingLine.item_id))}
          currency={currency}
          unitPriceMinor={editingLine.unit_price_minor}
          initial={{ note: editingLine.note ?? '', chosen: chosenOf(floor.groups, catalogue.itemById.get(editingLine.item_id), editingLine) }}
          confirmLabel={t('restaurant.line.save')}
          onClose={() => setEditing(null)}
          onConfirm={({ modifiers, note }) =>
            run(async () => {
              setEditing(null);
              await service.editLine({ orderId, lineId: editingLine.id, patch: { modifiers, note } });
            })
          }
        />
      ) : null}

      {voiding && lineById.get(voiding) ? <VoidDialog line={lineById.get(voiding)} onClose={() => setVoiding(null)} onVoid={(reason) => voidLine(lineById.get(voiding), reason)} onResend={() => resend([voiding])} /> : null}
      {dialog === 'resend' ? <ResendDialog order={order} rounds={roundList} onClose={() => setDialog(null)} onResend={resend} /> : null}
      {dialog === 'transfer' ? (
        <TransferDialog
          staff={catalogue.staff}
          current={order.waiter_id}
          onClose={() => setDialog(null)}
          onTransfer={(waiter) =>
            run(async () => {
              setDialog(null);
              await service.transfer({ orderId, waiter, user });
              say('success', t('restaurant.transfer.done', { name: waiter.name }));
            })
          }
        />
      ) : null}
      {dialog === 'merge' ? (
        <MergeDialog
          orders={others}
          label={(other) => other.local.table_name ?? other.tab_name ?? ''}
          onClose={() => setDialog(null)}
          onMerge={(other) =>
            run(async () => {
              setDialog(null);
              await service.merge({ orderId, intoId: other.id, user });
              onBack(other.id);
            })
          }
        />
      ) : null}
    </SafeAreaView>
  );
}

/** The service charge and tip items are never on the menu grid. */
function isServiceItem(itemId, settings) {
  return itemId === settings.serviceItemId || itemId === settings.tipItemId;
}

/** The choice a line holds, as the dialog takes it: { groupId: [modifierId] }. */
function chosenOf(groups, item, line) {
  const chosen = {};
  const ids = new Set((line.modifiers ?? []).map((modifier) => modifier.id));
  for (const group of groupsFor(groups, item ?? { id: line.item_id })) chosen[group.id] = group.modifiers.filter((modifier) => ids.has(modifier.id)).map((modifier) => modifier.id);
  return chosen;
}
