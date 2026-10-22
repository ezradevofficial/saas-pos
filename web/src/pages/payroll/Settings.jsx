import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Checkbox, DataTable, DecimalInput, Select, StatusBadge, Switch, Tabs, TextField } from '@/components/ds'
import { ResearchStatus, ResearchedNotice } from '@/components/ResearchedNotice'
import { PageHeader } from '@/layouts/PageHeader'
import { useHrCompany } from '@/pages/hr/hrData'
import { codeLabel, enumLabel, enumOptions, FREQUENCIES, ITEM_KINDS, payrollKey, PERM, usePayrollMutation } from './payrollData'
import { CalendarDate, CompanyPicker, ConfirmDialog, Detail, FormDialog, NoCompany, PayrollStatus } from './PayrollParts'

const TABS = ['settings', 'payGroups', 'items', 'statutory']

/**
 * PAY-02 to PAY-04, PAY-08: a company's Payroll settings: multipliers and
 * standard hours and days, pay groups, pay items, and the statutory pack in
 * force with what it lacks (runs are refused until it is complete).
 */
export default function PayrollSettings() {
  const { t } = useTranslation()
  const chosen = useHrCompany()
  const [companyId, setCompanyId] = useState(null)
  const company = companyId ?? chosen?.id ?? null
  const [searchParams, setSearchParams] = useSearchParams()
  const tab = TABS.includes(searchParams.get('tab')) ? searchParams.get('tab') : 'settings'
  const setTab = (next) =>
    setSearchParams(
      (current) => {
        const params = new URLSearchParams(current)
        params.set('tab', next)
        return params
      },
      { replace: true },
    )
  const statutory = useQuery({ queryKey: [...payrollKey, 'statutory', company], queryFn: () => api.get(`payroll/statutory/${company}`), enabled: Boolean(company), retry: false })
  if (!company) return <NoCompany />
  const pack = statutory.data?.data
  return (
    <>
      <PageHeader title={t('payroll.settings.title')} description={t('payroll.settings.description')} />
      <div className="flex flex-wrap items-end gap-3">
        <CompanyPicker value={company} onChange={setCompanyId} />
      </div>
      {pack && (!pack.available || !pack.ready) && tab !== 'statutory' ? (
        <Alert
          tone="warning"
          title={pack.available ? t('payroll.statutory.notReadyTitle') : t('payroll.statutory.noPackTitle', { country: pack.country })}
          action={<Button onClick={() => setTab('statutory')}>{t('payroll.statutory.review')}</Button>}
        >
          {pack.available ? t('payroll.statutory.notReadyHelp') : t('payroll.statutory.noPackHelp')}
        </Alert>
      ) : null}
      <Tabs value={tab} onChange={setTab} items={TABS.map((value) => ({ value, label: t(`payroll.settings.tabs.${value}`) }))} />
      {tab === 'settings' ? <CompanySettings key={company} company={company} /> : null}
      {tab === 'payGroups' ? <PayGroups company={company} /> : null}
      {tab === 'items' ? <Items company={company} /> : null}
      {tab === 'statutory' ? <StatutoryPack query={statutory} company={company} /> : null}
    </>
  )
}

const MULTIPLIERS = ['overtime_multiplier', 'night_multiplier', 'holiday_multiplier', 'rest_day_multiplier']
const SETTING_FIELDS = [...MULTIPLIERS, 'monthly_hours', 'monthly_days', 'premiums_taxable', 'premiums_contributory']

/** PAY-02: multipliers (null: not paid extra), standard monthly hours and days, and how premiums count. */
function CompanySettings({ company }) {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const manage = can(PERM.settingsManage)
  const queryClient = useQueryClient()
  const key = [...payrollKey, 'settings', company]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`payroll/settings/${company}`) })
  const settings = query.data?.data
  if (!settings) return query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : <p className="text-ink-muted">{t('common.loading')}</p>
  return <SettingsForm key={JSON.stringify(settings)} settings={settings} manage={manage} company={company} onSaved={(response) => queryClient.setQueryData(key, response)} />
}

function SettingsForm({ settings, manage, company, onSaved }) {
  const { t } = useTranslation()
  const [form, setForm] = useState(() => Object.fromEntries(SETTING_FIELDS.map((field) => [field, settings[field] ?? (field.startsWith('premiums_') ? false : '')])))
  const [submitted, setSubmitted] = useState(false)
  const save = usePayrollMutation((body) => api.put(`payroll/settings/${company}`, body), { success: t('payroll.settings.saved'), onDone: onSaved })
  const errors = save.errors(SETTING_FIELDS)
  const valid = SETTING_FIELDS.every((field) => form[field] !== null)
  const submit = (event) => {
    event.preventDefault()
    setSubmitted(true)
    if (!valid) return
    save.mutate(Object.fromEntries(SETTING_FIELDS.map((field) => [field, form[field] === '' ? null : form[field]])))
  }
  const decimal = (field, options = {}) => (
    <DecimalInput
      label={t(`payroll.settings.fields.${field}`)}
      help={t(`payroll.settings.help.${field}`)}
      value={form[field]}
      maxDecimals={options.decimals ?? 4}
      maxIntegerDigits={options.digits ?? 3}
      disabled={!manage}
      showErrors={submitted}
      error={errors.fields[field]}
      onChange={(value) => setForm((current) => ({ ...current, [field]: value }))}
    />
  )
  return (
    <form noValidate onSubmit={submit} className="flex flex-col gap-5">
      {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
      <Card title={t('payroll.settings.multipliers')} subtitle={t('payroll.settings.multipliersHelp')}>
        <div className="grid gap-4 sm:grid-cols-2">{MULTIPLIERS.map((field) => <div key={field}>{decimal(field)}</div>)}</div>
      </Card>
      <Card title={t('payroll.settings.standard')} subtitle={t('payroll.settings.standardHelp')}>
        <div className="grid gap-4 sm:grid-cols-2">
          {decimal('monthly_hours', { decimals: 2, digits: 3 })}
          {decimal('monthly_days', { decimals: 2, digits: 2 })}
        </div>
      </Card>
      <Card title={t('payroll.settings.premiums')} subtitle={t('payroll.settings.premiumsHelp')}>
        <div className="flex flex-col gap-3">
          <Switch label={t('payroll.settings.fields.premiums_taxable')} checked={form.premiums_taxable} disabled={!manage} onChange={(value) => setForm({ ...form, premiums_taxable: value })} />
          <Switch label={t('payroll.settings.fields.premiums_contributory')} checked={form.premiums_contributory} disabled={!manage} onChange={(value) => setForm({ ...form, premiums_contributory: value })} />
        </div>
      </Card>
      {manage ? (
        <div>
          <Button type="submit" variant="primary" loading={save.isPending}>
            {t('payroll.settings.save')}
          </Button>
        </div>
      ) : null}
    </form>
  )
}

/** PAY-03: the company's pay groups; archived, never deleted (TEN-06). */
function PayGroups({ company }) {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const manage = can(PERM.settingsManage)
  const [status, setStatus] = useState('active')
  const [dialog, setDialog] = useState(null)
  const query = useQuery({ queryKey: [...payrollKey, 'pay-groups', company, status], queryFn: () => api.get(`payroll/pay-groups?company=${company}&status=${status}&per_page=200`) })
  const archive = usePayrollMutation((row) => api.post(`payroll/pay-groups/${row.id}/archive`, {}), { success: t('payroll.payGroups.archived'), onDone: () => setDialog(null) })
  return (
    <Card
      title={t('payroll.payGroups.title')}
      subtitle={t('payroll.payGroups.help')}
      actions={
        manage ? (
          <Button variant="primary" icon="plus" onClick={() => setDialog({ kind: 'edit' })}>
            {t('payroll.payGroups.add')}
          </Button>
        ) : null
      }
    >
      <div className="flex flex-col gap-3">
        <Tabs value={status} onChange={setStatus} items={['active', 'archived'].map((value) => ({ value, label: t(`payroll.statuses.${value}`) }))} className="w-auto" />
        {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
        <DataTable
          caption={t('payroll.payGroups.title')}
          loading={query.isLoading}
          rows={query.data?.data ?? []}
          emptyText={t('payroll.payGroups.empty')}
          columns={[
            { key: 'code', label: t('payroll.columns.code'), render: (row) => <span className="font-medium tabular-nums">{row.code}</span> },
            { key: 'name', label: t('payroll.columns.name'), render: (row) => row.name },
            { key: 'frequency', label: t('payroll.columns.frequency'), render: (row) => enumLabel(t, 'frequencies', row.frequency) },
            {
              key: 'schedule',
              label: t('payroll.columns.schedule'),
              render: (row) =>
                row.frequency === 'monthly' ? (
                  row.pay_day ? (
                    t('payroll.payGroups.payDay', { day: row.pay_day })
                  ) : (
                    t('payroll.payGroups.lastDay')
                  )
                ) : (
                  <span className="flex gap-1">
                    {t('payroll.payGroups.anchor')} <CalendarDate value={row.anchor_date} />
                  </span>
                ),
            },
            { key: 'status', label: t('payroll.columns.status'), render: (row) => <PayrollStatus status={row.archived_at ? 'archived' : 'active'} /> },
            {
              key: 'actions',
              label: '',
              align: 'end',
              render: (row) =>
                manage && !row.archived_at ? (
                  <span className="flex justify-end gap-2">
                    <Button variant="ghost" icon="edit" onClick={() => setDialog({ kind: 'edit', row })}>
                      {t('payroll.actions.edit')}
                    </Button>
                    <Button variant="ghost" icon="archive" onClick={() => setDialog({ kind: 'archive', row })}>
                      {t('payroll.actions.archive')}
                    </Button>
                  </span>
                ) : null,
            },
          ]}
        />
      </div>
      {dialog?.kind === 'edit' ? <PayGroupDialog company={company} row={dialog.row} onClose={() => setDialog(null)} /> : null}
      <ConfirmDialog
        open={dialog?.kind === 'archive'}
        title={t('payroll.payGroups.archiveTitle', { name: codeLabel(dialog?.row) })}
        text={t('payroll.payGroups.archiveHelp')}
        confirmLabel={t('payroll.actions.archive')}
        tone="danger"
        pending={archive.isPending}
        error={archive.error}
        onConfirm={() => archive.mutate(dialog.row)}
        onClose={() => setDialog(null)}
      />
    </Card>
  )
}

function PayGroupDialog({ company, row, onClose }) {
  const { t } = useTranslation()
  const [form, setForm] = useState(() => ({ code: row?.code ?? '', name: row?.name ?? '', frequency: row?.frequency ?? 'monthly', anchor_date: row?.anchor_date ?? '', pay_day: row?.pay_day ? String(row.pay_day) : '' }))
  const save = usePayrollMutation((body) => (row ? api.patch(`payroll/pay-groups/${row.id}`, body) : api.post('payroll/pay-groups', { company_id: company, ...body })), { success: t('payroll.payGroups.saved'), onDone: onClose })
  const errors = save.errors(['code', 'name', 'frequency', 'anchor_date', 'pay_day'])
  const monthly = form.frequency === 'monthly'
  const dayValid = form.pay_day === '' || (/^\d{1,2}$/.test(form.pay_day) && Number(form.pay_day) >= 1 && Number(form.pay_day) <= 31)
  const ready = form.code.trim() && form.name.trim() && (monthly ? dayValid : form.anchor_date)
  const set = (key) => (event) => setForm({ ...form, [key]: event.target.value })
  const body = () => ({
    code: form.code.trim(),
    name: form.name.trim(),
    ...(row ? {} : { frequency: form.frequency }),
    ...(monthly ? { pay_day: form.pay_day ? Number(form.pay_day) : null } : { anchor_date: form.anchor_date }),
  })
  return (
    <FormDialog open title={row ? t('payroll.payGroups.editTitle') : t('payroll.payGroups.addTitle')} onClose={onClose} submitLabel={t('payroll.payGroups.saveGroup')} pending={save.isPending} disabled={!ready} errors={errors} onSubmit={() => save.mutate(body())}>
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField label={t('payroll.columns.code')} value={form.code} required maxLength={40} error={errors.fields.code} onChange={set('code')} />
        <TextField label={t('payroll.columns.name')} value={form.name} required maxLength={120} error={errors.fields.name} onChange={set('name')} />
        <Select label={t('payroll.columns.frequency')} options={enumOptions(t, 'frequencies', FREQUENCIES)} value={form.frequency} required disabled={Boolean(row)} error={errors.fields.frequency} onChange={set('frequency')} />
        {monthly ? (
          <TextField
            type="number"
            inputMode="numeric"
            min={1}
            max={31}
            label={t('payroll.fields.payDay')}
            help={t('payroll.payGroups.payDayHelp')}
            value={form.pay_day}
            error={errors.fields.pay_day ?? (dayValid ? undefined : t('payroll.validation.payDay'))}
            onChange={set('pay_day')}
          />
        ) : (
          <TextField type="date" label={t('payroll.fields.anchorDate')} help={t('payroll.payGroups.anchorHelp')} value={form.anchor_date} required error={errors.fields.anchor_date} onChange={set('anchor_date')} />
        )}
      </div>
    </FormDialog>
  )
}

/** PAY-04: the company's pay items and their flags; the company decides them, nothing is assumed. */
function Items({ company }) {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const manage = can(PERM.settingsManage)
  const [status, setStatus] = useState('active')
  const [dialog, setDialog] = useState(null)
  const query = useQuery({ queryKey: [...payrollKey, 'items', company, status], queryFn: () => api.get(`payroll/items?company=${company}&status=${status}&per_page=200`) })
  const archive = usePayrollMutation((row) => api.post(`payroll/items/${row.id}/archive`, {}), { success: t('payroll.items.archived'), onDone: () => setDialog(null) })
  const flags = (row) =>
    row.kind === 'earning'
      ? [row.taxable ? t('payroll.flags.taxable') : null, row.contributory ? t('payroll.flags.contributory') : null]
      : [row.pre_tax ? t('payroll.flags.preTax') : null, row.insurance_premium ? t('payroll.flags.insurancePremium') : null]
  return (
    <Card
      title={t('payroll.items.title')}
      subtitle={t('payroll.items.help')}
      actions={
        manage ? (
          <Button variant="primary" icon="plus" onClick={() => setDialog({ kind: 'edit' })}>
            {t('payroll.items.add')}
          </Button>
        ) : null
      }
    >
      <div className="flex flex-col gap-3">
        <Tabs value={status} onChange={setStatus} items={['active', 'archived'].map((value) => ({ value, label: t(`payroll.statuses.${value}`) }))} className="w-auto" />
        {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
        <DataTable
          caption={t('payroll.items.title')}
          loading={query.isLoading}
          rows={query.data?.data ?? []}
          emptyText={t('payroll.items.empty')}
          columns={[
            { key: 'code', label: t('payroll.columns.code'), render: (row) => <span className="font-medium tabular-nums">{row.code}</span> },
            { key: 'name', label: t('payroll.columns.name'), render: (row) => row.name },
            { key: 'kind', label: t('payroll.columns.kind'), render: (row) => enumLabel(t, 'itemKinds', row.kind) },
            { key: 'flags', label: t('payroll.columns.flags'), wrap: true, render: (row) => flags(row).filter(Boolean).join(', ') || <span className="text-ink-muted">—</span> },
            { key: 'status', label: t('payroll.columns.status'), render: (row) => <PayrollStatus status={row.archived_at ? 'archived' : 'active'} /> },
            {
              key: 'actions',
              label: '',
              align: 'end',
              render: (row) =>
                manage && !row.archived_at ? (
                  <span className="flex justify-end gap-2">
                    <Button variant="ghost" icon="edit" onClick={() => setDialog({ kind: 'edit', row })}>
                      {t('payroll.actions.edit')}
                    </Button>
                    <Button variant="ghost" icon="archive" onClick={() => setDialog({ kind: 'archive', row })}>
                      {t('payroll.actions.archive')}
                    </Button>
                  </span>
                ) : null,
            },
          ]}
        />
      </div>
      {dialog?.kind === 'edit' ? <ItemDialog company={company} row={dialog.row} onClose={() => setDialog(null)} /> : null}
      <ConfirmDialog
        open={dialog?.kind === 'archive'}
        title={t('payroll.items.archiveTitle', { name: codeLabel(dialog?.row) })}
        text={t('payroll.items.archiveHelp')}
        confirmLabel={t('payroll.actions.archive')}
        tone="danger"
        pending={archive.isPending}
        error={archive.error}
        onConfirm={() => archive.mutate(dialog.row)}
        onClose={() => setDialog(null)}
      />
    </Card>
  )
}

const ITEM_FLAGS = { earning: ['taxable', 'contributory'], deduction: ['pre_tax', 'insurance_premium'] }
const PRE_TAX_CATEGORIES = ['pension', 'post_retirement_medical', 'mortgage_interest']

function ItemDialog({ company, row, onClose }) {
  const { t } = useTranslation()
  const [form, setForm] = useState(() => ({ code: row?.code ?? '', name: row?.name ?? '', kind: row?.kind ?? 'earning', taxable: row?.taxable ?? false, contributory: row?.contributory ?? false, pre_tax: row?.pre_tax ?? false, insurance_premium: row?.insurance_premium ?? false, pre_tax_category: row?.pre_tax_category ?? '' }))
  const save = usePayrollMutation((body) => (row ? api.patch(`payroll/items/${row.id}`, body) : api.post('payroll/items', { company_id: company, ...body })), { success: t('payroll.items.saved'), onDone: onClose })
  const errors = save.errors(['code', 'name', 'kind', 'taxable', 'contributory', 'pre_tax', 'insurance_premium', 'pre_tax_category'])
  const set = (key) => (event) => setForm({ ...form, [key]: event.target.value })
  const flags = ITEM_FLAGS[form.kind]
  // PAY-07: the statutory limit of a pre-tax deduction (KE: pension with NSSF, post-retirement medical fund, mortgage interest).
  const category = form.kind === 'deduction' ? { pre_tax_category: form.pre_tax && form.pre_tax_category ? form.pre_tax_category : null } : {}
  const body = () => ({ code: form.code.trim(), name: form.name.trim(), ...(row ? {} : { kind: form.kind }), ...Object.fromEntries(flags.map((flag) => [flag, form[flag]])), ...category })
  return (
    <FormDialog open title={row ? t('payroll.items.editTitle') : t('payroll.items.addTitle')} onClose={onClose} submitLabel={t('payroll.items.saveItem')} pending={save.isPending} disabled={!form.code.trim() || !form.name.trim()} errors={errors} onSubmit={() => save.mutate(body())}>
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField label={t('payroll.columns.code')} value={form.code} required maxLength={40} error={errors.fields.code} onChange={set('code')} />
        <TextField label={t('payroll.columns.name')} value={form.name} required maxLength={120} error={errors.fields.name} onChange={set('name')} />
        <Select label={t('payroll.columns.kind')} options={enumOptions(t, 'itemKinds', ITEM_KINDS)} value={form.kind} required disabled={Boolean(row)} error={errors.fields.kind} onChange={set('kind')} />
      </div>
      <fieldset className="flex flex-col gap-3">
        <legend className="mb-2 text-label text-ink">{t('payroll.items.flagsTitle')}</legend>
        {flags.map((flag) => (
          <Checkbox key={flag} label={t(`payroll.items.flags.${flag}`)} help={t(`payroll.items.flagHelp.${flag}`)} checked={form[flag]} onChange={(event) => setForm({ ...form, [flag]: event.target.checked })} />
        ))}
        {form.kind === 'deduction' && form.pre_tax ? (
          <Select
            label={t('payroll.items.preTaxCategory')}
            help={t('payroll.items.preTaxCategoryHelp')}
            options={[{ value: '', label: t('payroll.items.preTaxCategories.none') }, ...PRE_TAX_CATEGORIES.map((value) => ({ value, label: t(`payroll.items.preTaxCategories.${value}`) }))]}
            value={form.pre_tax_category}
            error={errors.fields.pre_tax_category}
            onChange={set('pre_tax_category')}
          />
        ) : null}
      </fieldset>
    </FormDialog>
  )
}

/**
 * PAY-08: the company's statutory pack in force today: each component and
 * what it lacks, the pack's own list of values to confirm, and its return
 * layouts. Runs are refused until every value a run needs is confirmed or
 * researched (CP-01: each value's status, research confidence and sources
 * are listed; this is the statutory rates page the researched notice links
 * to). A component the pack allows may be marked not applicable for the
 * company, with a reason (audited).
 */
function StatutoryPack({ query, company }) {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const manage = can(PERM.statutoryExempt)
  const [marking, setMarking] = useState(null)
  const mark = usePayrollMutation((body) => api.post(`payroll/statutory/${company}/not-applicable`, body), { success: t('payroll.statutory.markedNotApplicable'), onDone: () => setMarking(null) })
  const clear = usePayrollMutation((code) => api.delete(`payroll/statutory/${company}/not-applicable/${code}`), { success: t('payroll.statutory.markedApplicable') })
  const pack = query.data?.data
  if (query.isError) return <Alert tone="danger" title={errorMessage(query.error)} />
  if (!pack) return <p className="text-ink-muted">{t('common.loading')}</p>
  if (!pack.available) {
    return (
      <Alert tone="warning" title={t('payroll.statutory.noPackTitle', { country: pack.country })}>
        {t('payroll.statutory.noPackHelp')}
      </Alert>
    )
  }
  return (
    <div className="flex flex-col gap-5">
      {pack.ready ? (
        <Alert tone="success" title={t('payroll.statutory.readyTitle')}>
          {t('payroll.statutory.readyHelp')}
        </Alert>
      ) : (
        <Alert tone="danger" title={t('payroll.statutory.notReadyTitle')}>
          <div className="flex flex-col gap-1">
            <p>{t('payroll.statutory.notReadyHelp')}</p>
            {pack.missing ? <p>{pack.missing}</p> : null}
          </div>
        </Alert>
      )}
      {pack.researched ? <ResearchedNotice /> : null}
      <Card title={t('payroll.statutory.title')} subtitle={t('payroll.statutory.help')}>
        <div className="flex flex-col gap-4">
          <dl className="grid gap-4 sm:grid-cols-3">
            <Detail label={t('payroll.statutory.country')}>{pack.country}</Detail>
            <Detail label={t('payroll.statutory.currency')}>{pack.currency ?? '—'}</Detail>
            <Detail label={t('payroll.statutory.nonMonthly')}>{pack.non_monthly ? t(`payroll.statutory.nonMonthlyRules.${pack.non_monthly}`, { defaultValue: pack.non_monthly }) : t('payroll.statutory.notSet')}</Detail>
          </dl>
          <DataTable
            caption={t('payroll.statutory.title')}
            rows={(pack.components ?? []).map((row) => ({ ...row, id: row.code }))}
            emptyText={t('payroll.statutory.noComponents')}
            columns={[
              {
                key: 'name',
                label: t('payroll.columns.component'),
                render: (row) => (
                  <span className="flex flex-col">
                    <span className="text-ink">{row.name}</span>
                    <span className="font-mono text-caption text-ink-muted">{row.code}</span>
                  </span>
                ),
              },
              { key: 'kind', label: t('payroll.columns.kind'), render: (row) => t(`payroll.statutory.kinds.${row.kind}`, { defaultValue: row.kind }) },
              { key: 'method', label: t('payroll.columns.method'), render: (row) => <span className="font-mono text-caption">{row.method}</span> },
              { key: 'required', label: t('payroll.columns.applies'), render: (row) => (row.required ? t('payroll.statutory.always') : t('payroll.statutory.when', { condition: t(`payroll.statutory.conditions.${row.when}`, { defaultValue: row.when ?? '' }) })) },
              { key: 'effective_from', label: t('payroll.columns.inForceFrom'), render: (row) => (row.in_force ? <CalendarDate value={row.effective_from} /> : <span className="text-ink-muted">{t('payroll.statutory.noPeriod')}</span>) },
              {
                key: 'status',
                label: t('payroll.columns.valueStatus'),
                wrap: true,
                render: (row) => <ResearchStatus status={row.status} confidence={row.confidence} researchedOn={row.researched_on} source={row.source ?? []} assumed={(row.assumed ?? []).map((field) => field.name)} />,
              },
              {
                key: 'missing',
                label: t('payroll.columns.missing'),
                wrap: true,
                render: (row) =>
                  row.not_applicable ? (
                    <span className="flex flex-col items-start gap-1">
                      <StatusBadge>{t('payroll.statutory.notApplicable')}</StatusBadge>
                      <span className="text-caption text-ink-muted">{row.not_applicable.reason}</span>
                      {manage ? (
                        <Button variant="ghost" size="sm" loading={clear.isPending} onClick={() => clear.mutate(row.code)}>
                          {t('payroll.statutory.markApplicable')}
                        </Button>
                      ) : null}
                    </span>
                  ) : row.not_applied ? (
                    <span className="flex flex-col gap-1">
                      <StatusBadge>{t('payroll.statutory.notApplied')}</StatusBadge>
                      <span className="text-caption text-ink-muted">{t('payroll.statutory.notAppliedHelp')}</span>
                    </span>
                  ) : row.missing?.length ? (
                    <span className="flex flex-col gap-1">
                      <StatusBadge tone="danger">{t('payroll.statutory.valuesMissing', { count: row.missing.length })}</StatusBadge>
                      <span className="text-caption text-ink-muted">{row.missing.map((field) => field.name).join(', ')}</span>
                      {row.may_not_apply && manage ? (
                        <Button variant="secondary" size="sm" onClick={() => setMarking(row)}>
                          {t('payroll.statutory.markNotApplicable')}
                        </Button>
                      ) : null}
                    </span>
                  ) : (
                    <StatusBadge tone="success">{t('payroll.statutory.complete')}</StatusBadge>
                  ),
              },
            ]}
          />
        </div>
      </Card>
      <ConfirmDialog
        open={Boolean(marking)}
        title={t('payroll.statutory.markTitle', { component: marking?.name ?? '' })}
        text={t('payroll.statutory.markText')}
        reasonLabel={t('payroll.statutory.markReason')}
        reasonRequired
        confirmLabel={t('payroll.statutory.markNotApplicable')}
        pending={mark.isPending}
        error={mark.error}
        onConfirm={(reason) => mark.mutate({ component: marking.code, reason })}
        onClose={() => setMarking(null)}
      />
      {pack.todo?.length ? (
        <Card title={t('payroll.statutory.todo')} subtitle={t('payroll.statutory.todoHelp')}>
          <ul className="flex list-disc flex-col gap-1 pl-5 text-body">
            {pack.todo.map((entry) => (
              <li key={entry}>{entry}</li>
            ))}
          </ul>
        </Card>
      ) : null}
      {pack.returns?.length ? (
        <Card title={t('payroll.statutory.returns')}>
          <ul className="flex flex-col gap-2">
            {pack.returns.map((row) => (
              <li key={row.key} className="flex flex-wrap items-center justify-between gap-2">
                <span>{row.name}</span>
                {row.needs_confirmation ? <PayrollStatus status="to_confirm" label={t('payroll.returns.toConfirm')} /> : <PayrollStatus status="ready" label={t('payroll.returns.confirmed')} />}
              </li>
            ))}
          </ul>
        </Card>
      ) : null}
    </div>
  )
}
