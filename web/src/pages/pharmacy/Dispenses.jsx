import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Dialog, ListView, TextField } from '@/components/ds'
import { errorMessage } from '@/api/errorMessage'
import { PageHeader } from '@/layouts/PageHeader'
import { formatDateTime } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { FlagChips, Qty, RecordStatus } from './PharmacyParts'
import { choiceField, DISPENSE_REVIEW, dateField, ITEM_CLASSES, locationFilter, pharmacyKey, usePlaces } from './pharmacyData'

/** PHA-26: close a flagged dispense with a note on what was checked. */
function ReviewDialog({ dispense, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [note, setNote] = useState('')
  const review = useMutation({
    mutationFn: () => api.post(`pharmacy/dispenses/${dispense.id}/review`, { note: note.trim() }),
    onSuccess: async () => {
      toast.success(t('pharmacy.dispenses.reviewed'))
      await queryClient.invalidateQueries({ queryKey: pharmacyKey })
      onClose()
    },
  })
  return (
    <Dialog
      open
      title={t('pharmacy.dispenses.reviewTitle', { receipt: dispense.receipt_number ?? '' })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={review.isPending} disabled={!note.trim()} onClick={() => review.mutate()}>
            {t('pharmacy.dispenses.close')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <p className="text-body text-ink-muted">{t('pharmacy.dispenses.reviewHelp')}</p>
        <FlagChips flags={dispense.flags} />
        {review.error ? <Alert tone="danger" title={errorMessage(review.error)} /> : null}
        <TextField label={t('pharmacy.dispenses.note')} value={note} maxLength={1000} onChange={(event) => setNote(event.target.value)} />
      </div>
    </Dialog>
  )
}

/**
 * PHA-24, PHA-26: dispensed sale lines that needed a prescription or a
 * pharmacist, or are controlled. `log` shows the prescription log (the ones
 * made against a prescription); otherwise the lines the server flagged, open
 * ones first, for a reviewer to close.
 */
export default function Dispenses({ log = false }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const { can } = usePermissions()
  const places = usePlaces()
  const [reviewing, setReviewing] = useState(null)
  const columns = [
    { key: 'dispensed_at', label: t('pharmacy.columns.dispensed_at'), sortKey: 'dispensed_at', hideable: false, render: (row) => <span className="tabular-nums">{formatDateTime(row.dispensed_at, locale)}</span> },
    { key: 'receipt_number', label: t('pharmacy.columns.receipt_number'), sortKey: 'receipt_number', render: (row) => row.receipt_number ?? '' },
    { key: 'item', label: t('pharmacy.columns.item'), wrap: true, render: (row) => row.item?.name },
    { key: 'class', label: t('pharmacy.columns.class'), render: (row) => t(`pharmacy.classes.${row.class}`) },
    { key: 'qty', label: t('pharmacy.columns.qty'), align: 'end', render: (row) => <Qty value={row.qty} /> },
    { key: 'patient_name', label: t('pharmacy.columns.patient_name'), render: (row) => row.prescription?.patient_name ?? '' },
    { key: 'prescriber', label: t('pharmacy.columns.prescriber'), wrap: true, render: (row) => (row.prescription ? `${row.prescription.prescriber_name} ${row.prescription.prescriber_licence}` : '') },
    { key: 'pharmacist', label: t('pharmacy.columns.pharmacist'), render: (row) => row.pharmacist?.name ?? '' },
    { key: 'approval_mode', label: t('pharmacy.columns.approval_mode'), render: (row) => t(`pharmacy.approvals.${row.approval_mode}`) },
    { key: 'flags', label: t('pharmacy.columns.flags'), wrap: true, render: (row) => <FlagChips flags={row.flags} /> },
    { key: 'review', label: t('pharmacy.columns.review'), render: (row) => (row.review_status === 'none' ? '' : <RecordStatus status={row.review_status} />) },
    { key: 'location', label: t('pharmacy.columns.location'), render: (row) => row.location?.name ?? '' },
    {
      key: 'actions',
      label: t('pharmacy.columns.actions'),
      inMenu: false,
      render: (row) =>
        row.review_status === 'open' && can(DISPENSE_REVIEW) ? (
          <Button variant="ghost" onClick={(event) => {
            event.stopPropagation()
            setReviewing(row)
          }}>
            {t('pharmacy.dispenses.review')}
          </Button>
        ) : null,
    },
  ]
  const list = useServerList({
    id: log ? 'pharmacy-prescription-log' : 'pharmacy-dispenses',
    endpoint: log ? 'pharmacy/reports/prescriptions' : 'pharmacy/dispenses',
    queryKey: [...pharmacyKey, log ? 'log' : 'dispenses'],
    filters: log ? { class: '', location: '', from: '', to: '' } : { review: 'open', class: '', location: '', from: '', to: '' },
    defaultSort: '-dispensed_at',
    columns,
  })
  const title = log ? t('pharmacy.dispenses.logTitle') : t('pharmacy.dispenses.title')

  return (
    <>
      <PageHeader title={title} description={log ? t('pharmacy.dispenses.logDescription') : t('pharmacy.dispenses.description')} />
      <ListView
        list={list}
        title={title}
        searchLabel={t('pharmacy.dispenses.search')}
        filterFields={[
          ...(log ? [] : [{ name: 'review', label: t('pharmacy.columns.review'), options: [{ value: 'all', label: t('pharmacy.filters.any') }, ...['open', 'reviewed', 'none'].map((value) => ({ value, label: t(`pharmacy.states.${value}`) }))] }]),
          choiceField(t, 'class', ITEM_CLASSES, t('pharmacy.columns.class'), 'pharmacy.classes'),
          ...locationFilter(t, places),
          dateField('from', t('pharmacy.filters.from')),
          dateField('to', t('pharmacy.filters.to')),
        ]}
        emptyText={log ? t('pharmacy.dispenses.logEmpty') : t('pharmacy.dispenses.empty')}
      />
      {reviewing ? <ReviewDialog dispense={reviewing} onClose={() => setReviewing(null)} /> : null}
    </>
  )
}
