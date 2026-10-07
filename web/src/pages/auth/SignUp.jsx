import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Button, Select, TextField } from '@/components/ds'
import { resolveLocale, setLocale } from '@/i18n'
import { contactPayload } from '@/lib/contact'
import { AuthForm, AuthPage, TextLink } from './AuthPage'

const FIELDS = ['name', 'contact', 'password', 'business_name', 'country', 'locale']

/** AUTH-01: a new business and its owner; a code then verifies the contact. */
export default function SignUp() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [values, setValues] = useState(() => {
    const locale = resolveLocale(typeof navigator === 'undefined' ? undefined : navigator.language)
    return { name: '', contact: '', password: '', business_name: '', country: locale === 'fr' ? 'CD' : 'KE', locale }
  })
  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }))

  const mutation = useMutation({
    mutationFn: ({ contact, ...rest }) => api.post('auth/sign-up', { ...rest, ...contactPayload(contact) }),
    onSuccess: (data) => {
      navigate('/verify', { state: { challengeId: data.challenge_id, destination: data.destination_masked } })
    },
  })

  const errors = formErrors(mutation.error, FIELDS, { email: 'contact', phone: 'contact' })

  const changeLocale = (event) => {
    set('locale')(event)
    setLocale(event.target.value)
  }

  return (
    <AuthPage
      title={t('auth.signUp.title')}
      intro={t('auth.signUp.intro')}
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
        <div className="grid gap-4 sm:grid-cols-2">
          <Select
            label={t('auth.fields.country')}
            value={values.country}
            onChange={set('country')}
            error={errors.fields.country}
            options={[
              { value: 'KE', label: t('auth.countries.KE') },
              { value: 'CD', label: t('auth.countries.CD') },
            ]}
          />
          <Select
            label={t('auth.fields.language')}
            value={values.locale}
            onChange={changeLocale}
            error={errors.fields.locale}
            options={[
              { value: 'en', label: t('shell.languages.en') },
              { value: 'fr', label: t('shell.languages.fr') },
            ]}
          />
        </div>
        <Button type="submit" variant="primary" block loading={mutation.isPending}>
          {t('auth.signUp.submit')}
        </Button>
      </AuthForm>
    </AuthPage>
  )
}
