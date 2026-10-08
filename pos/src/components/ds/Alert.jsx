import { Text, View } from 'react-native';
import { cn } from '../../lib/cn';

// Same tones as web/src/components/ds/Alert.jsx. The POS has no icon set yet,
// so a small dot marks the tone; the words carry the meaning.
const TONES = {
  info: { box: 'border-border bg-surface-200', dot: 'bg-primary' },
  success: { box: 'border-transparent bg-success-tint', dot: 'bg-success' },
  warning: { box: 'border-transparent bg-warning-tint', dot: 'bg-warning' },
  danger: { box: 'border-transparent bg-danger-tint', dot: 'bg-danger' },
};

export function Alert({ tone = 'info', title, children, action, className }) {
  const style = TONES[tone] ?? TONES.info;
  return (
    <View
      testID="alert"
      role={tone === 'danger' ? 'alert' : 'status'}
      accessibilityLiveRegion="polite"
      className={cn('flex-row items-start gap-3 rounded-md border px-4 py-3', style.box, className)}
    >
      <View testID="alert-dot" className={cn('mt-2 h-2 w-2 rounded-pill', style.dot)} />
      <View className="min-w-0 flex-1 gap-1">
        {title ? <Text className="font-sans text-body-lg font-medium text-ink">{title}</Text> : null}
        {children ? <Text className="font-sans text-body-lg text-ink-muted">{children}</Text> : null}
      </View>
      {action ? <View className="shrink-0 self-center">{action}</View> : null}
    </View>
  );
}
