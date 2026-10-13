import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { formErrors } from '@/api/formErrors'
import { Button } from '@/components/ds'
import { AuthForm } from '@/pages/auth/AuthPage'
import { CodeField } from '@/pages/auth/CodeField'
import { platformApi } from '../platformClient'

/**
 * ADR 013: two-factor is required for staff. Shows the authenticator QR
 * code and secret (`setup`: {secret, qr_svg}) and confirms a code; the
 * session token then gets full access.
 */
export function TotpEnrolment({ setup, onDone }) {
  const { t } = useTranslation()
  const [code, setCode] = useState('')
  const confirm = useMutation({
    mutationFn: () => platformApi.post('platform/me/two-factor/totp/confirm', { code }),
    onSuccess: (data) => onDone(data.user),
  })
  const errors = formErrors(confirm.error, ['code'])

  return (
    <AuthForm onSubmit={() => confirm.mutate()} error={errors.form} failure={confirm.error}>
      <div className="flex flex-col items-center gap-3">
        <p className="text-body text-ink-muted">{t('platform.enrol.scan')}</p>
        <img
          className="size-qr rounded-md border border-border"
          alt={t('auth.enrol.qrAlt')}
          src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(setup.qr_svg)}`}
        />
        <p className="text-caption text-ink-muted">{t('auth.enrol.manual')}</p>
        <code data-testid="totp-secret" className="font-mono text-caption break-all text-ink">
          {setup.secret}
        </code>
      </div>
      <CodeField value={code} onChange={setCode} error={errors.fields.code} />
      <Button type="submit" variant="primary" block loading={confirm.isPending}>
        {t('platform.enrol.submit')}
      </Button>
    </AuthForm>
  )
}
