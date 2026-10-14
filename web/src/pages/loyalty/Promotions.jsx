import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { usePermissions } from '@/auth/usePermissions'
import { Button, ListView, StatusBadge } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { PROMOTION_STATUSES, PROMOTION_TONES } from './loyaltyData'

/** LOY-08: the promotions of the companies the user sees, highest priority first. */
export default function Promotions() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const companyOf = useCompanyOfRecord()

  const columns = [
    { key: 'name', label: t('loyalty.promotions.columns.name'), sortKey: 'name', hideable: false, render: (row) => <span className="font-medium text-ink">{row.name}</span> },
    { key: 'type', label: t('loyalty.promotions.columns.type'), render: (row) => t(`loyalty.promotions.types.${row.type}`) },
    { key: 'status', label: t('loyalty.promotions.columns.status'), sortKey: 'status', render: (row) => <StatusBadge tone={PROMOTION_TONES[row.status]}>{t(`loyalty.promotions.statuses.${row.status}`)}</StatusBadge> },
    { key: 'priority', label: t('loyalty.promotions.columns.priority'), sortKey: 'priority', render: (row) => <span className="tabular-nums">{row.priority}</span> },
    { key: 'coupon', label: t('loyalty.promotions.columns.coupon'), render: (row) => (row.requires_coupon ? t('loyalty.promotions.needsCoupon') : t('loyalty.promotions.noCoupon')) },
    { key: 'company', label: t('loyalty.promotions.columns.company'), defaultHidden: true, render: (row) => row.company?.name ?? '' },
    { key: 'updated_at', label: t('loyalty.promotions.columns.updated'), sortKey: 'updated_at', render: (row) => <span className="tabular-nums">{formatCompanyTime(row.updated_at, locale, companyOf(row.company_id))}</span> },
  ]

  const list = useServerList({ id: 'loyalty-promotions', endpoint: 'loyalty/promotions', queryKey: ['loyalty-promotions'], filters: { status: '' }, defaultSort: '-priority', columns })

  return (
    <>
      <PageHeader
        title={t('loyalty.promotions.title')}
        description={t('loyalty.promotions.description')}
        actions={can('loyalty.promotion.create') ? (
          <Button variant="primary" icon="plus" onClick={() => navigate('/loyalty/promotions/new')}>
            {t('loyalty.promotions.new')}
          </Button>
        ) : null}
      />
      <ListView
        list={list}
        title={t('loyalty.promotions.title')}
        searchLabel={t('loyalty.promotions.search')}
        filterFields={[
          {
            name: 'status',
            label: t('loyalty.promotions.columns.status'),
            options: [{ value: '', label: t('loyalty.promotions.allStatuses') }, ...PROMOTION_STATUSES.map((status) => ({ value: status, label: t(`loyalty.promotions.statuses.${status}`) }))],
          },
        ]}
        emptyText={t('loyalty.promotions.empty')}
        onRowClick={(row) => navigate(`/loyalty/promotions/${row.id}`)}
      />
    </>
  )
}
