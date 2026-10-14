import { useTranslation } from 'react-i18next'
import { ListView, Money, StatusBadge } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { useLocale } from '@/lib/useLocale'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { useServerList } from '@/lib/useServerList'
import { REVIEW_FLAGS } from './loyaltyData'

/**
 * LOY-12, H2: POS sales Loyalty kept but flagged (device wins: a sale is
 * never refused), for a manager to review: balances overdrawn offline,
 * coupons used too often, promotions that differ, credit sold without the
 * permission, gift card sales that do not match.
 */
export default function FlaggedSales() {
  const { t } = useTranslation()
  const locale = useLocale()
  const companyOf = useCompanyOfRecord()
  const flagLabel = (code) => t(`loyalty.sales.flags.${code}`, { defaultValue: t('loyalty.sales.flags.other') })

  const columns = [
    { key: 'receipt', label: t('loyalty.sales.columns.receipt'), hideable: false, render: (row) => <span className="font-mono">{row.receipt_number}</span> },
    { key: 'sold_at', label: t('loyalty.sales.columns.soldAt'), sortKey: 'sold_at', render: (row) => <span className="tabular-nums">{formatCompanyTime(row.sold_at, locale, companyOf(row.company_id))}</span> },
    { key: 'customer', label: t('loyalty.sales.columns.customer'), render: (row) => row.party?.name ?? t('loyalty.sales.noCustomer') },
    { key: 'total', label: t('loyalty.sales.columns.total'), render: (row) => <Money amount={row.total.amount_minor} currency={row.total.currency} /> },
    {
      key: 'flags',
      label: t('loyalty.sales.columns.flags'),
      render: (row) => (
        <span className="flex flex-wrap gap-x-3 gap-y-1">
          {row.flags.map((flag, index) => (
            <StatusBadge key={`${flag.code}-${index}`} tone="warning">{flagLabel(flag.code)}</StatusBadge>
          ))}
          {row.voided ? <StatusBadge tone="neutral">{t('loyalty.sales.voided')}</StatusBadge> : null}
        </span>
      ),
    },
  ]
  const list = useServerList({ id: 'loyalty-sales', endpoint: 'loyalty/sales', queryKey: ['loyalty-sales'], filters: { flag: '' }, defaultSort: '-sold_at', columns })

  return (
    <>
      <PageHeader title={t('loyalty.sales.title')} description={t('loyalty.sales.description')} />
      <ListView
        list={list}
        title={t('loyalty.sales.title')}
        searchLabel={t('loyalty.sales.search')}
        filterFields={[{ name: 'flag', label: t('loyalty.sales.columns.flags'), options: [{ value: '', label: t('loyalty.sales.allFlags') }, ...REVIEW_FLAGS.map((code) => ({ value: code, label: flagLabel(code) }))] }]}
        emptyText={t('loyalty.sales.empty')}
      />
    </>
  )
}
