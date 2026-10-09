import tokens from '@app/tokens'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Select, TextField, VersionBar } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useConfigDocument } from '@/lib/useConfigDocument'
import { useDebounced } from '@/lib/useDebounced'
import { BrandPreview } from './brand/BrandPreview'
import { AssetField, ChoiceGroup, ColourField } from './brand/fields'
import { cleanTheme, derivedFor, presetTokens } from './brand/theme'

const DEFAULT_THEME = { preset: 'light' }
const TENANT = 'tenant'
const WELCOME_MAX = 280

const scopeOf = (value) => {
  if (value === TENANT) return { type: 'tenant', id: null }
  const [type, id] = value.split(':')
  return { type, id }
}

/**
 * The editor of one scope's theme (BR-02, BR-03): presets, colours with
 * derived values and contrast meters, sidebar, corners, font, logos and (for
 * the whole business) the sign-in page, beside a live preview. Changes are
 * saved to the draft a moment after the last edit; publishing is the
 * VersionBar's job and the API refuses it while a pair is under WCAG AA.
 */
function ThemeEditor({ initial, scope, config, canEdit, canPublish, copyTargets, onReset }) {
  const { t } = useTranslation()
  const [theme, setTheme] = useState(initial)
  const [mode, setMode] = useState('light')
  const [saveState, setSaveState] = useState(null)
  const saved = useRef(JSON.stringify(cleanTheme(initial)))
  const settled = useDebounced(theme, 600)
  const assetsQuery = useQuery({ queryKey: ['branding', 'assets'], queryFn: () => api.get('branding/assets') })
  const urls = useMemo(() => Object.fromEntries((assetsQuery.data?.data ?? []).map((asset) => [asset.id, asset.url])), [assetsQuery.data])

  const clean = useMemo(() => cleanTheme(theme), [theme])
  const dirty = JSON.stringify(clean) !== saved.current
  const { saveDraft } = config

  useEffect(() => {
    const next = cleanTheme(settled)
    const text = JSON.stringify(next)
    if (!canEdit || text === saved.current) return
    saved.current = text
    setSaveState('saving')
    saveDraft(next, { name: t('brand.documentName') }).then(
      () => setSaveState('saved'),
      () => {
        saved.current = ''
        setSaveState('error')
      },
    )
    // saveDraft changes identity every render; the settled theme is what triggers a save.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [settled, canEdit])

  const set = (patch) => setTheme((current) => ({ ...current, ...patch }))
  const setColour = (name) => (value) => setTheme((current) => ({ ...current, colors: { ...current.colors, [name]: value } }))
  const setLogin = (patch) => setTheme((current) => ({ ...current, login: { ...current.login, ...patch } }))

  const checks = useMemo(() => tokens.themeChecks(clean), [clean])
  const checksFor = (field) => checks.filter((check) => check.field === field)
  const base = presetTokens(clean.preset)
  const failing = checks.filter((check) => !check.passes)
  // What the API says about the saved draft (assets, unknown values) besides contrast, which the meters show.
  const serverProblems = dirty ? [] : config.problems.filter((problem) => problem.code !== 'contrast')

  const presets = tokens.PRESETS.map((id) => ({ value: id, label: t(`ds.theme.${id}`), description: t(`appearance.themes.${id}`) }))
  const fonts = [
    { value: '', label: t('brand.font.preset') },
    ...Object.keys(tokens.FONTS).map((id) => ({ value: id, label: t(`brand.font.options.${id}`) })),
  ]
  const logo = urls[mode === 'dark' || clean.sidebar === 'dark' ? (clean.logo_dark ?? clean.logo_light) : (clean.logo_light ?? clean.logo_dark)] ?? null

  return (
    <>
      <VersionBar
        document={config.document}
        problems={dirty ? [] : config.problems}
        canEdit={canEdit}
        canPublish={canPublish && !dirty && saveState !== 'saving'}
        copyTargets={copyTargets}
        pending={config.pending}
        saveState={dirty ? 'saving' : saveState}
        onPublish={config.publish}
        onDiscard={async () => {
          await config.discardDraft()
          onReset()
        }}
        onRollback={async (version) => {
          await config.rollback(version)
          onReset()
        }}
        onCopy={(target, from) => config.copyTo(target, from)}
      />

      {failing.length > 0 ? (
        <Alert tone="danger" title={t('brand.contrast.blockedTitle')}>
          {t('brand.contrast.blockedText', { count: failing.length })}
        </Alert>
      ) : null}
      {serverProblems.length > 0 ? (
        <Alert tone="danger" title={t('brand.problemsTitle')}>
          <ul className="flex list-disc flex-col gap-1 pl-5">
            {serverProblems.map((problem) => (
              <li key={`${problem.path}-${problem.code}`}>{problem.message}</li>
            ))}
          </ul>
        </Alert>
      ) : null}
      {!canEdit ? <Alert tone="info" title={t('brand.readOnly')} /> : null}

      <div className="grid gap-5 xl:grid-cols-2">
        <div className="flex min-w-0 flex-col gap-5">
          <Card title={t('brand.sections.preset')} subtitle={t('brand.sections.presetText')}>
            <ChoiceGroup label={t('brand.preset')} name="preset" value={clean.preset} options={presets} onChange={(preset) => set({ preset })} disabled={!canEdit} />
          </Card>

          <Card title={t('brand.sections.colours')} subtitle={t('brand.sections.coloursText')}>
            <div className="flex flex-col gap-6">
              <ColourField
                field="primary"
                label={t('brand.colours.primary')}
                help={t('brand.colours.primaryHelp')}
                value={clean.colors?.primary ?? null}
                presetValue={base.primary}
                derived={derivedFor(clean, 'primary')}
                checks={checksFor('colors.primary')}
                onChange={setColour('primary')}
                disabled={!canEdit}
              />
              <ColourField
                field="accent"
                label={t('brand.colours.accent')}
                help={t('brand.colours.accentHelp')}
                value={clean.colors?.accent ?? null}
                presetValue={base.accent}
                derived={derivedFor(clean, 'accent')}
                checks={checksFor('colors.accent')}
                onChange={setColour('accent')}
                disabled={!canEdit}
              />
            </div>
          </Card>

          <Card title={t('brand.sections.shape')} subtitle={t('brand.sections.shapeText')}>
            <div className="flex flex-col gap-5">
              <ChoiceGroup
                label={t('brand.sidebar.label')}
                name="sidebar"
                value={clean.sidebar ?? ''}
                columns="sm:grid-cols-3"
                options={[
                  { value: '', label: t('brand.sidebar.preset') },
                  { value: 'light', label: t('brand.sidebar.light') },
                  { value: 'dark', label: t('brand.sidebar.dark') },
                ]}
                onChange={(sidebar) => set({ sidebar: sidebar || null })}
                disabled={!canEdit}
              />
              <ChoiceGroup
                label={t('brand.corners.label')}
                name="corners"
                value={clean.corners ?? 'standard'}
                columns="sm:grid-cols-3"
                options={['sharp', 'standard', 'soft'].map((value) => ({ value, label: t(`brand.corners.${value}`) }))}
                onChange={(corners) => set({ corners: corners === 'standard' ? null : corners })}
                disabled={!canEdit}
              />
              <Select
                label={t('brand.font.label')}
                help={t('brand.font.help')}
                options={fonts}
                value={clean.font ?? ''}
                onChange={(event) => set({ font: event.target.value || null })}
                disabled={!canEdit}
                className="max-w-field"
              />
            </div>
          </Card>

          <Card title={t('brand.sections.logos')} subtitle={t('brand.sections.logosText')}>
            <div className="flex flex-col gap-5">
              <AssetField label={t('brand.assets.logoLight')} help={t('brand.assets.logoLightHelp')} kind="logo" value={clean.logo_light} url={urls[clean.logo_light]} onChange={(id) => set({ logo_light: id })} disabled={!canEdit} />
              <AssetField label={t('brand.assets.logoDark')} help={t('brand.assets.logoDarkHelp')} kind="logo" value={clean.logo_dark} url={urls[clean.logo_dark]} onChange={(id) => set({ logo_dark: id })} disabled={!canEdit} />
              <AssetField label={t('brand.assets.favicon')} help={t('brand.assets.faviconHelp')} kind="favicon" value={clean.favicon} url={urls[clean.favicon]} onChange={(id) => set({ favicon: id })} disabled={!canEdit} />
            </div>
          </Card>

          {scope.type === 'tenant' ? (
            <Card title={t('brand.sections.signIn')} subtitle={t('brand.sections.signInText')}>
              <div className="flex flex-col gap-5">
                <TextField
                  label={t('brand.signIn.welcome')}
                  help={t('brand.signIn.welcomeHelp', { max: WELCOME_MAX })}
                  maxLength={WELCOME_MAX}
                  value={theme.login?.welcome ?? ''}
                  onChange={(event) => setLogin({ welcome: event.target.value })}
                  disabled={!canEdit}
                />
                <AssetField
                  label={t('brand.signIn.background')}
                  help={t('brand.signIn.backgroundHelp')}
                  kind="background"
                  value={clean.login?.background}
                  url={urls[clean.login?.background]}
                  onChange={(id) => setLogin({ background: id })}
                  disabled={!canEdit}
                />
              </div>
            </Card>
          ) : null}
        </div>

        <section aria-labelledby="brand-preview-heading" className="flex min-w-0 flex-col gap-3 xl:sticky xl:top-4 xl:self-start">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <h2 id="brand-preview-heading" className="text-h2 text-ink">
              {t('brand.preview.title')}
            </h2>
            <div role="group" aria-label={t('brand.preview.mode')} className="flex gap-1">
              {['light', 'dark'].map((one) => (
                <Button key={one} variant="ghost" aria-pressed={mode === one} className={mode === one ? 'bg-surface-300' : undefined} onClick={() => setMode(one)}>
                  {t(`brand.contrast.modes.${one}`)}
                </Button>
              ))}
            </div>
          </div>
          <p className="text-caption text-ink-muted">{t('brand.preview.text')}</p>
          <BrandPreview theme={clean} mode={mode} logo={logo} />
        </section>
      </div>
    </>
  )
}

/**
 * BR-02, BR-03, BR-08: Settings → Brand. The business's theme, for the
 * whole business or overridden for one company or branch, as versioned
 * configuration (draft, publish, roll back, copy).
 */
export default function Brand() {
  const { t } = useTranslation()
  const { can, canWithin } = usePermissions()
  const queryClient = useQueryClient()
  const [scopeValue, setScopeValue] = useState(TENANT)
  const [resets, setResets] = useState(0)
  const scope = scopeOf(scopeValue)

  const companiesQuery = useQuery({ queryKey: ['companies', 'brand'], queryFn: () => api.get('companies?per_page=200') })
  const branchesQuery = useQuery({ queryKey: ['branches', 'brand'], queryFn: () => api.get('branches?per_page=200') })
  const companies = companiesQuery.data?.data ?? []
  const branches = branchesQuery.data?.data ?? []

  const places = [
    ...companies.map((company) => ({ type: 'company', id: company.id, label: t('brand.scope.company', { name: company.name }) })),
    ...branches.map((branch) => ({ type: 'branch', id: branch.id, label: t('brand.scope.branch', { name: branch.name, company: branch.company?.name ?? '' }) })),
  ]
  const options = [{ value: TENANT, label: t('brand.scope.tenant') }, ...places.map((place) => ({ value: `${place.type}:${place.id}`, label: place.label }))]

  const chain =
    scope.type === 'tenant'
      ? null
      : scope.type === 'company'
        ? [scope]
        : [scope, { type: 'company', id: branches.find((branch) => branch.id === scope.id)?.company_id }]
  const allowed = (name) => (chain === null ? can(name, { type: 'tenant' }) : canWithin(name, chain))
  const canEdit = allowed('core.theme.edit')
  const canPublish = allowed('core.theme.publish')

  const config = useConfigDocument('theme', 'default', scope)
  const published = config.publish
  // Publishing changes what the whole app looks like: fetch the resolved theme again.
  const publish = async () => {
    const response = await published()
    await queryClient.invalidateQueries({ queryKey: ['config', 'theme', 'resolved'] })
    return response
  }
  const afterChange = async () => {
    await queryClient.invalidateQueries({ queryKey: ['config', 'theme', 'resolved'] })
    setResets((count) => count + 1)
  }

  return (
    <>
      <PageHeader title={t('brand.title')} description={t('brand.description')} />
      <Select
        label={t('brand.scope.label')}
        help={scope.type === 'tenant' ? t('brand.scope.tenantHelp') : t('brand.scope.overrideHelp')}
        options={options}
        value={scopeValue}
        onChange={(event) => setScopeValue(event.target.value)}
        className="max-w-field"
      />
      {config.error ? <Alert tone="danger" title={errorMessage(config.error)} /> : null}
      {config.isLoading ? (
        <p className="text-ink-muted">{t('common.loading')}</p>
      ) : config.error ? null : (
        <ThemeEditor
          key={`${scopeValue}:${resets}`}
          initial={config.payload ?? DEFAULT_THEME}
          scope={scope}
          config={{ ...config, publish }}
          canEdit={canEdit}
          canPublish={canPublish}
          copyTargets={places.filter((place) => !(place.type === scope.type && place.id === scope.id))}
          onReset={afterChange}
        />
      )}
    </>
  )
}
