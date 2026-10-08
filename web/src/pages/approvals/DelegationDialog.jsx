import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { useAuth } from '@/auth/AuthProvider'
import { Alert, Button, Checkbox, Dialog, Select, StatusBadge, TextField } from '@/components/ds'
import { APPROVALS_KEY, DELEGATION_TONES, useDelegations } from '@/lib/approvals'
import { formatCalendarDate, todayIn } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { peopleOptions, useDelegationCandidates, useDocumentTypeOptions } from './approvalData'
import { TextAreaField } from './TextAreaField'

const FIELDS = ['to_user_id', 'starts_on', 'ends_on', 'document_types', 'note']
const STATES = ['active', 'scheduled', 'ended', 'revoked']

function DelegationRow({ delegation, onRevoke, revoking }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const given = delegation.direction === 'given'
  const status = STATES.includes(delegation.status) ? delegation.status : 'ended'
  const types = delegation.document_types?.length ? delegation.document_types.length : null
  return (
    <li className="flex flex-wrap items-start justify-between gap-3 border-b border-border py-3 last:border-b-0">
      <div className="flex min-w-0 flex-col gap-1">
        <span className="text-ink">
          {given
            ? t('approvals.delegation.givenTo', { name: delegation.to?.name ?? t('approvals.someone') })
            : t('approvals.delegation.receivedFrom', { name: delegation.from?.name ?? t('approvals.someone') })}
        </span>
        <span className="text-caption text-ink-muted">
          {[
            t('approvals.delegation.period', { from: formatCalendarDate(delegation.starts_on, locale), to: formatCalendarDate(delegation.ends_on, locale) }),
            types ? t('approvals.delegation.someTypes', { count: types }) : t('approvals.delegation.allTypes'),
          ].join(' · ')}
        </span>
        {delegation.note ? <span className="text-caption text-ink-muted">{delegation.note}</span> : null}
      </div>
      <div className="flex items-center gap-3">
        <StatusBadge tone={DELEGATION_TONES[status]}>{t(`approvals.delegation.status.${status}`)}</StatusBadge>
        {given && (status === 'active' || status === 'scheduled') ? (
          <Button
            variant="ghost"
            loading={revoking}
            onClick={() => onRevoke(delegation)}
            aria-label={t('approvals.delegation.revokeFor', { name: delegation.to?.name ?? t('approvals.someone') })}
          >
            {t('approvals.delegation.revoke')}
          </Button>
        ) : null}
      </div>
    </li>
  )
}

/**
 * Delegation (APR-06): let a colleague decide the user's approvals for a
 * period, for every document type or chosen ones, with a note; and the
 * delegations given and received, with Revoke for the ones still running.
 */
export function DelegationDialog({ open, onClose }) {
  const { t } = useTranslation()
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const today = todayIn()
  const empty = { to_user_id: '', starts_on: today, ends_on: '', scope: 'all', document_types: [], note: '' }
  const [values, setValues] = useState(empty)
  const [missing, setMissing] = useState({})
  // The delegate picker searches the server as the user types; the chosen person stays listed.
  const [personSearch, setPersonSearch] = useState('')
  const [chosenPerson, setChosenPerson] = useState(null)
  const users = useDelegationCandidates(personSearch, { enabled: open })
  const candidates = users.data ?? []
  const people = chosenPerson && !candidates.some((person) => person.id === chosenPerson.id) ? [chosenPerson, ...candidates] : candidates
  const delegations = useDelegations({ enabled: open })
  const typeOptions = useDocumentTypeOptions()

  const refresh = () => queryClient.invalidateQueries({ queryKey: APPROVALS_KEY })
  const create = useMutation({
    mutationFn: (body) => api.post('me/delegations', body),
    onSuccess: () => {
      refresh()
      setValues(empty)
      setChosenPerson(null)
      toast.success(t('approvals.delegation.created'))
    },
  })
  const revoke = useMutation({
    mutationFn: (delegation) => api.post(`me/delegations/${delegation.id}/revoke`),
    onSuccess: () => {
      refresh()
      toast.success(t('approvals.delegation.revoked'))
    },
  })

  const set = (name) => (event) => setValues((current) => ({ ...current, [name]: event.target.value }))
  const toggleType = (key, on) =>
    setValues((current) => ({ ...current, document_types: on ? [...current.document_types, key] : current.document_types.filter((entry) => entry !== key) }))

  const submit = () => {
    const problems = {}
    if (!values.to_user_id) problems.to_user_id = t('approvals.delegation.toRequired')
    if (!values.starts_on) problems.starts_on = t('approvals.delegation.startRequired')
    if (!values.ends_on) problems.ends_on = t('approvals.delegation.endRequired')
    if (values.scope === 'some' && values.document_types.length === 0) problems.document_types = t('approvals.delegation.typesRequired')
    setMissing(problems)
    if (Object.keys(problems).length) return
    create.mutate({
      to_user_id: values.to_user_id,
      starts_on: values.starts_on,
      ends_on: values.ends_on,
      document_types: values.scope === 'some' ? values.document_types : null,
      ...(values.note.trim() ? { note: values.note.trim() } : {}),
    })
  }

  const errors = formErrors(create.error, FIELDS)
  const error = (name) => missing[name] ?? errors.fields[name]
  const rows = delegations.data ?? []

  return (
    <Dialog open={open} onClose={onClose} title={t('approvals.delegation.title')} size="lg">
      <div className="flex flex-col gap-5 text-ink">
        <p className="text-ink-muted">{t('approvals.delegation.intro')}</p>
        <form
          noValidate
          aria-label={t('approvals.delegation.formLabel')}
          onSubmit={(event) => {
            event.preventDefault()
            submit()
          }}
          className="flex flex-col gap-4"
        >
          {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
          <Select
            label={t('approvals.delegation.to')}
            placeholder={users.isPending ? t('common.loading') : t('approvals.delegation.choosePerson')}
            options={peopleOptions(people, { exclude: [user?.id].filter(Boolean) })}
            value={values.to_user_id}
            onChange={(event) => {
              setChosenPerson(people.find((person) => person.id === event.target.value) ?? null)
              set('to_user_id')(event)
            }}
            onSearchChange={setPersonSearch}
            error={error('to_user_id')}
            help={users.isError ? t('approvals.form.peopleUnavailable') : undefined}
            required
          />
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <TextField type="date" label={t('approvals.delegation.from')} value={values.starts_on} onChange={set('starts_on')} error={error('starts_on')} required />
            <TextField type="date" label={t('approvals.delegation.until')} value={values.ends_on} min={values.starts_on || undefined} onChange={set('ends_on')} error={error('ends_on')} required />
          </div>
          <Select
            label={t('approvals.delegation.scope')}
            options={[
              { value: 'all', label: t('approvals.delegation.scopeAll') },
              { value: 'some', label: t('approvals.delegation.scopeSome') },
            ]}
            value={values.scope}
            onChange={set('scope')}
          />
          {values.scope === 'some' ? (
            <fieldset className="flex flex-col gap-2">
              <legend className="pb-2 text-label text-ink">{t('approvals.delegation.types')}</legend>
              {typeOptions.length ? (
                typeOptions.map((option) => (
                  <Checkbox key={option.value} label={option.label} checked={values.document_types.includes(option.value)} onChange={(event) => toggleType(option.value, event.target.checked)} />
                ))
              ) : (
                <p className="text-caption text-ink-muted">{t('approvals.delegation.noTypes')}</p>
              )}
              {error('document_types') ? <p className="text-caption text-danger">{error('document_types')}</p> : null}
            </fieldset>
          ) : null}
          <TextAreaField label={t('approvals.delegation.note')} value={values.note} maxLength={500} onChange={set('note')} error={error('note')} rows={2} />
          <div className="flex justify-end">
            <Button type="submit" variant="primary" loading={create.isPending}>
              {t('approvals.delegation.save')}
            </Button>
          </div>
        </form>

        <section aria-label={t('approvals.delegation.listTitle')} className="flex flex-col gap-2 border-t border-border pt-4">
          <h3 className="text-h3 text-ink">{t('approvals.delegation.listTitle')}</h3>
          {revoke.error ? <Alert tone="danger" title={errorMessage(revoke.error)} /> : null}
          {delegations.isPending ? (
            <p className="text-ink-muted">{t('common.loading')}</p>
          ) : delegations.isError ? (
            <Alert tone="danger" title={errorMessage(delegations.error)} />
          ) : rows.length === 0 ? (
            <p className="text-ink-muted">{t('approvals.delegation.none')}</p>
          ) : (
            <ul className="flex flex-col">
              {rows.map((delegation) => (
                <DelegationRow
                  key={delegation.id}
                  delegation={delegation}
                  revoking={revoke.isPending && revoke.variables?.id === delegation.id}
                  onRevoke={(entry) => revoke.mutate(entry)}
                />
              ))}
            </ul>
          )}
        </section>
      </div>
    </Dialog>
  )
}
