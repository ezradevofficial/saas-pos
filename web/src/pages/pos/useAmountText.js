import { formatAmount } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'

/** "KES 1,125.00" as text (currency code first), for sentences and summaries; '' when absent. */
export function useAmountText() {
  const locale = useLocale()
  return (value) => (value ? `${value.currency} ${formatAmount(value.amount_minor, value.currency, locale)}` : '')
}
