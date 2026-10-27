import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, DataTable, Dialog, Money, MoneyInput, Select, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { saveFile } from '@/lib/files'
import { ActionsLabel, PlaceBar, QueryNotes, ReasonDialog, Status, When } from './SalonParts'
import {
  COMMISSION_APPROVE,
  COMMISSION_BUILD,
  COMMISSION_EXPORT,
  COMMISSION_RULE,
  queryString,
  salonError,
  salonKey,
  useSalonPlace,
  useServices,
  useStaff,
} from './salonData'

/** SAL-23, SAL-24: a batch: its payouts per staff member and the steps from draft to export. */
function BatchDialog({ id, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const query = useQuery({ queryKey: [...salonKey, 'batch', id], queryFn: () => api.get(`salon/commission-batches/${id}`) })
  const [rejecting, setRejecting] = useState(false)
  const batch = query.data?.data
  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: salonKey })
    setRejecting(false)
  }
  const step = useMutation({ mutationFn: (verb) => api.post(`salon/commission-batches/${id}/${verb}`, {}), onSuccess: (response) => {
    if (response?.refused?.length) toast.warning(t('salon.commissions.refused', { count: response.refused.length }))
    else toast.success(t('salon.commissions.stepDone'))
    refresh()
  } })
  const csv = useMutation({
    mutationFn: () => api.download(`salon/commission-batches/${id}/csv`),
    onSuccess: ({ blob, filename }) => saveFile(blob, filename ?? 'commissions.csv'),
  })
  const columns = [
    { key: 'staff', label: t('salon.commissions.staff'), render: (row) => row.staff_name },
    { key: 'entries', label: t('salon.commissions.entries'), align: 'end', render: (row) => row.entry_count },
    { key: 'amount', label: t('salon.commissions.amount'), align: 'end', render: (row) => <Money amount={row.amount_minor} currency={row.currency} /> },
    { key: 'status', label: t('salon.fields.status'), render: (row) => <Status status={row.status} /> },
  ]
  return (
    <>
      <Dialog open title={batch ? t('salon.commissions.batchTitle', { number: batch.number }) : t('salon.commissions.batchLoading')} onClose={onClose} footer={<Button onClick={onClose}>{t('salon.close')}</Button>}>
        <div className="flex flex-col gap-4 pt-1">
          <QueryNotes query={query} />
          {step.isError ? <Alert tone="danger" title={salonError(step.error)} /> : null}
          {csv.isError ? <Alert tone="danger" title={salonError(csv.error)} /> : null}
          {batch ? (
            <>
              <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-ink-muted">{t('salon.commissions.period', { from: batch.period_start, to: batch.period_end })}</p>
                <Status status={batch.status} />
              </div>
              <DataTable caption={t('salon.commissions.payouts')} columns={columns} rows={batch.payouts} />
              <p className="flex justify-between gap-3">
                <span>{t('salon.commissions.total')}</span>
                <Money amount={batch.total_minor} currency={batch.currency} />
              </p>
              {batch.reject_reason ? <Alert tone="warning" title={t('salon.commissions.rejectedBecause', { reason: batch.reject_reason })} /> : null}
              <p className="text-caption text-ink-muted">{t('salon.commissions.fourEyes')}</p>
              <div className="flex flex-wrap gap-2">
                {batch.status === 'draft' && can(COMMISSION_BUILD) ? (
                  <Button variant="primary" loading={step.isPending} onClick={() => step.mutate('submit')}>
                    {t('salon.commissions.submit')}
                  </Button>
                ) : null}
                {batch.status === 'submitted' && can(COMMISSION_APPROVE) ? (
                  <>
                    <Button variant="primary" loading={step.isPending} onClick={() => step.mutate('approve')}>
                      {t('salon.commissions.approve')}
                    </Button>
                    <Button onClick={() => setRejecting(true)}>{t('salon.commissions.reject')}</Button>
                  </>
                ) : null}
                {batch.status === 'approved' && can(COMMISSION_EXPORT) ? (
                  <Button variant="primary" loading={step.isPending} onClick={() => step.mutate('export')}>
                    {t('salon.commissions.export')}
                  </Button>
                ) : null}
                <Button variant="ghost" loading={csv.isPending} onClick={() => csv.mutate()}>
                  {t('salon.commissions.csv')}
                </Button>
              </div>
            </>
          ) : null}
        </div>
      </Dialog>
      {rejecting ? <ReasonDialog title={t('salon.commissions.rejectTitle')} label={t('salon.appointment.reason')} confirm={t('salon.commissions.reject')} variant="danger" path={`salon/commission-batches/${id}/reject`} onClose={() => setRejecting(false)} onDone={refresh} /> : null}
    </>
  )
}

/** SAL-23: builds a batch for a period from the entries not yet in one. */
function BuildDialog({ company, onClose, onBuilt }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [values, setValues] = useState({ period_start: '', period_end: '', currency: company.base_currency })
  const build = useMutation({
    mutationFn: () => api.post('salon/commission-batches', { company_id: company.id, ...values }),
    onSuccess: (response) => {
      toast.success(t('salon.commissions.built'))
      queryClient.invalidateQueries({ queryKey: salonKey })
      onBuilt(response.data.id)
    },
  })
  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }))
  return (
    <Dialog
      open
      title={t('salon.commissions.buildTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={build.isPending} disabled={!values.period_start || !values.period_end} onClick={() => build.mutate()}>
            {t('salon.commissions.build')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        <p className="text-caption text-ink-muted">{t('salon.commissions.buildHelp')}</p>
        {build.isError ? <Alert tone="danger" title={salonError(build.error)} /> : null}
        <div className="grid grid-cols-2 gap-4">
          <TextField label={t('salon.reports.from')} type="date" value={values.period_start} onChange={set('period_start')} />
          <TextField label={t('salon.reports.to')} type="date" value={values.period_end} onChange={set('period_end')} />
        </div>
        <TextField label={t('salon.commissions.currency')} value={values.currency} maxLength={3} onChange={(event) => setValues((current) => ({ ...current, currency: event.target.value.toUpperCase() }))} className="max-w-32" />
      </div>
    </Dialog>
  )
}

/** SAL-20: a commission rule, effective-dated: a staff member or all, a service or all, or product sales. */
function RuleDialog({ rule, company, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const staff = useStaff(company.id).rows.filter((entry) => !entry.archived_at)
  const services = useServices(company.id).rows.filter((entry) => !entry.archived_at)
  const [v, setV] = useState({
    staff_id: rule?.staff_id ?? '',
    service_id: rule?.service_id ?? '',
    applies_to: rule?.applies_to ?? 'service',
    kind: rule?.kind ?? 'percent',
    rate: rule?.rate ?? '',
    amount_minor: rule?.amount_minor ?? '',
    effective_from: rule?.effective_from ?? '',
    effective_to: rule?.effective_to ?? '',
  })
  const set = (field) => (event) => setV((current) => ({ ...current, [field]: event.target.value }))
  const save = useMutation({
    mutationFn: () => {
      const body = {
        staff_id: v.staff_id || null,
        service_id: v.applies_to === 'service' ? v.service_id || null : null,
        applies_to: v.applies_to,
        kind: v.kind,
        rate: v.kind === 'percent' ? v.rate : null,
        amount_minor: v.kind === 'fixed' ? v.amount_minor : null,
        currency: v.kind === 'fixed' ? company.base_currency : null,
        effective_from: v.effective_from,
        effective_to: v.effective_to || null,
      }
      return rule ? api.put(`salon/commission-rules/${rule.id}`, body) : api.post('salon/commission-rules', { ...body, company_id: company.id })
    },
    onSuccess: () => {
      toast.success(t('salon.rules.saved'))
      queryClient.invalidateQueries({ queryKey: salonKey })
      onClose()
    },
  })
  const errors = formErrors(save.error, ['staff_id', 'service_id', 'rate', 'amount_minor', 'effective_from', 'effective_to'])
  const valid = v.effective_from && (v.kind === 'percent' ? v.rate !== '' : v.amount_minor !== '')
  return (
    <Dialog
      open
      title={rule ? t('salon.rules.editTitle') : t('salon.rules.addTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={save.isPending} disabled={!valid} onClick={() => save.mutate()}>
            {t('salon.rules.save')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <Select label={t('salon.rules.appliesTo')} options={['service', 'product'].map((value) => ({ value, label: t(`salon.rules.applies.${value}`) }))} value={v.applies_to} onChange={set('applies_to')} />
        <Select label={t('salon.commissions.staff')} help={t('salon.rules.staffHelp')} options={[{ value: '', label: t('salon.rules.everyone') }, ...staff.map((entry) => ({ value: entry.id, label: entry.name }))]} value={v.staff_id} error={errors.fields.staff_id} onChange={set('staff_id')} />
        {v.applies_to === 'service' ? <Select label={t('salon.rules.service')} options={[{ value: '', label: t('salon.rules.everyService') }, ...services.map((entry) => ({ value: entry.id, label: entry.name }))]} value={v.service_id} error={errors.fields.service_id} onChange={set('service_id')} /> : null}
        <Select label={t('salon.rules.kind')} options={['percent', 'fixed'].map((value) => ({ value, label: t(`salon.rules.kinds.${value}`) }))} value={v.kind} onChange={set('kind')} />
        {v.kind === 'percent' ? (
          <TextField label={t('salon.rules.rate')} help={t('salon.rules.rateHelp')} inputMode="decimal" suffix="%" value={v.rate} error={errors.fields.rate} onChange={set('rate')} />
        ) : (
          <MoneyInput label={t('salon.rules.amount')} help={t('salon.rules.amountHelp')} currency={company.base_currency} value={v.amount_minor} error={errors.fields.amount_minor} onChange={(next) => setV((current) => ({ ...current, amount_minor: next ?? '' }))} />
        )}
        <div className="grid grid-cols-2 gap-4">
          <TextField label={t('salon.rules.from')} type="date" value={v.effective_from} error={errors.fields.effective_from} onChange={set('effective_from')} />
          <TextField label={t('salon.rules.to')} type="date" value={v.effective_to} error={errors.fields.effective_to} onChange={set('effective_to')} />
        </div>
      </div>
    </Dialog>
  )
}

/** A paged list from the API as a table. */
function useListQuery(name, path, params) {
  return useQuery({ queryKey: [...salonKey, name, params], queryFn: () => api.get(`${path}${queryString(params)}`), enabled: Boolean(params.company_id) })
}

/** SAL-20 to SAL-24: rules, the ledger, tips and batches. */
export default function Commissions() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const place = useSalonPlace()
  const company = place.company
  const params = { company_id: company?.id, per_page: 50 }
  const [tab, setTab] = useState('batches')
  const [dialog, setDialog] = useState(null)
  const batches = useListQuery('batches', 'salon/commission-batches', params)
  const entries = useListQuery('entries', 'salon/commissions', params)
  const tips = useListQuery('tips', 'salon/tips', params)
  const rules = useListQuery('rules', 'salon/commission-rules', { company_id: company?.id })
  const archive = useMutation({ mutationFn: (rule) => api.post(`salon/commission-rules/${rule.id}/archive`, {}), onSuccess: () => queryClient.invalidateQueries({ queryKey: salonKey }) })
  const canRule = can(COMMISSION_RULE)
  const batchColumns = [
    { key: 'number', label: t('salon.commissions.number'), render: (row) => row.number },
    { key: 'period', label: t('salon.commissions.periodShort'), render: (row) => `${row.period_start} – ${row.period_end}` },
    { key: 'staff', label: t('salon.commissions.staffCount'), align: 'end', render: (row) => row.staff_count },
    { key: 'total', label: t('salon.commissions.total'), align: 'end', render: (row) => <Money amount={row.total_minor} currency={row.currency} /> },
    { key: 'status', label: t('salon.fields.status'), render: (row) => <Status status={row.status} /> },
    {
      key: 'actions',
      label: <ActionsLabel />,
      align: 'end',
      render: (row) => (
        <Button variant="ghost" aria-label={t('salon.commissions.openNamed', { number: row.number })} onClick={() => setDialog({ type: 'batch', id: row.id })}>
          {t('salon.commissions.open')}
        </Button>
      ),
    },
  ]
  const entryColumns = [
    { key: 'when', label: t('salon.commissions.when'), render: (row) => <When value={row.earned_at} /> },
    { key: 'staff', label: t('salon.commissions.staff'), render: (row) => row.staff_name },
    { key: 'kind', label: t('salon.commissions.kind'), render: (row) => t(`salon.commissions.kinds.${row.kind}`) },
    { key: 'line', label: t('salon.commissions.line'), render: (row) => t(`salon.commissions.lineKinds.${row.line_kind}`) },
    { key: 'base', label: t('salon.commissions.base'), align: 'end', render: (row) => <Money amount={row.base_minor} currency={row.currency} /> },
    { key: 'amount', label: t('salon.commissions.amount'), align: 'end', render: (row) => <Money amount={row.amount_minor} currency={row.currency} /> },
    { key: 'batch', label: t('salon.commissions.batched'), render: (row) => (row.batch_id ? t('salon.yes') : t('salon.no')) },
  ]
  const tipColumns = [
    { key: 'when', label: t('salon.commissions.when'), render: (row) => <When value={row.earned_at} /> },
    { key: 'staff', label: t('salon.commissions.staff'), render: (row) => row.staff_name },
    { key: 'kind', label: t('salon.commissions.kind'), render: (row) => t(`salon.commissions.tipKinds.${row.kind}`) },
    { key: 'amount', label: t('salon.commissions.amount'), align: 'end', render: (row) => <Money amount={row.amount_minor} currency={row.currency} /> },
  ]
  const ruleColumns = [
    { key: 'applies', label: t('salon.rules.appliesTo'), render: (row) => t(`salon.rules.applies.${row.applies_to}`) },
    { key: 'staff', label: t('salon.commissions.staff'), render: (row) => row.staff_name ?? t('salon.rules.everyone') },
    { key: 'service', label: t('salon.rules.service'), render: (row) => (row.applies_to === 'product' ? '' : (row.service_name ?? t('salon.rules.everyService'))) },
    { key: 'value', label: t('salon.rules.value'), align: 'end', render: (row) => (row.kind === 'percent' ? `${row.rate}%` : <Money amount={row.amount_minor} currency={row.currency} />) },
    { key: 'from', label: t('salon.rules.from'), render: (row) => row.effective_from },
    { key: 'to', label: t('salon.rules.to'), render: (row) => row.effective_to ?? '' },
    {
      key: 'actions',
      label: <ActionsLabel />,
      align: 'end',
      render: (row) =>
        canRule && !row.archived_at ? (
          <div className="flex justify-end gap-2">
            <Button variant="ghost" aria-label={t('salon.rules.edit')} onClick={() => setDialog({ type: 'rule', rule: row })}>
              {t('salon.rules.edit')}
            </Button>
            <Button variant="ghost" aria-label={t('salon.rules.archive')} loading={archive.isPending} onClick={() => archive.mutate(row)}>
              {t('salon.rules.archive')}
            </Button>
          </div>
        ) : null,
    },
  ]
  const section = (query, columns, empty, caption) => (
    <>
      <QueryNotes query={query} />
      {!query.isError ? <DataTable caption={caption} columns={columns} rows={query.data?.data ?? []} loading={query.isPending && Boolean(company)} emptyText={empty} /> : null}
    </>
  )
  return (
    <>
      <PageHeader
        title={t('salon.commissions.title')}
        description={t('salon.commissions.description')}
        actions={
          company ? (
            tab === 'batches' && can(COMMISSION_BUILD) ? (
              <Button variant="primary" icon="plus" onClick={() => setDialog({ type: 'build' })}>
                {t('salon.commissions.build')}
              </Button>
            ) : tab === 'rules' && canRule ? (
              <Button variant="primary" icon="plus" onClick={() => setDialog({ type: 'rule', rule: null })}>
                {t('salon.rules.add')}
              </Button>
            ) : null
          ) : null
        }
      />
      <div className="flex flex-col gap-5">
        <PlaceBar place={place} branch={false} />
        <Tabs
          items={[
            { value: 'batches', label: t('salon.commissions.batches') },
            { value: 'entries', label: t('salon.commissions.ledger') },
            { value: 'tips', label: t('salon.commissions.tips') },
            { value: 'rules', label: t('salon.rules.tab') },
          ]}
          value={tab}
          onChange={setTab}
        />
        {tab === 'batches' ? section(batches, batchColumns, t('salon.commissions.noBatches'), t('salon.commissions.batches')) : null}
        {tab === 'entries' ? section(entries, entryColumns, t('salon.commissions.noEntries'), t('salon.commissions.ledger')) : null}
        {tab === 'tips' ? section(tips, tipColumns, t('salon.commissions.noTips'), t('salon.commissions.tips')) : null}
        {tab === 'rules' ? section(rules, ruleColumns, t('salon.rules.empty'), t('salon.rules.tab')) : null}
      </div>
      {dialog?.type === 'batch' ? <BatchDialog id={dialog.id} onClose={() => setDialog(null)} /> : null}
      {dialog?.type === 'build' ? <BuildDialog company={company} onClose={() => setDialog(null)} onBuilt={(id) => setDialog({ type: 'batch', id })} /> : null}
      {dialog?.type === 'rule' ? <RuleDialog key={dialog.rule?.id ?? 'new'} rule={dialog.rule} company={company} onClose={() => setDialog(null)} /> : null}
    </>
  )
}
