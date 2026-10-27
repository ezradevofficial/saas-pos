import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, DataTable, Dialog, Icon, Money, MoneyInput, MultiSelect, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useMoneyDefaults } from '@/lib/defaultCurrency'
import { CompanyPicker, ConfirmDialog } from './RestaurantParts'
import { MODIFIER_MANAGE, restaurantKey, toInt, useCompanyChoice, useItemCategories, useItems } from './restaurantData'

/** REST-07: add or edit a modifier group, its choices and where it applies. */
function GroupDialog({ group, company, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { currency: defaultCurrency, options: currencies } = useMoneyDefaults(company.id)
  const categories = useItemCategories()
  const items = useItems()
  const [form, setForm] = useState({
    name: group?.name ?? '',
    min_select: String(group?.min_select ?? 0),
    max_select: String(group?.max_select ?? 1),
    currency: group?.currency ?? defaultCurrency,
    item_ids: (group?.item_ids ?? []).map(String),
    category_ids: (group?.category_ids ?? []).map(String),
  })
  const [modifiers, setModifiers] = useState(() => (group?.modifiers ?? []).filter((entry) => !entry.archived_at).map((entry) => ({ id: entry.id, name: entry.name, price_minor: String(entry.price_minor ?? '0') })))
  const set = (field) => (event) => setForm((current) => ({ ...current, [field]: event.target.value }))
  const change = (index, patch) => setModifiers((current) => current.map((entry, at) => (at === index ? { ...entry, ...patch } : entry)))
  const min = toInt(form.min_select)
  const max = toInt(form.max_select)
  const rangeError = min === null || max === null || max < min || max < 1 ? t('restaurant.modifiers.rangeInvalid') : undefined
  const invalid = !form.name.trim() || Boolean(rangeError) || modifiers.some((entry) => !entry.name.trim() || entry.price_minor === null)
  const save = useMutation({
    mutationFn: () => {
      const body = {
        company_id: company.id,
        name: form.name.trim(),
        min_select: min,
        max_select: max,
        currency: form.currency,
        item_ids: form.item_ids,
        category_ids: form.category_ids,
        modifiers: modifiers.map((entry, index) => ({ ...(entry.id ? { id: entry.id } : {}), name: entry.name.trim(), price_minor: entry.price_minor === '' ? '0' : entry.price_minor, sort: index })),
      }
      return group?.id ? api.put(`restaurant/modifier-groups/${group.id}`, body) : api.post('restaurant/modifier-groups', body)
    },
    onSuccess: () => {
      toast.success(t('restaurant.modifiers.saved'))
      queryClient.invalidateQueries({ queryKey: restaurantKey })
      onClose()
    },
  })
  const errors = formErrors(save.error, ['name', 'min_select', 'max_select', 'currency', 'item_ids', 'category_ids', 'modifiers'])
  return (
    <Dialog
      open
      size="lg"
      title={group?.id ? t('restaurant.modifiers.edit') : t('restaurant.modifiers.add')}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>{t('common.cancel')}</Button>
          <Button variant="primary" loading={save.isPending} disabled={invalid} onClick={() => save.mutate()}>
            {t('restaurant.modifiers.save')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField label={t('restaurant.fields.name')} value={form.name} error={errors.fields.name} onChange={set('name')} />
          <Select label={t('restaurant.modifiers.currency')} options={currencies.map((code) => ({ value: code, label: code }))} value={form.currency} error={errors.fields.currency} onChange={set('currency')} />
          <TextField label={t('restaurant.modifiers.minSelect')} type="number" inputMode="numeric" min={0} value={form.min_select} error={errors.fields.min_select} onChange={set('min_select')} />
          <TextField label={t('restaurant.modifiers.maxSelect')} type="number" inputMode="numeric" min={1} value={form.max_select} error={errors.fields.max_select ?? rangeError} onChange={set('max_select')} />
        </div>
        <fieldset className="flex flex-col gap-3">
          <legend className="text-label text-ink">{t('restaurant.modifiers.choices')}</legend>
          {errors.fields.modifiers ? <p className="text-caption text-danger">{errors.fields.modifiers}</p> : null}
          {modifiers.length === 0 ? <p className="text-caption text-ink-muted">{t('restaurant.modifiers.noChoices')}</p> : null}
          {modifiers.map((entry, index) => (
            <div key={entry.id ?? `new-${index}`} className="grid items-end gap-3 sm:grid-cols-[1fr_1fr_auto]">
              <TextField label={t('restaurant.modifiers.choiceName')} value={entry.name} onChange={(event) => change(index, { name: event.target.value })} />
              <MoneyInput
                key={`${index}-${form.currency}`}
                label={t('restaurant.modifiers.priceEffect')}
                currency={form.currency}
                value={entry.price_minor}
                onChange={(next) => change(index, { price_minor: next })}
              />
              <Button variant="ghost" aria-label={t('restaurant.modifiers.removeChoice', { name: entry.name })} onClick={() => setModifiers((current) => current.filter((_, at) => at !== index))}>
                <Icon name="remove" size={16} />
              </Button>
            </div>
          ))}
          <div>
            <Button icon="plus" onClick={() => setModifiers((current) => [...current, { name: '', price_minor: '0' }])}>
              {t('restaurant.modifiers.addChoice')}
            </Button>
          </div>
        </fieldset>
        <MultiSelect
          label={t('restaurant.modifiers.items')}
          placeholder={t('restaurant.modifiers.noItems')}
          options={items.map((item) => ({ value: String(item.id), label: item.code ? `${item.name} · ${item.code}` : item.name }))}
          value={form.item_ids}
          error={errors.fields.item_ids}
          onChange={(next) => setForm((current) => ({ ...current, item_ids: next }))}
        />
        <MultiSelect
          label={t('restaurant.modifiers.categories')}
          placeholder={t('restaurant.modifiers.noCategories')}
          options={categories.map((category) => ({ value: String(category.id), label: category.name }))}
          value={form.category_ids}
          error={errors.fields.category_ids}
          onChange={(next) => setForm((current) => ({ ...current, category_ids: next }))}
        />
      </div>
    </Dialog>
  )
}

/**
 * REST-07: modifier groups of a company (Cooking, Extras): how many choices
 * a line takes, each choice with its price effect, and the items and
 * categories the group applies to. Needs restaurant.modifier.manage.
 */
export default function Modifiers() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const companies = useCompanyChoice()
  const { company } = companies
  const { canWithin } = usePermissions()
  const canManage = Boolean(company) && canWithin(MODIFIER_MANAGE, [{ type: 'company', id: company.id }])
  const [dialog, setDialog] = useState(null)
  const query = useQuery({ queryKey: [...restaurantKey, 'modifier-groups', company?.id], queryFn: () => api.get(`restaurant/modifier-groups?company_id=${company.id}`), enabled: Boolean(company) })
  const groups = (query.data?.data ?? []).filter((entry) => !entry.archived_at)
  const archive = useMutation({
    mutationFn: (id) => api.post(`restaurant/modifier-groups/${id}/archive`),
    onSuccess: () => {
      toast.success(t('restaurant.modifiers.archived'))
      queryClient.invalidateQueries({ queryKey: restaurantKey })
      setDialog(null)
    },
  })
  const columns = [
    { key: 'name', label: t('restaurant.fields.name'), render: (row) => row.name },
    { key: 'choose', label: t('restaurant.modifiers.choose'), render: (row) => t('restaurant.modifiers.chooseRange', { min: row.min_select, max: row.max_select }) },
    {
      key: 'modifiers',
      label: t('restaurant.modifiers.choices'),
      wrap: true,
      render: (row) => (
        <ul className="flex flex-col gap-1">
          {row.modifiers
            .filter((entry) => !entry.archived_at)
            .map((entry) => (
              <li key={entry.id} className="flex flex-wrap items-center gap-2">
                <span>{entry.name}</span>
                {String(entry.price_minor) !== '0' ? <Money amount={entry.price_minor} currency={row.currency} /> : null}
              </li>
            ))}
        </ul>
      ),
    },
    {
      key: 'applies',
      label: t('restaurant.modifiers.appliesTo'),
      render: (row) => t('restaurant.modifiers.appliesCount', { items: row.item_ids?.length ?? 0, categories: row.category_ids?.length ?? 0 }),
    },
    {
      key: 'actions',
      label: <span className="sr-only">{t('restaurant.fields.actions')}</span>,
      align: 'end',
      render: (row) =>
        canManage ? (
          <div className="flex justify-end gap-2">
            <Button onClick={() => setDialog({ type: 'edit', group: row })}>{t('restaurant.modifiers.edit')}</Button>
            <Button variant="ghost" onClick={() => setDialog({ type: 'archive', group: row })}>
              {t('restaurant.modifiers.archive')}
            </Button>
          </div>
        ) : null,
    },
  ]

  return (
    <>
      <PageHeader
        title={t('restaurant.modifiers.title')}
        description={t('restaurant.modifiers.description')}
        actions={
          canManage ? (
            <Button variant="primary" onClick={() => setDialog({ type: 'edit' })}>
              {t('restaurant.modifiers.add')}
            </Button>
          ) : null
        }
      />
      <div className="flex flex-col gap-5">
        <CompanyPicker choice={companies} />
        {!company ? <p className="text-ink-muted">{t('restaurant.noCompany')}</p> : null}
        {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
        {company && !query.isError ? (
          <DataTable caption={t('restaurant.modifiers.title')} columns={columns} rows={groups} loading={query.isPending} emptyText={t('restaurant.modifiers.empty')} />
        ) : null}
      </div>
      {dialog?.type === 'edit' ? <GroupDialog key={dialog.group?.id ?? 'new'} group={dialog.group} company={company} onClose={() => setDialog(null)} /> : null}
      <ConfirmDialog
        open={dialog?.type === 'archive'}
        title={t('restaurant.modifiers.archive')}
        message={t('restaurant.modifiers.archiveAsk', { name: dialog?.group?.name ?? '' })}
        confirmLabel={t('restaurant.modifiers.archive')}
        loading={archive.isPending}
        error={archive.error ? errorMessage(archive.error) : null}
        onClose={() => setDialog(null)}
        onConfirm={() => archive.mutate(dialog.group.id)}
      />
    </>
  )
}
