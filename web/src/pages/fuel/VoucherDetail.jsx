import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { Amount, Back, Detail, DetailState, Flags, ReasonDialog, RecordStatus, When } from './FuelParts'
import { fuelKey, VOUCHER_MANAGE } from './fuelData'

/** FUEL-21: one voucher: value, balance, status and its redemptions; voiding keeps the redemptions and needs a reason. */
export default function VoucherDetail() {
  const { t } = useTranslation()
  const { voucherId } = useParams()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const [voiding, setVoiding] = useState(false)
  const key = [...fuelKey, 'voucher', voucherId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`fuel/vouchers/${voucherId}`) })
  const voucher = query.data?.data
  const back = <Back to="/fuel/vouchers">{t('fuel.voucher.back')}</Back>
  if (!voucher) return <DetailState query={query} back={back} notFound={t('fuel.voucher.notFound')} />

  const columns = [
    { key: 'at', label: t('fuel.voucher.redeemedAt'), render: (row) => <When value={row.redeemed_at} /> },
    { key: 'station', label: t('fuel.fields.station'), render: (row) => row.location?.name ?? '' },
    { key: 'amount', label: t('fuel.voucher.amount'), align: 'end', render: (row) => <Amount minor={row.amount_minor} currency={row.currency} /> },
    { key: 'reversed', label: t('fuel.voucher.reversedAt'), render: (row) => <When value={row.reversed_at} /> },
    { key: 'flags', label: t('fuel.columns.flags'), render: (row) => <Flags flags={row.flags} /> },
  ]
  return (
    <>
      {back}
      <PageHeader
        eyebrow={t('fuel.voucher.eyebrow')}
        title={voucher.code}
        description={<RecordStatus status={voucher.status} />}
        actions={
          voucher.status === 'active' && can(VOUCHER_MANAGE) ? (
            <Button variant="danger" onClick={() => setVoiding(true)}>
              {t('fuel.voucher.void')}
            </Button>
          ) : null
        }
      />
      {voucher.status === 'void' ? (
        <Alert tone="info" title={t('fuel.voucher.voided')}>
          <span>
            {voucher.void_reason} {voucher.voided_at ? <When value={voucher.voided_at} /> : null}
          </span>
        </Alert>
      ) : null}
      <div className="flex flex-col gap-5">
        <Card title={t('fuel.voucher.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Detail label={t('fuel.vouchers.value')}>
              <Amount minor={voucher.value_minor} currency={voucher.currency} />
            </Detail>
            <Detail label={t('fuel.vouchers.balance')}>
              <Amount minor={voucher.balance_minor} currency={voucher.currency} className="font-medium text-ink" />
            </Detail>
            <Detail label={t('fuel.fields.customer')}>{voucher.customer?.name ?? t('fuel.voucher.anyone')}</Detail>
            <Detail label={t('fuel.fields.fuelType')}>{voucher.fuel_type ? t(`fuel.types.${voucher.fuel_type}`, { defaultValue: voucher.fuel_type }) : t('fuel.vehicles.anyFuel')}</Detail>
            <Detail label={t('fuel.vouchers.expiresOn')}>{voucher.expires_on ?? t('fuel.voucher.noExpiry')}</Detail>
            <Detail label={t('fuel.voucher.issuedBy')}>
              <span className="flex flex-col">
                <span>{voucher.issued_by?.name}</span>
                <span className="text-caption text-ink-muted">
                  <When value={voucher.created_at} />
                </span>
              </span>
            </Detail>
          </dl>
        </Card>
        <Card title={t('fuel.voucher.redemptions')}>
          <DataTable caption={t('fuel.voucher.redemptions')} columns={columns} rows={(voucher.redemptions ?? []).map((row, index) => ({ ...row, id: `${row.sale_id}-${index}` }))} emptyText={t('fuel.voucher.noRedemptions')} />
        </Card>
      </div>
      <ReasonDialog
        open={voiding}
        title={t('fuel.voucher.voidTitle', { code: voucher.code })}
        help={t('fuel.voucher.voidHelp')}
        label={t('fuel.voucher.voidReason')}
        action={t('fuel.voucher.void')}
        path={`fuel/vouchers/${voucher.id}/void`}
        onClose={() => setVoiding(false)}
        onDone={async (response) => {
          setVoiding(false)
          if (response?.data) queryClient.setQueryData(key, response)
          toast.success(t('fuel.voucher.voidedToast'))
          await queryClient.invalidateQueries({ queryKey: fuelKey })
        }}
      />
    </>
  )
}
