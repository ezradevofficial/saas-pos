import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Pressable, Text, View } from 'react-native';
import { Button } from '../../components/ds/Button';
import { Dialog } from '../../components/ds/Dialog';
import { TextField } from '../../components/ds/TextField';
import { cn } from '../../lib/cn';
import { FOCUS_RING } from '../../lib/focus';
import { choiceProblems, chosenModifiers } from '../../restaurant/menu';
import { useMoneyText } from '../pos/format';

/** "Choose 1", "Choose up to 2", "Choose 1 to 3": what a group asks for. */
function hint(t, group, max) {
  const min = Number(group.min_select) || 0;
  if (min && min === max) return t('restaurant.modifiers.exactly', { count: min });
  if (min) return t('restaurant.modifiers.between', { min, max });
  return t('restaurant.modifiers.upTo', { count: max });
}

/**
 * REST-07: the choices of an item's modifier groups (Cooking, Extras) with
 * their minimum and maximum, the price effect of each, and a note ("no
 * onions"). Confirming is allowed once every minimum is met.
 * `initial` is { chosen: { groupId: [modifierId] }, note } when editing a line.
 */
export function ModifierDialog({ item, groups, currency, unitPriceMinor, initial = null, confirmLabel, onConfirm, onClose }) {
  const { t } = useTranslation();
  const money = useMoneyText();
  const [chosen, setChosen] = useState(initial?.chosen ?? {});
  const [note, setNote] = useState(initial?.note ?? '');
  const problems = useMemo(() => choiceProblems(groups, chosen), [groups, chosen]);
  const modifiers = useMemo(() => chosenModifiers(groups, chosen), [groups, chosen]);
  const total = modifiers.reduce((sum, modifier) => sum + BigInt(modifier.price_minor), BigInt(unitPriceMinor));

  function toggle(group, modifierId) {
    const max = Number(group.max_select) > 0 ? Number(group.max_select) : group.modifiers.length;
    setChosen((current) => {
      const list = current[group.id] ?? [];
      if (list.includes(modifierId)) return { ...current, [group.id]: list.filter((id) => id !== modifierId) };
      // One choice replaces the last; with more, the oldest makes room once the maximum is reached.
      const next = max === 1 ? [modifierId] : [...list, modifierId].slice(-max);
      return { ...current, [group.id]: next };
    });
  }

  return (
    <Dialog
      open
      title={item.name}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onPress={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" disabled={problems.length > 0} onPress={() => onConfirm({ modifiers, note: note.trim() || null, chosen })}>
            {confirmLabel ?? t('restaurant.modifiers.add', { amount: money(String(total), currency) })}
          </Button>
        </>
      }
    >
      {groups.map((group) => {
        const max = Number(group.max_select) > 0 ? Number(group.max_select) : group.modifiers.length;
        const problem = problems.find((entry) => entry.groupId === group.id);
        return (
          <View key={group.id} className="gap-2">
            <Text accessibilityRole="header" className="font-sans text-body-lg font-medium text-ink">
              {group.name}
            </Text>
            <Text className={cn('font-sans text-caption', problem ? 'text-danger' : 'text-ink-muted')}>{hint(t, group, max)}</Text>
            <View className="rounded-md border border-border">
              {group.modifiers.map((modifier) => {
                const selected = (chosen[group.id] ?? []).includes(modifier.id);
                const price = BigInt(modifier.price_minor ?? 0);
                return (
                  <Pressable
                    key={modifier.id}
                    accessibilityRole={max === 1 ? 'radio' : 'checkbox'}
                    accessibilityState={{ selected, checked: selected }}
                    accessibilityLabel={price > 0n ? `${modifier.name}, +${money(String(price), currency)}` : modifier.name}
                    onPress={() => toggle(group, modifier.id)}
                    className={cn('min-h-12 flex-row items-center gap-3 border-b border-border px-4 py-3 hover:bg-surface-300 active:bg-surface-300', selected && 'bg-primary-tint', FOCUS_RING)}
                  >
                    <View className={cn('h-4 w-4 items-center justify-center rounded-pill border', selected ? 'border-primary bg-primary' : 'border-border-strong')} />
                    <Text className="min-w-0 flex-1 font-sans text-body-lg text-ink">{modifier.name}</Text>
                    {price > 0n ? <Text className="font-sans text-body tabular-nums text-ink-muted">{`+${money(String(price), currency)}`}</Text> : null}
                  </Pressable>
                );
              })}
            </View>
          </View>
        );
      })}
      <TextField label={t('restaurant.line.note')} placeholder={t('restaurant.line.notePlaceholder')} value={note} onChangeText={setNote} maxLength={120} />
    </Dialog>
  );
}
