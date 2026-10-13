import { useMutation, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, MultiSelect, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { contactPayload } from '@/lib/contact'
import { platformApi } from '../platformClient'

const BUSINESS_TYPES = ['retail', 'supermarket', 'restaurant', 'bar', 'hotel', 'pharmacy', 'wholesale', 'van_sales', 'salon', 'fuel_station', 'non_pos']
const FIELDS = ['business_name', 'country', 'locale', 'business_type', 'outlets', 'modules', 'cycle', 'contact_name', 'contact']

/**
 * ONB-08: platform staff create a tenant for a customer: the business,
 * its country and language, business type and modules, and the contact
 * who receives an invitation to become its Owner. Staff get no access to
 * the tenant; the trial starts with the selection.
 */
export default function NewTenant() {
  const { t } = useTranslation()
  const [values, setValues] = useState({
    business_name: '',
    country: 'KE',
    locale: 'en',
    business_type: 'retail',
    outlets: '1',
    modules: ['pos', 'inventory', 'loyalty'],
    cycle: 'monthly',
    contact_name: '',
    contact: '',
  })
  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }))
  const catalogue = useQuery({ queryKey: ['catalogue', values.country], queryFn: () => platformApi.get(`catalogue?country=${values.country}`) })
  const modules = (catalogue.data?.data?.modules ?? []).filter((module) => module.kind === 'module')

  const create = useMutation({
    mutationFn: () =>
      platformApi.post('platform/tenants', {
        business_name: values.business_name.trim(),
        country: values.country,
        locale: values.locale,
        business_type: values.business_type,
        outlets: Number(values.outlets || 1),
        modules: values.modules,
        quantities: { outlet: Number(values.outlets || 1) },
        cycle: values.cycle,
        contact_name: values.contact_name.trim(),
        ...contactPayload(values.contact),
      }),
  })
  const errors = formErrors(create.error, FIELDS, { email: 'contact', phone: 'contact' })
  const created = create.data?.data

  return (
    <>
      <PageHeader title={t('platform.tenants.title')} description={t('platform.tenants.intro')} />
      {created ? (
        <Alert tone="success" title={t('platform.tenants.created')}>
          {t('platform.tenants.createdText', { destination: created.destination_masked, slug: created.slug })}
        </Alert>
      ) : null}
      <Card>
        <form
          noValidate
          className="flex flex-col gap-5"
          onSubmit={(event) => {
            event.preventDefault()
            create.mutate()
          }}
        >
          {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField label={t('platform.tenants.businessName')} value={values.business_name} onChange={set('business_name')} error={errors.fields.business_name} required />
            <Select
              label={t('platform.tenants.businessType')}
              value={values.business_type}
              onChange={set('business_type')}
              error={errors.fields.business_type}
              options={BUSINESS_TYPES.map((value) => ({ value, label: t(`platform.tenants.types.${value}`) }))}
            />
            <Select
              label={t('platform.fields.country')}
              value={values.country}
              onChange={set('country')}
              error={errors.fields.country}
              options={['KE', 'CD'].map((value) => ({ value, label: t(`auth.countries.${value}`) }))}
            />
            <Select
              label={t('auth.fields.language')}
              value={values.locale}
              onChange={set('locale')}
              error={errors.fields.locale}
              options={['en', 'fr'].map((value) => ({ value, label: t(`shell.languages.${value}`) }))}
            />
            <TextField label={t('platform.tenants.outlets')} type="number" inputMode="numeric" min={1} value={values.outlets} onChange={set('outlets')} error={errors.fields.outlets} />
            <Select
              label={t('platform.quote.cycle')}
              value={values.cycle}
              onChange={set('cycle')}
              error={errors.fields.cycle}
              options={['monthly', 'annual'].map((value) => ({ value, label: t(`platform.quote.cycles.${value}`) }))}
            />
          </div>
          <MultiSelect
            label={t('platform.quote.modules')}
            value={values.modules}
            onChange={(modulesChosen) => setValues((current) => ({ ...current, modules: modulesChosen }))}
            options={modules.map((module) => ({ value: module.key, label: module.name }))}
            error={errors.fields.modules}
          />
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField label={t('platform.tenants.contactName')} value={values.contact_name} onChange={set('contact_name')} error={errors.fields.contact_name} required />
            <TextField
              label={t('platform.tenants.contact')}
              help={t('auth.signUp.contactHelp')}
              value={values.contact}
              onChange={set('contact')}
              error={errors.fields.contact}
              required
            />
          </div>
          <p className="text-caption text-ink-muted">{t('platform.tenants.handover')}</p>
          <div className="flex justify-end">
            <Button type="submit" variant="primary" loading={create.isPending}>
              {t('platform.tenants.submit')}
            </Button>
          </div>
        </form>
      </Card>
    </>
  )
}
