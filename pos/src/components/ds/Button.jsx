import { useTranslation } from 'react-i18next';
import { ActivityIndicator, Pressable, Text } from 'react-native';
import { cn } from '../../lib/cn';
import { FOCUS_RING } from '../../lib/focus';

// Same variants as web/src/components/ds/Button.jsx, with token classes only.
const VARIANTS = {
  primary: { box: 'bg-primary hover:bg-primary-hover active:bg-primary-hover', text: 'text-on-primary' },
  secondary: { box: 'border border-border-strong bg-surface-200 hover:bg-surface-300 active:bg-surface-300', text: 'text-ink' },
  ghost: { box: 'bg-transparent hover:bg-surface-300 active:bg-surface-300', text: 'text-ink-muted' },
  danger: { box: 'border border-border-strong bg-transparent hover:bg-danger-tint active:bg-danger-tint', text: 'text-danger' },
  pay: { box: 'bg-accent hover:bg-accent-hover active:bg-accent-hover', text: 'text-on-accent' },
};


/**
 * Props mirror the web Button; onClick is accepted as an alias of onPress.
 * POS buttons are always 48px (touch targets), so the web's `size` prop is
 * accepted for call-site parity and ignored.
 * `icon` is accepted for parity but not drawn yet: the POS icon set arrives
 * with the remaining design-system components.
 */
export function Button({
  variant = 'secondary',
  size: _size,
  icon: _icon,
  block = false,
  loading = false,
  disabled,
  onPress,
  onClick,
  className,
  children,
  ...rest
}) {
  const { t } = useTranslation();
  const look = VARIANTS[variant] ?? VARIANTS.secondary;
  const inactive = Boolean(disabled || loading);

  return (
    <Pressable
      accessibilityRole="button"
      {...rest}
      onPress={onPress ?? onClick}
      disabled={inactive}
      accessibilityState={{ disabled: inactive, busy: loading }}
      className={cn(
        'h-12 flex-row items-center justify-center gap-2 rounded-md px-5',
        FOCUS_RING,
        look.box,
        block && 'w-full',
        inactive && 'opacity-40',
        className,
      )}
    >
      {loading ? <ActivityIndicator size="small" accessibilityLabel={t('ds.button.loading')} className={look.text} /> : null}
      {typeof children === 'string' || typeof children === 'number' ? (
        <Text className={cn('font-sans text-body-lg font-medium', look.text)}>{children}</Text>
      ) : (
        children
      )}
    </Pressable>
  );
}
