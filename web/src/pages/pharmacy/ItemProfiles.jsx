import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Dialog, ListView, Select, StatusBadge, Switch, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useServerList } from '@/lib/useServerList'
import { ItemPicker } from './PharmacyParts'
import { choiceField, ITEM_CLASSES, ITEM_CONFIGURE, pharmacyKey, PRODUCT_KINDS } from './pharmacyData'

const CLASS_TONES = { otc: 'neutral', prescription_only: 'info', controlled: 'warning', poison: 'danger', agrochemical: 'warning' }

/**
 * PHA-03, PHA-20, PHA-21: an item's pharmacy profile: its class (what rules
 * apply), its kind (human, veterinary, agrochemical), lot tracking,
 * restricted sale, the usage notes the till shows, the schedule in the
 * regulator's words (never checked here) and the withdrawal period.
 */
function ProfileDialog({ profile, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [item, setItem] = useState(profile?.item ?? null)
  const [form, setForm] = useState({
    class: profile?.class ?? 'otc',
    product_kind: profile?.product_kind ?? 'human',
    batch_tracked: profile?.batch_tracked ?? true,
    restricted_sale: profile?.restricted_sale ?? false,
    usage_notes: profile?.usage_notes ?? '',
    schedule_note: profile?.schedule_note ?? '',
    withdrawal_days: profile?.withdrawal_days == null ? '' : String(profile.withdrawal_days),
  })
  const save = useMutation({
    mutationFn: () =>
      api.put(`pharmacy/items/${item.id}/profile`, {
        ...form,
        usage_notes: form.usage_notes.trim() || null,
        schedule_note: form.schedule_note.trim() || null,
        withdrawal_days: form.withdrawal_days === '' ? null : Number(form.withdrawal_days),
      }),
    onSuccess: async () => {
      toast.success(t('pharmacy.items.saved'))
      await queryClient.invalidateQueries({ queryKey: pharmacyKey })
      onClose()
    },
  })
  const errors = formErrors(save.error, ['class', 'product_kind', 'batch_tracked', 'restricted_sale', 'usage_notes', 'schedule_note', 'withdrawal_days'])
  const set = (name) => (value) => setForm((current) => ({ ...current, [name]: value }))
  return (
    <Dialog
      open
      size="lg"
      title={profile ? t('pharmacy.items.editTitle', { name: profile.item.name }) : t('pharmacy.items.addTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={save.isPending} disabled={!item || (form.withdrawal_days !== '' && !/^\d+$/.test(form.withdrawal_days))} onClick={() => save.mutate()}>
            {t('pharmacy.items.save')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <div className="grid gap-3 sm:grid-cols-2">
          {profile ? null : <ItemPicker label={t('pharmacy.fields.item')} value={item} required onChange={setItem} className="sm:col-span-2" />}
          <Select label={t('pharmacy.items.class')} options={ITEM_CLASSES.map((value) => ({ value, label: t(`pharmacy.classes.${value}`) }))} value={form.class} error={errors.fields.class} onChange={(event) => set('class')(event.target.value)} />
          <Select label={t('pharmacy.items.kind')} options={PRODUCT_KINDS.map((value) => ({ value, label: t(`pharmacy.kindsOfProduct.${value}`) }))} value={form.product_kind} error={errors.fields.product_kind} onChange={(event) => set('product_kind')(event.target.value)} />
          <Switch label={t('pharmacy.items.batchTracked')} checked={form.batch_tracked} onChange={set('batch_tracked')} />
          <Switch label={t('pharmacy.items.restrictedSale')} checked={form.restricted_sale} onChange={set('restricted_sale')} />
          <TextField label={t('pharmacy.items.usageNotes')} help={t('pharmacy.items.usageNotesHelp')} value={form.usage_notes} maxLength={2000} error={errors.fields.usage_notes} onChange={(event) => set('usage_notes')(event.target.value)} className="sm:col-span-2" />
          <TextField label={t('pharmacy.items.scheduleNote')} help={t('pharmacy.items.scheduleNoteHelp')} value={form.schedule_note} maxLength={200} error={errors.fields.schedule_note} onChange={(event) => set('schedule_note')(event.target.value)} />
          <TextField label={t('pharmacy.items.withdrawalDays')} help={t('pharmacy.items.withdrawalDaysHelp')} inputMode="numeric" value={form.withdrawal_days} error={errors.fields.withdrawal_days} onChange={(event) => set('withdrawal_days')(event.target.value)} />
        </div>
      </div>
    </Dialog>
  )
}

/** PHA-03: the items with a pharmacy profile; open one to change its class and rules, or add an item. */
export default function ItemProfiles() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const [editing, setEditing] = useState(null)
  const columns = [
    { key: 'item', label: t('pharmacy.columns.item'), hideable: false, wrap: true, render: (row) => (
      <span className="flex flex-col">
        <span className="text-ink">{row.item?.name}</span>
        <span className="font-mono text-caption text-ink-muted">{row.item?.code}</span>
      </span>
    ) },
    { key: 'class', label: t('pharmacy.columns.class'), render: (row) => <StatusBadge tone={CLASS_TONES[row.class] ?? 'neutral'}>{t(`pharmacy.classes.${row.class}`)}</StatusBadge> },
    { key: 'kind', label: t('pharmacy.items.kind'), render: (row) => t(`pharmacy.kindsOfProduct.${row.product_kind}`) },
    { key: 'batch_tracked', label: t('pharmacy.items.batchTracked'), render: (row) => (row.batch_tracked ? t('pharmacy.yes') : t('pharmacy.no')) },
    { key: 'restricted_sale', label: t('pharmacy.items.restrictedSale'), render: (row) => (row.restricted_sale ? t('pharmacy.yes') : t('pharmacy.no')) },
    { key: 'schedule_note', label: t('pharmacy.items.scheduleNote'), defaultHidden: true, wrap: true, render: (row) => row.schedule_note ?? '' },
  ]
  const list = useServerList({ id: 'pharmacy-items', endpoint: 'pharmacy/items', queryKey: [...pharmacyKey, 'items'], filters: { class: '', product_kind: '' }, columns })
  return (
    <>
      <PageHeader
        title={t('pharmacy.items.title')}
        description={t('pharmacy.items.description')}
        actions={
          can(ITEM_CONFIGURE) ? (
            <Button variant="primary" icon="plus" onClick={() => setEditing({})}>
              {t('pharmacy.items.add')}
            </Button>
          ) : null
        }
      />
      <ListView
        list={list}
        title={t('pharmacy.items.title')}
        searchLabel={t('pharmacy.items.search')}
        filterFields={[choiceField(t, 'class', ITEM_CLASSES, t('pharmacy.columns.class'), 'pharmacy.classes'), choiceField(t, 'product_kind', PRODUCT_KINDS, t('pharmacy.items.kind'), 'pharmacy.kindsOfProduct')]}
        emptyText={t('pharmacy.items.empty')}
        onRowClick={can(ITEM_CONFIGURE) ? (row) => setEditing(row) : undefined}
      />
      {editing ? <ProfileDialog profile={editing.item ? editing : null} onClose={() => setEditing(null)} /> : null}
    </>
  )
}
