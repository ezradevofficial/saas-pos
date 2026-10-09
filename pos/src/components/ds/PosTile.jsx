import { memo } from 'react';
import { useTranslation } from 'react-i18next';
import { Image, Pressable, Text, View } from 'react-native';
import { cn } from '../../lib/cn';
import { FOCUS_RING } from '../../lib/focus';
import { formatAmount } from '../../lib/money';
import { useLocale } from '../../lib/useLocale';
import { CATEGORY_COLOURS, TILE_SIZE_CLASSES } from '../../pos/layout';
import { StatusBadge } from './StatusBadge';

const DEFAULT_LOW_STOCK = 5;

/**
 * A product button on the POS grid; price in minor units. `unavailable`
 * (e.g. "Rate needed") disables the tile and says why, as the web tile does.
 * `color` is a category colour token from the POS layout (LAY-05: one of
 * CATEGORY_COLOURS, never a hex) and tints the tile; `size` is the
 * layout's tile size. Every size keeps the 48px touch target.
 */
export const PosTile = memo(function PosTile({ name, price, currency, stock, lowStock = DEFAULT_LOW_STOCK, image, color, size = 'standard', unavailable, onSelect, className }) {
  const { t } = useTranslation();
  const lang = useLocale();
  const out = stock === 0 || Boolean(unavailable);
  const low = !out && stock != null && stock <= lowStock;
  const priceText = price == null ? '' : `${currency} ${formatAmount(price, currency, lang)}`;
  const stockText = unavailable || (out ? t('ds.posTile.outOfStock') : low ? t('ds.posTile.left', { count: stock }) : null);
  // The badge is inside the button, so its words go into the button's label.
  const label = [name, priceText, stockText].filter(Boolean).join(', ');
  const tint = CATEGORY_COLOURS[color]?.tile;
  const sizing = TILE_SIZE_CLASSES[size] ?? TILE_SIZE_CLASSES.standard;

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ disabled: out }}
      disabled={out}
      onPress={onSelect}
      className={cn(
        'justify-between rounded-md border border-border',
        sizing.tile,
        tint ?? 'bg-surface-200',
        'hover:border-border-strong active:bg-surface-300',
        FOCUS_RING,
        out && 'opacity-50',
        className,
      )}
    >
      {image ? <Image source={{ uri: image }} accessibilityIgnoresInvertColors className="aspect-video w-full rounded-sm" /> : null}
      <Text numberOfLines={2} className={cn('font-sans font-medium text-ink', sizing.name)}>
        {name}
      </Text>
      <View className="flex-row items-center justify-between gap-2">
        {/* Ink on tints (the design system's pairing); muted ink only on the plain surface. */}
        <Text className={cn('font-sans text-label font-normal tabular-nums', tint ? 'text-ink' : 'text-ink-muted')}>{priceText}</Text>
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
