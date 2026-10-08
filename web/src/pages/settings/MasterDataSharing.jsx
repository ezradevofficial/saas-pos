import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Checkbox, Dialog, Select, StatusBadge } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatDate } from '@/lib/dates'
import { useErrorFocus } from '@/lib/useErrorFocus'
import { useLocale } from '@/lib/useLocale'
import { useSettingsCompany } from './finance/useSettingsCompany'

const SHARED = 'shared'
const PER_COMPANY = 'per_company'

/** The switch's refusals in the web's words (TEN-08); codes are never shown. */
function switchError(t, error) {
  if (!error) return null
  if (error.code === 'records_need_company') return t('sharing.errors.recordsNeedCompany', { count: Number(error.data?.count ?? 0) })
  if (error.code === 'confirmation_required') return t('sharing.errors.confirmationRequired')
  if (error.code === 'duplicate_codes') {
    const values = [...(error.data?.codes ?? []), ...(error.data?.barcodes ?? [])]
    return t('sharing.errors.duplicateCodes', { values: values.join(', ') })
  }
  return errorMessage(error)
}

/**
 * TEN-08: switch one data type between shared and per company. To per
 * company, records with no company go to the company chosen; to shared,
 * the user confirms that every company's records become visible.
 */
function ChangeDialog({ setting, companies, onClose, onDone }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const bodyRef = useRef(null)
  const alertRef = useRef(null)
  const formId = useId()
  const toPerCompany = setting.mode === SHARED
  const target = toPerCompany ? PER_COMPANY : SHARED
  const [companyId, setCompanyId] = useState(companies.length === 1 ? companies[0].id : '')
  const [confirmed, setConfirmed] = useState(false)
  const [missing, setMissing] = useState(false)
  // Mid-sentence, so lower case: "Keep customers per company?"
  const type = t(`sharing.types.${setting.data_type}`).toLowerCase()

  const mutation = useMutation({
    mutationFn: () =>
      api.put('master-data/settings', {
        data_type: setting.data_type,
        mode: target,
        ...(toPerCompany ? (companyId ? { assign_to_company_id: companyId } : {}) : { confirm: true }),
      }),
    onSuccess: async (response) => {
      queryClient.setQueryData(['master-data-settings'], { data: response.data })
      // Lists of these records now follow the new mode.
      await queryClient.invalidateQueries({ predicate: (query) => query.queryKey[0] !== 'me' && query.queryKey[0] !== 'master-data-settings' })
      onDone({ type: setting.data_type, mode: target, meta: response.meta ?? {}, company: companies.find((entry) => entry.id === companyId) })
    },
  })
  useErrorFocus(bodyRef, alertRef, mutation.error)
  const error = switchError(t, mutation.error)
  const needsCompany = mutation.error?.code === 'records_need_company'

  return (
    <Dialog
      open
      title={t(`sharing.dialog.title.${target}`, { type })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('sharing.dialog.keep')}
          </Button>
          <Button variant="primary" type="submit" form={formId} loading={mutation.isPending}>
            {t(`sharing.dialog.confirm.${target}`)}
          </Button>
        </>
      }
    >
      <form
        id={formId}
        ref={bodyRef}
        noValidate
        className="flex flex-col gap-4 pt-1"
        onSubmit={(event) => {
          event.preventDefault()
          if (!toPerCompany && !confirmed) {
            setMissing(true)
            return
          }
          mutation.mutate()
        }}
      >
        {error ? (
          <div ref={alertRef} tabIndex={-1} className="rounded-md">
            <Alert tone="danger" title={error} />
          </div>
        ) : null}
        <p>{t(`sharing.dialog.text.${target}`, { type })}</p>
        <ul className="flex list-disc flex-col gap-1 pl-5">
          {['one', 'two', 'three'].map((point) => (
            <li key={point}>{t(`sharing.dialog.points.${target}.${point}`, { type })}</li>
          ))}
        </ul>
        {toPerCompany ? (
          <Select
            label={t('sharing.dialog.company')}
            help={t('sharing.dialog.companyHelp', { type })}
            placeholder={t('sharing.dialog.chooseCompany')}
            options={companies.map((company) => ({ value: company.id, label: company.name }))}
            value={companyId}
            onChange={(event) => setCompanyId(event.target.value)}
            error={needsCompany && !companyId ? t('sharing.dialog.companyRequired') : undefined}
            required={needsCompany}
          />
        ) : (
          <Checkbox
            label={t('sharing.dialog.understand', { type })}
            checked={confirmed}
            onChange={(event) => {
              setConfirmed(event.target.checked)
              setMissing(false)
            }}
            aria-invalid={missing ? 'true' : undefined}
          />
        )}
        {missing ? <p className="text-caption text-danger">{t('sharing.dialog.confirmRequired')}</p> : null}
      </form>
    </Dialog>
  )
}

/** TEN-08: whether items, customers, suppliers and employees are shared across the group or kept per company. */
export default function MasterDataSharing() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { tenantWide } = usePermissions()
  const canEdit = tenantWide('core.master_data_settings.edit')
  const { companies } = useSettingsCompany()
  const settings = useQuery({ queryKey: ['master-data-settings'], queryFn: () => api.get('master-data/settings') })
  const [changing, setChanging] = useState(null)
  const [done, setDone] = useState(null)

  const doneMessage = (result) => {
    const type = t(`sharing.types.${result.type}`)
    if (result.mode === PER_COMPANY) {
      return result.meta.assigned
        ? t('sharing.done.assigned', { type, count: result.meta.assigned, company: result.company?.name ?? '' })
        : t('sharing.done.perCompany', { type })
    }
    return t('sharing.done.shared', { type })
  }

  return (
    <>
      <PageHeader title={t('settings.sharing.title')} description={t('settings.sharing.description')} />
      {settings.isError ? <Alert tone="danger" title={errorMessage(settings.error)} action={<Button onClick={() => settings.refetch()}>{t('common.retry')}</Button>} /> : null}
      {done ? <Alert tone="success" title={doneMessage(done)} /> : null}
      {settings.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      <div className="flex flex-col gap-5">
        {(settings.data?.data ?? []).map((setting) => {
          const shared = setting.mode === SHARED
          return (
            <Card
              key={setting.data_type}
              title={t(`sharing.types.${setting.data_type}`)}
              actions={
                canEdit ? (
                  <Button
                    onClick={() => {
                      setDone(null)
                      setChanging(setting)
                    }}
                    aria-label={t('sharing.changeFor', { type: t(`sharing.types.${setting.data_type}`) })}
                  >
                    {t('sharing.change')}
                  </Button>
                ) : null
              }
            >
              <div className="flex flex-col gap-2">
                <StatusBadge tone={shared ? 'info' : 'neutral'}>{shared ? t('sharing.modes.shared') : t('sharing.modes.per_company')}</StatusBadge>
                <p className="text-ink-muted">{t(`sharing.explain.${setting.mode}`, { type: t(`sharing.types.${setting.data_type}`).toLowerCase() })}</p>
                {setting.changed_at ? <p className="text-caption text-ink-muted">{t('sharing.changedAt', { date: formatDate(setting.changed_at, locale) })}</p> : null}
              </div>
            </Card>
          )
        })}
      </div>
      {changing ? (
        <ChangeDialog
          key={changing.data_type}
          setting={changing}
          companies={companies}
          onClose={() => setChanging(null)}
          onDone={(result) => {
            setChanging(null)
            setDone(result)
          }}
        />
      ) : null}
    </>
  )
}
