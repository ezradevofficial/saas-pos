import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'
import { Alert, Button, Card, Combobox, DataTable, Dialog, Icon, Select, StageTracker, StatusBadge } from '@/components/ds'
import { Progress } from '@/components/ui/progress'
import { PageHeader } from '@/layouts/PageHeader'
import { downloadFile } from '@/lib/files'
import { formatInteger } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'
import { RUNNING, STATUS_TONE, useImport, useImportActions, useImporters, usePreview } from './importData'

const STEPS = ['type', 'template', 'upload', 'mapping', 'preview', 'dryRun', 'import', 'result']
const ACCEPT = '.csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'

/**
 * IMP-01..IMP-03: the import wizard. Choose what to import, download the
 * template (English or French), upload the file, map its columns, preview
 * the first rows with their errors, check the whole file (dry run), import
 * with progress, then download the result file or undo the batch within 24
 * hours. `/settings/imports/new?type=items` starts with a type chosen;
 * `/settings/imports/:importId` resumes an import where it stands.
 */
export default function ImportWizard() {
  const { t } = useTranslation()
  const { importId } = useParams()
  const navigate = useNavigate()
  const importQuery = useImport(importId)
  const record = importQuery.data

  return (
    <>
      <PageHeader
        title={record ? t('imports.wizard.titleOf', { file: record.file_name }) : t('imports.wizard.title')}
        description={t('imports.wizard.description')}
        actions={
          <Button variant="ghost" icon="back" onClick={() => navigate('/settings/imports')}>
            {t('imports.wizard.back')}
          </Button>
        }
      />
      {importId ? (
        importQuery.isPending ? (
          <p className="text-body text-ink-muted">{t('imports.wizard.loading')}</p>
        ) : importQuery.isError ? (
          <Alert tone="danger">{importQuery.error.message}</Alert>
        ) : (
          <ExistingImport record={record} />
        )
      ) : (
        <NewImport />
      )}
    </>
  )
}

function Steps({ current }) {
  const { t } = useTranslation()
  return <StageTracker stages={STEPS.map((step) => t(`imports.steps.${step}`))} current={t(`imports.steps.${current}`)} className="mb-6" />
}

/** Type, template and upload: nothing exists on the server until the file is sent. */
function NewImport() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const importers = useImporters()
  const actions = useImportActions(null)
  const [type, setType] = useState(searchParams.get('type') ?? '')
  const [step, setStep] = useState(searchParams.get('type') ? 'template' : 'type')
  const [file, setFile] = useState(null)
  const input = useRef(null)
  const available = importers.data ?? []
  const chosen = available.find((importer) => importer.key === type)

  const send = () =>
    actions.upload.mutate({ importer: type, file }, { onSuccess: (answer) => navigate(`/settings/imports/${answer.data.id}`, { replace: true }) })

  return (
    <>
      <Steps current={step} />
      {step === 'type' ? (
        <Card title={t('imports.type.title')} subtitle={t('imports.type.subtitle')}>
          <div className="flex flex-col gap-4 p-5">
            {importers.isPending ? <p className="text-body text-ink-muted">{t('imports.wizard.loading')}</p> : null}
            {importers.isSuccess && available.length === 0 ? <Alert tone="info">{t('imports.type.none')}</Alert> : null}
            <div role="radiogroup" aria-label={t('imports.type.title')} className="grid gap-3 sm:grid-cols-2">
              {available.map((importer) => (
                <button
                  key={importer.key}
                  type="button"
                  role="radio"
                  aria-checked={type === importer.key}
                  onClick={() => setType(importer.key)}
                  className={
                    type === importer.key
                      ? 'flex flex-col items-start gap-1 rounded-md border border-primary bg-primary-tint p-4 text-left focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2 focus-visible:outline-focus'
                      : 'flex flex-col items-start gap-1 rounded-md border border-border bg-surface-200 p-4 text-left hover:bg-surface-300 focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2 focus-visible:outline-focus'
                  }
                >
                  <span className="text-label font-medium text-ink">{importer.label}</span>
                  <span className="text-caption text-ink-muted">{t('imports.type.columns', { count: importer.columns.length })}</span>
                </button>
              ))}
            </div>
            <div className="flex justify-end">
              <Button variant="primary" disabled={!chosen} onClick={() => setStep('template')}>
                {t('imports.type.next')}
              </Button>
            </div>
          </div>
        </Card>
      ) : null}

      {step === 'template' && chosen ? (
        <Card title={t('imports.template.title', { type: chosen.label })} subtitle={t('imports.template.subtitle')}>
          <div className="flex flex-col gap-4 p-5">
            <ul className="flex flex-col gap-1 text-body text-ink">
              {chosen.columns.map((column) => (
                <li key={column.key} className="flex flex-wrap items-baseline gap-2">
                  <span className="font-medium">{column.label}</span>
                  {column.required ? <span className="text-caption text-ink-muted">{t('imports.template.required')}</span> : null}
                  {column.help ? <span className="text-caption text-ink-muted">{column.help}</span> : null}
                </li>
              ))}
            </ul>
            <div className="flex flex-wrap gap-3">
              {['en', 'fr'].map((locale) => (
                <Button
                  key={locale}
                  icon="download"
                  onClick={() => downloadFile(`imports/importers/${chosen.key}/template?locale=${locale}`, `${chosen.key}-template-${locale}.xlsx`)}
                >
                  {t(`imports.template.download.${locale}`)}
                </Button>
              ))}
            </div>
            <div className="flex justify-between gap-3">
              <Button variant="ghost" onClick={() => setStep('type')}>
                {t('imports.wizard.previous')}
              </Button>
              <Button variant="primary" onClick={() => setStep('upload')}>
                {t('imports.template.next')}
              </Button>
            </div>
          </div>
        </Card>
      ) : null}

      {step === 'upload' && chosen ? (
        <Card title={t('imports.upload.title', { type: chosen.label })} subtitle={t('imports.upload.subtitle')}>
          <div className="flex flex-col gap-4 p-5">
            <input
              ref={input}
              id="import-file"
              type="file"
              accept={ACCEPT}
              className="sr-only"
              onChange={(event) => {
                setFile(event.target.files?.[0] ?? null)
                actions.upload.reset()
              }}
            />
            <div className="flex flex-wrap items-center gap-3">
              <Button icon="upload" onClick={() => input.current?.click()}>
                {file ? t('imports.upload.change') : t('imports.upload.choose')}
              </Button>
              <label htmlFor="import-file" className="text-body text-ink-muted">
                {file ? file.name : t('imports.upload.noFile')}
              </label>
            </div>
            {actions.upload.isError ? <Alert tone="danger">{actions.upload.error.message}</Alert> : null}
            <div className="flex justify-between gap-3">
              <Button variant="ghost" onClick={() => setStep('template')}>
                {t('imports.wizard.previous')}
              </Button>
              <Button variant="primary" disabled={!file} loading={actions.upload.isPending} onClick={send}>
                {t('imports.upload.send')}
              </Button>
            </div>
          </div>
        </Card>
      ) : null}
    </>
  )
}

function stepOf(record, view) {
  if (record.status === 'checking') return 'dryRun'
  if (record.status === 'checked') return view === 'mapping' ? 'mapping' : view === 'preview' ? 'preview' : 'dryRun'
  if (record.status === 'importing') return 'import'
  if (record.status === 'ready') return view === 'preview' ? 'preview' : 'mapping'
  return 'result'
}

function ExistingImport({ record }) {
  const [view, setView] = useState(null)
  const step = stepOf(record, view)

  return (
    <>
      <Steps current={step} />
      {step === 'mapping' ? <Mapping record={record} onDone={() => setView('preview')} /> : null}
      {step === 'preview' ? <Preview record={record} onBack={() => setView('mapping')} onChecked={() => setView(null)} /> : null}
      {step === 'dryRun' ? <DryRun record={record} onBack={() => setView('mapping')} /> : null}
      {step === 'import' ? <Running record={record} /> : null}
      {step === 'result' ? <Result record={record} /> : null}
    </>
  )
}

/** IMP-02: each importer column takes a file column, guessed from the headers (either language) or the last mapping. */
function Mapping({ record, onDone }) {
  const { t } = useTranslation()
  const importers = useImporters()
  const actions = useImportActions(record.id)
  const importer = (importers.data ?? []).find((entry) => entry.key === record.importer)
  const [mapping, setMapping] = useState(() => Object.fromEntries(Object.entries(record.mapping ?? {}).map(([key, index]) => [key, String(index)])))
  const [mode, setMode] = useState(record.mode)
  const headerOptions = [{ value: '', label: t('imports.mapping.skip') }, ...record.headers.map((header, index) => ({ value: String(index), label: header || t('imports.mapping.column', { number: index + 1 }) }))]
  const errors = actions.update.error?.errors ?? {}

  if (!importer) return <p className="text-body text-ink-muted">{t('imports.wizard.loading')}</p>

  const save = () => {
    const body = { mode, mapping: Object.fromEntries(Object.entries(mapping).filter(([, index]) => index !== '').map(([key, index]) => [key, Number(index)])) }
    actions.update.mutate(body, { onSuccess: onDone })
  }

  return (
    <Card title={t('imports.mapping.title')} subtitle={t('imports.mapping.subtitle', { rows: record.total_rows })}>
      <div className="flex flex-col gap-4 p-5">
        <div className="grid gap-3">
          {importer.columns.map((column) => {
            const id = `map-${column.key}`
            const error = errors[`mapping.${column.key}`]?.[0]
            return (
              <div key={column.key} className="grid items-center gap-2 sm:grid-cols-2">
                <div className="flex items-baseline gap-2">
                  <label htmlFor={id} className="text-label text-ink">
                    {column.label}
                  </label>
                  {column.required ? <span className="text-caption text-ink-muted">{t('imports.template.required')}</span> : null}
                </div>
                <div className="flex flex-col gap-1">
                  <Combobox
                    id={id}
                    value={mapping[column.key] ?? ''}
                    onValueChange={(value) => setMapping((current) => ({ ...current, [column.key]: value }))}
                    options={headerOptions}
                    invalid={Boolean(error)}
                    required={column.required}
                    aria-describedby={error ? `${id}-error` : undefined}
                  />
                  {error ? (
                    <span id={`${id}-error`} className="text-caption text-danger">
                      {error}
                    </span>
                  ) : null}
                </div>
              </div>
            )
          })}
        </div>
        <Select
          label={t('imports.mapping.mode')}
          value={mode}
          onChange={(event) => setMode(event.target.value)}
          options={['create', ...(importer.can_update ? ['upsert'] : [])].map((value) => ({ value, label: t(`imports.modes.${value}`) }))}
          help={t('imports.mapping.matchedBy', { columns: importer.match_columns.map((key) => importer.columns.find((c) => c.key === key)?.label ?? key).join(', ') })}
        />
        {actions.update.isError && !Object.keys(errors).length ? <Alert tone="danger">{actions.update.error.message}</Alert> : null}
        <div className="flex justify-end">
          <Button variant="primary" loading={actions.update.isPending} onClick={save}>
            {t('imports.mapping.next')}
          </Button>
        </div>
      </div>
    </Card>
  )
}

function ErrorList({ errors, labels }) {
  return (
    <ul className="flex flex-col gap-1 whitespace-normal">
      {errors.map((error, index) => (
        <li key={index} className="text-caption text-danger">
          {error.column ? `${labels[error.column] ?? error.column}: ` : ''}
          {error.message}
        </li>
      ))}
    </ul>
  )
}

/** IMP-02: the first 200 rows with their errors, and counts for the whole file. */
function Preview({ record, onBack, onChecked }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const preview = usePreview(record.id, true)
  const importers = useImporters()
  const actions = useImportActions(record.id)
  const importer = (importers.data ?? []).find((entry) => entry.key === record.importer)
  const labels = Object.fromEntries((importer?.columns ?? []).map((column) => [column.key, column.label]))
  const data = preview.data
  const shownKeys = Object.keys(record.mapping ?? {}).slice(0, 3)
  const [onlyErrors, setOnlyErrors] = useState(true)
  const rows = (data?.rows ?? []).filter((row) => !onlyErrors || row.errors.length).map((row) => ({ ...row, id: row.row }))
  const n = (value) => formatInteger(value ?? 0, locale)

  return (
    <Card title={t('imports.preview.title')} subtitle={t('imports.preview.subtitle', { shown: n(data?.rows.length), total: n(record.total_rows) })}>
      <div className="flex flex-col gap-4 p-5">
        {preview.isPending ? <p className="text-body text-ink-muted">{t('imports.wizard.loading')}</p> : null}
        {preview.isError ? <Alert tone="danger">{preview.error.message}</Alert> : null}
        {data ? (
          <>
            <p className="text-body text-ink" aria-live="polite">
              {t('imports.preview.counts', { valid: n(data.valid), invalid: n(data.invalid), total: n(data.total_rows) })}
            </p>
            <div className="flex gap-2">
              <Button variant={onlyErrors ? 'primary' : 'secondary'} aria-pressed={onlyErrors} onClick={() => setOnlyErrors(true)}>
                {t('imports.preview.onlyErrors')}
              </Button>
              <Button variant={onlyErrors ? 'secondary' : 'primary'} aria-pressed={!onlyErrors} onClick={() => setOnlyErrors(false)}>
                {t('imports.preview.allRows')}
              </Button>
            </div>
            <DataTable
              caption={t('imports.preview.title')}
              rows={rows}
                            emptyText={onlyErrors ? t('imports.preview.noErrors') : t('imports.preview.empty')}
              columns={[
                { key: 'row', label: t('imports.preview.row'), numeric: true, render: (row) => n(row.row) },
                ...shownKeys.map((key) => ({ key, label: labels[key] ?? key, render: (row) => row.values[key] ?? '' })),
                {
                  key: 'status',
                  label: t('imports.preview.status'),
                  render: (row) =>
                    row.errors.length ? <ErrorList errors={row.errors} labels={labels} /> : <StatusBadge tone="success">{t(`imports.preview.${row.action ?? 'create'}`)}</StatusBadge>,
                },
              ]}
            />
          </>
        ) : null}
        {actions.dryRun.isError ? <Alert tone="danger">{actions.dryRun.error.message}</Alert> : null}
        <div className="flex justify-between gap-3">
          <Button variant="ghost" onClick={onBack}>
            {t('imports.preview.back')}
          </Button>
          <Button variant="primary" loading={actions.dryRun.isPending} disabled={!data} onClick={() => actions.dryRun.mutate(undefined, { onSuccess: onChecked })}>
            {t('imports.preview.check')}
          </Button>
        </div>
      </div>
    </Card>
  )
}

function ProgressBlock({ record, label }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const percent = record.total_rows ? Math.round((record.processed_rows / record.total_rows) * 100) : 0
  return (
    <div className="flex flex-col gap-2">
      <Progress value={percent} aria-label={label} aria-valuetext={t('imports.progress.text', { done: formatInteger(record.processed_rows, locale), total: formatInteger(record.total_rows, locale) })} />
      <p className="text-caption text-ink-muted tabular-nums" aria-live="polite">
        {t('imports.progress.text', { done: formatInteger(record.processed_rows, locale), total: formatInteger(record.total_rows, locale) })}
      </p>
    </div>
  )
}

function Counts({ record, checking }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const items = [
    ['created', record.created_rows],
    ['updated', record.updated_rows],
    ['failed', record.failed_rows],
  ]
  return (
    <dl className="grid grid-cols-3 gap-3">
      {items.map(([key, value]) => (
        <div key={key} className="rounded-md border border-border bg-surface-100 p-3">
          <dt className="text-caption text-ink-muted">{t(`imports.counts.${checking ? 'check' : 'done'}.${key}`)}</dt>
          <dd className="text-h3 text-ink tabular-nums">{formatInteger(value ?? 0, locale)}</dd>
        </div>
      ))}
    </dl>
  )
}

/** IMP-02: the whole file checked, nothing written; the rows that would fail in an error file. */
function DryRun({ record, onBack }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const actions = useImportActions(record.id)
  const running = record.status === 'checking'
  const toWrite = (record.created_rows ?? 0) + (record.updated_rows ?? 0)

  return (
    <Card title={t('imports.dryRun.title')} subtitle={running ? t('imports.dryRun.running') : t('imports.dryRun.done')}>
      <div className="flex flex-col gap-4 p-5">
        {running ? <ProgressBlock record={record} label={t('imports.dryRun.title')} /> : <Counts record={record} checking />}
        {record.failure ? <Alert tone="danger">{record.failure}</Alert> : null}
        {!running && record.failed_rows > 0 ? (
          <Alert
            tone="warning"
            title={t('imports.dryRun.errorsTitle', { count: record.failed_rows })}
            action={
              record.error_file_url ? (
                <a href={record.error_file_url} className="inline-flex items-center gap-2 text-label font-medium text-primary underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2 focus-visible:outline-focus">
                  <Icon name="download" size={16} />
                  {t('imports.dryRun.errorFile')}
                </a>
              ) : null
            }
          >
            {t('imports.dryRun.errorsHelp')}
          </Alert>
        ) : null}
        {actions.run.isError ? <Alert tone="danger">{actions.run.error.message}</Alert> : null}
        {!running ? (
          <div className="flex justify-between gap-3">
            <Button variant="ghost" onClick={onBack}>
              {t('imports.preview.back')}
            </Button>
            {record.can_change ? (
              <Button variant="pay" disabled={toWrite === 0} loading={actions.run.isPending} onClick={() => actions.run.mutate()}>
                {t('imports.dryRun.import', { count: toWrite, rows: formatInteger(toWrite, locale) })}
              </Button>
            ) : null}
          </div>
        ) : null}
      </div>
    </Card>
  )
}

function Running({ record }) {
  const { t } = useTranslation()
  return (
    <Card title={t('imports.running.title')} subtitle={t('imports.running.subtitle')}>
      <div className="flex flex-col gap-4 p-5">
        <ProgressBlock record={record} label={t('imports.running.title')} />
        <Counts record={record} />
      </div>
    </Card>
  )
}

/** IMP-03 result file; IMP-02 undo within 24 hours, refused with the rows that depend on the batch. */
function Result({ record }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const actions = useImportActions(record.id)
  const [confirming, setConfirming] = useState(false)
  const blocked = actions.undo.error?.data?.blocking ?? (record.blocking?.length ? { count: record.blocking.length, rows: record.blocking } : null)

  return (
    <Card
      title={t('imports.result.title')}
      subtitle={<StatusBadge tone={STATUS_TONE[record.status]}>{t(`imports.statuses.${record.status}`)}</StatusBadge>}
    >
      <div className="flex flex-col gap-4 p-5">
        {RUNNING.includes(record.status) ? <ProgressBlock record={record} label={t('imports.result.title')} /> : null}
        <Counts record={record} />
        {record.failure ? <Alert tone="danger">{record.failure}</Alert> : null}
        {record.status === 'undone' ? <Alert tone="info">{t('imports.result.undone')}</Alert> : null}
        {blocked && record.status !== 'undone' ? (
          <Alert tone="danger" title={t('imports.result.blockedTitle', { count: blocked.count })}>
            <ul className="mt-2 flex flex-col gap-1">
              {blocked.rows.map((row) => (
                <li key={`${row.row}-${row.record}`} className="text-caption">
                  {t('imports.result.blockedRow', { row: formatInteger(row.row, locale), record: row.record, reason: row.reason })}
                </li>
              ))}
            </ul>
          </Alert>
        ) : null}
        <div className="flex flex-wrap justify-between gap-3">
          <Link to="/settings/imports" className="inline-flex h-control items-center px-3 text-label text-ink-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2 focus-visible:outline-focus">
            {t('imports.result.all')}
          </Link>
          <div className="flex flex-wrap gap-3">
            {record.result_file_url ? (
              <a
                href={record.result_file_url}
                className="inline-flex h-control items-center gap-2 rounded-md border border-border-strong bg-surface-200 px-3 text-label font-medium text-ink hover:bg-surface-300 focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2 focus-visible:outline-focus"
              >
                <Icon name="download" size={16} />
                {t('imports.result.download')}
              </a>
            ) : null}
            {record.can_undo ? (
              <Button variant="danger" onClick={() => setConfirming(true)}>
                {t('imports.result.undo')}
              </Button>
            ) : null}
          </div>
        </div>
      </div>
      <Dialog
        open={confirming}
        onClose={() => setConfirming(false)}
        title={t('imports.result.undoTitle')}
        footer={
          <>
            <Button onClick={() => setConfirming(false)}>{t('imports.result.keep')}</Button>
            <Button variant="danger" loading={actions.undo.isPending} onClick={() => actions.undo.mutate(undefined, { onSettled: () => setConfirming(false) })}>
              {t('imports.result.undoConfirm')}
            </Button>
          </>
        }
      >
        <p className="text-body text-ink">{t('imports.result.undoText', { created: record.created_rows, updated: record.updated_rows })}</p>
      </Dialog>
    </Card>
  )
}
