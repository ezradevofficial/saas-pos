import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Navigate, useNavigate } from 'react-router'
import { formErrors } from '@/api/formErrors'
import { Alert, Button } from '@/components/ds'
import { AuthPage } from '@/pages/auth/AuthPage'
import { usePlatformAuth } from '../usePlatformAuth'
import { platformApi } from '../platformClient'
import { TotpEnrolment } from './TotpEnrolment'

/** ADR 013: a staff session that may only enrol two-factor, until a code is confirmed. */
export default function PlatformEnrol() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { token, user, fullAccess, refresh, signOut } = usePlatformAuth()
  const [setup, setSetup] = useState(null)
  const start = useMutation({ mutationFn: () => platformApi.post('platform/me/two-factor/totp'), onSuccess: setSetup })

  if (!token) return <Navigate to="/platform/sign-in" replace />
  if (user && fullAccess) return <Navigate to="/platform/catalogue" replace />

  const errors = formErrors(start.error, [])

  return (
    <AuthPage
      title={t('platform.enrol.title')}
      intro={t('platform.enrol.intro')}
      footer={
        <p>
          <Button variant="ghost" className="px-0" onClick={() => signOut()}>
            {t('shell.signOut')}
          </Button>
        </p>
      }
    >
      {setup ? (
        <TotpEnrolment
          setup={setup}
          onDone={async () => {
            await refresh()
            navigate('/platform/catalogue', { replace: true })
          }}
        />
      ) : (
        <div className="flex flex-col gap-4">
          {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
          <Button variant="primary" block loading={start.isPending} onClick={() => start.mutate()}>
            {t('auth.enrol.useApp')}
          </Button>
        </div>
      )}
    </AuthPage>
  )
}
