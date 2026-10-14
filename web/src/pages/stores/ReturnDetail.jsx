import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { api } from '@/api/client'
import { Card, DataTable } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { useLocale } from '@/lib/useLocale'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { Amount, Back, Detail, DetailState, Qty } from './StoresParts'
import { storesKey } from './storesData'

/** STR-06: one return to store: its issue, store, department, reason and lines (values for value holders). */
export default function ReturnDetail() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { returnId } = useParams()
  const companyOf = useCompanyOfRecord()
  const query = useQuery({ queryKey: [...storesKey, 'return', returnId], queryFn: () => api.get(`stores/returns/${returnId}`) })
  const entry = query.data?.data
  const back = <Back to="/stores/returns">{t('stores.returns.back')}</Back>

  if (!entry) return <DetailState query={query} back={back} notFound={t('stores.returnDetail.notFound')} />

  const lineColumns = [
    { key: 'line_no', label: t('stores.columns.lineNo'), numeric: true, render: (line) => line.line_no },
    {
      key: 'item',
      label: t('stores.columns.item'),
      wrap: true,
      render: (line) => (
        <span className="flex flex-col">
          <span className="text-ink">{line.item?.name}</span>
          <span className="font-mono text-caption text-ink-muted">{line.item?.code}</span>
        </span>
      ),
    },
    { key: 'qty', label: t('stores.columns.returned'), align: 'end', render: (line) => <Qty value={line.qty} unit={line.uom?.code} /> },
    { key: 'value', label: t('stores.columns.value'), align: 'end', render: (line) => <Amount value={line.value} /> },
  ]

  return (
    <>
      {back}
      <PageHeader eyebrow={t('stores.returnDetail.eyebrow')} title={entry.number} description={t(`stores.returnReasons.${entry.reason}`, { defaultValue: entry.reason })} />
      <div className="flex flex-col gap-5">
        <Card title={t('stores.returnDetail.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('stores.fields.issue')}>
              <Link to={`/stores/issues/${entry.issue?.id}`} className="text-primary tabular-nums hover:text-primary-hover">
                {entry.issue?.number}
              </Link>
            </Detail>
            <Detail label={t('stores.fields.store')}>{entry.location?.name}</Detail>
            <Detail label={t('stores.fields.department')}>{entry.department?.name}</Detail>
            <Detail label={t('stores.fields.returnedBy')}>
              <span className="flex flex-col">
                <span>{entry.returned_by?.name}</span>
                <span className="text-caption text-ink-muted tabular-nums">{formatCompanyTime(entry.returned_at, locale, companyOf(entry.company?.id))}</span>
              </span>
            </Detail>
            {entry.value ? (
              <Detail label={t('stores.columns.value')}>
                <Amount value={entry.value} />
              </Detail>
            ) : null}
            {entry.note ? <Detail label={t('stores.fields.note')}>{entry.note}</Detail> : null}
          </dl>
        </Card>
        <Card title={t('stores.lines.title')}>
          <DataTable caption={t('stores.lines.title')} columns={lineColumns} rows={entry.lines ?? []} />
        </Card>
      </div>
    </>
  )
}
