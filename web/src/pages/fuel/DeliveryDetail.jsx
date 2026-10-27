import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router'
import { api } from '@/api/client'
import { Alert, Card } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { Amount, Back, Detail, DetailState, Flags, Litres, When } from './FuelParts'
import { flagCodes, fuelKey } from './fuelData'

/** FUEL-25: what the note said against what was measured: received = after - before + sold while offloading; shortage = note - received. */
export function DeliveryFigures({ delivery }) {
  const { t } = useTranslation()
  const short = flagCodes(delivery.flags).includes('delivery_short')
  return (
    <div className="flex flex-col gap-4">
      {short ? <Alert tone="warning" title={t('fuel.deliveries.shortage', { litres: delivery.shortage_litres })}>{t('fuel.deliveries.shortageHelp')}</Alert> : null}
      <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Detail label={t('fuel.deliveries.noteLitres')}>
          <Litres value={delivery.note_litres} />
        </Detail>
        <Detail label={t('fuel.deliveries.received')}>
          <Litres value={delivery.received_litres} className="font-medium text-ink" />
        </Detail>
        <Detail label={t('fuel.deliveries.shortageLabel')}>
          <Litres value={delivery.shortage_litres} className={short ? 'font-medium text-danger' : undefined} />
        </Detail>
        <Detail label={t('fuel.columns.flags')}>
          <Flags flags={delivery.flags} />
        </Detail>
        <Detail label={t('fuel.deliveries.before')}>
          <Litres value={delivery.before_litres} />
        </Detail>
        <Detail label={t('fuel.deliveries.after')}>
          <Litres value={delivery.after_litres} />
        </Detail>
        <Detail label={t('fuel.deliveries.soldDuring')}>
          <Litres value={delivery.sold_during_litres} />
        </Detail>
        <Detail label={t('fuel.deliveries.unitCost')}>
          <Amount minor={delivery.unit_cost_minor} currency={delivery.currency} />
        </Detail>
        <Detail label={t('fuel.deliveries.cost')}>
          <Amount minor={delivery.cost_minor} currency={delivery.currency} />
        </Detail>
      </dl>
    </div>
  )
}

/** FUEL-25: one delivery: the note, the dips before and after, what was received and the shortage. */
export default function DeliveryDetail() {
  const { t } = useTranslation()
  const { deliveryId } = useParams()
  const query = useQuery({ queryKey: [...fuelKey, 'delivery', deliveryId], queryFn: () => api.get(`fuel/deliveries/${deliveryId}`) })
  const delivery = query.data?.data
  const back = <Back to="/fuel/deliveries">{t('fuel.delivery.back')}</Back>
  if (!delivery) return <DetailState query={query} back={back} notFound={t('fuel.delivery.notFound')} />

  return (
    <>
      {back}
      <PageHeader eyebrow={t('fuel.delivery.eyebrow')} title={delivery.number} description={[delivery.tank ? `${delivery.tank.code} · ${delivery.tank.name}` : '', delivery.location?.name].filter(Boolean).join(' · ')} />
      <div className="flex flex-col gap-5">
        <Card title={t('fuel.delivery.figures')} subtitle={t('fuel.delivery.figuresHelp')}>
          <DeliveryFigures delivery={delivery} />
        </Card>
        <Card title={t('fuel.delivery.note')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Detail label={t('fuel.deliveries.noteNumber')}>{delivery.note_number}</Detail>
            <Detail label={t('fuel.deliveries.supplier')}>{delivery.supplier?.name ?? t('fuel.deliveries.noSupplier')}</Detail>
            <Detail label={t('fuel.deliveries.receivedAt')}>
              <When value={delivery.received_at} />
            </Detail>
            <Detail label={t('fuel.deliveries.recordedBy')}>{delivery.recorded_by?.name}</Detail>
          </dl>
        </Card>
      </div>
    </>
  )
}
