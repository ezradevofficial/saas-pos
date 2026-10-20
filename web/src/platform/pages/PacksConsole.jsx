import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, DataTable, Dialog, Select, StatusBadge, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCalendarDate } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { platformApi } from '../platformClient'
import { usePlatformAuth } from '../usePlatformAuth'
import { TextAreaField } from './adminParts'

const KEY = ['platform', 'packs']
const KINDS = ['tax', 'payroll', 'hr', 'holidays', 'fixed_assets', 'accounts']
const TONES = { draft: 'warning', approved: 'info', published: 'success', rejected: 'neutral' }

/**
 * ADM-04 (ADR 022): country pack releases. Staff load a JSON document,
 * see its validation and diff, a second person approves, a third role
 * publishes. A published release is what the app reads from its date.
 */
export default function PacksConsole() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { can } = usePlatformAuth()
  const [loading, setLoading] = useState(false)
  const [open, setOpen] = useState(null)
  const releases = useQuery({ queryKey: KEY, queryFn: () => platformApi.get('platform/packs') })
  const current = useQuery({ queryKey: [...KEY, 'current'], queryFn: () => platformApi.get('platform/packs/current') })
  const columns = [
    { key: 'pack', label: t('admin.packs.pack'), render: (row) => <span className="text-ink">{`${t(`admin.countries.${row.country}`)} · ${t(`admin.packs.kinds.${row.kind}`)}`}</span> },
    { key: 'version', label: t('admin.packs.version'), render: (row) => <span className="tabular-nums">{row.version}</span> },
    { key: 'from', label: t('admin.packs.effectiveFrom'), render: (row) => formatCalendarDate(row.effective_from, locale) },
    { key: 'status', label: t('admin.packs.status'), render: (row) => <StatusBadge tone={TONES[row.status]}>{t(`admin.packs.statuses.${row.status}`)}</StatusBadge> },
    {
      key: 'checks',
      label: t('admin.packs.checks'),
      render: (row) =>
        row.validation.errors.length ? (
          <StatusBadge tone="danger">{t('admin.packs.errorCount', { count: row.validation.errors.length })}</StatusBadge>
        ) : (
          <span className="text-caption text-ink-muted">{t('admin.packs.warningCount', { count: row.validation.warnings.length })}</span>
        ),
    },
    { key: 'loaded', label: t('admin.packs.loadedBy'), render: (row) => row.loaded_by?.name ?? '' },
  ]
  const currentColumns = [
    { key: 'pack', label: t('admin.packs.pack'), render: (row) => `${t(`admin.countries.${row.country}`)} · ${t(`admin.packs.kinds.${row.kind}`)}` },
    { key: 'source', label: t('admin.packs.source'), render: (row) => (row.source === 'release' ? t('admin.packs.sourceRelease', { version: row.version }) : t(`admin.packs.sources.${row.source}`)) },
    { key: 'from', label: t('admin.packs.effectiveFrom'), render: (row) => (row.effective_from ? formatCalendarDate(row.effective_from, locale) : '') },
  ]
  return (
    <>
      <PageHeader
        title={t('admin.packs.title')}
        description={t('admin.packs.description')}
        actions={can('platform.packs.manage') ? <Button variant="primary" onClick={() => setLoading(true)}>{t('admin.packs.load')}</Button> : null}
      />
      {releases.error ? <Alert tone="danger" title={errorMessage(releases.error)} /> : null}
      <DataTable caption={t('admin.packs.releases')} columns={columns} rows={releases.data?.data ?? []} loading={releases.isPending} emptyText={t('admin.packs.empty')} onRowClick={(row) => setOpen(row.id)} />
      <section className="flex flex-col gap-3">
        <h2 className="text-h2 text-ink">{t('admin.packs.inForce')}</h2>
        <DataTable caption={t('admin.packs.inForce')} columns={currentColumns} rows={(current.data?.data ?? []).map((row) => ({ ...row, id: `${row.country}-${row.kind}` }))} loading={current.isPending} />
      </section>
      {loading ? <LoadDialog onClose={() => setLoading(false)} onLoaded={(id) => setOpen(id)} /> : null}
      {open ? <ReleaseDialog id={open} onClose={() => setOpen(null)} /> : null}
    </>
  )
}

function LoadDialog({ onClose, onLoaded }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [values, setValues] = useState(() => ({ country: 'KE', kind: 'payroll', effective_from: new Date().toISOString().slice(0, 10), notes: '', json: '' }))
  const [parseError, setParseError] = useState(null)
  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }))
  const load = useMutation({
    mutationFn: (content) => platformApi.post('platform/packs', { country: values.country, kind: values.kind, effective_from: values.effective_from, notes: values.notes || null, content }),
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: KEY })
      onClose()
      onLoaded(response.data.id)
    },
  })
  const errors = formErrors(load.error)
  const submit = () => {
    try {
      setParseError(null)
      load.mutate(JSON.parse(values.json))
    } catch {
      setParseError(t('admin.packs.notJson'))
    }
  }
  const readFile = async (event) => {
    const file = event.target.files?.[0]
    if (!file) return
    const text = await file.text()
    setValues((current) => ({ ...current, json: text }))
  }
  return (
    <Dialog
      open
      size="lg"
      title={t('admin.packs.loadTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button variant="primary" loading={load.isPending} disabled={!values.json.trim()} onClick={submit}>{t('admin.packs.validate')}</Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <p className="text-body text-ink-muted">{t('admin.packs.loadText')}</p>
        <div className="grid gap-4 sm:grid-cols-3">
          <Select label={t('admin.packs.country')} value={values.country} onChange={set('country')} options={['KE', 'CD'].map((c) => ({ value: c, label: t(`admin.countries.${c}`) }))} />
          <Select label={t('admin.packs.kind')} value={values.kind} onChange={set('kind')} options={KINDS.map((k) => ({ value: k, label: t(`admin.packs.kinds.${k}`) }))} />
          <TextField type="date" label={t('admin.packs.effectiveFrom')} value={values.effective_from} error={errors.effective_from} onChange={set('effective_from')} />
        </div>
        <TextField type="file" accept="application/json,.json" label={t('admin.packs.file')} onChange={readFile} />
        <TextAreaField label={t('admin.packs.document')} help={t('admin.packs.documentHelp')} rows={10} mono value={values.json} error={parseError ?? errors.content} onChange={set('json')} />
        <TextField label={t('admin.packs.notes')} value={values.notes} onChange={set('notes')} />
        {load.error && !errors.content && !errors.effective_from ? <Alert tone="danger" title={errorMessage(load.error)} /> : null}
      </div>
    </Dialog>
  )
}

function ReleaseDialog({ id, onClose }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const { can, user } = usePlatformAuth()
  const release = useQuery({ queryKey: [...KEY, id], queryFn: () => platformApi.get(`platform/packs/${id}`) })
  const act = useMutation({
    mutationFn: (action) => platformApi.post(`platform/packs/${id}/${action}`, {}),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: KEY }),
  })
  const data = release.data?.data
  const mine = (person) => person?.id && person.id === user?.id
  const actions = []
  if (data?.status === 'draft' && can('platform.packs.approve') && !mine(data.loaded_by) && !data.validation.errors.length) actions.push(['approve', 'primary'])
  if (data?.status === 'approved' && can('platform.packs.publish') && !mine(data.approved_by)) actions.push(['publish', 'pay'])
  if ((data?.status === 'draft' || data?.status === 'approved') && can('platform.packs.approve')) actions.push(['reject', 'ghost'])
  // Review L7: put back what was in force before a published release (a new draft, approved and published as usual).
  if (data?.status === 'published' && can('platform.packs.manage')) actions.push(['supersede', 'ghost'])
  return (
    <Dialog
      open
      size="lg"
      title={data ? t('admin.packs.releaseTitle', { pack: `${t(`admin.countries.${data.country}`)} · ${t(`admin.packs.kinds.${data.kind}`)}`, version: data.version }) : t('admin.packs.title')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('admin.close')}</Button>
          {actions.map(([action, variant]) => (
            <Button key={action} variant={variant} loading={act.isPending && act.variables === action} onClick={() => act.mutate(action)}>
              {t(`admin.packs.${action}`)}
            </Button>
          ))}
        </>
      }
    >
      {release.error ? <Alert tone="danger" title={errorMessage(release.error)} /> : null}
      {act.error ? <Alert tone="danger" title={errorMessage(act.error)} /> : null}
      {data ? (
        <div className="flex flex-col gap-4">
          <div className="flex flex-wrap items-center gap-4">
            <StatusBadge tone={TONES[data.status]}>{t(`admin.packs.statuses.${data.status}`)}</StatusBadge>
            <span className="text-caption text-ink-muted">{t('admin.packs.fromDate', { date: formatCalendarDate(data.effective_from, locale) })}</span>
            <span className="text-caption text-ink-muted">{t('admin.packs.people', { loaded: data.loaded_by?.name ?? '', approved: data.approved_by?.name ?? '—', published: data.published_by?.name ?? '—' })}</span>
          </div>
          {data.validation.errors.length ? (
            <Card title={t('admin.packs.errors')}>
              <ul className="flex list-disc flex-col gap-1 px-9 py-4 text-body text-danger">{data.validation.errors.map((line) => <li key={line}>{line}</li>)}</ul>
            </Card>
          ) : (
            <Alert tone="success" title={t('admin.packs.noErrors')} />
          )}
          {data.validation.warnings.length ? (
            <Card title={t('admin.packs.warnings')}>
              <ul className="flex list-disc flex-col gap-1 px-9 py-4 text-caption text-ink-muted">{data.validation.warnings.map((line) => <li key={line}>{line}</li>)}</ul>
            </Card>
          ) : null}
          <Card title={t('admin.packs.diff')} subtitle={data.diff.base?.source === 'release' ? t('admin.packs.againstRelease', { version: data.diff.base.version }) : t('admin.packs.againstShipped')}>
            <dl className="grid gap-4 px-5 py-4 sm:grid-cols-3">
              {['added', 'changed', 'removed'].map((part) => (
                <div key={part} className="flex min-w-0 flex-col gap-1">
                  <dt className="text-caption text-ink-muted">{t(`admin.packs.${part}`, { count: data.diff[part].length })}</dt>
                  <dd className="flex min-w-0 flex-col gap-1 break-all font-mono text-caption text-ink">{data.diff[part].length ? data.diff[part].map((entry) => <span key={entry}>{entry}</span>) : '—'}</dd>
                </div>
              ))}
            </dl>
          </Card>
        </div>
      ) : null}
    </Dialog>
  )
}
