import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, DecimalInput, MoneyInput, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useMoneyDefaults } from '@/lib/defaultCurrency'
import { Back, DetailState } from './FixedAssetsParts'
import { faKey, locationLabel, METHODS, useCategories, useLocations } from './fixedAssetsData'

const FIELDS = ['location_id', 'name', 'number', 'category_id', 'description', 'serial_number', 'acquired_on', 'in_service_on', 'currency', 'cost_minor',
  'supplier_reference', 'warranty_until', 'warranty_note', 'method', 'useful_life_months', 'residual_minor', 'declining_rate', 'total_units']

/** The form of a draft (FA-04): new, or an existing draft's fields. Active assets change by estimates and transfers. */
function Form({ asset }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const locations = useLocations()
  const categories = useCategories()
  const money = useMoneyDefaults()
  const [values, setValues] = useState(() => ({
    location_id: asset?.location?.id ?? '',
    name: asset?.name ?? '',
    number: asset?.number ?? '',
    category_id: asset?.category?.id ?? '',
    description: asset?.description ?? '',
    serial_number: asset?.serial_number ?? '',
    acquired_on: asset?.acquired_on ?? '',
    in_service_on: asset?.in_service_on ?? '',
    // Empty until chosen: the company's base currency applies once it has loaded (below).
    currency: asset?.cost?.currency ?? '',
    cost_minor: asset?.cost?.amount_minor ?? '',
    supplier_reference: asset?.supplier_reference ?? '',
    warranty_until: asset?.warranty_until ?? '',
    warranty_note: asset?.warranty_note ?? '',
    method: asset?.method ?? '',
    useful_life_months: asset?.useful_life_months ? String(asset.useful_life_months) : '',
    residual_minor: asset?.residual?.amount_minor ?? '',
    declining_rate: asset?.declining_rate ?? '',
    total_units: asset?.total_units ?? '',
  }))
  // CUR-01: the base currency is known only once companies and currencies load; on a direct
  // load the first render had neither and the form started in another currency (stage 7D walkthrough).
  const currency = values.currency || money.currency
  const set = (name) => (value) => setValues((current) => ({ ...current, [name]: value }))
  const onInput = (name) => (event) => set(name)(event.target.value)
  const save = useMutation({
    mutationFn: () => {
      const body = Object.fromEntries(Object.entries({ ...values, currency }).filter(([, value]) => value !== '' && value !== null))
      if (body.useful_life_months) body.useful_life_months = Number.parseInt(body.useful_life_months, 10)
      // A category's terms apply unless the form names its own method.
      if (!values.method) {
        delete body.useful_life_months
        delete body.declining_rate
      }
      return asset ? api.patch(`fixed-assets/assets/${asset.id}`, body) : api.post('fixed-assets/assets', body)
    },
    onSuccess: async (response) => {
      toast.success(t(asset ? 'fixedAssets.form.saved' : 'fixedAssets.form.created', { number: response.data.number }))
      await queryClient.invalidateQueries({ queryKey: faKey })
      navigate(`/fixed-assets/assets/${response.data.id}`)
    },
  })
  const errors = formErrors(save.error, FIELDS)
  const method = values.method || categories.find((category) => category.id === values.category_id)?.method

  return (
    <div className="flex flex-col gap-5">
      {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
      <Card title={t('fixedAssets.form.register')}>
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField label={t('fixedAssets.fields.name')} value={values.name} required maxLength={200} error={errors.fields.name} onChange={onInput('name')} />
          <TextField label={t('fixedAssets.fields.tag')} help={t('fixedAssets.form.tagHelp')} value={values.number} maxLength={80} error={errors.fields.number} onChange={onInput('number')} />
          <Select
            label={t('fixedAssets.fields.location')}
            placeholder={t('fixedAssets.pickers.location')}
            options={locations.map((location) => ({ value: location.id, label: locationLabel(location) }))}
            value={values.location_id}
            required
            error={errors.fields.location_id}
            onChange={onInput('location_id')}
          />
          <Select
            label={t('fixedAssets.fields.category')}
            placeholder={t('fixedAssets.pickers.category')}
            options={categories.map((category) => ({ value: category.id, label: `${category.code} · ${category.name}` }))}
            value={values.category_id}
            error={errors.fields.category_id}
            onChange={onInput('category_id')}
          />
          <TextField label={t('fixedAssets.fields.serialNumber')} value={values.serial_number} maxLength={120} error={errors.fields.serial_number} onChange={onInput('serial_number')} />
          <TextField label={t('fixedAssets.fields.description')} value={values.description} maxLength={5000} error={errors.fields.description} onChange={onInput('description')} />
        </div>
      </Card>
      <Card title={t('fixedAssets.form.acquisition')}>
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField type="date" label={t('fixedAssets.fields.acquiredOn')} value={values.acquired_on} error={errors.fields.acquired_on} onChange={onInput('acquired_on')} />
          <TextField type="date" label={t('fixedAssets.fields.inServiceOn')} help={t('fixedAssets.form.inServiceHelp')} value={values.in_service_on} error={errors.fields.in_service_on} onChange={onInput('in_service_on')} />
          <Select
            label={t('fixedAssets.fields.currency')}
            options={money.options.map((code) => ({ value: code, label: code }))}
            value={currency}
            error={errors.fields.currency}
            onChange={onInput('currency')}
          />
          <MoneyInput label={t('fixedAssets.fields.cost')} currency={currency} value={values.cost_minor} error={errors.fields.cost_minor} onChange={(value) => set('cost_minor')(value ?? '')} />
          <TextField label={t('fixedAssets.fields.supplierReference')} value={values.supplier_reference} maxLength={120} error={errors.fields.supplier_reference} onChange={onInput('supplier_reference')} />
          <TextField type="date" label={t('fixedAssets.fields.warrantyUntil')} value={values.warranty_until} error={errors.fields.warranty_until} onChange={onInput('warranty_until')} />
        </div>
      </Card>
      <Card title={t('fixedAssets.form.terms')} subtitle={t('fixedAssets.form.termsHelp')}>
        <div className="grid gap-4 sm:grid-cols-2">
          <Select
            label={t('fixedAssets.fields.method')}
            options={[{ value: '', label: t('fixedAssets.form.categoryTerms') }, ...METHODS.map((value) => ({ value, label: t(`fixedAssets.methods.${value}`) }))]}
            value={values.method}
            error={errors.fields.method}
            onChange={onInput('method')}
          />
          {values.method && values.method !== 'none' && values.method !== 'units_of_production' ? (
            <TextField type="number" min={1} max={1200} label={t('fixedAssets.fields.usefulLife')} value={values.useful_life_months} error={errors.fields.useful_life_months} onChange={onInput('useful_life_months')} />
          ) : null}
          {values.method === 'declining_balance' ? (
            <DecimalInput label={t('fixedAssets.fields.decliningRate')} value={values.declining_rate} maxDecimals={4} error={errors.fields.declining_rate} onChange={(value) => set('declining_rate')(value ?? '')} />
          ) : null}
          {method === 'units_of_production' ? (
            <DecimalInput label={t('fixedAssets.fields.totalUnits')} value={values.total_units} maxDecimals={6} error={errors.fields.total_units} onChange={(value) => set('total_units')(value ?? '')} />
          ) : null}
          <MoneyInput label={t('fixedAssets.fields.residual')} help={t('fixedAssets.form.residualHelp')} currency={currency} value={values.residual_minor} error={errors.fields.residual_minor} onChange={(value) => set('residual_minor')(value ?? '')} />
        </div>
      </Card>
      <div className="flex gap-2">
        <Button variant="primary" loading={save.isPending} disabled={!values.name.trim() || !values.location_id} onClick={() => save.mutate()}>
          {asset ? t('fixedAssets.form.save') : t('fixedAssets.form.create')}
        </Button>
        <Button variant="ghost" onClick={() => navigate(asset ? `/fixed-assets/assets/${asset.id}` : '/fixed-assets/assets')}>
          {t('fixedAssets.actions.back')}
        </Button>
      </div>
    </div>
  )
}

/** FA-04: a new draft asset, or a draft's changes. */
export default function AssetForm() {
  const { t } = useTranslation()
  const { assetId } = useParams()
  const query = useQuery({ queryKey: [...faKey, 'asset', assetId], queryFn: () => api.get(`fixed-assets/assets/${assetId}`), enabled: Boolean(assetId) })
  const back = <Back to={assetId ? `/fixed-assets/assets/${assetId}` : '/fixed-assets/assets'}>{t('fixedAssets.assets.back')}</Back>

  if (assetId && !query.data) return <DetailState query={query} back={back} notFound={t('fixedAssets.detail.notFound')} />

  return (
    <>
      {back}
      <PageHeader title={assetId ? t('fixedAssets.form.editTitle', { number: query.data.data.number }) : t('fixedAssets.form.newTitle')} description={t('fixedAssets.form.description')} />
      <Form asset={query.data?.data} />
    </>
  )
}
