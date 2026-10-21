import { useMutation } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Navigate, useNavigate, useSearchParams } from 'react-router'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Button, TextField } from '@/components/ds'
import { setLocale } from '@/i18n'
import { contactPayload } from '@/lib/contact'
import { AuthForm, AuthPage, TextLink } from './AuthPage'

// Country and language errors have no field here: they show in the form's alert.
const FIELDS = ['name', 'contact', 'password', 'business_name']

const COUNTRIES = ['KE', 'CD']
const LOCALES = ['en', 'fr']

/**
 * AUTH-01, ONB-01: a new business and its owner, after the country and
 * language were chosen on /welcome (they arrive as ?country=&locale=); a
 * code then verifies the contact.
 */
export default function SignUp() {
  const [params] = useSearchParams()
  const country = params.get('country')
  const locale = params.get('locale')
  // API-05: a developer sandbox (from the developer portal): never billed, sample data, test keys.
  const sandbox = params.get('sandbox') === '1'
  if (!COUNTRIES.includes(country) || !LOCALES.includes(locale)) return <Navigate to={sandbox ? '/welcome?sandbox=1' : '/welcome'} replace />
  return <SignUpForm country={country} locale={locale} sandbox={sandbox} />
}

function SignUpForm({ country, locale, sandbox = false }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [values, setValues] = useState({ name: '', contact: '', password: '', business_name: '', country, locale, ...(sandbox ? { sandbox: true } : {}) })
  useEffect(() => {
    setLocale(locale)
  }, [locale])
  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }))

  const mutation = useMutation({
    mutationFn: ({ contact, ...rest }) => api.post('auth/sign-up', { ...rest, ...contactPayload(contact) }),
    onSuccess: (data) => {
      navigate('/verify', { state: { challengeId: data.challenge_id, destination: data.destination_masked } })
    },
  })

  const errors = formErrors(mutation.error, FIELDS, { email: 'contact', phone: 'contact' })

  return (
    <AuthPage
      title={sandbox ? t('auth.signUp.sandboxTitle') : t('auth.signUp.title')}
      intro={sandbox ? t('auth.signUp.sandboxIntro') : t('auth.signUp.intro')}
      footer={
        <p>
          {t('auth.signUp.haveAccount')} <TextLink to="/sign-in">{t('auth.signUp.signIn')}</TextLink>
        </p>
      }
    >
      <AuthForm onSubmit={() => mutation.mutate(values)} error={errors.form} failure={mutation.error}>
        <TextField
          label={t('auth.fields.name')}
          autoComplete="name"
          value={values.name}
          onChange={set('name')}
          error={errors.fields.name}
          required
        />
        <TextField
          label={t('auth.fields.login')}
          help={t('auth.signUp.contactHelp')}
          autoComplete="username"
          value={values.contact}
          onChange={set('contact')}
          error={errors.fields.contact}
          required
        />
        <TextField
          label={t('auth.fields.newPassword')}
          help={t('auth.passwordHelp')}
          type="password"
          autoComplete="new-password"
          value={values.password}
          onChange={set('password')}
          error={errors.fields.password}
          required
        />
        <TextField
          label={t('auth.fields.businessName')}
          autoComplete="organization"
          value={values.business_name}
          onChange={set('business_name')}
          error={errors.fields.business_name}
          required
        />
        <p className="text-caption text-ink-muted">
          {t('onboarding.welcome.chosen', { country: t(`auth.countries.${country}`), language: t(`shell.languages.${locale}`) })}{' '}
          <TextLink to="/welcome">{t('onboarding.welcome.change')}</TextLink>
        </p>
        <Button type="submit" variant="primary" block loading={mutation.isPending}>
          {t('auth.signUp.submit')}
        </Button>
      </AuthForm>
    </AuthPage>
  )
}
