import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, Icon, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { contactPayload } from '@/lib/contact'
import { useErrorFocus } from '@/lib/useErrorFocus'
import { AssignmentFields } from './users/AssignmentFields'
import { assignmentBody, emptyAssignment, offeredRow, useGrantOptions } from './users/assignments'

const MAX_ROWS = 20

/** Errors for row `index` from keys such as `assignments.0.scope_id`. */
function rowErrors(fields, index) {
  const prefix = `assignments.${index}.`
  return Object.fromEntries(
    Object.entries(fields)
      .filter(([key]) => key.startsWith(prefix))
      .map(([key, message]) => [key.slice(prefix.length), message]),
  )
}

/**
 * AUTH-05: invite someone by email or phone with one or more roles, each
 * at a place (RBAC-04). A page, not a dialog: the role rows can grow.
 */
export default function InviteUser() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const formRef = useRef(null)
  const alertRef = useRef(null)
  const options = useGrantOptions({ invite: true })
  const [name, setName] = useState('')
  const [contact, setContact] = useState('')
  const [rows, setRows] = useState(() => [emptyAssignment()])

  // A row keeps a kind of place the user can offer once the lists have loaded.
  const effective = rows.map((row) => offeredRow(row, options))

  const mutation = useMutation({
    mutationFn: () =>
      api.post('invitations', {
        name: name.trim(),
        ...contactPayload(contact),
        assignments: effective.map(assignmentBody),
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['invitations'] })
      navigate('/settings/users?tab=invitations', { state: { notice: t('users.invite.sent', { name: name.trim() }) } })
    },
  })

  const rowFields = rows.flatMap((_, index) => ['role_id', 'scope_type', 'scope_id'].map((field) => `assignments.${index}.${field}`))
  const errors = formErrors(mutation.error, ['name', 'contact', ...rowFields], { email: 'contact', phone: 'contact' })
  const hasFieldErrors = Object.keys(mutation.error?.errors ?? {}).length > 0
  const formError = errors.form ? (hasFieldErrors ? errors.form : errorMessage(mutation.error)) : null
  useErrorFocus(formRef, alertRef, mutation.error)

  const update = (index, value) => setRows((current) => current.map((row, i) => (i === index ? value : row)))
  const remove = (index) => setRows((current) => current.filter((_, i) => i !== index))

  return (
    <>
      <Link to="/settings/users" className="flex w-fit items-center gap-2 text-label text-primary hover:text-primary-hover">
        <Icon name="back" />
        {t('users.backToUsers')}
      </Link>
      <PageHeader title={t('users.invite.title')} description={t('users.invite.description')} />
      {options.rolesError ? <Alert tone="danger" title={errorMessage(options.rolesError)} /> : null}
      <Card>
        <form
          ref={formRef}
          noValidate
          onSubmit={(event) => {
            event.preventDefault()
            mutation.mutate()
          }}
          className="flex flex-col gap-5"
        >
          {formError ? (
            <div ref={alertRef} tabIndex={-1} className="rounded-md">
              <Alert tone="danger" title={formError} />
            </div>
          ) : null}
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField label={t('users.fields.name')} value={name} onChange={(event) => setName(event.target.value)} error={errors.fields.name} autoComplete="off" required />
            <TextField
              label={t('users.fields.contact')}
              help={t('users.invite.contactHelp')}
              value={contact}
              onChange={(event) => setContact(event.target.value)}
              error={errors.fields.contact}
              autoComplete="off"
              required
            />
          </div>
          <div className="flex flex-col gap-3">
            <div className="flex flex-col gap-1">
              <h2 className="text-h3 text-ink">{t('users.invite.rolesTitle')}</h2>
              <p className="text-ink-muted">{t('users.invite.rolesText')}</p>
            </div>
            {effective.map((row, index) => (
              <AssignmentFields
                key={index}
                index={index}
                value={row}
                onChange={(value) => update(index, value)}
                onRemove={rows.length > 1 ? () => remove(index) : null}
                options={options}
                errors={rowErrors(errors.fields, index)}
              />
            ))}
            {rows.length < MAX_ROWS ? (
              <div>
                <Button icon="plus" onClick={() => setRows((current) => [...current, emptyAssignment(options.scopeTypes)])}>
                  {t('users.invite.addRole')}
                </Button>
              </div>
            ) : null}
          </div>
          <div className="flex flex-wrap justify-end gap-2 border-t border-border pt-4">
            <Button variant="ghost" onClick={() => navigate('/settings/users')}>
              {t('common.cancel')}
            </Button>
            <Button variant="primary" type="submit" loading={mutation.isPending}>
              {t('users.invite.send')}
            </Button>
          </div>
        </form>
      </Card>
    </>
  )
}
