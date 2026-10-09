import { useQuery } from '@tanstack/react-query'
import { useCallback, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams, useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Icon, Select, Switch, TextField, VersionBar } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useConfigDocument } from '@/lib/useConfigDocument'
import { BlockCanvas } from './BlockCanvas'
import { BlockInspector } from './BlockInspector'
import {
  blockLabel,
  changeLayout,
  findBlock,
  hasBlock,
  inRange,
  isThermal,
  LANGUAGES,
  layoutOf,
  layouts,
  newBlock,
  nextVariantId,
  normalise,
  OPERATORS,
  PAPERS,
  RANGES,
  removeBlock,
  updateBlock,
} from './blocks'
import { allowedAt, parseScope, scopeQuery, scopeValue } from './scope'
import { TemplatePreview } from './TemplatePreview'
import { VariantsPanel } from './VariantsPanel'

const SIDES = ['top', 'right', 'bottom', 'left']
const BLOCK_TYPES = ['text', 'field', 'logo', 'lines', 'totals', 'payments', 'qr', 'barcode', 'signature', 'terms', 'spacer', 'divider', 'fiscal', 'row']

function Back() {
  const { t } = useTranslation()
  return (
    <Link to="/settings/document-templates" className="flex w-fit items-center gap-2 text-label text-primary hover:text-primary-hover">
      <Icon name="back" />
      {t('documentTemplates.back')}
    </Link>
  )
}

/** Paper, language and margins of the main template or of one variant (which may keep the main one's). */
function PageSettings({ payload, layout, editing, canEdit, onChange }) {
  const { t } = useTranslation()
  const variant = layout.variant
  const same = { value: '', label: t('documentTemplates.page.sameAsMain') }
  const margins = layout.margins ?? payload.margins
  const ownMargins = !variant || Boolean(variant.margins)
  const set = (patch) =>
    onChange((current) =>
      changeLayout(current, editing, (target) => {
        const next = { ...target, ...patch }
        for (const [key, value] of Object.entries(patch)) if (value === undefined) delete next[key]
        return next
      }),
    )

  return (
    <div className="flex flex-col gap-4">
      <Select
        label={t('documentTemplates.page.paper')}
        options={[...(variant ? [same] : []), ...PAPERS.map((paper) => ({ value: paper, label: t(`documentTemplates.papers.${paper}`) }))]}
        value={variant ? (variant.paper ?? '') : payload.paper}
        disabled={!canEdit}
        onChange={(event) => set({ paper: event.target.value || undefined })}
      />
      <Select
        label={t('documentTemplates.page.language')}
        help={t('documentTemplates.page.languageHelp')}
        options={[...(variant ? [same] : []), ...LANGUAGES.map((language) => ({ value: language, label: t(`documentTemplates.languages.${language}`) }))]}
        value={variant ? (variant.language ?? '') : payload.language}
        disabled={!canEdit}
        onChange={(event) => set({ language: event.target.value || undefined })}
      />
      {variant ? (
        <Switch
          label={t('documentTemplates.page.ownMargins')}
          checked={ownMargins}
          disabled={!canEdit}
          onChange={(on) => set({ margins: on ? { ...payload.margins } : undefined })}
        />
      ) : null}
      <fieldset className="grid grid-cols-2 gap-3">
        <legend className="mb-2 text-label text-ink">{t('documentTemplates.page.margins')}</legend>
        {SIDES.map((side) => {
          const value = margins?.[side]
          const invalid = value !== null && value !== undefined && !inRange(value, RANGES.margin)
          return (
            <TextField
              key={side}
              label={t(`documentTemplates.page.sides.${side}`)}
              type="number"
              inputMode="numeric"
              min={RANGES.margin[0]}
              max={RANGES.margin[1]}
              step={1}
              suffix={t('documentTemplates.page.mm')}
              value={value ?? ''}
              disabled={!canEdit || !ownMargins}
              error={invalid ? t('documentTemplates.inspector.range', { min: RANGES.margin[0], max: RANGES.margin[1] }) : undefined}
              onChange={(event) => {
                const raw = event.target.value
                set({ margins: { ...margins, [side]: raw === '' ? null : Math.trunc(Number(raw)) } })
              }}
            />
          )
        })}
      </fieldset>
    </div>
  )
}

/** The designer of one document type at one place, on the versioned configuration store (LAY-06). */
function Designer({ type, meta, scope, canEdit, canPublish, copyTargets }) {
  const { t } = useTranslation()
  const config = useConfigDocument('template', type.key, scope)
  const [local, setLocal] = useState(null)
  const [editing, setEditing] = useState(null) // a variant id, or null for the main template
  const [selected, setSelected] = useState(null)
  const [saveError, setSaveError] = useState(null)
  const [saved, setSaved] = useState(false)
  const [previewProblems, setPreviewProblems] = useState(null)
  const onProblems = useCallback((problems) => setPreviewProblems(problems), [])

  if (config.isLoading) return <p className="text-ink-muted">{t('common.loading')}</p>
  if (config.error) return <Alert tone="danger" title={errorMessage(config.error)} />

  const server = normalise(config.payload, type.default)
  const payload = local ?? server
  const dirty = local !== null && JSON.stringify(local) !== JSON.stringify(server)
  const layout = layoutOf(payload, editing)
  const locked = type.locked ?? []
  const block = selected ? findBlock(layout.blocks, selected) : null
  const fiscalAllowed = Boolean(type.fiscal?.allowed)
  const missingFiscal = locked.includes('fiscal') && layouts(payload).some((blocks) => !hasBlock(blocks, 'fiscal'))
  const problems = previewProblems ?? config.problems ?? []

  const edit = (change) => {
    setSaved(false)
    setLocal((current) => change(current ?? server))
  }
  const editBlocks = (change) => edit((current) => changeLayout(current, editing, (target) => ({ blocks: change(target.blocks ?? []) })))

  const paletteTypes = (meta.blocks ?? BLOCK_TYPES).filter((entry) => {
    if (entry === 'row') return !isThermal(layout.paper)
    if (entry === 'fiscal') return fiscalAllowed && !hasBlock(layout.blocks, 'fiscal')
    return true
  })

  const add = (blockType) => {
    const created = newBlock(blockType, layout.blocks, type)
    editBlocks((blocks) => [...blocks, created])
    setSelected(created.id)
  }
  const addToRow = (rowId, column, blockType) => {
    const created = newBlock(blockType, layout.blocks, type)
    editBlocks((blocks) => updateBlock(blocks, rowId, (row) => ({ ...row, columns: row.columns.map((entry, index) => (index === column ? [...entry, created] : entry)) })))
    setSelected(created.id)
  }
  const remove = (id) => {
    const target = findBlock(layout.blocks, id)
    // TPL-03: a locked block (the tax authority's) stays.
    if (!target || locked.includes(target.type)) return
    editBlocks((blocks) => removeBlock(blocks, id))
    if (selected === id) setSelected(null)
  }
  const restoreFiscal = () =>
    edit((current) => {
      const withFiscal = (blocks) => (hasBlock(blocks, 'fiscal') ? blocks : [...blocks, { id: 'fiscal', type: 'fiscal' }])
      return { ...current, blocks: withFiscal(current.blocks ?? []), variants: (current.variants ?? []).map((variant) => ({ ...variant, blocks: withFiscal(variant.blocks ?? []) })) }
    })

  const save = async () => {
    setSaveError(null)
    try {
      await config.saveDraft(payload)
      setLocal(null)
      setSaved(true)
      toast.success(t('documentTemplates.saved'))
    } catch (error) {
      setSaveError(error)
    }
  }
  // Someone else changed the draft (409 config_changed): the bar says so and Reload adopts their version.
  const conflict = Boolean(config.conflict) || saveError?.code === 'config_changed'
  const reload = async () => {
    setLocal(null)
    setSaveError(null)
    await config.reload()
  }
  const publish = async () => {
    if (missingFiscal) throw new Error(t('documentTemplates.fiscal.missing'))
    if (dirty) {
      await config.saveDraft(payload)
      setLocal(null)
    }
    await config.publish()
  }
  const reset = (action) => async (...args) => {
    await action(...args)
    setLocal(null)
  }

  const variantsOf = (current) => current.variants ?? []
  const addVariant = () => {
    const id = nextVariantId(payload)
    edit((current) => ({
      ...current,
      variants: [...variantsOf(current), { id, name: t('documentTemplates.variants.newName', { n: variantsOf(current).length + 1 }), applies_when: { customer_tags: [], conditions: [] }, blocks: current.blocks ?? [] }],
    }))
    setEditing(id)
    setSelected(null)
  }

  const saveState = config.pending?.save ? 'saving' : saveError && !conflict ? 'error' : saved && !dirty ? 'saved' : undefined

  return (
    <div className="flex flex-col gap-6">
      <VersionBar
        document={config.document}
        problems={config.problems}
        pending={config.pending}
        canEdit={canEdit}
        canPublish={canPublish}
        copyTargets={copyTargets}
        saveState={saveState}
        conflict={config.conflict}
        onReload={reload}
        onPublish={publish}
        onDiscard={reset(config.discardDraft)}
        onRollback={reset(config.rollback)}
        onCopy={(target, from) => config.copyTo(target, from)}
      />

      {saveError && !conflict ? <Alert tone="danger" title={errorMessage(saveError)} /> : null}
      {missingFiscal ? (
        <Alert
          tone="danger"
          title={t('documentTemplates.fiscal.missingTitle', { authority: t(`documentTemplates.authorities.${type.fiscal?.authority ?? 'other'}`) })}
          action={canEdit ? <Button onClick={restoreFiscal}>{t('documentTemplates.fiscal.restore')}</Button> : null}
        >
          {t('documentTemplates.fiscal.missing')}
        </Alert>
      ) : null}
      {problems.length > 0 ? (
        <Alert tone="warning" title={t('documentTemplates.problems', { count: problems.length })}>
          <ul className="flex flex-col gap-1" aria-label={t('documentTemplates.problemsLabel')}>
            {problems.map((problem, index) => (
              <li key={`${problem.path}-${problem.code}-${index}`}>{problem.message}</li>
            ))}
          </ul>
        </Alert>
      ) : null}

      {canEdit ? (
        <div className="flex flex-wrap items-center justify-end gap-3">
          {dirty ? <span className="text-caption text-ink-muted">{t('documentTemplates.unsaved')}</span> : null}
          <Button variant="primary" disabled={!dirty || missingFiscal} loading={config.pending?.save} onClick={save}>
            {t('documentTemplates.saveDraft')}
          </Button>
        </div>
      ) : null}

      <div className="grid gap-6 xl:grid-cols-3">
        <div className="flex min-w-0 flex-col gap-6">
          <Card title={editing ? t('documentTemplates.blocksOf', { name: layout.variant?.name ?? '' }) : t('documentTemplates.blocksTitle')}>
            <div className="flex flex-col gap-4">
              {canEdit ? (
                <div className="flex flex-col gap-2">
                  <h4 className="text-label text-ink">{t('documentTemplates.palette.title')}</h4>
                  <ul aria-label={t('documentTemplates.palette.label')} className="flex flex-wrap gap-2">
                    {paletteTypes.map((entry) => (
                      <li key={entry}>
                        <Button icon="plus" aria-label={t('documentTemplates.palette.add', { block: blockLabel(t, { type: entry }) })} onClick={() => add(entry)}>
                          {blockLabel(t, { type: entry })}
                        </Button>
                      </li>
                    ))}
                  </ul>
                </div>
              ) : null}
              <BlockCanvas
                blocks={layout.blocks}
                type={type}
                selected={selected}
                onSelect={setSelected}
                onChange={(blocks) => editBlocks(() => blocks)}
                onRemove={remove}
                onChangeRow={(rowId, column, next) => editBlocks((blocks) => updateBlock(blocks, rowId, (row) => ({ ...row, columns: row.columns.map((entry, index) => (index === column ? next : entry)) })))}
                locked={locked}
                canEdit={canEdit}
              />
            </div>
          </Card>
        </div>

        <div className="flex min-w-0 flex-col gap-6">
          <Card title={t('documentTemplates.inspector.title')}>
            <BlockInspector
              key={`${editing ?? 'main'}|${selected ?? ''}`}
              block={block}
              type={type}
              paper={layout.paper}
              canEdit={canEdit}
              paletteTypes={paletteTypes}
              onChange={(next) => editBlocks((blocks) => updateBlock(blocks, next.id, () => next))}
              onAddToRow={addToRow}
            />
          </Card>
          <Card title={t('documentTemplates.page.title')}>
            <PageSettings payload={payload} layout={layout} editing={editing} canEdit={canEdit} onChange={edit} />
          </Card>
          <Card title={t('documentTemplates.variants.title')}>
            <VariantsPanel
              payload={payload}
              type={type}
              operators={meta.operators ?? OPERATORS}
              editing={editing}
              canEdit={canEdit}
              onEdit={(id) => {
                setEditing(id)
                setSelected(null)
              }}
              onAdd={addVariant}
              onChangeVariant={(variant) => edit((current) => ({ ...current, variants: variantsOf(current).map((entry) => (entry.id === variant.id ? variant : entry)) }))}
              onRemoveVariant={(id) => {
                edit((current) => ({ ...current, variants: variantsOf(current).filter((entry) => entry.id !== id) }))
                setEditing(null)
                setSelected(null)
              }}
            />
          </Card>
        </div>

        <div className="flex min-w-0 flex-col gap-6">
          <Card>
            <TemplatePreview type={type} payload={payload} scope={scope} variant={editing} onProblems={onProblems} />
          </Card>
        </div>
      </div>
    </div>
  )
}

/**
 * TPL-01..TPL-03, TPL-05: the designer of one document type. Pick where
 * the template applies (the organisation, a company or a branch), arrange
 * blocks from the palette on the canvas, set each block, the paper,
 * language and margins, add variants, and see the server's preview. Drafts,
 * publishing and roll back go through the configuration store (LAY-06).
 */
export default function TemplateDesigner() {
  const { t } = useTranslation()
  const { type: typeKey } = useParams()
  const [params, setParams] = useSearchParams()
  const permissions = usePermissions()
  const scope = parseScope(params.get('scope'))

  const companies = useQuery({ queryKey: ['companies', 'templates'], queryFn: () => api.get('companies?per_page=200') })
  const branches = useQuery({ queryKey: ['branches', 'templates'], queryFn: () => api.get('branches?per_page=200') })
  const types = useQuery({ queryKey: ['templates', 'types', scope.type, scope.id], queryFn: () => api.get(`templates/types?${scopeQuery(scope)}`) })
  const companyList = companies.data?.data ?? []
  const branchList = branches.data?.data ?? []
  const type = (types.data?.data ?? []).find((entry) => entry.key === typeKey)
  const meta = types.data?.meta ?? {}

  const can = (name, where = scope) => allowedAt(permissions, name, where, branchList)
  const canEdit = can('core.template.edit')
  const canPublish = can('core.template.publish')

  const places = [
    ...companyList.map((company) => ({ type: 'company', id: company.id, label: t('documentTemplates.scope.company', { name: company.name }) })),
    ...branchList.map((branch) => ({
      type: 'branch',
      id: branch.id,
      label: t('documentTemplates.scope.branch', { name: branch.name, company: branch.company?.name ?? companyList.find((company) => company.id === branch.company_id)?.name ?? '' }),
    })),
  ]
  const scopeOptions = [{ value: 'tenant', label: t('documentTemplates.scope.tenant') }, ...places.map((place) => ({ value: `${place.type}:${place.id}`, label: place.label }))]
  const copyTargets = places.filter((place) => !(place.type === scope.type && place.id === scope.id) && can('core.template.edit', place))

  return (
    <>
      <Back />
      <PageHeader
        title={type?.label ?? t('documentTemplates.title')}
        description={type ? t('documentTemplates.designerDescription', { paper: t(`documentTemplates.papers.${type.paper}`, { defaultValue: type.paper }) }) : null}
      />
      <Select
        label={t('documentTemplates.scope.label')}
        help={t('documentTemplates.scope.help')}
        className="max-w-field"
        options={scopeOptions}
        value={scopeValue(scope)}
        onChange={(event) => setParams(event.target.value === 'tenant' ? {} : { scope: event.target.value }, { replace: true })}
      />
      {types.isError ? <Alert tone="danger" title={errorMessage(types.error)} action={<Button onClick={() => types.refetch()}>{t('common.retry')}</Button>} /> : null}
      {types.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      {types.isSuccess && !type ? <Alert tone="danger" title={t('documentTemplates.unknownType')} /> : null}
      {type ? (
        <Designer key={`${type.key}|${scopeValue(scope)}`} type={type} meta={meta} scope={scope} canEdit={canEdit} canPublish={canPublish} copyTargets={copyTargets} />
      ) : null}
    </>
  )
}
