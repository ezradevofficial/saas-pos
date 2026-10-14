import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams, useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, MultiSelect, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { Back, DetailState, LinesEditor, TextAreaField } from './ProcurementParts'
import { blankLine, lineBody, lineFieldNames, lineFromRecord, lineFromRequisition, lineReady } from './procurementHelpers'
import { companyById, companyOfBranch, fromLocalInput, namedOptions, procurementKey, toLocalInput, usePlaces, useSourceRequisition, useSuppliers } from './procurementData'

const FIELDS = ['branch_id', 'requisition_id', 'title', 'note', 'deadline_at', 'lines', 'supplier_ids']

function Form({ rfq, requisition }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const places = usePlaces()
  const suppliers = useSuppliers()
  const editing = Boolean(rfq)
  const [branch, setBranch] = useState(rfq?.branch?.id ?? requisition?.branch?.id ?? '')
  const companyId = companyOfBranch(places, branch)
  const zone = companyById(places, companyId)?.timezone
  const [title, setTitle] = useState(rfq?.title ?? requisition?.purpose ?? '')
  const [note, setNote] = useState(rfq?.note ?? '')
  const [deadline, setDeadline] = useState(() => toLocalInput(rfq?.deadline_at, zone))
  const [supplierIds, setSupplierIds] = useState(() => (rfq?.suppliers ?? []).map((party) => party.id))
  const [lines, setLines] = useState(() => {
    if (rfq?.lines?.length) return rfq.lines.map((line) => ({ ...lineFromRecord(line, null), requisition_line_id: line.requisition_line_id }))
    if (requisition?.lines?.length) return requisition.lines.map(lineFromRequisition)
    return [blankLine()]
  })
  const [submitted, setSubmitted] = useState(false)

  const save = useMutation({
    mutationFn: () => {
      const body = {
        title: title.trim(),
        note: note.trim() || null,
        deadline_at: fromLocalInput(deadline, zone),
        lines: lines.map((line) => lineBody(line, { requisitionLine: true })),
        supplier_ids: supplierIds,
      }
      if (editing) return api.patch(`procurement/rfqs/${rfq.id}`, body)
      return api.post('procurement/rfqs', { branch_id: branch, ...(requisition ? { requisition_id: requisition.id } : {}), ...body })
    },
    onSuccess: async (response) => {
      toast.success(t(editing ? 'procurement.rfq.saved' : 'procurement.rfq.created'))
      await queryClient.invalidateQueries({ queryKey: procurementKey })
      navigate(`/procurement/rfqs/${response.data.id}`)
    },
  })
  const errors = formErrors(save.error, [...FIELDS, ...lineFieldNames(lines.length)])
  const ready = branch && title.trim() && lines.every((line) => lineReady(line))
  const submit = (event) => {
    event.preventDefault()
    setSubmitted(true)
    if (ready) save.mutate()
  }

  return (
    <form noValidate onSubmit={submit} className="flex flex-col gap-5">
      {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
      {requisition ? <Alert tone="info" title={t('procurement.fromRequisition', { number: requisition.number })} /> : null}
      <Card>
        <div className="grid gap-4 sm:grid-cols-2">
          <Select
            label={t('procurement.fields.branch')}
            placeholder={t('procurement.pickers.branch')}
            options={namedOptions(places.activeBranches)}
            value={branch}
            required
            disabled={editing || Boolean(requisition)}
            error={errors.fields.branch_id ?? (submitted && !branch ? t('procurement.validation.branch') : undefined)}
            onChange={(event) => setBranch(event.target.value)}
          />
          <TextField
            label={t('procurement.fields.title')}
            value={title}
            maxLength={200}
            required
            error={errors.fields.title ?? (submitted && !title.trim() ? t('procurement.validation.title') : undefined)}
            onChange={(event) => setTitle(event.target.value)}
          />
          <TextField type="datetime-local" label={t('procurement.fields.deadline')} help={t('procurement.rfq.deadlineHelp')} value={deadline} error={errors.fields.deadline_at} onChange={(event) => setDeadline(event.target.value)} />
          <MultiSelect
            label={t('procurement.fields.invitedSuppliers')}
            placeholder={t('procurement.pickers.suppliers')}
            options={namedOptions(suppliers.active)}
            value={supplierIds}
            error={errors.fields.supplier_ids}
            onChange={setSupplierIds}
          />
          <TextAreaField label={t('procurement.fields.note')} value={note} maxLength={2000} error={errors.fields.note} onChange={(event) => setNote(event.target.value)} className="sm:col-span-2" />
        </div>
      </Card>
      <LinesEditor lines={lines} setLines={setLines} companyId={companyId} errors={errors} submitted={submitted} />
      <div className="flex flex-wrap gap-2">
        <Button variant="ghost" onClick={() => navigate(editing ? `/procurement/rfqs/${rfq.id}` : '/procurement/rfqs')}>
          {t('common.cancel')}
        </Button>
        <Button type="submit" variant="primary" loading={save.isPending}>
          {t('procurement.rfq.save')}
        </Button>
      </div>
    </form>
  )
}

/**
 * PRC-03: a new RFQ (blank, or from an approved requisition's lines) or a
 * draft's changes: branch, title, response deadline, note, lines and the
 * invited suppliers. It is issued from its page.
 */
export default function RfqForm() {
  const { t } = useTranslation()
  const { rfqId } = useParams()
  const [params] = useSearchParams()
  const source = useSourceRequisition(rfqId ? null : params.get('requisition'))
  const query = useQuery({ queryKey: [...procurementKey, 'rfq', rfqId], queryFn: () => api.get(`procurement/rfqs/${rfqId}`), enabled: Boolean(rfqId) })
  const back = <Back to={rfqId ? `/procurement/rfqs/${rfqId}` : '/procurement/rfqs'}>{t('procurement.rfqs.back')}</Back>
  if (rfqId && !query.data?.data) return <DetailState query={query} back={back} notFound={t('procurement.rfq.notFound')} />
  if (source.waiting) return <p className="text-ink-muted">{t('common.loading')}</p>
  return (
    <>
      {back}
      <PageHeader title={t(rfqId ? 'procurement.rfq.editTitle' : 'procurement.rfq.newTitle')} description={t('procurement.rfq.formHelp')} />
      <Form key={rfqId ?? source.requisition?.id ?? 'new'} rfq={query.data?.data} requisition={source.requisition} />
    </>
  )
}
