import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Dialog, MoneyInput, MultiSelect, Select, TextField } from '@/components/ds'
import { platformApi } from '../platformClient'
import { useCatalogueName } from '../useCatalogueName'

const EMPTY = { code: '', kind: 'percent', percent: '', amount: '', currency: 'KES', applies_to_modules: [], applies_to_bundles: [], expires_on: '', max_redemptions: '' }

/** SUB-01: a new coupon (mounted fresh each time it opens). Its value is fixed once saved; the expiry and limit can change later. */
export function CouponDialog({ open, onClose }) {
  const { t } = useTranslation()
  const name = useCatalogueName()
  const queryClient = useQueryClient()
  const [values, setValues] = useState(EMPTY)
  const modules = useQuery({ queryKey: ['platform', 'catalogue', 'modules'], queryFn: () => platformApi.get('platform/catalogue/modules'), enabled: open })
  const bundles = useQuery({ queryKey: ['platform', 'catalogue', 'bundles'], queryFn: () => platformApi.get('platform/catalogue/bundles'), enabled: open })

  const save = useMutation({
    mutationFn: () =>
      platformApi.post('platform/catalogue/coupons', {
        code: values.code.trim(),
        kind: values.kind,
        ...(values.kind === 'percent'
          ? { percent: values.percent === '' ? null : Number(values.percent) }
          : { amount: values.amount ? Number(values.amount) : null, currency: values.currency }),
        applies_to_modules: values.applies_to_modules,
        applies_to_bundles: values.applies_to_bundles,
        expires_at: values.expires_on ? `${values.expires_on}T23:59:59Z` : null,
        max_redemptions: values.max_redemptions === '' ? null : Number(values.max_redemptions),
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['platform', 'catalogue', 'coupons'] })
      onClose()
    },
  })

  const fields = ['code', 'kind', 'percent', 'amount', 'currency', 'applies_to_modules', 'applies_to_bundles', 'expires_at', 'max_redemptions']
  const errors = formErrors(save.error, fields)
  const set = (field) => (value) => setValues((current) => ({ ...current, [field]: value }))
  const fromEvent = (field) => (event) => set(field)(event.target.value)

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={t('platform.coupons.new')}
      footer={
        <>
          <Button onClick={onClose}>{t('common.cancel')}</Button>
          <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>
            {t('platform.coupons.save')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <TextField label={t('platform.coupons.code')} help={t('platform.coupons.codeHelp')} value={values.code} onChange={fromEvent('code')} error={errors.fields.code} required />
        <Select
          label={t('platform.coupons.kind')}
          value={values.kind}
          onChange={fromEvent('kind')}
          options={['percent', 'fixed'].map((value) => ({ value, label: t(`platform.coupons.kinds.${value}`) }))}
          error={errors.fields.kind}
          required
        />
        {values.kind === 'percent' ? (
          <TextField label={t('platform.coupons.percent')} type="number" inputMode="numeric" min={1} max={100} suffix="%" value={values.percent} onChange={fromEvent('percent')} error={errors.fields.percent} required />
        ) : (
          <>
            <Select
              label={t('platform.coupons.currency')}
              value={values.currency}
              onChange={fromEvent('currency')}
              options={['KES', 'USD'].map((value) => ({ value, label: value }))}
              error={errors.fields.currency}
              required
            />
            <MoneyInput label={t('platform.coupons.amount')} currency={values.currency} value={values.amount} onChange={set('amount')} error={errors.fields.amount} required />
          </>
        )}
        <MultiSelect
          label={t('platform.coupons.modules')}
          help={t('platform.coupons.appliesHelp')}
          value={values.applies_to_modules}
          onChange={set('applies_to_modules')}
          options={(modules.data?.data ?? []).filter((row) => !row.archived_at).map((row) => ({ value: row.key, label: name(row.name) }))}
          error={errors.fields.applies_to_modules}
        />
        <MultiSelect
          label={t('platform.coupons.bundles')}
          value={values.applies_to_bundles}
          onChange={set('applies_to_bundles')}
          options={(bundles.data?.data ?? []).filter((row) => !row.archived_at).map((row) => ({ value: row.key, label: name(row.name) }))}
          error={errors.fields.applies_to_bundles}
        />
        <TextField label={t('platform.coupons.expires')} type="date" value={values.expires_on} onChange={fromEvent('expires_on')} error={errors.fields.expires_at} />
        <TextField label={t('platform.coupons.maxRedemptions')} type="number" inputMode="numeric" min={1} value={values.max_redemptions} onChange={fromEvent('max_redemptions')} error={errors.fields.max_redemptions} />
      </div>
    </Dialog>
  )
}
