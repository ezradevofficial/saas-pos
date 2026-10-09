import { memo } from 'react';
import { useTranslation } from 'react-i18next';
import { Image, Pressable, Text, View } from 'react-native';
import { cn } from '../../lib/cn';
import { FOCUS_RING } from '../../lib/focus';
import { formatAmount } from '../../lib/money';
import { useLocale } from '../../lib/useLocale';
import { StatusBadge } from './StatusBadge';

const DEFAULT_LOW_STOCK = 5;

/**
 * A product button on the POS grid; price in minor units. `unavailable`
 * (POS only, e.g. "Rate needed") disables the tile and says why; the web
 * tile has no such state yet.
 */
export const PosTile = memo(function PosTile({ name, price, currency, stock, lowStock = DEFAULT_LOW_STOCK, image, color, unavailable, onSelect, className }) {
  const { t } = useTranslation();
  const lang = useLocale();
  const out = stock === 0 || Boolean(unavailable);
  const low = !out && stock != null && stock <= lowStock;
  const priceText = price == null ? '' : `${currency} ${formatAmount(price, currency, lang)}`;
  const stockText = unavailable || (out ? t('ds.posTile.outOfStock') : low ? t('ds.posTile.left', { count: stock }) : null);
  // The badge is inside the button, so its words go into the button's label.
  const label = [name, priceText, stockText].filter(Boolean).join(', ');

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ disabled: out }}
      disabled={out}
      onPress={onSelect}
      className={cn(
        'min-h-12 justify-between gap-3 rounded-md border border-border bg-surface-200 p-4',
        'hover:border-border-strong active:bg-surface-300',
        FOCUS_RING,
        out && 'opacity-50',
        className,
      )}
    >
      {image ? <Image source={{ uri: image }} accessibilityIgnoresInvertColors className="aspect-video w-full rounded-sm" /> : null}
      <View className="flex-row items-start gap-2">
        {color ? (
          // Category colour comes from the tenant's saved POS layout at runtime.
          <View className="mt-2 h-2 w-2 rounded-pill" style={{ backgroundColor: color }} />
        ) : null}
        <Text numberOfLines={2} className="flex-1 font-sans text-body-lg font-medium text-ink">
          {name}
        </Text>
      </View>
      <View className="flex-row items-center justify-between gap-2">
        <Text className="font-sans text-label font-normal tabular-nums text-ink-muted">{priceText}</Text>
        {unavailable ? (
          <StatusBadge tone="warning">{unavailable}</StatusBadge>
        ) : out ? (
          <StatusBadge tone="danger">{t('ds.posTile.out')}</StatusBadge>
        ) : low ? (
          <StatusBadge tone="warning">{t('ds.posTile.left', { count: stock })}</StatusBadge>
        ) : null}
      </View>
    </Pressable>
  );
});
