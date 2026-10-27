import { Pressable, Text, View } from 'react-native';
import { Button } from '../../components/ds/Button';
import { cn } from '../../lib/cn';
import { FOCUS_RING } from '../../lib/focus';

/** A row of exclusive choices (a segmented control): 48px targets, the chosen one in `primary`. */
export function Segmented({ options, value, onChange, label, className }) {
  return (
    <View accessibilityRole="radiogroup" accessibilityLabel={label} className={cn('flex-row flex-wrap gap-2', className)}>
      {options.map((option) => {
        const selected = option.value === value;
        return (
          <Pressable
            key={String(option.value)}
            accessibilityRole="radio"
            accessibilityLabel={option.accessibilityLabel}
            accessibilityState={{ selected }}
            onPress={() => onChange(option.value)}
            className={cn('h-12 min-w-12 items-center justify-center rounded-md border px-4', selected ? 'border-primary bg-primary' : 'border-border-strong bg-surface-200 hover:bg-surface-300 active:bg-surface-300', FOCUS_RING)}
          >
            <Text className={cn('font-sans text-body font-medium', selected ? 'text-on-primary' : 'text-ink')}>{option.label}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

/** A number with minus and plus buttons (covers, parts). */
export function Stepper({ value, onChange, min = 1, max = 99, lessLabel, moreLabel, label }) {
  return (
    <View className="flex-row items-center gap-2" accessibilityLabel={label}>
      <Button variant="secondary" className="w-12 px-0" accessibilityLabel={lessLabel} disabled={value <= min} onPress={() => onChange(Math.max(min, value - 1))}>
        −
      </Button>
      <Text accessibilityLiveRegion="polite" className="min-w-10 text-center font-sans text-body-lg font-medium tabular-nums text-ink">
        {value}
      </Text>
      <Button variant="secondary" className="w-12 px-0" accessibilityLabel={moreLabel} disabled={value >= max} onPress={() => onChange(Math.min(max, value + 1))}>
        +
      </Button>
    </View>
  );
}
