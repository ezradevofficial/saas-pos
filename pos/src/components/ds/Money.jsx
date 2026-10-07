import { Text, View } from 'react-native';
import { cn } from '../../lib/cn';
import { formatAmount } from '../../lib/money';
import { useLocale } from '../../lib/useLocale';

const TONES = { success: 'text-success', danger: 'text-danger' };

/** An amount in minor units with its currency code first, in tabular figures. */
export function Money({ amount, currency, secondary, size = 'md', tone, locale, className }) {
  const lang = useLocale(locale);
  const large = size === 'lg';

  return (
    <View className={cn('items-start', className)}>
      <Text
        testID="money-main"
        className={cn('font-sans tabular-nums', large ? 'text-amount-lg' : 'text-amount', TONES[tone] ?? 'text-ink')}
      >
        <Text className={cn('font-sans font-normal text-ink-muted', large && 'text-body-lg')}>{currency}</Text>
        {` ${formatAmount(amount, currency, lang)}`}
      </Text>
      {secondary ? (
        <Text className="font-sans text-caption tabular-nums text-ink-muted">
          {`≈ ${secondary.currency} ${formatAmount(secondary.amount, secondary.currency, lang)}`}
        </Text>
      ) : null}
    </View>
  );
}
