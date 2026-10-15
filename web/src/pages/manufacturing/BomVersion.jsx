import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, DataTable, DecimalInput, Dialog, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { zonedToUtc } from '@/lib/dates'
import { useDebounced } from '@/lib/useDebounced'
import { useLocale } from '@/lib/useLocale'
import { useTimeZone } from '@/lib/useTimeZone'
import { LineEditor, lineFieldNames, lineFromApi, linesReady, VersionFields, versionBody } from './BomLines'
import { Amount, Back, Detail, DetailState, MfgStatus, Qty, useLineUnits } from './ManufacturingParts'
import { isPositive, kindLabel, manufacturingKey } from './manufacturingData'

const valuesOf = (version) => ({
  output_qty: version.output_qty ?? '',
  output_uom_id: version.output_uom?.id ?? version.output_uom_id ?? '',
  labour_minutes: version.labour_minutes === null || version.labour_minutes === undefined ? '' : String(version.labour_minutes),
  note: version.note ?? '',
})

/** MFG-03: publish a draft from a moment (now when left empty); it is fixed from then on. */
function PublishDialog({ version, open, onClose, onDone }) {
  const { t } = useTranslation()
  const timeZone = useTimeZone(version.bom?.company?.id)
  const [from, setFrom] = useState('')
  const send = useMutation({
    mutationFn: () => api.post(`manufacturing/bom-versions/${version.id}/publish`, from ? { effective_from: zonedToUtc(from, timeZone) } : {}),
    onSuccess: (response) => onDone(response),
  })
  const close = () => {
    send.reset()
    onClose()
  }
  return (
    <Dialog
      open={open}
      title={t('manufacturing.versions.publishTitle', { number: version.version_no })}
      onClose={close}
      footer={
        <>
          <Button variant="ghost" onClick={close}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={send.isPending} onClick={() => send.mutate()}>
            {t('manufacturing.versions.publish')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        <p>{t('manufacturing.versions.publishHelp')}</p>
        {send.isError ? <Alert tone="danger" title={errorMessage(send.error)} /> : null}
        <TextField type="datetime-local" label={t('manufacturing.fields.effectiveFrom')} help={t('manufacturing.fields.effectiveFromHelp')} value={from} onChange={(event) => setFrom(event.target.value)} />
      </div>
    </Dialog>
  )
}

/** One node of the explosion and, under it, what an exploded component is made of. */
function TreeNode({ node }) {
  const { t } = useTranslation()
  return (
    <li className="flex flex-col gap-2">
      <div className="flex flex-wrap items-baseline justify-between gap-3 rounded-md border border-border px-3 py-2">
        <span className="flex min-w-0 flex-col">
          <span className="text-ink">{node.item?.name}</span>
          <span className="text-caption text-ink-muted">
            <span className="font-mono">{node.item?.code}</span>
            {node.explode ? ` · ${t('manufacturing.explosion.exploded')}` : ''}
          </span>
        </span>
        <span className="flex flex-col items-end">
          <Qty value={node.qty_base} unit={node.uom?.code} className="font-medium" />
          {node.net_base !== node.qty_base ? <span className="text-caption text-ink-muted">{t('manufacturing.explosion.net', { qty: node.net_base })}</span> : null}
        </span>
      </div>
      {node.children?.length ? (
        <ul className="ml-3 flex flex-col gap-2 border-l border-border pl-4" aria-label={t('manufacturing.explosion.madeFrom', { item: node.item?.name ?? '' })}>
          {node.children.map((child, index) => (
            <TreeNode key={`${child.line_id}-${index}`} node={child} />
          ))}
        </ul>
      ) : null}
    </li>
  )
}

/**
 * MFG-05, MFG-06: the version exploded for a quantity of its output: the
 * tree (exploded components show what they are made of), the leaves taken
 * from stock with their expected wastage, the by-products and, for cost
 * holders with Inventory active, the current costs.
 */
function Explosion({ version }) {
  const { t } = useTranslation()
  const units = useLineUnits(version.bom?.item, version.output_uom)
  const [qty, setQty] = useState(version.output_qty ?? '1')
  const [uom, setUom] = useState(version.output_uom?.id ?? version.output_uom_id ?? '')
  const asked = useDebounced(qty, 300)
  const ready = isPositive(asked)
  const query = useQuery({
    queryKey: [...manufacturingKey, 'explode', version.id, asked, uom],
    queryFn: () => api.get(`manufacturing/bom-versions/${version.id}/explode?qty=${encodeURIComponent(asked)}${uom ? `&uom_id=${encodeURIComponent(uom)}` : ''}`),
    enabled: ready,
    retry: false,
  })
  const data = query.data?.data
  const cost = data?.cost ?? null
  const leafCost = new Map((cost?.leaves ?? []).map((leaf) => [leaf.item_id, leaf.cost]))
  const uncosted = new Set(cost?.uncosted ?? [])

  const leafColumns = [
    {
      key: 'item',
      label: t('manufacturing.columns.item'),
      wrap: true,
      render: (row) => (
        <span className="flex flex-col">
          <span className="text-ink">{row.item?.name}</span>
          <span className="font-mono text-caption text-ink-muted">{row.item?.code}</span>
        </span>
      ),
    },
    { key: 'unit', label: t('manufacturing.columns.unit'), render: (row) => row.uom?.code ?? '' },
    { key: 'qty', label: t('manufacturing.columns.qty'), align: 'end', render: (row) => <Qty value={row.qty_base} /> },
    { key: 'loss', label: t('manufacturing.columns.expectedWastage'), align: 'end', render: (row) => <Qty value={row.loss_base} /> },
    ...(cost
      ? [
          {
            key: 'cost',
            label: t('manufacturing.columns.cost'),
            align: 'end',
            render: (row) => (uncosted.has(row.item?.id) ? <span className="text-ink-muted">{t('manufacturing.explosion.noCost')}</span> : <Amount value={leafCost.get(row.item?.id)} />),
          },
        ]
      : []),
  ]

  return (
    <Card title={t('manufacturing.explosion.title')} subtitle={t('manufacturing.explosion.help')}>
      <div className="flex flex-col gap-5">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <DecimalInput label={t('manufacturing.explosion.qty')} value={qty} onChange={(value) => setQty(value ?? '')} />
          <Select label={t('manufacturing.fields.unit')} options={units} value={uom} onChange={(event) => setUom(event.target.value)} />
        </div>
        {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
        {ready && query.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
        {data ? (
          <>
            <section aria-label={t('manufacturing.explosion.tree')} className="flex flex-col gap-2">
              <h3 className="text-h3 text-ink">{t('manufacturing.explosion.tree')}</h3>
              {data.tree?.length ? (
                <ul className="flex flex-col gap-2">
                  {data.tree.map((node, index) => (
                    <TreeNode key={`${node.line_id}-${index}`} node={node} />
                  ))}
                </ul>
              ) : (
                <p className="text-ink-muted">{t('manufacturing.lines.empty')}</p>
              )}
            </section>
            <section aria-label={t('manufacturing.explosion.leaves')} className="flex flex-col gap-2">
              <h3 className="text-h3 text-ink">{t('manufacturing.explosion.leaves')}</h3>
              <DataTable caption={t('manufacturing.explosion.leaves')} columns={leafColumns} rows={(data.leaves ?? []).map((leaf) => ({ ...leaf, id: leaf.item?.id }))} emptyText={t('manufacturing.lines.empty')} />
            </section>
            {data.by_products?.length ? (
              <section aria-label={t('manufacturing.explosion.byProducts')} className="flex flex-col gap-2">
                <h3 className="text-h3 text-ink">{t('manufacturing.explosion.byProducts')}</h3>
                <ul className="flex flex-col gap-1">
                  {data.by_products.map((line, index) => (
                    <li key={`${line.item?.id}-${index}`} className="flex flex-wrap justify-between gap-3">
                      <span className="text-ink">{line.item?.name}</span>
                      <span className="flex gap-3">
                        <Qty value={line.qty_base} />
                        <span className="text-caption text-ink-muted tabular-nums">{t('manufacturing.explosion.costShare', { percent: line.cost_share_percent ?? '0' })}</span>
                      </span>
                    </li>
                  ))}
                </ul>
              </section>
            ) : null}
            {cost ? (
              <dl className="grid gap-4 sm:grid-cols-2">
                <Detail label={t('manufacturing.explosion.totalCost')}>
                  <Amount value={cost.total} className="font-medium" />
                </Detail>
                {uncosted.size ? <Detail label={t('manufacturing.explosion.uncosted')}>{t('manufacturing.explosion.uncostedCount', { count: uncosted.size })}</Detail> : null}
              </dl>
            ) : null}
          </>
        ) : null}
      </div>
    </Card>
  )
}

/** MFG-03, MFG-04: the editor of a draft (header and lines), Save and Publish. */
function VersionEditor({ version, onSaved }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const editable = Boolean(version.can?.edit)
  const [values, setValues] = useState(() => valuesOf(version))
  const [lines, setLines] = useState(() => (version.lines ?? []).map(lineFromApi))
  const [submitted, setSubmitted] = useState(false)
  const [changed, setChanged] = useState(false)
  const [publishing, setPublishing] = useState(false)
  const save = useMutation({
    mutationFn: () => api.put(`manufacturing/bom-versions/${version.id}`, versionBody(values, lines)),
    onSuccess: async (response) => {
      toast.success(t('manufacturing.versions.saved'))
      await queryClient.invalidateQueries({ queryKey: [...manufacturingKey, 'explode', version.id] })
      onSaved(response)
    },
  })
  const fieldNames = ['output_qty', 'output_uom_id', 'labour_minutes', 'note', 'lines', ...lineFieldNames('', lines.length)]
  const errors = formErrors(save.error, fieldNames)
  const ready = values.output_qty && linesReady(lines)
  const edit = (setter) => (next) => {
    setter(next)
    setChanged(true)
  }

  return (
    <>
      {editable ? null : <Alert tone="info" title={t('manufacturing.versions.readOnly')} />}
      {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
      <Card title={t('manufacturing.versions.batch')}>
        <VersionFields item={version.bom?.item} outputUom={version.output_uom} values={values} onChange={edit(setValues)} readOnly={!editable} submitted={submitted} errors={errors.fields} />
      </Card>
      <LineEditor lines={lines} onChange={edit(setLines)} readOnly={!editable} submitted={submitted} errors={errors.fields} />
      {editable ? (
        <div className="flex flex-wrap items-center gap-2">
          <Button
            variant="primary"
            loading={save.isPending}
            onClick={() => {
              setSubmitted(true)
              if (ready) save.mutate()
            }}
          >
            {t('manufacturing.versions.save')}
          </Button>
          {version.can?.publish ? (
            <Button disabled={changed} onClick={() => setPublishing(true)}>
              {t('manufacturing.versions.publish')}
            </Button>
          ) : null}
          {changed && version.can?.publish ? <span className="text-caption text-ink-muted">{t('manufacturing.versions.saveFirst')}</span> : null}
        </div>
      ) : null}
      {publishing ? (
        <PublishDialog
          version={version}
          open
          onClose={() => setPublishing(false)}
          onDone={async (response) => {
            setPublishing(false)
            toast.success(t('manufacturing.versions.published', { number: version.version_no }))
            await queryClient.invalidateQueries({ queryKey: manufacturingKey })
            onSaved(response)
          }}
        />
      ) : null}
    </>
  )
}

/**
 * MFG-03 to MFG-05: one version of a BOM: its batch (output, labour), its
 * lines in a tree editor while it is a draft (read-only once published)
 * and its explosion.
 */
export default function BomVersion() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { versionId } = useParams()
  const queryClient = useQueryClient()
  const key = [...manufacturingKey, 'bom-version', versionId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`manufacturing/bom-versions/${versionId}`) })
  const version = query.data?.data
  const timeZone = useTimeZone(version?.bom?.company?.id)
  const back = <Back to={version?.bom?.id ? `/manufacturing/boms/${version.bom.id}` : '/manufacturing/boms'}>{t('manufacturing.versions.back')}</Back>

  if (!version) return <DetailState query={query} back={back} notFound={t('manufacturing.versions.notFound')} />

  // The editor starts again from the saved version after a save or publish.
  const editorKey = `${version.id}-${version.status}-${query.dataUpdatedAt}`

  return (
    <>
      {back}
      <PageHeader
        eyebrow={`${kindLabel(t, version.bom?.kind)} · ${version.bom?.item?.name ?? ''}`}
        title={t('manufacturing.boms.versionNo', { number: version.version_no })}
        description={<MfgStatus status={version.status} />}
      />
      <div className="flex flex-col gap-5">
        {version.status === 'published' ? (
          <Card>
            <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              <Detail label={t('manufacturing.fields.effectiveFrom')}>
                <span className="tabular-nums">{version.effective_from ? formatCompanyTime(version.effective_from, locale, timeZone ? { timezone: timeZone } : null) : ''}</span>
              </Detail>
              <Detail label={t('manufacturing.versions.publishedBy')}>
                <span className="flex flex-col">
                  <span>{version.published_by?.name}</span>
                  <span className="text-caption text-ink-muted tabular-nums">{version.published_at ? formatCompanyTime(version.published_at, locale, timeZone ? { timezone: timeZone } : null) : ''}</span>
                </span>
              </Detail>
            </dl>
          </Card>
        ) : null}
        <VersionEditor key={editorKey} version={version} onSaved={(response) => (response?.data ? queryClient.setQueryData(key, response) : null)} />
        <Explosion key={`explode-${editorKey}`} version={version} />
      </div>
    </>
  )
}
