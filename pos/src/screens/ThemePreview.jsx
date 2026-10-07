import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Pressable, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { PosTile } from '../components/ds/PosTile';
import { SaleTotal } from '../components/ds/SaleTotal';
import { SyncStatus } from '../components/ds/SyncStatus';
import { cn } from '../lib/cn';
import { FOCUS_RING } from '../lib/focus';
import { PREVIEW_THEMES } from '../theme/themes';
import { SAMPLE_CART, SAMPLE_CURRENCY, SAMPLE_PRODUCTS, saleTotals } from './sampleSale';

// One segment of the segmented control (README: surface-300 track, selected
// segment surface-200 with shadow-sm); 48px tall for touch.
function ThemeChoice({ label, selected, onPress }) {
  return (
    <Pressable
      accessibilityRole="radio"
      accessibilityLabel={label}
      accessibilityState={{ checked: selected }}
      onPress={onPress}
      className={cn(
        'h-12 justify-center rounded-md px-4',
        FOCUS_RING,
        selected ? 'bg-surface-200 shadow-sm' : 'bg-transparent hover:bg-surface-200 active:bg-surface-200',
      )}
    >
      <Text className={cn('font-sans text-label', selected ? 'text-ink' : 'text-ink-muted')}>{label}</Text>
    </Pressable>
  );
}

/** Shows the POS building blocks in every theme: sync status, product grid, sale total. */
export function ThemePreview({ themeKey, onThemeChange }) {
  const { t } = useTranslation();
  const [cart, setCart] = useState(SAMPLE_CART);
  const totals = saleTotals(cart);
  const add = (id) => setCart((current) => ({ ...current, [id]: (current[id] ?? 0) + 1 }));

  return (
    <SafeAreaView className="flex-1">
      <View className="flex-row flex-wrap items-center gap-4 border-b border-border bg-surface-200 px-4 py-3 md:px-6">
        <View className="flex-1">
          <Text accessibilityRole="header" className="font-sans text-h3 text-ink">
            {t('app.name')}
          </Text>
          <Text className="font-sans text-caption text-ink-muted">{t('preview.subtitle')}</Text>
        </View>
        <SyncStatus state="online" />
      </View>

      <View className="items-start px-4 pt-4 md:px-6">
        <View
          testID="theme-switcher"
          accessibilityRole="radiogroup"
          accessibilityLabel={t('preview.theme')}
          className="flex-row flex-wrap gap-1 rounded-md bg-surface-300 p-1"
        >
          {PREVIEW_THEMES.map((option) => (
            <ThemeChoice
              key={option.key}
              label={t(`ds.theme.${option.key}`)}
              selected={option.key === themeKey}
              onPress={() => onThemeChange(option.key)}
            />
          ))}
        </View>
      </View>

      <View className="flex-1 lg:flex-row">
        <ScrollView className="flex-1" contentContainerClassName="p-2 md:p-4">
          <Text accessibilityRole="header" className="px-2 pb-2 font-sans text-h3 text-ink">
            {t('preview.products')}
          </Text>
          <View className="flex-row flex-wrap">
            {SAMPLE_PRODUCTS.map((product) => (
              <View key={product.id} className="w-1/2 p-2 md:w-1/3 xl:w-1/4">
                <PosTile
                  name={product.name}
                  price={product.price}
                  currency={SAMPLE_CURRENCY}
                  stock={product.stock}
                  onSelect={() => add(product.id)}
                  className="flex-1"
                />
              </View>
            ))}
          </View>
        </ScrollView>

        <View className="gap-3 border-t border-border p-4 lg:w-1/3 lg:border-l lg:border-t-0 lg:pl-0 lg:pr-6 lg:pt-6">
          <View className="flex-row items-baseline justify-between gap-2 lg:pl-4">
            <Text accessibilityRole="header" className="font-sans text-h3 text-ink">
              {t('preview.sale')}
            </Text>
            <Text className="font-sans text-caption text-ink-muted">{t('preview.items', { count: totals.count })}</Text>
          </View>
          <SaleTotal
            currency={SAMPLE_CURRENCY}
            subtotal={totals.subtotal}
            tax={totals.tax}
            total={totals.total}
            onPay={() => setCart({})}
            className="lg:ml-4"
          />
        </View>
      </View>
    </SafeAreaView>
  );
}
