import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, DataTable, Dialog, Money, MoneyInput, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatDecimal } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { CalendarDate } from './CrmParts'
import { useCrmAction } from './crmHelpers'
import { crmKey, namedOptions, TARGET_MANAGE, TARGET_METRICS, TARGET_SUBJECTS, useAssignees, useCrmCompany, usePlaces, useTeams } from './crmData'

const TARGET_FIELDS = ['subject_type', 'subject_id', 'metric', 'period_start', 'period_end', 'amount_minor', 'branch_id']

/** CRM-30: a target for a user, team or branch, a metric and a period, in the company's base currency. */
function TargetDialog({ target, company, onClose, onDone }) {
  const { t } = useTranslation()
  const places = usePlaces()
  const users = useAssignees(null)
  const teams = useTeams(company.id)
  const editing = Boolean(target)
  const [values, setValues] = useState(() => ({
    subject_type: target?.subject_type ?? 'user',
    subject_id: target?.subject?.id ?? '',
    metric: target?.metric ?? 'won_value',
    period_start: target?.period_start ?? '',
    period_end: target?.period_end ?? '',
  }))
  const [amount, setAmount] = useState(target?.amount_minor ?? '')
  const currency = target?.currency ?? company.base_currency
  const set = (name, value) => setValues((current) => ({ ...current, [name]: value }))
  const subjects = {
    user: users,
    team: teams.active,
    branch: places.activeBranches.filter((branch) => branch.company_id === company.id),
  }[values.subject_type]
  const save = useMutation({
    mutationFn: () => {
      const body = { ...values, amount_minor: amount, ...(values.subject_type === 'branch' ? { branch_id: values.subject_id } : {}) }
      return editing ? api.patch(`crm/targets/${target.id}`, body) : api.post('crm/targets', { company_id: company.id, ...body })
    },
    onSuccess: onDone,
  })
  const errors = formErrors(save.error, TARGET_FIELDS)
  const ready = values.subject_id && values.period_start && values.period_end && amount !== '' && amount !== null
  return (
    <Dialog
      open
      size="lg"
      title={t(editing ? 'crm.targets.editTitle' : 'crm.targets.newTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('crm.actions.back')}</Button>
          <Button variant="primary" loading={save.isPending} disabled={!ready} onClick={() => save.mutate()}>{t('crm.targets.save')}</Button>
        </>
      }
    >
      <div className="grid gap-4 pt-1 sm:grid-cols-2">
        {errors.form ? <Alert tone="danger" title={errors.form} className="sm:col-span-2" /> : null}
        <Select
          label={t('crm.fields.subjectType')}
          options={TARGET_SUBJECTS.map((type) => ({ value: type, label: t(`crm.targets.subjects.${type}`) }))}
          value={values.subject_type}
          error={errors.fields.subject_type}
          onChange={(event) => {
            set('subject_type', event.target.value)
            set('subject_id', '')
          }}
        />
        <Select
          label={t(`crm.targets.subjects.${values.subject_type}`)}
          placeholder={t('crm.pickers.subject')}
          options={namedOptions(subjects)}
          value={values.subject_id}
          required
          error={errors.fields.subject_id ?? errors.fields.branch_id}
          onChange={(event) => set('subject_id', event.target.value)}
        />
        <Select
          label={t('crm.fields.metric')}
          options={TARGET_METRICS.map((metric) => ({ value: metric, label: t(`crm.targets.metrics.${metric}`) }))}
          value={values.metric}
          error={errors.fields.metric}
          onChange={(event) => set('metric', event.target.value)}
          className="sm:col-span-2"
        />
        <TextField type="date" label={t('crm.fields.periodStart')} value={values.period_start} required error={errors.fields.period_start} onChange={(event) => set('period_start', event.target.value)} />
        <TextField type="date" label={t('crm.fields.periodEnd')} value={values.period_end} required error={errors.fields.period_end} onChange={(event) => set('period_end', event.target.value)} />
        <MoneyInput label={t('crm.fields.amount')} help={t('crm.targets.amountHelp')} currency={currency} value={amount} required error={errors.fields.amount_minor} onChange={setAmount} />
      </div>
    </Dialog>
  )
}

/** Percent achieved as a quiet bar and a number. */
function Progress({ target }) {
  const { t } = useTranslation()
  const locale = useLocale()
  if (!target.available) return <span className="text-ink-muted">{t('crm.targets.notAvailable')}</span>
  const percent = target.percent === null || target.percent === undefined ? null : Number(target.percent)
  if (percent === null) return <span className="text-ink-muted">—</span>
  const text = `${formatDecimal(String(target.percent), locale)} %`
  return (
    <span className="flex items-center gap-2">
      <progress max={100} value={Math.min(Math.max(percent, 0), 100)} aria-label={t('crm.targets.achieved', { percent: text })} className="h-2 w-full min-w-0 accent-primary" />
      <span className="shrink-0 tabular-nums">{text}</span>
    </span>
  )
}

/**
 * CRM-30: sales targets of a company with actuals and percent achieved
 * (computed when read; "Not available" when the metric's module is off),
 * and New, Edit and Archive for holders of crm.target.manage.
 */
export default function Targets() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const { company, companies, choose } = useCrmCompany()
  const [dialog, setDialog] = useState(null)
  const key = [...crmKey, 'targets', company?.id]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`crm/targets?company=${company.id}&per_page=200`), enabled: Boolean(company) })
  const action = useCrmAction(null)
  const rows = (query.data?.data ?? []).filter((target) => !target.archived_at)
  const manage = can(TARGET_MANAGE)
  const columns = [
    {
      key: 'subject',
      label: t('crm.columns.subject'),
      render: (row) => (
        <span className="flex flex-col">
          <span className="font-medium text-ink">{row.subject?.name}</span>
          <span className="text-caption text-ink-muted">{t(`crm.targets.subjects.${row.subject_type}`)}</span>
        </span>
      ),
    },
    { key: 'metric', label: t('crm.columns.metric'), render: (row) => t(`crm.targets.metrics.${row.metric}`, { defaultValue: row.metric }) },
    {
      key: 'period',
      label: t('crm.columns.period'),
      render: (row) => (
        <span className="whitespace-nowrap">
          <CalendarDate value={row.period_start} /> – <CalendarDate value={row.period_end} />
        </span>
      ),
    },
    { key: 'amount', label: t('crm.columns.target'), align: 'end', render: (row) => <Money amount={row.amount_minor} currency={row.currency} /> },
    { key: 'actual', label: t('crm.columns.actual'), align: 'end', render: (row) => (row.available && row.actual_minor !== null && row.actual_minor !== undefined ? <Money amount={row.actual_minor} currency={row.currency} /> : <span className="text-ink-muted">{row.unavailable ? t(`crm.targets.unavailable.${row.unavailable}`) : '—'}</span>) },
    { key: 'percent', label: t('crm.columns.achieved'), render: (row) => <Progress target={row} /> },
    ...(manage
      ? [
          {
            key: 'actions',
            label: t('crm.columns.actions'),
            align: 'end',
            render: (row) =>
              row.can?.manage !== false ? (
                <span className="flex justify-end gap-2">
                  <Button variant="ghost" icon="edit" aria-label={t('crm.targets.editNamed', { name: row.subject?.name })} onClick={() => setDialog(row)} />
                  <Button variant="ghost" icon="archive" aria-label={t('crm.targets.archiveNamed', { name: row.subject?.name })} onClick={() => action.mutate({ path: `crm/targets/${row.id}/archive`, done: 'crm.targets.archived' })} />
                </span>
              ) : null,
          },
        ]
      : []),
  ]

  return (
    <>
      <PageHeader
        title={t('crm.targets.title')}
        description={t('crm.targets.description')}
        actions={manage && company ? <Button variant="primary" icon="plus" onClick={() => setDialog('new')}>{t('crm.targets.new')}</Button> : null}
      />
      <div className="flex flex-col gap-4">
        {companies.length > 1 ? <Select label={t('crm.fields.company')} options={namedOptions(companies)} value={company?.id ?? ''} onChange={(event) => choose(event.target.value)} className="max-w-field" /> : null}
        {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
        <DataTable caption={t('crm.targets.title')} columns={columns} rows={rows} loading={query.isPending && Boolean(company)} emptyText={t('crm.targets.empty')} />
      </div>
      {dialog && company ? (
        <TargetDialog
          target={dialog === 'new' ? null : dialog}
          company={company}
          onClose={() => setDialog(null)}
          onDone={async () => {
            toast.success(t(dialog === 'new' ? 'crm.targets.created' : 'crm.targets.saved'))
            setDialog(null)
            await queryClient.invalidateQueries({ queryKey: crmKey })
          }}
        />
      ) : null}
    </>
  )
}
