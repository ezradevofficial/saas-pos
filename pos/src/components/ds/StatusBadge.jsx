import { Text, View } from 'react-native';
import { cn } from '../../lib/cn';

// Status is a small dot and a word, never a filled pill and never colour alone.
const DOT = {
  neutral: 'bg-neutral-dot',
  info: 'bg-primary',
  success: 'bg-success',
  warning: 'bg-warning',
  danger: 'bg-danger',
  accent: 'bg-accent-ink',
};

const WORD = {
  danger: 'text-danger',
  accent: 'text-accent-ink',
};

export function StatusBadge({ tone = 'neutral', children, className }) {
  return (
    <View className={cn('flex-row items-center gap-2', className)}>
      <View testID="status-dot" className={cn('h-2 w-2 shrink-0 rounded-pill', DOT[tone] ?? DOT.neutral)} />
      <Text className={cn('font-sans text-caption font-medium', WORD[tone] ?? 'text-ink')}>{children}</Text>
    </View>
  );
}
