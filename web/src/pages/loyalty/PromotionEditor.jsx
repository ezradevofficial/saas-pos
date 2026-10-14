import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Checkbox, DataTable, Icon, Money, MoneyInput, MultiSelect, Select, StatusBadge, Switch, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { fieldErrors, PROMOTION_TONES, PROMOTION_TYPES, useBranches, useCatalogueOptions, useLoyaltyCompanyChoice, WEEKDAYS } from './loyaltyData'

const EMPTY = {
  name: '', description: '', type: 'percent', priority: '0', percent: '', amount_minor: '', buy_qty: '', get_qty: '',
  items: [], categories: [], get_items: [], components: [], min_spend_minor: '', branch_ids: [], customer_tags: '',
  starts_on: '', ends_on: '', days: [], time_from: '', time_to: '', requires_coupon: false,
}

/** A promotion as the form holds it. */
function toForm(p) {
  return {
    name: p.name, description: p.description ?? '', type: p.type, priority: String(p.priority ?? 0), percent: p.percent ?? '', amount_minor: p.amount_minor ?? '',
    buy_qty: p.buy_qty == null ? '' : String(p.buy_qty), get_qty: p.get_qty == null ? '' : String(p.get_qty),
    items: p.targets?.items ?? [], categories: p.targets?.categories ?? [], get_items: p.get_targets?.items ?? [],
    components: (p.components ?? []).map((c) => ({ item_id: c.item_id, qty: String(c.qty) })), min_spend_minor: p.min_spend_minor ?? '',
    branch_ids: p.branch_ids ?? [], customer_tags: (p.customer_tags ?? []).join(', '),
    starts_on: p.schedule?.starts_on ?? '', ends_on: p.schedule?.ends_on ?? '', days: p.schedule?.days ?? [], time_from: p.schedule?.time_from ?? '', time_to: p.schedule?.time_to ?? '',
    requires_coupon: Boolean(p.requires_coupon),
  }
}

/** The API's body (LOY-08) from the form, in the company's currency. */
function toBody(form, currency) {
  const nullable = (value) => (value === '' ? null : value)
  const needsCurrency = ['amount_off', 'bundle'].includes(form.type) || form.min_spend_minor !== ''
  return {
    name: form.name, description: nullable(form.description), priority: Number(form.priority || 0),
    percent: ['percent', 'buy_x_get_y'].includes(form.type) ? nullable(form.percent) : null,
    amount_minor: ['amount_off', 'bundle'].includes(form.type) ? nullable(form.amount_minor) : null,
    currency: needsCurrency ? currency : null,
    buy_qty: form.type === 'buy_x_get_y' ? Number(form.buy_qty) || null : null,
    get_qty: form.type === 'buy_x_get_y' ? Number(form.get_qty) || null : null,
    targets: { items: form.items, categories: form.categories },
    get_targets: form.type === 'buy_x_get_y' && form.get_items.length ? { items: form.get_items, categories: [] } : null,
    components: form.type === 'bundle' ? form.components.filter((c) => c.item_id).map((c) => ({ item_id: c.item_id, qty: Number(c.qty) || 1 })) : [],
    min_spend_minor: nullable(form.min_spend_minor), branch_ids: form.branch_ids,
    customer_tags: form.customer_tags.split(',').map((tag) => tag.trim()).filter(Boolean),
    schedule: { starts_on: nullable(form.starts_on), ends_on: nullable(form.ends_on), days: form.days, time_from: nullable(form.time_from), time_to: nullable(form.time_to) },
    requires_coupon: form.requires_coupon,
  }
}

/**
 * LOY-08, LOY-09, LOY-13: create or change a promotion (every saved change
 * is a new version), its schedule (happy hour: days and a time window in
 * the branch's time zone), its coupons, and a preview on a sample cart.
 */
export default function PromotionEditor() {
  const { promotionId } = useParams()
  const creating = !promotionId
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const { company, companies, choose } = useLoyaltyCompanyChoice()
  const key = ['loyalty', 'promotion', promotionId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`loyalty/promotions/${promotionId}`), enabled: !creating })
  const promotion = query.data?.data
  const [form, setForm] = useState(creating ? EMPTY : null)
  if (!creating && promotion && !form) setForm(toForm(promotion))
  const companyId = promotion?.company_id ?? company?.id
  const currency = company?.base_currency ?? 'KES'
  const { items, categories } = useCatalogueOptions()
  const branches = useBranches(companyId)
  const [notice, setNotice] = useState(null)

  const save = useMutation({
    mutationFn: (status) => (creating ? api.post('loyalty/promotions', { ...toBody(form, currency), company_id: companyId, type: form.type, status }) : api.patch(`loyalty/promotions/${promotionId}`, toBody(form, currency))),
    onSuccess: (response) => {
      queryClient.invalidateQueries({ queryKey: ['loyalty-promotions'] })
      if (creating) navigate(`/loyalty/promotions/${response.data.id}`, { replace: true, state: { saved: true } })
      else {
        queryClient.setQueryData(key, response)
        setNotice(t('loyalty.promotions.savedVersion', { version: response.data.version }))
      }
    },
  })
  const status = useMutation({
    mutationFn: (action) => api.post(`loyalty/promotions/${promotionId}/${action}`),
    onSuccess: (response) => {
      queryClient.setQueryData(key, response)
      queryClient.invalidateQueries({ queryKey: ['loyalty-promotions'] })
      setNotice(t(`loyalty.promotions.${response.data.status}Now`))
    },
  })

  if (query.isError) return <Alert tone="danger" title={errorMessage(query.error)} />
  if (!form || (!creating && !promotion)) return <p className="text-ink-muted">{t('common.loading')}</p>
  const editable = creating ? can('loyalty.promotion.create') : can('loyalty.promotion.edit') && promotion.status !== 'archived'
  const errors = fieldErrors(save.error)
  const set = (name) => (value) => setForm((current) => ({ ...current, [name]: value }))
  const text = (name) => (event) => set(name)(event.target.value)
  const typeIs = (...types) => types.includes(form.type)

  return (
    <>
      <Link to="/loyalty/promotions" className="flex w-fit items-center gap-2 text-label text-primary hover:text-primary-hover">
        <Icon name="back" />
        {t('loyalty.promotions.back')}
      </Link>
      <PageHeader
        title={creating ? t('loyalty.promotions.new') : promotion.name}
        description={creating ? t('loyalty.promotions.newDescription') : (
          <span className="flex flex-wrap items-center gap-3">
            <StatusBadge tone={PROMOTION_TONES[promotion.status]}>{t(`loyalty.promotions.statuses.${promotion.status}`)}</StatusBadge>
            <span>{t('loyalty.promotions.version', { version: promotion.version })}</span>
          </span>
        )}
        actions={!creating && editable ? (
          <span className="flex gap-2">
            {promotion.status === 'draft' ? <Button variant="primary" loading={status.isPending} onClick={() => status.mutate('activate')}>{t('loyalty.promotions.activate')}</Button> : null}
            {can('loyalty.promotion.archive') ? <Button variant="danger" icon="archive" loading={status.isPending} onClick={() => status.mutate('archive')}>{t('loyalty.promotions.archive')}</Button> : null}
          </span>
        ) : null}
      />
      {notice ? <Alert tone="success" title={notice} /> : null}
      {(save.isError && !Object.keys(errors).length) || status.isError ? <Alert tone="danger" title={errorMessage(save.error ?? status.error)} /> : null}
      <Card title={t('loyalty.promotions.what')}>
        <div className="grid gap-4 md:grid-cols-2">
          {creating && companies.length > 1 ? (
            <Select
              label={t('loyalty.promotions.columns.company')}
              options={companies.map((entry) => ({ value: entry.id, label: entry.name }))}
              value={companyId ?? ''}
              onChange={(event) => choose(event.target.value)}
              error={errors.company_id}
            />
          ) : null}
          <TextField label={t('loyalty.promotions.name')} value={form.name} onChange={text('name')} error={errors.name} disabled={!editable} required />
          <Select label={t('loyalty.promotions.type')} value={form.type} onChange={(event) => set('type')(event.target.value)} disabled={!creating} options={PROMOTION_TYPES.map((type) => ({ value: type, label: t(`loyalty.promotions.types.${type}`) }))} />
          {typeIs('percent', 'buy_x_get_y') ? <TextField label={typeIs('percent') ? t('loyalty.promotions.percent') : t('loyalty.promotions.getPercent')} suffix="%" inputMode="decimal" value={form.percent} onChange={text('percent')} error={errors.percent} disabled={!editable} /> : null}
          {typeIs('amount_off', 'bundle') ? <MoneyInput label={typeIs('bundle') ? t('loyalty.promotions.bundlePrice') : t('loyalty.promotions.amountOff')} currency={currency} value={form.amount_minor} onChange={set('amount_minor')} error={errors.amount_minor} disabled={!editable} /> : null}
          {typeIs('buy_x_get_y') ? (
            <>
              <TextField label={t('loyalty.promotions.buyQty')} inputMode="numeric" value={form.buy_qty} onChange={text('buy_qty')} error={errors.buy_qty} disabled={!editable} />
              <TextField label={t('loyalty.promotions.getQty')} inputMode="numeric" value={form.get_qty} onChange={text('get_qty')} error={errors.get_qty} disabled={!editable} />
            </>
          ) : null}
          <TextField label={t('loyalty.promotions.priority')} help={t('loyalty.promotions.priorityHelp')} inputMode="numeric" value={form.priority} onChange={text('priority')} error={errors.priority} disabled={!editable} />
          <MoneyInput label={t('loyalty.promotions.minSpend')} currency={currency} value={form.min_spend_minor} onChange={set('min_spend_minor')} error={errors.min_spend_minor} disabled={!editable} />
        </div>
      </Card>
      <Card title={t('loyalty.promotions.targets')} subtitle={t('loyalty.promotions.targetsHelp')}>
        <div className="grid gap-4 md:grid-cols-2">
          {typeIs('bundle') ? (
            <div className="flex flex-col gap-3 md:col-span-2">
              {form.components.map((component, index) => (
                <div key={index} className="flex flex-wrap items-end gap-3">
                  <Select className="min-w-48 flex-1" label={t('loyalty.promotions.componentItem')} placeholder={t('loyalty.promotions.chooseItem')} value={component.item_id} options={items} disabled={!editable}
                    onChange={(event) => set('components')(form.components.map((c, i) => (i === index ? { ...c, item_id: event.target.value } : c)))} />
                  <TextField className="w-28" label={t('loyalty.promotions.componentQty')} inputMode="numeric" value={component.qty} disabled={!editable}
                    onChange={(event) => set('components')(form.components.map((c, i) => (i === index ? { ...c, qty: event.target.value } : c)))} />
                  {editable ? <Button variant="ghost" icon="remove" aria-label={t('loyalty.promotions.removeComponent')} onClick={() => set('components')(form.components.filter((_, i) => i !== index))} /> : null}
                </div>
              ))}
              {errors.components ? <p className="text-caption text-danger">{errors.components}</p> : null}
              {editable ? <div><Button icon="plus" onClick={() => set('components')([...form.components, { item_id: '', qty: '1' }])}>{t('loyalty.promotions.addComponent')}</Button></div> : null}
            </div>
          ) : (
            <>
              <MultiSelect label={t('loyalty.promotions.items')} placeholder={t('loyalty.promotions.allItems')} options={items} value={form.items} onChange={set('items')} error={errors['targets.items']} disabled={!editable} />
              <MultiSelect label={t('loyalty.promotions.categories')} placeholder={t('loyalty.promotions.allCategories')} options={categories} value={form.categories} onChange={set('categories')} error={errors['targets.categories']} disabled={!editable} />
              {typeIs('buy_x_get_y') ? <MultiSelect label={t('loyalty.promotions.getItems')} help={t('loyalty.promotions.getItemsHelp')} options={items} value={form.get_items} onChange={set('get_items')} disabled={!editable} /> : null}
            </>
          )}
          <MultiSelect label={t('loyalty.promotions.branches')} placeholder={t('loyalty.promotions.allBranches')} options={branches} value={form.branch_ids} onChange={set('branch_ids')} error={errors.branch_ids} disabled={!editable} />
          <TextField label={t('loyalty.promotions.groups')} help={t('loyalty.promotions.groupsHelp')} value={form.customer_tags} onChange={text('customer_tags')} disabled={!editable} />
        </div>
      </Card>
      <Card title={t('loyalty.promotions.schedule')} subtitle={t('loyalty.promotions.scheduleHelp')}>
        <div className="grid gap-4 md:grid-cols-2">
          <TextField type="date" label={t('loyalty.promotions.startsOn')} value={form.starts_on} onChange={text('starts_on')} error={errors['schedule.starts_on']} disabled={!editable} />
          <TextField type="date" label={t('loyalty.promotions.endsOn')} value={form.ends_on} onChange={text('ends_on')} error={errors['schedule.ends_on']} disabled={!editable} />
          <TextField type="time" label={t('loyalty.promotions.timeFrom')} value={form.time_from} onChange={text('time_from')} error={errors['schedule.time_from']} disabled={!editable} />
          <TextField type="time" label={t('loyalty.promotions.timeTo')} value={form.time_to} onChange={text('time_to')} error={errors['schedule.time_to']} disabled={!editable} />
          <fieldset className="flex flex-wrap gap-4 md:col-span-2">
            <legend className="mb-2 text-label text-ink">{t('loyalty.promotions.days')}</legend>
            {WEEKDAYS.map((day) => (
              <Checkbox key={day} label={t(`loyalty.weekdays.${day}`)} checked={form.days.includes(day)} disabled={!editable}
                onChange={(event) => set('days')(event.target.checked ? [...form.days, day].sort() : form.days.filter((d) => d !== day))} />
            ))}
          </fieldset>
          <Switch label={t('loyalty.promotions.requiresCoupon')} checked={form.requires_coupon} onChange={set('requires_coupon')} disabled={!editable} />
        </div>
      </Card>
      {editable ? (
        <div className="flex flex-wrap gap-3">
          {creating ? (
            <>
              <Button variant="pay" loading={save.isPending} onClick={() => save.mutate('active')}>{t('loyalty.promotions.createActive')}</Button>
              <Button loading={save.isPending} onClick={() => save.mutate('draft')}>{t('loyalty.promotions.createDraft')}</Button>
            </>
          ) : (
            <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>{t('loyalty.promotions.save')}</Button>
          )}
        </div>
      ) : null}
      {!creating && promotion.requires_coupon ? <Coupons promotion={promotion} editable={editable} /> : null}
      <Preview companyId={companyId} currency={currency} draft={{ ...toBody(form, currency), type: form.type }} items={items} branches={branches} />
    </>
  )
}

/** LOY-09: the promotion's codes, their limits and uses. */
function Coupons({ promotion, editable }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const key = ['loyalty', 'coupons', promotion.id]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`loyalty/promotions/${promotion.id}/coupons`) })
  const [codes, setCodes] = useState('')
  const [generate, setGenerate] = useState('')
  const [maxUses, setMaxUses] = useState('')
  const add = useMutation({
    mutationFn: () => api.post(`loyalty/promotions/${promotion.id}/coupons`, {
      ...(codes.trim() ? { codes: codes.split(/[\s,]+/).filter(Boolean) } : {}),
      ...(generate ? { generate: Number(generate) } : {}),
      max_uses: maxUses ? Number(maxUses) : null,
    }),
    onSuccess: () => {
      setCodes('')
      setGenerate('')
      queryClient.invalidateQueries({ queryKey: key })
    },
  })
  const archive = useMutation({ mutationFn: (id) => api.post(`loyalty/coupons/${id}/archive`), onSuccess: () => queryClient.invalidateQueries({ queryKey: key }) })
  const errors = fieldErrors(add.error)
  const rows = query.data?.data ?? []

  return (
    <Card title={t('loyalty.coupons.title')} subtitle={t('loyalty.coupons.help')}>
      <DataTable
        caption={t('loyalty.coupons.title')}
        rows={rows}
        emptyText={t('loyalty.coupons.empty')}
        columns={[
          { key: 'code', label: t('loyalty.coupons.code'), render: (row) => <span className="font-mono">{row.code}</span> },
          { key: 'uses', label: t('loyalty.coupons.uses'), render: (row) => <span className="tabular-nums">{row.max_uses ? t('loyalty.coupons.usesOf', { uses: row.uses, max: row.max_uses }) : row.uses}</span> },
          { key: 'state', label: t('loyalty.coupons.state'), render: (row) => <StatusBadge tone={row.archived_at ? 'neutral' : 'success'}>{row.archived_at ? t('loyalty.coupons.archived') : t('loyalty.coupons.usable')}</StatusBadge> },
          { key: 'actions', label: '', render: (row) => (editable && !row.archived_at ? <Button variant="ghost" onClick={() => archive.mutate(row.id)}>{t('loyalty.coupons.archive')}</Button> : null) },
        ]}
      />
      {editable ? (
        <div className="mt-4 grid gap-4 md:grid-cols-4">
          <TextField className="md:col-span-2" label={t('loyalty.coupons.typeCodes')} help={t('loyalty.coupons.typeCodesHelp')} value={codes} onChange={(event) => setCodes(event.target.value)} error={errors.codes ?? errors['codes.0']} />
          <TextField label={t('loyalty.coupons.generate')} inputMode="numeric" value={generate} onChange={(event) => setGenerate(event.target.value)} error={errors.generate} />
          <TextField label={t('loyalty.coupons.maxUses')} help={t('loyalty.coupons.maxUsesHelp')} inputMode="numeric" value={maxUses} onChange={(event) => setMaxUses(event.target.value)} error={errors.max_uses} />
          <div>
            <Button variant="primary" loading={add.isPending} onClick={() => add.mutate()}>{t('loyalty.coupons.add')}</Button>
          </div>
        </div>
      ) : null}
    </Card>
  )
}

/** LOY-13: a sample cart through the same engine as the till. */
function Preview({ companyId, currency, draft, items, branches }) {
  const { t } = useTranslation()
  const [lines, setLines] = useState([{ item_id: '', qty: '1', unit_price_minor: '' }])
  const [branch, setBranch] = useState('')
  const [at, setAt] = useState('')
  const [coupon, setCoupon] = useState('')
  const preview = useMutation({
    mutationFn: () => api.post('loyalty/promotions/preview', {
      company_id: companyId, currency, promotion: draft, branch_id: branch || null, coupons: coupon ? [coupon] : [],
      ...(at ? { at: new Date(at).toISOString() } : {}),
      lines: lines.filter((line) => line.item_id && line.unit_price_minor).map((line, index) => ({ id: `line-${index + 1}`, ...line })),
    }),
  })
  const result = preview.data?.data
  const nameOf = (id) => items.find((item) => item.value === id)?.label ?? ''

  return (
    <Card title={t('loyalty.preview.title')} subtitle={t('loyalty.preview.help')}>
      <div className="flex flex-col gap-3">
        {lines.map((line, index) => (
          <div key={index} className="flex flex-wrap items-end gap-3">
            <Select className="min-w-48 flex-1" label={t('loyalty.preview.item')} placeholder={t('loyalty.promotions.chooseItem')} value={line.item_id} options={items} onChange={(event) => setLines(lines.map((l, i) => (i === index ? { ...l, item_id: event.target.value } : l)))} />
            <TextField className="w-24" label={t('loyalty.preview.qty')} inputMode="decimal" value={line.qty} onChange={(event) => setLines(lines.map((l, i) => (i === index ? { ...l, qty: event.target.value } : l)))} />
            <MoneyInput className="w-48" label={t('loyalty.preview.price')} currency={currency} value={line.unit_price_minor} onChange={(value) => setLines(lines.map((l, i) => (i === index ? { ...l, unit_price_minor: value ?? '' } : l)))} />
          </div>
        ))}
        <div className="flex flex-wrap items-end gap-3">
          <Button icon="plus" onClick={() => setLines([...lines, { item_id: '', qty: '1', unit_price_minor: '' }])}>{t('loyalty.preview.addLine')}</Button>
        </div>
        <div className="grid gap-4 md:grid-cols-3">
          <Select label={t('loyalty.preview.branch')} placeholder={t('loyalty.preview.anyBranch')} value={branch} options={branches} onChange={(event) => setBranch(event.target.value)} />
          <TextField type="datetime-local" label={t('loyalty.preview.at')} help={t('loyalty.preview.atHelp')} value={at} onChange={(event) => setAt(event.target.value)} />
          <TextField label={t('loyalty.preview.coupon')} value={coupon} onChange={(event) => setCoupon(event.target.value)} />
        </div>
        <div>
          <Button variant="primary" loading={preview.isPending} onClick={() => preview.mutate()}>{t('loyalty.preview.run')}</Button>
        </div>
        {preview.isError ? <Alert tone="danger" title={errorMessage(preview.error)} /> : null}
        {result ? (
          <div className="flex flex-col gap-2" aria-live="polite">
            {result.lines.length ? (
              result.lines.map((line) => (
                <p key={line.line_id} className="flex justify-between gap-3 text-body">
                  <span>{nameOf(lines[Number(line.line_id.replace('line-', '')) - 1]?.item_id)}</span>
                  <Money amount={line.discount_minor} currency={currency} />
                </p>
              ))
            ) : (
              <Alert tone="info" title={t('loyalty.preview.nothing')} />
            )}
            <p className="flex justify-between gap-3 border-t border-border pt-2 text-body font-medium">
              <span>{t('loyalty.preview.total')}</span>
              <Money amount={result.total_discount_minor} currency={currency} />
            </p>
          </div>
        ) : null}
      </div>
    </Card>
  )
}
