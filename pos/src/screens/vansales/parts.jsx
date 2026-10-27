import { useTranslation } from 'react-i18next';
import { Pressable, Text, View } from 'react-native';
import { Button } from '../../components/ds/Button';
import { SyncStatus } from '../../components/ds/SyncStatus';
import { cn } from '../../lib/cn';
import { FOCUS_RING } from '../../lib/focus';
import { useSyncStatus } from '../../sync/useSyncStatus';

/** A visit status as a dot tone (status is a dot and a word, never a filled pill). */
export const VISIT_TONE = { pending: 'neutral', visited: 'info', sold: 'success', no_sale: 'warning', skipped: 'neutral' };

/** The header of the route screens: a way back, the title, and the sync state. */
export function VanHeader({ title, subtitle, onBack, backLabel }) {
  const { t } = useTranslation();
  const sync = useSyncStatus();
  return (
    <View className="flex-row flex-wrap items-center gap-3 border-b border-border bg-surface-200 px-4 py-3 md:px-6">
      {onBack ? (
        <Button variant="ghost" onPress={onBack}>
          {backLabel ?? t('common.back')}
        </Button>
      ) : null}
      <View className="min-w-0 flex-1">
        <Text accessibilityRole="header" numberOfLines={1} className="font-sans text-h3 font-semibold text-ink">
          {title}
        </Text>
        {subtitle ? (
          <Text numberOfLines={1} className="font-sans text-caption text-ink-muted">
            {subtitle}
          </Text>
        ) : null}
      </View>
      <SyncStatus state={sync.state} pending={sync.pending} />
    </View>
  );
}

/** A tappable row, at least 48px tall. */
export function Row({ onPress, children, disabled, label, className }) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ disabled: Boolean(disabled) }}
      disabled={disabled}
      onPress={onPress}
      className={cn('min-h-12 flex-row items-center gap-3 border-b border-border px-4 py-2 hover:bg-surface-300 active:bg-surface-300', FOCUS_RING, disabled && 'opacity-40', className)}
    >
      {children}
    </Pressable>
  );
}

/** A row of exclusive choices (48px targets, the chosen one in `primary`). */
export function Choice({ options, value, onChange, label }) {
  return (
    <View accessibilityRole="radiogroup" accessibilityLabel={label} className="flex-row flex-wrap gap-2">
      {options.map((option) => {
        const selected = option.value === value;
        return (
          <Pressable
            key={String(option.value)}
            accessibilityRole="radio"
            accessibilityState={{ selected }}
            onPress={() => onChange(option.value)}
            className={cn('h-12 min-w-12 items-center justify-center rounded-md border px-4', selected ? 'border-primary bg-primary' : 'border-border-strong bg-surface-200', FOCUS_RING)}
          >
            <Text className={cn('font-sans text-body font-medium', selected ? 'text-on-primary' : 'text-ink')}>{option.label}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

/** The signed-in person's name or an empty string, for headers. */
export const nameOf = (user) => user?.name ?? '';
