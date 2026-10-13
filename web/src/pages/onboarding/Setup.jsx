import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, Navigate, useNavigate, useParams } from 'react-router'
import { api } from '@/api/client'
import { Alert, Button, Card, Icon, StatusBadge } from '@/components/ds'
import { SampleDataCard } from '@/components/onboarding/SampleDataCard'
import { usePageTitle } from '@/lib/usePageTitle'
import { cn } from '@/lib/utils'
import { hasRoute, IMPORT_ITEMS_PATH } from '@/lib/hasRoute'
import Currencies from '../settings/Currencies'
import Organisation from '../settings/Organisation'
import { RecordDialog } from '../settings/organisation/RecordDialog'
import PaymentMethods from '../settings/PaymentMethods'
import Taxes from '../settings/Taxes'
import { SETUP_STEPS, useOnboarding, useOnboardingWrite } from './useOnboarding'

function stepStatus(state, key) {
  return state?.steps?.find((step) => step.key === key)?.status ?? 'todo'
}

function StepMark({ status, index }) {
  return (
    <span
      aria-hidden="true"
      className={cn(
        'flex size-6 shrink-0 items-center justify-center rounded-pill border text-caption font-medium',
        status === 'done' ? 'border-success bg-success text-surface-200' : 'border-border-strong bg-surface-200 text-ink-muted',
      )}
    >
      {status === 'done' ? <Icon name="check" size={12} /> : index + 1}
    </span>
  )
}

function StatusWord({ status }) {
  const { t } = useTranslation()
  if (status === 'done') return <StatusBadge tone="success">{t('onboarding.setup.status.done')}</StatusBadge>
  if (status === 'skipped') return <StatusBadge tone="neutral">{t('onboarding.setup.status.skipped')}</StatusBadge>
  return null
}

/**
 * ONB-05 (OnboardSetup): the setup checklist. Every step can be done in
 * any order, skipped and resumed; progress is kept on the server.
 */
export function SetupOverview() {
  const { t } = useTranslation()
  usePageTitle(t('onboarding.setup.title'))
  const navigate = useNavigate()
  const { data: state, isLoading } = useOnboarding()
  if (isLoading || !state) return <p className="text-ink-muted">{t('common.loading')}</p>

  const done = state.steps.filter((step) => step.status === 'done').length
  const settled = state.steps.filter((step) => step.status !== 'todo').length

  return (
    <div className="flex flex-col gap-10 lg:flex-row lg:items-start">
      <section className="flex min-w-0 flex-1 flex-col gap-5">
        <div className="flex flex-col gap-2">
          <h1 className="text-display text-ink">{t('onboarding.setup.title')}</h1>
          <p className="text-body-lg text-ink-muted">{t('onboarding.setup.intro')}</p>
        </div>
        <div className="flex flex-col gap-2">
          <div className="flex justify-between text-label text-ink">
            <span>{t('onboarding.setup.progress', { done, total: SETUP_STEPS.length })}</span>
          </div>
          <div
            role="progressbar"
            aria-label={t('onboarding.setup.progressLabel')}
            aria-valuemin={0}
            aria-valuemax={SETUP_STEPS.length}
            aria-valuenow={settled}
            className="grid grid-cols-8 gap-1"
          >
            {SETUP_STEPS.map((key, i) => (
              <span key={key} className={cn('h-2 rounded-pill', i < settled ? 'bg-primary' : 'bg-surface-300')} />
            ))}
          </div>
        </div>
        <ol className="flex flex-col overflow-hidden rounded-lg border border-border bg-surface-200">
          {SETUP_STEPS.map((key, index) => {
            const status = stepStatus(state, key)
            return (
              <li key={key} className="flex flex-wrap items-center gap-4 border-b border-border px-5 py-4 last:border-b-0">
                <StepMark status={status} index={index} />
                <div className="min-w-0 flex-1">
                  <div className="text-body-lg font-medium text-ink">{t(`onboarding.steps.${key}.title`, { context: state.country })}</div>
                  <div className="text-caption text-ink-muted">{t(`onboarding.steps.${key}.description`)}</div>
                </div>
                <StatusWord status={status} />
                <Button variant={status === 'todo' && index === SETUP_STEPS.findIndex((k) => stepStatus(state, k) === 'todo') ? 'primary' : 'secondary'} onClick={() => navigate(`/onboarding/setup/${key}`)}>
                  {status === 'todo' ? t('onboarding.setup.open') : t('onboarding.setup.review')}
                </Button>
              </li>
            )
          })}
        </ol>
      </section>
      <aside className="flex w-full flex-col gap-4 lg:w-drawer">
        <SampleDataCard />
        <Card title={t('onboarding.setup.help.title')}>
          <div className="flex flex-col gap-3">
            <p className="text-ink-muted">{t('onboarding.setup.help.text')}</p>
          </div>
        </Card>
        {state.pos_mode ? <Alert tone="info" title={t('onboarding.setup.canSell.title')}>{t('onboarding.setup.canSell.text', { context: state.country })}</Alert> : null}
        <Button variant="primary" size="lg" block onClick={() => navigate('/')}>
          {t('onboarding.setup.toDashboard')}
        </Button>
      </aside>
    </div>
  )
}

function CompanyStep({ country }) {
  const { t } = useTranslation()
  const [editing, setEditing] = useState(false)
  const companies = useQuery({ queryKey: ['companies', 'onboarding'], queryFn: () => api.get('companies?per_page=1') })
  const company = companies.data?.data?.[0]
  if (!company) return <p className="text-ink-muted">{t('common.loading')}</p>
  return (
    <Card title={company.name} subtitle={company.legal_name}>
      <dl className="grid gap-3 sm:grid-cols-2">
        <div>
          <dt className="text-caption text-ink-muted">{t('onboarding.steps.company.taxId', { context: country })}</dt>
          <dd className="text-body text-ink">{company.tax_id ?? t('onboarding.steps.company.noTaxId')}</dd>
        </div>
        <div>
          <dt className="text-caption text-ink-muted">{t('onboarding.steps.company.currency')}</dt>
          <dd className="text-body text-ink">{company.base_currency}</dd>
        </div>
      </dl>
      <div className="mt-4">
        <Button icon="edit" onClick={() => setEditing(true)}>
          {t('onboarding.steps.company.edit')}
        </Button>
      </div>
      {editing ? <RecordDialog level="company" record={company} onClose={() => setEditing(false)} /> : null}
    </Card>
  )
}

function ItemsStep() {
  const { t } = useTranslation()
  const canImport = hasRoute(IMPORT_ITEMS_PATH)
  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <Card title={t('onboarding.steps.items.manualTitle')}>
        <div className="flex flex-col items-start gap-3">
          <p className="text-ink-muted">{t('onboarding.steps.items.manualText')}</p>
          <Link className="text-label text-primary hover:text-primary-hover" to="/catalogue/items/new">
            {t('onboarding.steps.items.addItem')}
          </Link>
        </div>
      </Card>
      <Card title={t('onboarding.steps.items.importTitle')}>
        <div className="flex flex-col items-start gap-3">
          <p className="text-ink-muted">{t('onboarding.steps.items.importText')}</p>
          {canImport ? (
            <Link className="text-label text-primary hover:text-primary-hover" to={IMPORT_ITEMS_PATH}>
              {t('onboarding.steps.items.import')}
            </Link>
          ) : (
            <p className="text-caption text-ink-muted">{t('onboarding.steps.items.importSoon')}</p>
          )}
        </div>
      </Card>
    </div>
  )
}

function UsersStep() {
  const { t } = useTranslation()
  return (
    <Card title={t('onboarding.steps.users.cardTitle')}>
      <div className="flex flex-col items-start gap-3">
        <p className="text-ink-muted">{t('onboarding.steps.users.text')}</p>
        <div className="flex flex-wrap gap-4">
          <Link className="text-label text-primary hover:text-primary-hover" to="/settings/users/invite">
            {t('onboarding.steps.users.invite')}
          </Link>
          <Link className="text-label text-primary hover:text-primary-hover" to="/settings/roles">
            {t('onboarding.steps.users.roles')}
          </Link>
        </div>
      </div>
    </Card>
  )
}

/** Each step reuses the app's own page or form (no second copy of a form). */
function StepBody({ step, state }) {
  const { t } = useTranslation()
  switch (step) {
    case 'company':
      return <CompanyStep country={state.country} />
    case 'currencies':
      return <Currencies />
    case 'branches':
      return <Organisation />
    case 'taxes':
      return <Taxes />
    case 'payment_methods':
      return <PaymentMethods />
    case 'items':
      return <ItemsStep />
    case 'users':
      return <UsersStep />
    case 'devices':
      return (
        <div className="flex flex-col gap-4">
          <Alert tone="info" title={t('onboarding.steps.devices.how')} />
          <Organisation />
        </div>
      )
    default:
      return null
  }
}

/** ONB-05: one setup step, with the page it needs, then done or skipped. */
export function SetupStep() {
  const { t } = useTranslation()
  const { step } = useParams()
  const navigate = useNavigate()
  const { data: state } = useOnboarding()
  const index = SETUP_STEPS.indexOf(step)
  const next = SETUP_STEPS[index + 1]
  const mark = useOnboardingWrite((status) => api.post('onboarding/steps', { step, status }), {
    onSuccess: () => navigate(next ? `/onboarding/setup/${next}` : '/onboarding/setup'),
  })
  usePageTitle(index === -1 ? '' : t(`onboarding.steps.${step}.title`, { context: state?.country }))

  if (index === -1) return <Navigate to="/onboarding/setup" replace />
  if (!state) return <p className="text-ink-muted">{t('common.loading')}</p>
  const status = stepStatus(state, step)

  return (
    <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
      <nav aria-label={t('onboarding.setup.stepsLabel')} className="w-full lg:w-sidebar">
        <ol className="flex flex-col gap-1">
          {SETUP_STEPS.map((key, i) => (
            <li key={key}>
              <Link
                to={`/onboarding/setup/${key}`}
                aria-current={key === step ? 'step' : undefined}
                className={cn('flex items-center gap-3 rounded-md px-2 py-2 text-label', key === step ? 'bg-surface-300 text-ink' : 'text-ink-muted hover:text-ink')}
              >
                <StepMark status={stepStatus(state, key)} index={i} />
                <span className="min-w-0 truncate">{t(`onboarding.steps.${key}.title`, { context: state.country })}</span>
              </Link>
            </li>
          ))}
        </ol>
        <Link to="/onboarding/setup" className="mt-3 block px-2 text-label text-primary hover:text-primary-hover">
          {t('onboarding.setup.overview')}
        </Link>
      </nav>
      <section className="flex min-w-0 flex-1 flex-col gap-6">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="flex min-w-0 flex-col gap-1">
            <p className="text-caption text-ink-muted">{t('onboarding.setup.stepOf', { step: index + 1, total: SETUP_STEPS.length })}</p>
            <h1 className="text-h1 text-ink">{t(`onboarding.steps.${step}.title`, { context: state.country })}</h1>
            <p className="text-body text-ink-muted">{t(`onboarding.steps.${step}.description`)}</p>
          </div>
          <StatusWord status={status} />
        </div>
        <div className="flex flex-col gap-6">
          <StepBody step={step} state={state} />
        </div>
        <div className="flex flex-wrap justify-end gap-3 border-t border-border pt-4">
          {status !== 'todo' ? (
            <Button variant="ghost" loading={mark.isPending && mark.variables === 'todo'} onClick={() => mark.mutate('todo')}>
              {t('onboarding.setup.reopen')}
            </Button>
          ) : null}
          <Button loading={mark.isPending && mark.variables === 'skipped'} onClick={() => mark.mutate('skipped')}>
            {t('onboarding.setup.skip')}
          </Button>
          <Button variant="primary" loading={mark.isPending && mark.variables === 'done'} onClick={() => mark.mutate('done')}>
            {t('onboarding.setup.markDone')}
          </Button>
        </div>
      </section>
    </div>
  )
}
