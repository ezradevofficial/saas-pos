import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Dialog, ListView, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatDate } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { ItemPicker, RecordStatus } from './PharmacyParts'
import { pharmacyKey, RECALL_ISSUE } from './pharmacyData'

/** PHA-22: issue a recall of one batch of an item; it blocks that batch at every place at once. */
function IssueDialog({ onClose }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [item, setItem] = useState(null)
  const [batch, setBatch] = useState('')
  const [reason, setReason] = useState('')
  const [reference, setReference] = useState('')
  const issue = useMutation({
    mutationFn: () => api.post('pharmacy/recalls', { item_id: item.id, batch_number: batch.trim(), reason: reason.trim(), ...(reference.trim() ? { reference: reference.trim() } : {}) }),
    onSuccess: async (response) => {
      toast.success(t('pharmacy.recalls.issued'))
      await queryClient.invalidateQueries({ queryKey: pharmacyKey })
      onClose()
      navigate(`/pharmacy/recalls/${response.data.id}`)
    },
  })
  const errors = formErrors(issue.error, ['item_id', 'batch_number', 'reason', 'reference'])
  return (
    <Dialog
      open
      title={t('pharmacy.recalls.issueTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="danger" loading={issue.isPending} disabled={!item || !batch.trim() || !reason.trim()} onClick={() => issue.mutate()}>
            {t('pharmacy.recalls.issue')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <p className="text-body text-ink-muted">{t('pharmacy.recalls.issueHelp')}</p>
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <ItemPicker label={t('pharmacy.fields.item')} value={item} required error={errors.fields.item_id} onChange={setItem} />
        <TextField label={t('pharmacy.fields.batch')} value={batch} maxLength={60} required error={errors.fields.batch_number} onChange={(event) => setBatch(event.target.value)} />
        <TextField label={t('pharmacy.recalls.reason')} value={reason} maxLength={1000} required error={errors.fields.reason} onChange={(event) => setReason(event.target.value)} />
        <TextField label={t('pharmacy.recalls.reference')} help={t('pharmacy.recalls.referenceHelp')} value={reference} maxLength={100} error={errors.fields.reference} onChange={(event) => setReference(event.target.value)} />
      </div>
    </Dialog>
  )
}

/** PHA-22: recalls, open ones first; opening one lists who bought the batch. */
export default function Recalls() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const [issuing, setIssuing] = useState(false)
  const columns = [
    { key: 'item', label: t('pharmacy.columns.item'), hideable: false, wrap: true, render: (row) => <span className="text-ink">{row.item?.name}</span> },
    { key: 'batch_number', label: t('pharmacy.columns.batch_number'), render: (row) => <span className="font-medium text-ink">{row.batch_number}</span> },
    { key: 'status', label: t('pharmacy.columns.status'), render: (row) => <RecordStatus status={row.status} /> },
    { key: 'reason', label: t('pharmacy.columns.reason'), wrap: true, render: (row) => row.reason },
    { key: 'lots_count', label: t('pharmacy.recalls.lots'), align: 'end', render: (row) => row.lots_count },
    { key: 'issued_at', label: t('pharmacy.recalls.issuedAt'), render: (row) => <span className="tabular-nums">{formatDate(row.issued_at, locale)}</span> },
  ]
  const list = useServerList({ id: 'pharmacy-recalls', endpoint: 'pharmacy/recalls', queryKey: [...pharmacyKey, 'recalls'], filters: { status: '' }, columns })
  return (
    <>
      <PageHeader
        title={t('pharmacy.recalls.title')}
        description={t('pharmacy.recalls.description')}
        actions={
          can(RECALL_ISSUE) ? (
            <Button variant="danger" icon="alert" onClick={() => setIssuing(true)}>
              {t('pharmacy.recalls.issueTitle')}
            </Button>
          ) : null
        }
      />
      <ListView
        list={list}
        title={t('pharmacy.recalls.title')}
        searchLabel={t('pharmacy.recalls.search')}
        filterFields={[{ name: 'status', label: t('pharmacy.columns.status'), options: [{ value: '', label: t('pharmacy.filters.any') }, ...['open', 'lifted'].map((status) => ({ value: status, label: t(`pharmacy.states.${status}`) }))] }]}
        emptyText={t('pharmacy.recalls.empty')}
        onRowClick={(row) => navigate(`/pharmacy/recalls/${row.id}`)}
      />
      {issuing ? <IssueDialog onClose={() => setIssuing(false)} /> : null}
    </>
  )
}
