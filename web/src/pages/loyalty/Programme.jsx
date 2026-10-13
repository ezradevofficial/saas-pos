import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, MoneyInput, Switch, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { fieldErrors, useLoyaltyCompany } from './loyaltyData'

const NUMBERS = ['earn_points', 'expiry_months', 'expiry_notice_days', 'credit_warning_percent']

/**
 * LOY-01: the company's loyalty programme: how points are earned and what
 * one is worth, the least redeemed at once, expiry and its notice, the
 * credit warning and the gift card low-balance threshold.
 */
export default function Programme() {
  const { t } = useTranslation()
  const company = useLoyaltyCompany()
  const queryClient = useQueryClient()
  const { canWithin } = usePermissions()
  const key = ['loyalty', 'programme', company?.id]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`companies/${company.id}/loyalty-programme`), enabled: Boolean(company) })
  const [form, setForm] = useState(null)
  const [saved, setSaved] = useState(false)
  const programme = query.data?.data
  // The form starts from the saved programme (or the defaults) once they arrive.
  if (programme && form?.company_id !== programme.company_id) {
    setForm({ ...programme, expiry_months: programme.expiry_months == null ? '' : String(programme.expiry_months), earn_points: String(programme.earn_points), expiry_notice_days: String(programme.expiry_notice_days), credit_warning_percent: String(programme.credit_warning_percent) })
  }

  const save = useMutation({
    mutationFn: () =>
      api.put(`companies/${company.id}/loyalty-programme`, {
        active: form.active,
        earn_points: Number(form.earn_points),
        earn_spend_minor: form.earn_spend_minor,
        point_value_minor: form.point_value_minor,
        min_redeem_points: String(form.min_redeem_points || '0'),
        expiry_months: form.expiry_months === '' ? null : Number(form.expiry_months),
        expiry_notice_days: Number(form.expiry_notice_days),
        credit_warning_percent: Number(form.credit_warning_percent),
        gift_card_low_minor: form.gift_card_low_minor || null,
      }),
    onSuccess: (response) => {
      queryClient.setQueryData(key, response)
      setSaved(true)
    },
  })

  if (!company) return <Alert tone="info" title={t('loyalty.noCompany')} />
  if (query.isError) return <Alert tone="danger" title={errorMessage(query.error)} />
  if (!form) return <p className="text-ink-muted">{t('common.loading')}</p>

  const editable = canWithin('loyalty.programme.edit', [{ type: 'company', id: company.id }])
  const errors = fieldErrors(save.error)
  const set = (name) => (value) => {
    setSaved(false)
    setForm((current) => ({ ...current, [name]: value }))
  }

  return (
    <>
      <PageHeader title={t('loyalty.programme.title')} description={t('loyalty.programme.description', { company: company.name })} />
      {saved ? <Alert tone="success" title={t('loyalty.programme.saved')} /> : null}
      {save.isError && !Object.keys(errors).length ? <Alert tone="danger" title={errorMessage(save.error)} /> : null}
      <form
        className="flex flex-col gap-6"
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        <Card title={t('loyalty.programme.points')} subtitle={t('loyalty.programme.pointsHelp', { currency: form.currency })}>
          <div className="grid gap-4 md:grid-cols-2">
            <Switch label={t('loyalty.programme.active')} checked={Boolean(form.active)} onChange={set('active')} disabled={!editable} />
            <span />
            <TextField label={t('loyalty.programme.earnPoints')} inputMode="numeric" value={form.earn_points} onChange={(event) => set('earn_points')(event.target.value)} error={errors.earn_points} disabled={!editable} />
            <MoneyInput label={t('loyalty.programme.earnSpend')} currency={form.currency} value={form.earn_spend_minor} onChange={set('earn_spend_minor')} error={errors.earn_spend_minor} disabled={!editable} />
            <MoneyInput label={t('loyalty.programme.pointValue')} currency={form.currency} value={form.point_value_minor} onChange={set('point_value_minor')} error={errors.point_value_minor} disabled={!editable} />
            <TextField label={t('loyalty.programme.minRedeem')} inputMode="numeric" value={String(form.min_redeem_points ?? '')} onChange={(event) => set('min_redeem_points')(event.target.value)} error={errors.min_redeem_points} disabled={!editable} />
          </div>
        </Card>
        <Card title={t('loyalty.programme.expiry')}>
          <div className="grid gap-4 md:grid-cols-2">
            <TextField label={t('loyalty.programme.expiryMonths')} help={t('loyalty.programme.expiryMonthsHelp')} inputMode="numeric" value={form.expiry_months} onChange={(event) => set('expiry_months')(event.target.value)} error={errors.expiry_months} disabled={!editable} />
            <TextField label={t('loyalty.programme.noticeDays')} inputMode="numeric" value={form.expiry_notice_days} onChange={(event) => set('expiry_notice_days')(event.target.value)} error={errors.expiry_notice_days} disabled={!editable} />
          </div>
        </Card>
        <Card title={t('loyalty.programme.creditAndCards')}>
          <div className="grid gap-4 md:grid-cols-2">
            <TextField label={t('loyalty.programme.creditWarning')} suffix="%" inputMode="numeric" value={form.credit_warning_percent} onChange={(event) => set('credit_warning_percent')(event.target.value)} error={errors.credit_warning_percent} disabled={!editable} />
            <MoneyInput label={t('loyalty.programme.giftCardLow')} currency={form.currency} value={form.gift_card_low_minor ?? ''} onChange={set('gift_card_low_minor')} error={errors.gift_card_low_minor} disabled={!editable} />
          </div>
        </Card>
        {editable ? (
          <div>
            <Button type="submit" variant="primary" loading={save.isPending} disabled={NUMBERS.some((name) => form[name] !== '' && !/^\d+$/.test(form[name]))}>
              {t('loyalty.programme.save')}
            </Button>
          </div>
        ) : null}
      </form>
    </>
  )
}
