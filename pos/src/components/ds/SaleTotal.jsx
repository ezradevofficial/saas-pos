import { useTranslation } from 'react-i18next';
import { Text, View } from 'react-native';
import { cn } from '../../lib/cn';
import { formatAmount, toMinor } from '../../lib/money';
import { useLocale } from '../../lib/useLocale';
import { Button } from './Button';
import { Money } from './Money';

function Row({ label, value, tone = 'text-ink-muted', valueTone = 'text-ink' }) {
  return (
    <View className="flex-row justify-between gap-4">
      <Text className={cn('font-sans text-body-lg', tone)}>{label}</Text>
      <Text className={cn('font-sans text-body-lg tabular-nums', valueTone)}>{value}</Text>
    </View>
  );
}

/** The POS sale summary with the one decisive Charge button (pay variant); amounts in minor units. */
export function SaleTotal({ currency, subtotal, tax, total, discount, secondary, onPay, labels, className }) {
  const { t } = useTranslation();
  const lang = useLocale();
  const money = (minor) => `${currency} ${formatAmount(minor, currency, lang)}`;
  const hasDiscount = toMinor(discount) !== 0n;
  const amount = money(total);

  return (
    <View className={cn('gap-2 rounded-lg border border-border bg-surface-200 p-5', className)}>
      <Row label={labels?.subtotal ?? t('ds.saleTotal.subtotal')} value={money(subtotal)} />
      {hasDiscount ? (
        <Row
          label={labels?.discount ?? t('ds.saleTotal.discount')}
          value={money(-toMinor(discount))}
          tone="text-accent-ink"
          valueTone="text-accent-ink"
        />
      ) : null}
      <Row label={labels?.tax ?? t('ds.saleTotal.tax')} value={money(tax)} />
      <View className="mb-3 mt-1 flex-row items-end justify-between gap-4 border-t border-border pt-3">
        <Text className="font-sans text-body-lg font-medium text-ink">{labels?.total ?? t('ds.saleTotal.total')}</Text>
        <Money amount={total} currency={currency} size="lg" secondary={secondary} locale={lang} className="items-end" />
      </View>
      <Button variant="pay" size="lg" block onPress={onPay} disabled={toMinor(total) === 0n}>
        {labels?.pay ? `${labels.pay} ${amount}` : t('ds.saleTotal.pay', { amount })}
      </Button>
    </View>
  );
}
