import { memo } from 'react';
import { useTranslation } from 'react-i18next';
import { Pressable, Text, View } from 'react-native';
import { cn } from '../lib/cn';
import { FOCUS_RING } from '../lib/focus';

export const PIN_MAX = 6;

const ROWS = [
  ['1', '2', '3'],
  ['4', '5', '6'],
  ['7', '8', '9'],
  ['clear', '0', 'delete'],
];

const Key = memo(function Key({ value, text, label, onPress, disabled }) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      disabled={disabled}
      onPress={() => onPress(value)}
      className={cn(
        'h-12 flex-1 items-center justify-center rounded-md border border-border bg-surface-200 hover:bg-surface-300 active:bg-surface-300',
        FOCUS_RING,
        disabled && 'opacity-40',
      )}
    >
      <Text className={cn('font-sans text-ink', /^\d$/.test(value) ? 'text-h3' : 'text-label')}>{text}</Text>
    </Pressable>
  );
});

/** The PIN as dots: filled for each digit typed. Never shows the digits. */
export function PinDots({ length, max = PIN_MAX }) {
  const { t } = useTranslation();
  return (
    <View accessibilityLabel={t('signIn.pinEntered', { count: length })} accessible className="flex-row justify-center gap-3 py-2">
      {Array.from({ length: max }, (_, index) => (
        <View
          key={index}
          testID={index < length ? 'pin-dot-filled' : 'pin-dot'}
          className={cn('h-3 w-3 rounded-pill border border-border-strong', index < length && 'border-ink bg-ink')}
        />
      ))}
    </View>
  );
}

/** A 3 × 4 keypad with 48px keys (POS touch targets). */
export function PinPad({ value, onChange, disabled, max = PIN_MAX }) {
  const { t } = useTranslation();
  const press = (key) => {
    if (key === 'delete') onChange(value.slice(0, -1));
    else if (key === 'clear') onChange('');
    else if (value.length < max) onChange(value + key);
  };

  return (
    <View accessibilityLabel={t('signIn.keypad')} className="gap-2">
      {ROWS.map((row) => (
        <View key={row.join('')} className="flex-row gap-2">
          {row.map((key) => (
            <Key
              key={key}
              value={key}
              text={key === 'delete' ? '\u232B' : key === 'clear' ? t('signIn.clear') : key}
              label={key === 'delete' ? t('signIn.delete') : key === 'clear' ? t('signIn.clear') : key}
              onPress={press}
              disabled={disabled}
            />
          ))}
        </View>
      ))}
    </View>
  );
}
