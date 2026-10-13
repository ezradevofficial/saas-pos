import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Dialog, MoneyInput, Select, TextField } from '@/components/ds'
import { platformApi } from '../platformClient'

const CURRENCY = { KE: 'KES', CD: 'USD' }

const today = () => new Date().toISOString().slice(0, 10)

/**
 * SUB-01: a new price for a module or bundle in one country, from a date.
 * Prices are never edited: the new row takes over on its date.
 */
export function PriceDialog({ item, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [values, setValues] = useState({ country: 'KE', amount: '', minimum_amount: '', extra_outlet_amount: '', effective_from: today() })
  const isBundle = item?.type === 'bundle'

  const save = useMutation({
    mutationFn: () =>
      platformApi.post('platform/catalogue/prices', {
        item_type: item.type,
        item_key: item.key,
        country: values.country,
        amount: values.amount === '' || values.amount === null ? null : Number(values.amount),
        effective_from: values.effective_from,
        ...(isBundle
          ? { extra_outlet_amount: values.extra_outlet_amount ? Number(values.extra_outlet_amount) : null }
          : { minimum_amount: values.minimum_amount ? Number(values.minimum_amount) : null }),
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['platform', 'catalogue'] })
      onClose()
    },
  })

  const errors = formErrors(save.error, ['country', 'amount', 'minimum_amount', 'extra_outlet_amount', 'effective_from'])
  const currency = CURRENCY[values.country]
  const set = (field) => (value) => setValues((current) => ({ ...current, [field]: value }))

  return (
    <Dialog
      open={Boolean(item)}
      onClose={onClose}
      title={t('platform.prices.title', { key: item?.key ?? '' })}
      footer={
        <>
          <Button onClick={onClose}>{t('common.cancel')}</Button>
          <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>
            {t('platform.prices.save')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <p className="text-body text-ink-muted">{t('platform.prices.intro')}</p>
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <Select
          label={t('platform.fields.country')}
          value={values.country}
          onChange={(event) => set('country')(event.target.value)}
          options={['KE', 'CD'].map((value) => ({ value, label: t(`auth.countries.${value}`) }))}
          error={errors.fields.country}
          required
        />
        <MoneyInput label={t('platform.prices.amount')} currency={currency} value={values.amount} onChange={set('amount')} error={errors.fields.amount} required />
        {isBundle ? (
          <MoneyInput label={t('platform.prices.extraOutlet')} currency={currency} value={values.extra_outlet_amount} onChange={set('extra_outlet_amount')} error={errors.fields.extra_outlet_amount} />
        ) : (
          <MoneyInput label={t('platform.prices.minimum')} currency={currency} value={values.minimum_amount} onChange={set('minimum_amount')} error={errors.fields.minimum_amount} />
        )}
        <TextField
          label={t('platform.prices.effectiveFrom')}
          type="date"
          min={today()}
          value={values.effective_from}
          onChange={(event) => set('effective_from')(event.target.value)}
          error={errors.fields.effective_from}
          required
        />
      </div>
    </Dialog>
  )
}
