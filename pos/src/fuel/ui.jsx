import { useTranslation } from 'react-i18next';
import { Pressable, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button } from '../components/ds/Button';
import { cn } from '../lib/cn';
import { FOCUS_RING } from '../lib/focus';
import { AppHeader } from '../screens/AppHeader';

/** A fuel sub-screen: the till's top bar, a title with a way back, and a centred column on tablets. */
export function Frame({ title, onBack, location, children, wide = false }) {
  const { t } = useTranslation();
  return (
    <SafeAreaView className="flex-1">
      <AppHeader location={location} />
      <ScrollView contentContainerClassName="items-center p-4 md:p-6" keyboardShouldPersistTaps="handled">
        <View className={cn('w-full gap-5 rounded-lg border border-border bg-surface-200 p-5', wide ? 'md:w-3/4 xl:w-2/3' : 'md:w-2/3 xl:w-1/2')}>
          <View className="flex-row items-center justify-between gap-3">
            <Text accessibilityRole="header" className="min-w-0 flex-1 font-sans text-h2 font-semibold text-ink">
              {title}
            </Text>
            {onBack ? (
              <Button variant="ghost" onPress={onBack}>
                {t('fuel.common.back')}
              </Button>
            ) : null}
          </View>
          {children}
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

/** A labelled group of a form. */
export function Section({ title, help, children }) {
  return (
    <View className="gap-3">
      {title ? <Text className="font-sans text-body-lg font-medium text-ink">{title}</Text> : null}
      {help ? <Text className="font-sans text-body text-ink-muted">{help}</Text> : null}
      {children}
    </View>
  );
}

/** A row of label and value, the value in tabular figures. */
export function Figure({ label, value, strong = false, tone }) {
  return (
    <View className="flex-row items-baseline justify-between gap-4">
      <Text className="min-w-0 flex-1 font-sans text-body text-ink-muted">{label}</Text>
      <Text className={cn('font-sans text-body tabular-nums', strong ? 'font-medium text-ink' : 'text-ink', tone === 'danger' && 'text-danger')}>{value}</Text>
    </View>
  );
}

/** A pressable row with a 48px minimum target. */
export function Choice({ selected = false, onPress, children, label, disabled = false }) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ selected, disabled }}
      disabled={disabled}
      onPress={onPress}
      className={cn('min-h-12 justify-center rounded-md border px-4 py-3', selected ? 'border-primary bg-primary-tint' : 'border-border-strong bg-surface-200 hover:bg-surface-300 active:bg-surface-300', disabled && 'opacity-50', FOCUS_RING)}
    >
      {children}
    </Pressable>
  );
}
