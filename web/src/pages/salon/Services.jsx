import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, DataTable, Dialog, Money, MultiSelect, Select, Switch, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { ActionsLabel, PlaceBar, QueryNotes, Status } from './SalonParts'
import { salonError, SERVICE_MANAGE, salonKey, useItems, usePackageTypes, useResources, useSalonPlace, useServices, useStaff } from './salonData'

const SERVICE_FIELDS = ['item_id', 'name', 'category', 'kind', 'duration_minutes', 'buffer_minutes', 'staff_ids', 'resource_ids', 'addon_ids', 'products']

/** The items a new service or package can be made from. */
function useFreeItems(companyId) {
  const query = useQuery({ queryKey: [...salonKey, 'service-items', companyId], queryFn: () => api.get(`salon/service-items?company_id=${companyId}`), enabled: Boolean(companyId) })
  return query.data?.data ?? []
}

/** SAL-02: a service of the menu: duration, who may do it, chairs, add-ons and products used. */
function ServiceDialog({ service, companyId, services, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const free = useFreeItems(service ? null : companyId)
  const staff = useStaff(companyId).rows.filter((entry) => !entry.archived_at)
  const resources = useResources(companyId).rows.filter((entry) => !entry.archived_at)
  const stockItems = useItems().filter((item) => item.type === 'stock')
  const [values, setValues] = useState({
    item_id: service?.item_id ?? '',
    name: service?.name ?? '',
    category: service?.category ?? '',
    kind: service?.kind ?? 'service',
    duration_minutes: String(service?.duration_minutes ?? 30),
    buffer_minutes: String(service?.buffer_minutes ?? 0),
    online_bookable: service?.online_bookable ?? false,
    staff_ids: service?.staff_ids ?? [],
    resource_ids: service?.resource_ids ?? [],
    addon_ids: service?.addon_ids ?? [],
    products: (service?.products ?? []).map((product) => ({ item_id: product.item_id, qty: product.qty })),
  })
  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }))
  const save = useMutation({
    mutationFn: () => {
      const body = {
        name: values.name.trim() || null,
        category: values.category.trim() || null,
        kind: values.kind,
        duration_minutes: Number(values.duration_minutes),
        buffer_minutes: Number(values.buffer_minutes),
        online_bookable: values.online_bookable,
        staff_ids: values.staff_ids,
        resource_ids: values.resource_ids,
        addon_ids: values.addon_ids,
        products: values.products.filter((product) => product.item_id && product.qty),
      }
      return service ? api.put(`salon/services/${service.id}`, body) : api.post('salon/services', { ...body, company_id: companyId, item_id: values.item_id })
    },
    onSuccess: () => {
      toast.success(t('salon.services.saved'))
      queryClient.invalidateQueries({ queryKey: salonKey })
      onClose()
    },
  })
  const errors = formErrors(save.error, SERVICE_FIELDS)
  const duration = Number(values.duration_minutes)
  const valid = (service || values.item_id) && duration >= 5 && duration <= 600
  const setProduct = (index, patch) => setValues((current) => ({ ...current, products: current.products.map((product, at) => (at === index ? { ...product, ...patch } : product)) }))
  return (
    <Dialog
      open
      title={service ? t('salon.services.editTitle') : t('salon.services.addTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={save.isPending} disabled={!valid} onClick={() => save.mutate()}>
            {t('salon.services.save')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        {service ? (
          <TextField label={t('salon.services.item')} value={service.item_name} disabled readOnly />
        ) : (
          <Select label={t('salon.services.item')} help={t('salon.services.itemHelp')} placeholder={t('salon.services.pickItem')} options={free.map((item) => ({ value: item.id, label: `${item.name} (${item.code})` }))} value={values.item_id} error={errors.fields.item_id} onChange={set('item_id')} />
        )}
        <TextField label={t('salon.fields.name')} help={t('salon.services.nameHelp')} value={values.name} error={errors.fields.name} onChange={set('name')} />
        <TextField label={t('salon.services.category')} value={values.category} error={errors.fields.category} onChange={set('category')} />
        <Select label={t('salon.services.kind')} options={['service', 'addon'].map((kind) => ({ value: kind, label: t(`salon.services.kinds.${kind}`) }))} value={values.kind} onChange={set('kind')} />
        <div className="grid grid-cols-2 gap-4">
          <TextField label={t('salon.services.duration')} inputMode="numeric" value={values.duration_minutes} error={errors.fields.duration_minutes} onChange={set('duration_minutes')} />
          <TextField label={t('salon.services.buffer')} help={t('salon.services.bufferHelp')} inputMode="numeric" value={values.buffer_minutes} error={errors.fields.buffer_minutes} onChange={set('buffer_minutes')} />
        </div>
        <Switch label={t('salon.services.onlineBookable')} checked={values.online_bookable} onChange={(checked) => setValues((current) => ({ ...current, online_bookable: checked }))} />
        <MultiSelect label={t('salon.services.staff')} help={t('salon.services.staffHelp')} options={staff.map((entry) => ({ value: entry.id, label: entry.name }))} value={values.staff_ids} onChange={(next) => setValues((current) => ({ ...current, staff_ids: next }))} />
        <MultiSelect label={t('salon.services.resources')} help={t('salon.services.resourcesHelp')} options={resources.map((entry) => ({ value: entry.id, label: entry.name }))} value={values.resource_ids} onChange={(next) => setValues((current) => ({ ...current, resource_ids: next }))} />
        <MultiSelect
          label={t('salon.services.addons')}
          help={t('salon.services.addonsHelp')}
          options={services.filter((entry) => entry.kind === 'addon' && entry.id !== service?.id && !entry.archived_at).map((entry) => ({ value: entry.id, label: entry.name }))}
          value={values.addon_ids}
          onChange={(next) => setValues((current) => ({ ...current, addon_ids: next }))}
        />
        <fieldset className="flex flex-col gap-3">
          <legend className="text-label text-ink">{t('salon.services.products')}</legend>
          <p className="text-caption text-ink-muted">{t('salon.services.productsHelp')}</p>
          {values.products.map((product, index) => (
            <div key={index} className="flex items-end gap-3">
              <Select label={t('salon.services.product')} options={stockItems.map((item) => ({ value: item.id, label: item.name }))} placeholder={t('salon.services.pickProduct')} value={product.item_id} onChange={(event) => setProduct(index, { item_id: event.target.value })} className="flex-1" />
              <TextField label={t('salon.services.qty')} inputMode="decimal" value={product.qty} onChange={(event) => setProduct(index, { qty: event.target.value })} className="w-28" />
              <Button variant="ghost" onClick={() => setValues((current) => ({ ...current, products: current.products.filter((_, at) => at !== index) }))}>
                {t('salon.services.removeProduct')}
              </Button>
            </div>
          ))}
          <div>
            <Button onClick={() => setValues((current) => ({ ...current, products: [...current.products, { item_id: '', qty: '1' }] }))}>{t('salon.services.addProduct')}</Button>
          </div>
        </fieldset>
      </div>
    </Dialog>
  )
}

/** SAL-18: a package or membership: sessions, validity and the services it covers. */
function PackageDialog({ type, companyId, services, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const free = useFreeItems(type ? null : companyId)
  const [values, setValues] = useState({ item_id: type?.item_id ?? '', name: type?.name ?? '', kind: type?.kind ?? 'package', sessions: String(type?.sessions ?? 5), validity_days: type?.validity_days == null ? '' : String(type.validity_days), service_ids: type?.service_ids ?? [] })
  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }))
  const save = useMutation({
    mutationFn: () => {
      const body = { name: values.name.trim(), kind: values.kind, sessions: Number(values.sessions), validity_days: values.validity_days ? Number(values.validity_days) : null, service_ids: values.service_ids }
      return type ? api.put(`salon/package-types/${type.id}`, body) : api.post('salon/package-types', { ...body, company_id: companyId, item_id: values.item_id })
    },
    onSuccess: () => {
      toast.success(t('salon.packages.saved'))
      queryClient.invalidateQueries({ queryKey: salonKey })
      onClose()
    },
  })
  const errors = formErrors(save.error, ['item_id', 'name', 'kind', 'sessions', 'validity_days', 'service_ids'])
  const valid = (type || values.item_id) && values.name.trim() && Number(values.sessions) >= 1
  return (
    <Dialog
      open
      title={type ? t('salon.packages.editTitle') : t('salon.packages.addTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={save.isPending} disabled={!valid} onClick={() => save.mutate()}>
            {t('salon.packages.save')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        {type ? (
          <TextField label={t('salon.services.item')} value={type.item_name} disabled readOnly />
        ) : (
          <Select label={t('salon.packages.item')} help={t('salon.packages.itemHelp')} placeholder={t('salon.services.pickItem')} options={free.map((item) => ({ value: item.id, label: `${item.name} (${item.code})` }))} value={values.item_id} error={errors.fields.item_id} onChange={set('item_id')} />
        )}
        <TextField label={t('salon.fields.name')} value={values.name} error={errors.fields.name} onChange={set('name')} />
        <Select label={t('salon.packages.kind')} options={['package', 'membership'].map((kind) => ({ value: kind, label: t(`salon.packages.kinds.${kind}`) }))} value={values.kind} onChange={set('kind')} />
        <div className="grid grid-cols-2 gap-4">
          <TextField label={t('salon.packages.sessions')} inputMode="numeric" value={values.sessions} error={errors.fields.sessions} onChange={set('sessions')} />
          <TextField label={t('salon.packages.validity')} help={t('salon.packages.validityHelp')} inputMode="numeric" value={values.validity_days} error={errors.fields.validity_days} onChange={set('validity_days')} />
        </div>
        <MultiSelect label={t('salon.packages.covers')} help={t('salon.packages.coversHelp')} options={services.filter((entry) => !entry.archived_at).map((entry) => ({ value: entry.id, label: entry.name }))} value={values.service_ids} onChange={(next) => setValues((current) => ({ ...current, service_ids: next }))} />
      </div>
    </Dialog>
  )
}

/** SAL-02, SAL-18: the service menu and the package types sold from it. */
export default function Services() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const manage = can(SERVICE_MANAGE)
  const place = useSalonPlace()
  const companyId = place.company?.id ?? ''
  const currency = place.company?.base_currency
  const servicesQuery = useServices(companyId)
  const typesQuery = usePackageTypes(companyId)
  const [tab, setTab] = useState('services')
  const [editing, setEditing] = useState(null)
  const [archiving, setArchiving] = useState(null)
  const archive = useMutation({
    mutationFn: ({ kind, row }) => api.post(`salon/${kind === 'service' ? 'services' : 'package-types'}/${row.id}/archive`, {}),
    onSuccess: () => {
      toast.success(t('salon.services.archived'))
      queryClient.invalidateQueries({ queryKey: salonKey })
      setArchiving(null)
    },
  })
  const rowActions = (kind) => (row) =>
    manage && !row.archived_at ? (
      <div className="flex justify-end gap-2">
        <Button variant="ghost" aria-label={t('salon.services.editNamed', { name: row.name })} onClick={() => setEditing({ kind, row })}>
          {t('salon.services.edit')}
        </Button>
        <Button variant="ghost" aria-label={t('salon.services.archiveNamed', { name: row.name })} onClick={() => setArchiving({ kind, row })}>
          {t('salon.services.archive')}
        </Button>
      </div>
    ) : null
  const serviceColumns = [
    { key: 'name', label: t('salon.fields.name'), render: (row) => row.name },
    { key: 'category', label: t('salon.services.category'), render: (row) => row.category ?? '' },
    { key: 'kind', label: t('salon.services.kind'), render: (row) => t(`salon.services.kinds.${row.kind}`) },
    { key: 'duration', label: t('salon.services.duration'), align: 'end', render: (row) => t('salon.services.minutes', { count: row.duration_minutes }) },
    { key: 'price', label: t('salon.services.price'), align: 'end', render: (row) => (row.price_minor == null ? <span className="text-ink-muted">{t('salon.services.noPrice')}</span> : <Money amount={row.price_minor} currency={row.currency ?? currency} />) },
    { key: 'online', label: t('salon.services.online'), render: (row) => (row.online_bookable ? t('salon.yes') : t('salon.no')) },
    { key: 'status', label: t('salon.fields.status'), render: (row) => <Status status={row.archived_at ? 'archived' : 'active'} /> },
    { key: 'actions', label: <ActionsLabel />, align: 'end', render: rowActions('service') },
  ]
  const typeColumns = [
    { key: 'name', label: t('salon.fields.name'), render: (row) => row.name },
    { key: 'kind', label: t('salon.packages.kind'), render: (row) => t(`salon.packages.kinds.${row.kind}`) },
    { key: 'sessions', label: t('salon.packages.sessions'), align: 'end', render: (row) => row.sessions },
    { key: 'validity', label: t('salon.packages.validity'), align: 'end', render: (row) => (row.validity_days == null ? '' : t('salon.packages.days', { count: row.validity_days })) },
    { key: 'status', label: t('salon.fields.status'), render: (row) => <Status status={row.archived_at ? 'archived' : 'active'} /> },
    { key: 'actions', label: <ActionsLabel />, align: 'end', render: rowActions('package') },
  ]
  return (
    <>
      <PageHeader
        title={t('salon.services.title')}
        description={t('salon.services.description')}
        actions={
          manage && companyId ? (
            <Button variant="primary" icon="plus" onClick={() => setEditing({ kind: tab === 'services' ? 'service' : 'package', row: null })}>
              {tab === 'services' ? t('salon.services.add') : t('salon.packages.add')}
            </Button>
          ) : null
        }
      />
      <div className="flex flex-col gap-5">
        <PlaceBar place={place} branch={false} />
        <Tabs
          items={[
            { value: 'services', label: t('salon.services.tab'), count: servicesQuery.rows.filter((row) => !row.archived_at).length },
            { value: 'packages', label: t('salon.packages.tab'), count: typesQuery.rows.filter((row) => !row.archived_at).length },
          ]}
          value={tab}
          onChange={setTab}
        />
        {tab === 'services' ? (
          <>
            <QueryNotes query={servicesQuery} />
            {!servicesQuery.isError ? <DataTable caption={t('salon.services.title')} columns={serviceColumns} rows={servicesQuery.rows} loading={servicesQuery.isPending && Boolean(companyId)} emptyText={t('salon.services.empty')} /> : null}
          </>
        ) : (
          <>
            <QueryNotes query={typesQuery} />
            {!typesQuery.isError ? <DataTable caption={t('salon.packages.title')} columns={typeColumns} rows={typesQuery.rows} loading={typesQuery.isPending && Boolean(companyId)} emptyText={t('salon.packages.empty')} /> : null}
          </>
        )}
      </div>
      {editing?.kind === 'service' ? <ServiceDialog key={editing.row?.id ?? 'new'} service={editing.row} companyId={companyId} services={servicesQuery.rows} onClose={() => setEditing(null)} /> : null}
      {editing?.kind === 'package' ? <PackageDialog key={editing.row?.id ?? 'new'} type={editing.row} companyId={companyId} services={servicesQuery.rows} onClose={() => setEditing(null)} /> : null}
      <Dialog
        open={Boolean(archiving)}
        title={t('salon.services.archiveTitle')}
        onClose={() => setArchiving(null)}
        footer={
          <>
            <Button variant="ghost" onClick={() => setArchiving(null)}>
              {t('common.cancel')}
            </Button>
            <Button variant="danger" loading={archive.isPending} onClick={() => archive.mutate(archiving)}>
              {t('salon.services.archive')}
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-3">
          {archive.isError ? <Alert tone="danger" title={salonError(archive.error)} /> : null}
          <p>{t('salon.services.archiveAsk', { name: archiving?.row?.name ?? '' })}</p>
        </div>
      </Dialog>
    </>
  )
}
