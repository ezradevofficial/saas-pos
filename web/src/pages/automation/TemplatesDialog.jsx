import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Dialog, Select, TextField } from '@/components/ds'
import { MoneyCompany } from '@/lib/defaultCurrency'
import { cn } from '@/lib/utils'
import { ValueInput } from '@/pages/workflows/ConditionEditor'
import { useRoleOptions, useUserOptions } from '@/pages/workflows/workflowData'
import { filledValues, isBlank } from './automationData'
import { RecipientsPicker } from './RecipientsPicker'

const ALL = 'all'

/** A template's parameter for a document type: a field, a number of days, a value of the chosen field, or recipients. */
function ParameterInput({ parameter, value, params, info, onChange, roles, users }) {
  const { t } = useTranslation()
  const fields = info?.fields ?? []
  const label = t(`automation.templates.params.${parameter.kind}`, { defaultValue: parameter.name })
  switch (parameter.kind) {
    case 'field':
      return (
        <Select
          label={label}
          options={(parameter.fields ?? []).map((name) => ({ value: name, label: fields.find((field) => field.name === name)?.label ?? name }))}
          value={value ?? ''}
          placeholder={t('automation.trigger.chooseField')}
          onChange={(event) => onChange(event.target.value)}
        />
      )
    case 'days':
      return (
        <TextField
          label={label}
          type="number"
          inputMode="numeric"
          min={0}
          max={3650}
          value={value ?? ''}
          onChange={(event) => {
            const days = Number.parseInt(event.target.value, 10)
            onChange(Number.isInteger(days) ? Math.max(0, days) : '')
          }}
        />
      )
    case 'value': {
      const field = fields.find((one) => one.name === params.field)
      return field ? <ValueInput key={field.name} field={field} op="lt" label={label} value={value} onChange={onChange} /> : null
    }
    case 'recipients':
      return <RecipientsPicker value={value ?? []} onChange={onChange} roles={roles} users={users} userFields={info?.user_fields ?? []} fields={fields} />
    default:
      return <TextField label={label} value={value ?? ''} onChange={(event) => onChange(event.target.value)} />
  }
}

const defaultsOf = (usage) => Object.fromEntries((usage?.parameters ?? []).map((parameter) => [parameter.name, parameter.default ?? (parameter.kind === 'recipients' ? [] : '')]))

/**
 * Start from a template (AUTO-07): the templates for a document type with
 * what each does and its settings; using one saves a rule switched off
 * and opens it in the editor.
 */
export function TemplatesDialog({ types, companies, canAll, onClose }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const roles = useRoleOptions()
  const users = useUserOptions()
  const companyOptions = [...(canAll ? [{ value: ALL, label: t('automation.allCompanies') }] : []), ...companies.map((company) => ({ value: company.id, label: company.name }))]
  const [type, setType] = useState(types[0]?.key ?? '')
  const [company, setCompany] = useState(companyOptions[0]?.value ?? '')
  const [chosen, setChosen] = useState(null)
  const [params, setParams] = useState({})
  const [name, setName] = useState('')
  const info = types.find((one) => one.key === type)

  const templates = useQuery({ queryKey: ['automation-templates', type], queryFn: () => api.get(`automation-templates?type=${encodeURIComponent(type)}`), enabled: Boolean(type) })
  const list = templates.data?.data ?? []
  const template = list.find((one) => one.key === chosen)
  const usage = template?.document_types?.find((one) => one.key === type)

  const choose = (next) => {
    setChosen(next.key)
    setParams(defaultsOf(next.document_types?.find((one) => one.key === type)))
  }

  const use = useMutation({
    mutationFn: () =>
      api.post('automation-templates/use', {
        template: chosen,
        document_type: type,
        company_id: company === ALL ? null : company,
        ...(name.trim() ? { name: name.trim() } : {}),
        params: Object.fromEntries(Object.entries(filledValues(params)).filter(([, value]) => !isBlank(value))),
      }),
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: ['automation-rules'] })
      navigate(`/settings/automation-rules/${response.data.id}`)
    },
  })

  return (
    <Dialog
      open
      size="lg"
      title={t('automation.templates.title')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" disabled={!template || !company} loading={use.isPending} onClick={() => use.mutate()}>
            {t('automation.templates.use')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {use.isError ? <Alert tone="danger" title={errorMessage(use.error)} /> : null}
        <p>{t('automation.templates.body')}</p>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Select
            label={t('automation.fields.documentType')}
            options={types.map((one) => ({ value: one.key, label: one.label }))}
            value={type}
            onChange={(event) => {
              setType(event.target.value)
              setChosen(null)
              setParams({})
            }}
          />
          <Select label={t('automation.fields.company')} options={companyOptions} value={company} onChange={(event) => setCompany(event.target.value)} />
        </div>
        {templates.isError ? <Alert tone="danger" title={errorMessage(templates.error)} /> : null}
        {templates.isSuccess && list.length === 0 ? <p className="text-ink-muted">{t('automation.templates.none')}</p> : null}
        <div role="radiogroup" aria-label={t('automation.templates.list')} className="flex flex-col gap-2">
          {list.map((one) => (
            <button
              key={one.key}
              type="button"
              role="radio"
              aria-checked={one.key === chosen}
              onClick={() => choose(one)}
              className={cn(
                'flex flex-col gap-1 rounded-md border px-4 py-3 text-left transition-colors hover:bg-surface-300',
                'focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2 focus-visible:outline-focus',
                one.key === chosen ? 'border-primary bg-surface-100' : 'border-border bg-surface-200',
              )}
            >
              <span className="text-label text-ink">{one.label}</span>
              <span className="text-caption text-ink-muted">{one.description}</span>
            </button>
          ))}
        </div>
        {usage ? (
          <MoneyCompany value={company === ALL ? null : company || null}>
            <fieldset className="flex flex-col gap-4">
              <legend className="pb-2 text-label text-ink">{t('automation.templates.settings')}</legend>
              <TextField label={t('automation.templates.name')} help={t('automation.templates.nameHelp')} value={name} maxLength={120} onChange={(event) => setName(event.target.value)} />
              {usage.parameters.map((parameter) => (
                <ParameterInput
                  key={parameter.name}
                  parameter={parameter}
                  value={params[parameter.name]}
                  params={params}
                  info={info}
                  roles={roles}
                  users={users}
                  onChange={(value) => setParams((current) => ({ ...current, [parameter.name]: value, ...(parameter.kind === 'field' ? { value: '' } : {}) }))}
                />
              ))}
              <p className="text-caption text-ink-muted">{t('automation.templates.disabledNote')}</p>
            </fieldset>
          </MoneyCompany>
        ) : null}
      </div>
    </Dialog>
  )
}
