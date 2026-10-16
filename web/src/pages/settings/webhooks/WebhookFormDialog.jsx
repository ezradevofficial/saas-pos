import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Checkbox, Dialog, Select, Switch, TextField } from '@/components/ds'
import { CATALOGUE_KEY, eventLabel, WEBHOOKS_KEY } from './webhookShared'

const FIELDS = ['url', 'description', 'events', 'company_id', 'branch_id', 'active']

function initialState(webhook) {
  return {
    url: '',
    description: webhook?.description ?? '',
    events: webhook?.events ?? [],
    filter: webhook?.branch_id ? 'branch' : webhook?.company_id ? 'company' : 'all',
    companyId: webhook?.company_id ?? '',
    branchId: webhook?.branch_id ?? '',
    active: webhook?.active ?? true,
  }
}

/**
 * HOOK-01: add or edit a webhook. The URL is write-only: when editing it
 * stays as it is unless a new one is typed. Events come from the catalogue
 * (the modules in use), grouped by module; the filter is the whole
 * organisation, one company or one branch. On create, `onCreated` gets the
 * response, which carries the signing secret once.
 */
export function WebhookFormDialog({ open, webhook, onClose, onCreated }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [state, setState] = useState(() => initialState(webhook))
  const [localError, setLocalError] = useState(null)
  const editing = Boolean(webhook)
  const set = (patch) => setState((current) => ({ ...current, ...patch }))

  const catalogue = useQuery({ queryKey: CATALOGUE_KEY, queryFn: () => api.get('webhooks/catalogue'), enabled: open })
  const companies = useQuery({ queryKey: ['companies', 'webhooks'], queryFn: () => api.get('companies?per_page=200'), enabled: open })
  const branches = useQuery({ queryKey: ['branches', 'webhooks'], queryFn: () => api.get('branches?per_page=200'), enabled: open })

  const save = useMutation({
    mutationFn: () => {
      const body = {
        description: state.description.trim() === '' ? null : state.description.trim(),
        events: state.events,
        company_id: state.filter === 'company' ? state.companyId : null,
        branch_id: state.filter === 'branch' ? state.branchId : null,
      }
      if (!editing || state.url.trim() !== '') body.url = state.url.trim()
      if (editing) body.active = state.active
      return editing ? api.patch(`webhooks/${webhook.id}`, body) : api.post('webhooks', body)
    },
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: WEBHOOKS_KEY })
      if (!editing) onCreated?.(response.data)
      onClose()
    },
  })
  const errors = formErrors(save.error, FIELDS)

  const submit = (event) => {
    event.preventDefault()
    setLocalError(null)
    if (state.events.length === 0) return setLocalError(t('webhooks.form.noEvents'))
    if (state.filter === 'company' && !state.companyId) return setLocalError(t('webhooks.form.pickCompany'))
    if (state.filter === 'branch' && !state.branchId) return setLocalError(t('webhooks.form.pickBranch'))
    save.mutate()
  }

  const types = catalogue.data?.data ?? []
  const groups = types.reduce((all, type) => {
    ;(all[type.module] ??= []).push(type)
    return all
  }, {})
  const toggle = (key, checked) => set({ events: checked ? [...new Set([...state.events, key])] : state.events.filter((value) => value !== key) })
  const eventsError = errors.fields.events ?? Object.entries(errors.fields).find(([field]) => field.startsWith('events.'))?.[1]

  return (
    <Dialog
      open={open}
      size="lg"
      title={editing ? t('webhooks.form.editTitle') : t('webhooks.form.createTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" form="webhook-form" variant="primary" loading={save.isPending}>
            {editing ? t('webhooks.form.save') : t('webhooks.form.create')}
          </Button>
        </>
      }
    >
      <form id="webhook-form" noValidate className="flex flex-col gap-5 pt-1" onSubmit={submit}>
        {errors.form || localError ? <Alert tone="danger" title={localError ?? errors.form} /> : null}
        <TextField
          label={t('webhooks.fields.url')}
          help={editing ? t('webhooks.fields.urlKeep', { url: webhook.url_display }) : t('webhooks.fields.urlHelp')}
          value={state.url}
          onChange={(event) => set({ url: event.target.value })}
          error={errors.fields.url}
          placeholder="https://"
          inputMode="url"
          autoComplete="off"
          spellCheck={false}
          required={!editing}
        />
        <TextField
          label={t('webhooks.fields.description')}
          help={t('webhooks.fields.descriptionHelp')}
          value={state.description}
          onChange={(event) => set({ description: event.target.value })}
          error={errors.fields.description}
          maxLength={200}
        />
        <fieldset className="flex flex-col gap-3">
          <legend className="text-label text-ink">{t('webhooks.fields.events')}</legend>
          <p className="text-caption text-ink-muted">{t('webhooks.fields.eventsHelp')}</p>
          {catalogue.isPending ? <p className="text-caption text-ink-muted">{t('common.loading')}</p> : null}
          {Object.entries(groups).map(([module, list]) => (
            <div key={module} className="flex flex-col gap-2">
              <p className="text-caption text-ink-muted">{t(`webhooks.modules.${module}`, { defaultValue: module })}</p>
              <div className="grid gap-2 sm:grid-cols-2">
                {list.map((type) => (
                  <Checkbox
                    key={type.key}
                    label={eventLabel(t, type.key)}
                    help={type.key}
                    checked={state.events.includes(type.key)}
                    onChange={(event) => toggle(type.key, event.target.checked)}
                  />
                ))}
              </div>
            </div>
          ))}
          {eventsError ? <p className="text-caption text-danger">{eventsError}</p> : null}
        </fieldset>
        <div className="grid gap-4 sm:grid-cols-2">
          <Select
            label={t('webhooks.fields.filter')}
            value={state.filter}
            onChange={(event) => set({ filter: event.target.value })}
            options={[
              { value: 'all', label: t('webhooks.fields.filterAll') },
              { value: 'company', label: t('webhooks.fields.filterCompany') },
              { value: 'branch', label: t('webhooks.fields.filterBranch') },
            ]}
          />
          {state.filter === 'company' ? (
            <Select
              label={t('webhooks.fields.company')}
              value={state.companyId}
              placeholder={t('webhooks.form.pickCompany')}
              onChange={(event) => set({ companyId: event.target.value })}
              error={errors.fields.company_id}
              options={(companies.data?.data ?? []).map((company) => ({ value: company.id, label: company.name }))}
            />
          ) : null}
          {state.filter === 'branch' ? (
            <Select
              label={t('webhooks.fields.branch')}
              value={state.branchId}
              placeholder={t('webhooks.form.pickBranch')}
              onChange={(event) => set({ branchId: event.target.value })}
              error={errors.fields.branch_id}
              options={(branches.data?.data ?? []).map((branch) => ({ value: branch.id, label: branch.name }))}
            />
          ) : null}
        </div>
        {editing ? <Switch checked={state.active} onChange={(checked) => set({ active: checked })} label={t('webhooks.fields.active')} /> : null}
      </form>
    </Dialog>
  )
}
