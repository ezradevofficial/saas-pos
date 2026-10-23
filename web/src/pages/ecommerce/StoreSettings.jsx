import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, Dialog, Money, MoneyInput, Select, StatusBadge, Switch, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useCompanies } from '@/layouts/companySelection'
import { ecomKey, namedOptions, PAYMENT_KINDS, STORE_MANAGE, STORE_STATUSES, TONES, useEcomAction, useStores } from './ecommerceData'

/** The core payment method type each kind takes (MD-04; the API checks again). */
const KIND_TYPES = { mpesa: 'mobile_money', card: 'card', cod: 'cash', points: 'points', gift_card: 'voucher' }
const STORE_FIELDS = ['company_id', 'branch_id', 'location_id', 'handle', 'name', 'status', 'currency', 'price_list_id', 'min_order_minor', 'reservation_minutes', 'safety_buffer', 'contact_phone', 'contact_email', 'whatsapp_number', 'delivery_tax_code_id', 'pickup_location_ids', 'payment_methods']

/** The core records a store's form offers for a company. */
function useOption(key, path, companyId) {
  return useQuery({ queryKey: ['ecommerce-options', key, companyId], queryFn: () => api.get(path), enabled: Boolean(companyId), staleTime: 60_000, retry: false })
}

function useCompanyOptions(companyId) {
  const branches = useOption('branches', `companies/${companyId}/branches?per_page=200`, companyId)
  const locations = useOption('locations', 'locations?per_page=200', companyId)
  const lists = useOption('price-lists', `companies/${companyId}/price-lists`, companyId)
  const methods = useOption('payment-methods', `companies/${companyId}/payment-methods`, companyId)
  const taxCodes = useOption('tax-codes', `companies/${companyId}/tax-codes`, companyId)
  const branchList = (branches.data?.data ?? []).filter((branch) => !branch.archived_at)
  return {
    branches: branchList,
    locations: (locations.data?.data ?? []).filter((location) => !location.archived_at && branchList.some((branch) => branch.id === location.branch_id)),
    priceLists: (lists.data?.data ?? []).filter((list) => !list.archived_at),
    methods: (methods.data?.data ?? []).filter((method) => method.active !== false),
    taxCodes: (taxCodes.data?.data ?? []).filter((code) => !code.archived_at),
  }
}

/** ECOM-01: a store's settings (new or existing). */
function StoreForm({ store, onSaved }) {
  const { t } = useTranslation()
  const { companies } = useCompanies()
  const [values, setValues] = useState(() => ({
    company_id: store?.company?.id ?? companies[0]?.id ?? '',
    branch_id: store?.branch?.id ?? '',
    location_id: store?.location?.id ?? '',
    handle: store?.handle ?? '',
    name: store?.name ?? '',
    status: store?.status ?? 'draft',
    currency: store?.currency ?? 'KES',
    price_list_id: store?.price_list_id ?? '',
    delivery_enabled: store?.delivery_enabled ?? true,
    pickup_enabled: store?.pickup_enabled ?? false,
    pickup_location_ids: store?.pickup_location_ids ?? [],
    min_order_minor: store?.min_order_minor ?? '0',
    reservation_minutes: store?.reservation_minutes ?? 30,
    safety_buffer: store?.safety_buffer?.replace(/\.?0+$/, '') || '0',
    contact_phone: store?.contact_phone ?? '',
    contact_email: store?.contact_email ?? '',
    whatsapp_number: store?.whatsapp_number ?? '',
    delivery_tax_code_id: store?.delivery_tax_code_id ?? '',
    payment_methods: store?.payment_methods ?? [],
  }))
  const options = useCompanyOptions(values.company_id)
  const set = (name, value) => setValues((current) => ({ ...current, [name]: value }))
  const methodOf = (kind) => values.payment_methods.find((entry) => entry.kind === kind)?.payment_method_id ?? ''
  const setMethod = (kind, id) => set('payment_methods', [...values.payment_methods.filter((entry) => entry.kind !== kind), ...(id ? [{ kind, payment_method_id: id }] : [])])
  const save = useMutation({
    mutationFn: () => {
      const body = { ...values, delivery_tax_code_id: values.delivery_tax_code_id || null, contact_email: values.contact_email || null, reservation_minutes: Number(values.reservation_minutes) }
      if (store) delete body.company_id
      return store ? api.patch(`ecommerce/stores/${store.id}`, body) : api.post('ecommerce/stores', body)
    },
    onSuccess: (response) => onSaved(response.data),
  })
  const errors = formErrors(save.error, STORE_FIELDS)
  const locationsOfBranch = options.locations.filter((location) => location.branch_id === values.branch_id)
  return (
    <div className="flex flex-col gap-4">
      {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
      <div className="grid gap-4 sm:grid-cols-2">
        {!store ? <Select label={t('ecommerce.fields.company')} options={namedOptions(companies.filter((c) => !c.archived_at))} value={values.company_id} required error={errors.fields.company_id} onChange={(event) => set('company_id', event.target.value)} /> : null}
        <TextField label={t('ecommerce.fields.storeName')} value={values.name} required error={errors.fields.name} onChange={(event) => set('name', event.target.value)} />
        <TextField label={t('ecommerce.fields.handle')} help={t('ecommerce.stores.handleHelp')} value={values.handle} required error={errors.fields.handle} onChange={(event) => set('handle', event.target.value.toLowerCase())} />
        <Select label={t('ecommerce.fields.status')} options={STORE_STATUSES.map((value) => ({ value, label: t(`ecommerce.storeStatuses.${value}`) }))} value={values.status} error={errors.fields.status} onChange={(event) => set('status', event.target.value)} />
        <Select label={t('ecommerce.fields.branch')} placeholder={t('ecommerce.stores.chooseBranch')} options={namedOptions(options.branches)} value={values.branch_id} required error={errors.fields.branch_id} onChange={(event) => set('branch_id', event.target.value)} />
        <Select label={t('ecommerce.fields.location')} help={t('ecommerce.stores.locationHelp')} placeholder={t('ecommerce.stores.chooseLocation')} options={namedOptions(locationsOfBranch)} value={values.location_id} required error={errors.fields.location_id} onChange={(event) => set('location_id', event.target.value)} />
        <TextField label={t('ecommerce.fields.currency')} value={values.currency} required error={errors.fields.currency} onChange={(event) => set('currency', event.target.value.toUpperCase())} />
        <Select label={t('ecommerce.fields.priceList')} placeholder={t('ecommerce.stores.choosePriceList')} options={namedOptions(options.priceLists.filter((list) => list.currency === values.currency))} value={values.price_list_id} required error={errors.fields.price_list_id} onChange={(event) => set('price_list_id', event.target.value)} />
        <MoneyInput label={t('ecommerce.fields.minOrder')} currency={values.currency} value={values.min_order_minor} error={errors.fields.min_order_minor} onChange={(value) => set('min_order_minor', value ?? '0')} />
        <TextField type="number" label={t('ecommerce.fields.reservationMinutes')} help={t('ecommerce.stores.reservationHelp')} value={String(values.reservation_minutes)} error={errors.fields.reservation_minutes} onChange={(event) => set('reservation_minutes', event.target.value)} />
        <TextField label={t('ecommerce.fields.safetyBuffer')} help={t('ecommerce.stores.bufferHelp')} inputMode="decimal" value={values.safety_buffer} error={errors.fields.safety_buffer} onChange={(event) => set('safety_buffer', event.target.value)} />
        <Select label={t('ecommerce.fields.deliveryTax')} placeholder={t('ecommerce.stores.noDeliveryTax')} options={[{ value: '', label: t('ecommerce.stores.noDeliveryTax') }, ...options.taxCodes.map((code) => ({ value: code.id, label: `${code.code} · ${code.name}` }))]} value={values.delivery_tax_code_id} error={errors.fields.delivery_tax_code_id} onChange={(event) => set('delivery_tax_code_id', event.target.value)} />
        <TextField label={t('ecommerce.fields.contactPhone')} value={values.contact_phone} error={errors.fields.contact_phone} onChange={(event) => set('contact_phone', event.target.value)} />
        <TextField label={t('ecommerce.fields.contactEmail')} value={values.contact_email} error={errors.fields.contact_email} onChange={(event) => set('contact_email', event.target.value)} />
        <TextField label={t('ecommerce.fields.whatsappNumber')} value={values.whatsapp_number} error={errors.fields.whatsapp_number} onChange={(event) => set('whatsapp_number', event.target.value)} />
      </div>
      <div className="flex flex-wrap gap-6">
        <Switch label={t('ecommerce.stores.deliveryEnabled')} checked={values.delivery_enabled} onChange={(value) => set('delivery_enabled', value)} />
        <Switch label={t('ecommerce.stores.pickupEnabled')} checked={values.pickup_enabled} onChange={(value) => set('pickup_enabled', value)} />
      </div>
      {values.pickup_enabled ? (
        <Select
          label={t('ecommerce.fields.pickupLocation')}
          placeholder={t('ecommerce.stores.chooseLocation')}
          options={namedOptions(options.locations)}
          value={values.pickup_location_ids[0] ?? ''}
          error={errors.fields.pickup_location_ids}
          onChange={(event) => set('pickup_location_ids', event.target.value ? [event.target.value] : [])}
          className="max-w-field"
        />
      ) : null}
      <Card title={t('ecommerce.stores.paymentMethods')} subtitle={t('ecommerce.stores.paymentMethodsHelp')}>
        <div className="grid gap-4 sm:grid-cols-2">
          {PAYMENT_KINDS.map((kind) => (
            <Select
              key={kind}
              label={t(`ecommerce.paymentKinds.${kind}`)}
              placeholder={t('ecommerce.stores.notOffered')}
              options={[{ value: '', label: t('ecommerce.stores.notOffered') }, ...namedOptions(options.methods.filter((method) => method.type === KIND_TYPES[kind]))]}
              value={methodOf(kind)}
              onChange={(event) => setMethod(kind, event.target.value)}
            />
          ))}
        </div>
      </Card>
      <div>
        <Button variant="primary" loading={save.isPending} disabled={!values.name || !values.handle || !values.branch_id || !values.location_id || !values.price_list_id} onClick={() => save.mutate()}>
          {t(store ? 'ecommerce.stores.save' : 'ecommerce.stores.create')}
        </Button>
      </div>
    </div>
  )
}

/** ECOM-02: the store's catalogue from core items: add, publish, feature, describe, archive. */
function Catalogue({ store, manage }) {
  const { t } = useTranslation()
  const query = useQuery({ queryKey: [...ecomKey, 'products', store.id], queryFn: () => api.get(`ecommerce/stores/${store.id}/products?per_page=200`) })
  const [search, setSearch] = useState('')
  const items = useQuery({ queryKey: ['items', 'ecommerce-pick', search], queryFn: () => api.get(`items?search=${encodeURIComponent(search)}&per_page=20`), enabled: manage && search.length > 1 })
  const action = useEcomAction()
  const products = (query.data?.data ?? []).filter((product) => !product.archived_at)
  const columns = [
    { key: 'name', label: t('ecommerce.columns.item'), render: (row) => <span className="flex flex-col"><span className="font-medium text-ink">{row.item?.name}</span><span className="text-caption text-ink-muted">/{row.slug}</span></span> },
    { key: 'published', label: t('ecommerce.columns.published'), render: (row) => <Switch aria-label={t('ecommerce.stores.publishNamed', { name: row.item?.name })} checked={row.published} disabled={!manage} onChange={(value) => action.mutate({ path: `ecommerce/products/${row.id}`, method: 'patch', body: { published: value } })} /> },
    { key: 'featured', label: t('ecommerce.columns.featured'), render: (row) => <Switch aria-label={t('ecommerce.stores.featureNamed', { name: row.item?.name })} checked={row.featured} disabled={!manage} onChange={(value) => action.mutate({ path: `ecommerce/products/${row.id}`, method: 'patch', body: { featured: value } })} /> },
    { key: 'stock', label: t('ecommerce.columns.stock'), render: (row) => (row.track_stock ? t('ecommerce.stores.tracked') : <Switch aria-label={t('ecommerce.stores.inStockNamed', { name: row.item?.name })} label={t('ecommerce.stores.inStock')} checked={row.in_stock} disabled={!manage} onChange={(value) => action.mutate({ path: `ecommerce/products/${row.id}`, method: 'patch', body: { in_stock: value } })} />) },
    ...(manage ? [{ key: 'actions', label: t('ecommerce.columns.actions'), align: 'end', render: (row) => <Button variant="ghost" icon="archive" aria-label={t('ecommerce.stores.removeNamed', { name: row.item?.name })} onClick={() => action.mutate({ path: `ecommerce/products/${row.id}/archive`, done: 'ecommerce.stores.removed' })} /> }] : []),
  ]
  return (
    <div className="flex flex-col gap-4">
      {manage ? (
        <div className="flex flex-col gap-2">
          <TextField label={t('ecommerce.stores.addItems')} placeholder={t('ecommerce.stores.searchItems')} value={search} onChange={(event) => setSearch(event.target.value)} className="max-w-field" />
          <ul className="flex flex-wrap gap-2">
            {(items.data?.data ?? []).map((item) => (
              <li key={item.id}>
                <Button icon="plus" onClick={() => action.mutate({ path: `ecommerce/stores/${store.id}/products`, body: { item_ids: [item.id] }, done: 'ecommerce.stores.added' })}>{item.name}</Button>
              </li>
            ))}
          </ul>
        </div>
      ) : null}
      <DataTable caption={t('ecommerce.stores.catalogue')} columns={columns} rows={products} loading={query.isPending} emptyText={t('ecommerce.stores.noProducts')} />
    </div>
  )
}

/** ECOM-10: delivery zones and their fees. */
function Zones({ store, manage }) {
  const { t } = useTranslation()
  const query = useQuery({ queryKey: [...ecomKey, 'zones', store.id], queryFn: () => api.get(`ecommerce/stores/${store.id}/zones`) })
  const [zone, setZone] = useState({ name: '', fee_minor: '', free_over_minor: '', eta: '' })
  const action = useEcomAction({ onDone: () => setZone({ name: '', fee_minor: '', free_over_minor: '', eta: '' }) })
  const columns = [
    { key: 'name', label: t('ecommerce.columns.name'), render: (row) => <span className="font-medium text-ink">{row.name}</span> },
    { key: 'fee', label: t('ecommerce.columns.fee'), align: 'end', render: (row) => <Money amount={row.fee_minor} currency={store.currency} /> },
    { key: 'free', label: t('ecommerce.columns.freeOver'), align: 'end', render: (row) => (row.free_over_minor ? <Money amount={row.free_over_minor} currency={store.currency} /> : '—') },
    { key: 'eta', label: t('ecommerce.columns.eta'), render: (row) => row.eta ?? '—' },
    ...(manage ? [{ key: 'actions', label: t('ecommerce.columns.actions'), align: 'end', render: (row) => <Button variant="ghost" icon="archive" aria-label={t('ecommerce.stores.removeNamed', { name: row.name })} onClick={() => action.mutate({ path: `ecommerce/zones/${row.id}/archive`, done: 'ecommerce.stores.zoneRemoved' })} /> }] : []),
  ]
  return (
    <div className="flex flex-col gap-4">
      <DataTable caption={t('ecommerce.stores.zones')} columns={columns} rows={(query.data?.data ?? []).filter((row) => !row.archived_at)} loading={query.isPending} emptyText={t('ecommerce.stores.noZones')} />
      {manage ? (
        <Card title={t('ecommerce.stores.addZone')}>
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField label={t('ecommerce.fields.zoneName')} value={zone.name} onChange={(event) => setZone({ ...zone, name: event.target.value })} />
            <MoneyInput label={t('ecommerce.fields.fee')} currency={store.currency} value={zone.fee_minor} onChange={(value) => setZone({ ...zone, fee_minor: value ?? '' })} />
            <MoneyInput label={t('ecommerce.fields.freeOver')} currency={store.currency} value={zone.free_over_minor} onChange={(value) => setZone({ ...zone, free_over_minor: value ?? '' })} />
            <TextField label={t('ecommerce.fields.eta')} value={zone.eta} onChange={(event) => setZone({ ...zone, eta: event.target.value })} />
          </div>
          <Button className="mt-4" variant="primary" disabled={!zone.name || zone.fee_minor === ''} loading={action.isPending}
            onClick={() => action.mutate({ path: `ecommerce/stores/${store.id}/zones`, body: Object.fromEntries(Object.entries(zone).filter(([, v]) => v !== '')), done: 'ecommerce.stores.zoneAdded' })}>
            {t('ecommerce.stores.addZone')}
          </Button>
        </Card>
      ) : null}
    </div>
  )
}

/** ECOM-23, ECOM-24: the store's links and its WhatsApp webhook. */
function Sharing({ store, manage }) {
  const { t } = useTranslation()
  const [webhook, setWebhook] = useState(null)
  const rotate = useMutation({ mutationFn: () => api.post(`ecommerce/stores/${store.id}/whatsapp-token`), onSuccess: (response) => setWebhook(response.data), onError: (error) => toast.error(errorMessage(error)) })
  const copy = (text) => navigator.clipboard?.writeText(text).then(() => toast.success(t('ecommerce.stores.copied')))
  return (
    <div className="flex flex-col gap-4">
      <Card title={t('ecommerce.stores.storeLink')}>
        <div className="flex flex-wrap items-center gap-3">
          <code className="font-mono text-body text-ink">{store.share?.url}</code>
          <Button icon="copy" onClick={() => copy(store.share?.url)}>{t('ecommerce.stores.copy')}</Button>
          <a className="text-body text-primary hover:underline" href={store.share?.whatsapp} target="_blank" rel="noreferrer noopener">{t('ecommerce.stores.shareWhatsapp')}</a>
        </div>
      </Card>
      <Card title={t('ecommerce.stores.whatsapp')} subtitle={store.whatsapp_connected ? t('ecommerce.stores.whatsappConnected') : t('ecommerce.stores.whatsappNotConnected')}>
        {webhook ? (
          <Alert tone="warning" title={t('ecommerce.stores.webhookOnce')}>
            <code className="break-all font-mono">{webhook.webhook_url}</code>
          </Alert>
        ) : null}
        {manage ? <Button className="mt-4" onClick={() => rotate.mutate()} loading={rotate.isPending}>{t(store.whatsapp_connected ? 'ecommerce.stores.rotateWebhook' : 'ecommerce.stores.connectWebhook')}</Button> : null}
      </Card>
      {store.whatsapp_notifications ? <WhatsappNotifications state={store.whatsapp_notifications} /> : null}
    </div>
  )
}

/** ECOM-20: whether order updates can go out on WhatsApp (the platform owner sets up the provider). */
function WhatsappNotifications({ state }) {
  const { t } = useTranslation()
  const available = state === 'available'
  return (
    <Card title={t('ecommerce.stores.whatsappOrders')}>
      <div className="flex flex-col gap-1">
        <StatusBadge tone={available ? 'success' : 'neutral'}>{t(available ? 'ecommerce.stores.whatsappAvailable' : 'ecommerce.stores.whatsappNotConfigured')}</StatusBadge>
        <p className="text-caption text-ink-muted">{t(available ? 'ecommerce.stores.whatsappAvailableHelp' : 'ecommerce.stores.whatsappNotConfiguredHelp')}</p>
      </div>
    </Card>
  )
}

/** ECOM-01, ECOM-02, ECOM-10, ECOM-23: stores and their catalogue, zones and links. */
export default function StoreSettings() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const queryClient = useQueryClient()
  const { stores, isPending, isError, error } = useStores()
  const [selected, setSelected] = useState(null)
  const [creating, setCreating] = useState(false)
  const [tab, setTab] = useState('details')
  const manage = can(STORE_MANAGE)
  const store = stores.find((entry) => entry.id === selected) ?? stores[0] ?? null
  const detail = useQuery({ queryKey: [...ecomKey, 'store', store?.id], queryFn: () => api.get(`ecommerce/stores/${store.id}`), enabled: Boolean(store) })
  const full = detail.data?.data
  const saved = async (data) => {
    toast.success(t(creating ? 'ecommerce.stores.created' : 'ecommerce.stores.saved'))
    setCreating(false)
    setSelected(data.id)
    await queryClient.invalidateQueries({ queryKey: ecomKey })
  }
  return (
    <>
      <PageHeader title={t('ecommerce.stores.title')} description={t('ecommerce.stores.description')} actions={manage ? <Button variant="primary" icon="plus" onClick={() => setCreating(true)}>{t('ecommerce.stores.new')}</Button> : null} />
      {isError ? <Alert tone="danger" title={errorMessage(error)} /> : null}
      {!isPending && stores.length === 0 ? <p className="text-body text-ink-muted">{t('ecommerce.stores.empty')}</p> : null}
      {stores.length > 1 ? (
        <Select label={t('ecommerce.fields.store')} options={namedOptions(stores)} value={store?.id ?? ''} onChange={(event) => setSelected(event.target.value)} className="max-w-field" />
      ) : null}
      {full ? (
        <Card title={full.name} subtitle={<StatusBadge tone={TONES[full.status]}>{t(`ecommerce.storeStatuses.${full.status}`)}</StatusBadge>}>
          <Tabs
            value={tab}
            onChange={setTab}
            items={['details', 'catalogue', 'zones', 'sharing'].map((value) => ({ value, label: t(`ecommerce.stores.tabs.${value}`) }))}
          />
          <div className="pt-4">
            {tab === 'details' ? <StoreForm key={full.id} store={full} onSaved={saved} /> : null}
            {tab === 'catalogue' ? <Catalogue store={full} manage={manage} /> : null}
            {tab === 'zones' ? <Zones store={full} manage={manage} /> : null}
            {tab === 'sharing' ? <Sharing store={full} manage={manage} /> : null}
          </div>
        </Card>
      ) : null}
      {creating ? (
        <Dialog open size="lg" title={t('ecommerce.stores.new')} onClose={() => setCreating(false)}>
          <StoreForm onSaved={saved} />
        </Dialog>
      ) : null}
    </>
  )
}
