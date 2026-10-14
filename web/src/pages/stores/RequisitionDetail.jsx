import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, DataTable, DecimalInput, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { useLocale } from '@/lib/useLocale'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { Back, Detail, DetailState, DocumentStatus, Qty, ReasonDialog, RequisitionFlow } from './StoresParts'
import { isPositive, storesKey } from './storesData'

/**
 * STR-04: issue goods against the requisition's open lines, all or part
 * of each (prefilled with what is open; empty lines are left out).
 */
function IssuePanel({ requisition, onIssued }) {
  const { t } = useTranslation()
  const open = (requisition.lines ?? []).filter((line) => isPositive(line.qty_open))
  const [quantities, setQuantities] = useState(() => Object.fromEntries(open.map((line) => [line.id, line.qty_open])))
  const [note, setNote] = useState('')
  const chosen = open.filter((line) => isPositive(quantities[line.id]))
  const issue = useMutation({
    mutationFn: () =>
      api.post(`stores/requisitions/${requisition.id}/issues`, {
        ...(note.trim() ? { note: note.trim() } : {}),
        lines: chosen.map((line) => ({ requisition_line_id: line.id, qty: quantities[line.id] })),
      }),
    onSuccess: (response) => onIssued(response),
  })
  const errors = formErrors(issue.error, ['note', 'lines', ...chosen.flatMap((_, index) => [`lines.${index}.qty`, `lines.${index}.requisition_line_id`])])
  const lineError = (line) => errors.fields[`lines.${chosen.indexOf(line)}.qty`]

  return (
    <Card title={t('stores.issue.title')} subtitle={t('stores.issue.help')}>
      <div className="flex flex-col gap-4">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        {open.map((line) => (
          <div key={line.id} className="grid gap-3 sm:grid-cols-3 sm:items-end">
            <div className="flex flex-col sm:col-span-2">
              <span className="text-ink">{line.item?.name}</span>
              <span className="text-caption text-ink-muted">{t('stores.issue.open', { qty: line.qty_open, unit: line.uom?.code ?? '' })}</span>
            </div>
            <DecimalInput
              label={t('stores.issue.qty', { unit: line.uom?.code ?? '' })}
              value={quantities[line.id] ?? ''}
              error={lineError(line)}
              onChange={(value) => setQuantities((current) => ({ ...current, [line.id]: value ?? '' }))}
            />
          </div>
        ))}
        <TextField label={t('stores.fields.note')} value={note} maxLength={1000} error={errors.fields.note} onChange={(event) => setNote(event.target.value)} />
        <div>
          <Button variant="primary" loading={issue.isPending} disabled={!chosen.length} onClick={() => issue.mutate()}>
            {t('stores.issue.submit')}
          </Button>
        </div>
      </div>
    </Card>
  )
}

/**
 * STR-02 to STR-04, WF-10: one requisition: its store, department and
 * cost centre, its lines (asked, issued, open), its issues, where its
 * approval is, and Issue, Close and Cancel for those who may.
 */
export default function RequisitionDetail() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const { requisitionId } = useParams()
  const queryClient = useQueryClient()
  const companyOf = useCompanyOfRecord()
  const [dialog, setDialog] = useState(null)
  const key = [...storesKey, 'requisition', requisitionId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`stores/requisitions/${requisitionId}`) })
  const requisition = query.data?.data
  const back = <Back to="/stores/requisitions">{t('stores.requisitions.back')}</Back>

  if (!requisition) return <DetailState query={query} back={back} notFound={t('stores.requisition.notFound')} />

  const when = (value) => (value ? formatCompanyTime(value, locale, companyOf(requisition.company?.id)) : null)
  const refresh = async (response) => {
    setDialog(null)
    if (response?.data) queryClient.setQueryData(key, response)
    await queryClient.invalidateQueries({ queryKey: storesKey })
  }

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
          {line.note ? <span className="text-caption text-ink-muted">{line.note}</span> : null}
        </span>
      ),
    },
    { key: 'qty', label: t('stores.columns.asked'), align: 'end', render: (line) => <Qty value={line.qty} unit={line.uom?.code} /> },
    { key: 'qty_issued', label: t('stores.columns.issued'), align: 'end', render: (line) => <Qty value={line.qty_issued} unit={line.uom?.code} /> },
    { key: 'qty_open', label: t('stores.columns.open'), align: 'end', render: (line) => <Qty value={line.qty_open} unit={line.uom?.code} /> },
  ]

  return (
    <>
      {back}
      <PageHeader
        eyebrow={t('stores.requisition.eyebrow')}
        title={requisition.number}
        description={<DocumentStatus status={requisition.status} />}
        actions={
          <div className="flex flex-wrap gap-2">
            {requisition.can?.close ? <Button onClick={() => setDialog('close')}>{t('stores.requisition.close')}</Button> : null}
            {requisition.can?.cancel ? (
              <Button variant="danger" onClick={() => setDialog('cancel')}>
                {t('stores.requisition.cancel')}
              </Button>
            ) : null}
          </div>
        }
      />
      <div className="flex flex-col gap-5">
        <Card title={t('stores.requisition.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('stores.fields.store')}>
              <span className="flex flex-col">
                <span>{requisition.location?.name}</span>
                <span className="text-caption text-ink-muted">{[requisition.company?.name, requisition.branch?.name].filter(Boolean).join(' · ')}</span>
              </span>
            </Detail>
            <Detail label={t('stores.fields.department')}>{requisition.department?.name}</Detail>
            {requisition.cost_centre ? <Detail label={t('stores.fields.costCentre')}>{requisition.cost_centre.name}</Detail> : null}
            <Detail label={t('stores.fields.requestedBy')}>
              <span className="flex flex-col">
                <span>{requisition.requested_by?.name}</span>
                <span className="text-caption text-ink-muted tabular-nums">{when(requisition.created_at)}</span>
              </span>
            </Detail>
            {requisition.needed_by ? <Detail label={t('stores.fields.neededBy')}>{requisition.needed_by}</Detail> : null}
            {requisition.note ? <Detail label={t('stores.fields.note')}>{requisition.note}</Detail> : null}
            {requisition.closed_at ? (
              <Detail label={t('stores.requisition.closedAt')}>
                <span className="flex flex-col">
                  <span className="tabular-nums">{when(requisition.closed_at)}</span>
                  <span className="text-caption text-ink-muted">{requisition.close_reason}</span>
                </span>
              </Detail>
            ) : null}
            {requisition.cancelled_at ? (
              <Detail label={t('stores.requisition.cancelledAt')}>
                <span className="flex flex-col">
                  <span className="tabular-nums">{when(requisition.cancelled_at)}</span>
                  <span className="text-caption text-ink-muted">{requisition.cancel_reason}</span>
                </span>
              </Detail>
            ) : null}
          </dl>
        </Card>
        <Card title={t('stores.lines.title')}>
          <DataTable caption={t('stores.lines.title')} columns={lineColumns} rows={requisition.lines ?? []} />
        </Card>
        {requisition.can?.issue ? (
          <IssuePanel
            key={requisition.lines?.map((line) => line.qty_open).join('|')}
            requisition={requisition}
            onIssued={async (response) => {
              toast.success(t('stores.issue.done', { number: response?.data?.number ?? '' }))
              await queryClient.invalidateQueries({ queryKey: storesKey })
              navigate(`/stores/issues/${response.data.id}`)
            }}
          />
        ) : null}
        {requisition.issues?.length ? (
          <Card title={t('stores.requisition.issues')}>
            <ul className="flex flex-col gap-2">
              {requisition.issues.map((issue) => (
                <li key={issue.id} className="flex flex-wrap items-center gap-3">
                  <Link to={`/stores/issues/${issue.id}`} className="font-medium text-primary tabular-nums hover:text-primary-hover">
                    {issue.number}
                  </Link>
                  <DocumentStatus status={issue.status} />
                  <span className="text-caption text-ink-muted tabular-nums">{when(issue.issued_at)}</span>
                </li>
              ))}
            </ul>
          </Card>
        ) : null}
        <RequisitionFlow id={requisition.id} />
      </div>
      <ReasonDialog
        open={dialog === 'cancel'}
        title={t('stores.requisition.cancelTitle', { number: requisition.number })}
        help={t('stores.requisition.cancelHelp')}
        confirm={t('stores.requisition.confirmCancel')}
        path={`stores/requisitions/${requisition.id}/cancel`}
        onClose={() => setDialog(null)}
        onDone={async (response) => {
          toast.success(t('stores.requisition.cancelled'))
          await refresh(response)
        }}
      />
      <ReasonDialog
        open={dialog === 'close'}
        tone="primary"
        title={t('stores.requisition.closeTitle', { number: requisition.number })}
        help={t('stores.requisition.closeHelp')}
        confirm={t('stores.requisition.confirmClose')}
        path={`stores/requisitions/${requisition.id}/close`}
        onClose={() => setDialog(null)}
        onDone={async (response) => {
          toast.success(t('stores.requisition.closed'))
          await refresh(response)
        }}
      />
    </>
  )
}
