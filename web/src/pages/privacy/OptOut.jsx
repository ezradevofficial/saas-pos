import { useMutation, useQuery } from '@tanstack/react-query'
import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams, useSearchParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button } from '@/components/ds'
import { setLocale, supportedLocales } from '@/i18n'
import { AuthPage } from '@/pages/auth/AuthPage'

/**
 * NFR-08 (KE DPA): the page a marketing email's unsubscribe link opens, no
 * sign-in needed. Opening it only reads the link's state (mail scanners
 * open links); one click on the button withdraws consent for that channel.
 * It shows in the email's language (`?lang=`).
 */
export default function OptOut() {
  const { t } = useTranslation()
  const { token } = useParams()
  const [params] = useSearchParams()
  const lang = params.get('lang')

  useEffect(() => {
    if (supportedLocales.includes(lang)) setLocale(lang)
  }, [lang])

  const link = useQuery({
    queryKey: ['opt-out', token],
    queryFn: () => api.get(`opt-out/${token}`),
    select: (response) => response?.data ?? null,
    retry: false,
    staleTime: Infinity,
  })
  const confirm = useMutation({ mutationFn: () => api.post(`opt-out/${token}`) })

  if (confirm.isSuccess || link.data?.status === 'done') {
    return (
      <AuthPage title={t('optOut.doneTitle')} intro={t('optOut.doneText')}>
        <Alert tone="success" title={t('optOut.doneTitle')} />
      </AuthPage>
    )
  }

  if (link.isError) {
    return (
      <AuthPage title={t('optOut.title')}>
        <Alert tone="danger">{errorMessage(link.error)}</Alert>
      </AuthPage>
    )
  }

  return (
    <AuthPage title={t('optOut.title')} intro={t('optOut.intro')}>
      <div className="flex flex-col gap-4">
        {link.isLoading ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
        {confirm.error ? <Alert tone="danger">{errorMessage(confirm.error)}</Alert> : null}
        <Button variant="primary" disabled={!link.data} loading={confirm.isPending} onClick={() => confirm.mutate()}>
          {t('optOut.confirm')}
        </Button>
      </div>
    </AuthPage>
  )
}
