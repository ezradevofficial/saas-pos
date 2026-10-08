import { useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, Money, StatusBadge } from '@/components/ds'
import { formatCalendarDate } from '@/lib/dates'
import { formatDecimal } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { PriceDialog } from './PriceDialog'

/** "BOX", "BOX · from 10": a price's unit and, above 1, the quantity it applies from. */
function UnitLabel({ price }) {
  const { t } = useTranslation()
  const locale = useLocale()
  return (
    <span className="text-ink">
      {price.uom_code}
      {price.min_quantity !== '1' ? <span className="text-ink-muted"> · {t('prices.fromQuantity', { quantity: formatDecimal(price.min_quantity, locale) })}</span> : null}
    </span>
  )
}

function PriceRows({ prices, scheduled, onEdit, label }) {
  const { t } = useTranslation()
  const locale = useLocale()
  return (
    <ul aria-label={label} className="flex flex-col divide-y divide-border">
      {prices.map((price) => (
        <li key={price.id} className="flex flex-wrap items-center justify-between gap-3 py-2">
          <div className="flex min-w-0 flex-col">
            <span className="flex flex-wrap items-center gap-x-3">
              <UnitLabel price={price} />
              {scheduled ? <StatusBadge tone="info">{t('prices.states.scheduled')}</StatusBadge> : null}
            </span>
            <span className="text-caption text-ink-muted">
              {scheduled
                ? t('prices.item.startsOn', { date: formatCalendarDate(price.effective_from, locale) })
                : t('prices.item.since', { date: formatCalendarDate(price.effective_from, locale) })}
            </span>
          </div>
          <div className="flex items-center gap-2">
            <Money amount={price.amount_minor} currency={price.currency} />
            {onEdit ? (
              <Button variant="ghost" onClick={() => onEdit(price)} aria-label={t('prices.item.editFor', { unit: price.uom_code })}>
                {t('prices.item.edit')}
              </Button>
            ) : null}
          </div>
        </li>
      ))}
    </ul>
  )
}

/**
 * MD-03 follow-up: the item's price in each price list the user reads
 * (`prices` from `GET items/{id}`; absent when the user reads no prices
 * or field rules hide them): today's prices per unit and quantity, the
 * scheduled ones, and Set price / Edit where the user may change prices.
 * Amounts stay strings of minor units end to end.
 */
export function ItemPrices({ item, detailKey }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState(null) // { list, price? }
  const lists = item.prices ?? []

  const saved = async () => {
    setEditing(null)
    await queryClient.invalidateQueries({ queryKey: detailKey })
    queryClient.invalidateQueries({ queryKey: ['history', 'item', item.id] })
    queryClient.invalidateQueries({ queryKey: ['item-prices'] })
  }

  return (
    <Card title={t('prices.item.title')} subtitle={t('prices.item.subtitle')}>
      {lists.length === 0 ? (
        <p className="text-ink-muted">{t('prices.item.noLists')}</p>
      ) : (
        <div className="flex flex-col gap-5">
          {lists.map((list) => (
            <section key={list.price_list_id} aria-label={list.name} className="flex flex-col gap-2">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                  <h4 className="font-medium text-ink">{list.name}</h4>
                  <span className="text-caption text-ink-muted">
                    {list.currency} · {list.tax_inclusive ? t('taxes.priceLists.includeTax') : t('taxes.priceLists.excludeTax')}
                  </span>
                  {list.is_default ? <StatusBadge tone="info">{t('taxes.priceLists.defaultFor', { currency: list.currency })}</StatusBadge> : null}
                </div>
                {list.can_edit && !item.archived_at ? (
                  <Button icon="plus" onClick={() => setEditing({ list })} aria-label={t('prices.item.setFor', { list: list.name })}>
                    {t('prices.item.set')}
                  </Button>
                ) : null}
              </div>
              {list.prices.length === 0 ? (
                <p className="text-caption text-ink-muted">{t('prices.item.none')}</p>
              ) : (
                <PriceRows
                  prices={list.prices}
                  label={t('prices.item.currentIn', { list: list.name })}
                  onEdit={list.can_edit && !item.archived_at ? (price) => setEditing({ list, price }) : null}
                />
              )}
              {list.scheduled.length ? (
                <PriceRows prices={list.scheduled} scheduled label={t('prices.item.scheduledIn', { list: list.name })} onEdit={list.can_edit && !item.archived_at ? (price) => setEditing({ list, price }) : null} />
              ) : null}
            </section>
          ))}
        </div>
      )}
      {editing ? (
        <PriceDialog
          priceList={{ id: editing.list.price_list_id, name: editing.list.name, currency: editing.list.currency, company_id: editing.list.company_id }}
          item={item}
          price={editing.price ?? null}
          today={editing.list.today}
          onClose={() => setEditing(null)}
          onSaved={saved}
        />
      ) : null}
    </Card>
  )
}
