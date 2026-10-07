import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Dialog, TextField } from '@/components/ds'
import { useErrorFocus } from '@/lib/useErrorFocus'

/** RBAC-02, RBAC-03: copy a role (system roles are copied, never edited), then open the copy. */
export function CopyRoleDialog({ role, onClose }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const formId = useId()
  const formRef = useRef(null)
  const alertRef = useRef(null)
  const [name, setName] = useState(() => t('roles.copyName', { name: role.name }))

  const mutation = useMutation({
    mutationFn: () => api.post(`roles/${role.id}/copy`, { name: name.trim() }),
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: ['roles'] })
      onClose()
      navigate(`/settings/roles/${response.data.id}`)
    },
  })
  const errors = formErrors(mutation.error, ['name'])
  const formError = errors.form ? errorMessage(mutation.error) : null
  useErrorFocus(formRef, alertRef, mutation.error)

  return (
    <Dialog
      open
      title={t('roles.copyTitle', { name: role.name })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" type="submit" form={formId} loading={mutation.isPending}>
            {t('roles.copyConfirm')}
          </Button>
        </>
      }
    >
      <form
        id={formId}
        ref={formRef}
        noValidate
        onSubmit={(event) => {
          event.preventDefault()
          mutation.mutate()
        }}
        className="flex flex-col gap-4 pt-1"
      >
        <p>{t('roles.copyText')}</p>
        {formError ? (
          <div ref={alertRef} tabIndex={-1} className="rounded-md">
            <Alert tone="danger" title={formError} />
          </div>
        ) : null}
        <TextField label={t('roles.fields.name')} value={name} onChange={(event) => setName(event.target.value)} error={errors.fields.name} maxLength={100} required />
      </form>
    </Dialog>
  )
}
