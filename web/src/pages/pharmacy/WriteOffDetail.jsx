import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { useLocale } from '@/lib/useLocale'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { Back, CalendarDate, Detail, DetailState, DocumentFlow, Qty, ReasonDialog, RecordStatus } from './PharmacyParts'
import { pharmacyKey, WRITEOFF_CANCEL } from './pharmacyData'

/**
 * PHA-12, WF-10: one write-off: its place, reason and note, its lots, why it
 * failed when it did, where its flow is, and Cancel while it waits.
 */
export default function WriteOffDetail() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { writeOffId } = useParams()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const companyOf = useCompanyOfRecord()
  const [cancelling, setCancelling] = useState(false)
  const key = [...pharmacyKey, 'write-off', writeOffId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`pharmacy/write-offs/${writeOffId}`) })
  const writeOff = query.data?.data
  const back = <Back to="/pharmacy/write-offs">{t('pharmacy.writeOffs.back')}</Back>

  if (!writeOff) return <DetailState query={query} back={back} notFound={t('pharmacy.writeOff.notFound')} />

  const when = (value) => (value ? formatCompanyTime(value, locale, companyOf(writeOff.company?.id)) : null)
  const columns = [
    { key: 'line_no', label: t('pharmacy.columns.line_no'), numeric: true, render: (line) => line.line_no },
    { key: 'item', label: t('pharmacy.columns.item'), wrap: true, render: (line) => <span className="text-ink">{line.item?.name}</span> },
    { key: 'batch', label: t('pharmacy.columns.batch_number'), render: (line) => line.lot?.batch_number },
    { key: 'expires_on', label: t('pharmacy.columns.expires_on'), render: (line) => <CalendarDate value={line.lot?.expires_on} /> },
    { key: 'qty', label: t('pharmacy.columns.qty'), align: 'end', render: (line) => <Qty value={line.qty} /> },
  ]

  return (
    <>
      {back}
      <PageHeader
        eyebrow={t('pharmacy.writeOff.eyebrow')}
        title={writeOff.number}
        description={<RecordStatus status={writeOff.status} />}
        actions={
          writeOff.status === 'pending' && can(WRITEOFF_CANCEL) ? (
            <Button variant="danger" onClick={() => setCancelling(true)}>
              {t('pharmacy.writeOff.cancel')}
            </Button>
          ) : null
        }
      />
      {writeOff.failure ? (
        <Alert tone="danger" title={t('pharmacy.writeOff.failed')}>
          {t(`pharmacy.errors.${writeOff.failure}`, { batch: '', defaultValue: t('pharmacy.writeOff.failedHelp') })}
        </Alert>
      ) : null}
      <div className="flex flex-col gap-5">
        <Card title={t('pharmacy.writeOff.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('pharmacy.fields.location')}>
              <span className="flex flex-col">
                <span>{writeOff.location?.name}</span>
                <span className="text-caption text-ink-muted">{[writeOff.company?.name, writeOff.branch?.name].filter(Boolean).join(' · ')}</span>
              </span>
            </Detail>
            <Detail label={t('pharmacy.fields.reason')}>{t(`pharmacy.reasons.${writeOff.reason}`, { defaultValue: writeOff.reason })}</Detail>
            <Detail label={t('pharmacy.columns.requested_by')}>
              <span className="flex flex-col">
                <span>{writeOff.requested_by?.name}</span>
                <span className="text-caption text-ink-muted tabular-nums">{when(writeOff.created_at)}</span>
              </span>
            </Detail>
            {writeOff.note ? <Detail label={t('pharmacy.fields.note')}>{writeOff.note}</Detail> : null}
            {writeOff.posted_at ? <Detail label={t('pharmacy.writeOff.postedAt')}>{when(writeOff.posted_at)}</Detail> : null}
            {writeOff.cancelled_at ? (
              <Detail label={t('pharmacy.writeOff.cancelled')}>
                <span className="flex flex-col">
                  <span className="tabular-nums">{when(writeOff.cancelled_at)}</span>
                  <span className="text-caption text-ink-muted">{writeOff.cancel_reason}</span>
                </span>
              </Detail>
            ) : null}
          </dl>
        </Card>
        <Card title={t('pharmacy.writeOffs.lines')}>
          <DataTable caption={t('pharmacy.writeOffs.lines')} columns={columns} rows={writeOff.lines ?? []} />
        </Card>
        <DocumentFlow type="pharmacy.write_off" id={writeOff.id} />
      </div>
      <ReasonDialog
        open={cancelling}
        title={t('pharmacy.writeOff.cancelTitle', { number: writeOff.number })}
        help={t('pharmacy.writeOff.cancelHelp')}
        label={t('pharmacy.lot.reasonLabel')}
        action={t('pharmacy.writeOff.cancel')}
        path={`pharmacy/write-offs/${writeOff.id}/cancel`}
        onClose={() => setCancelling(false)}
        onDone={async (response) => {
          setCancelling(false)
          if (response?.data) queryClient.setQueryData(key, response)
          toast.success(t('pharmacy.writeOff.cancelledToast'))
          await queryClient.invalidateQueries({ queryKey: pharmacyKey })
        }}
      />
    </>
  )
}
