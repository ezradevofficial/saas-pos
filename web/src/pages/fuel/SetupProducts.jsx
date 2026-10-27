import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, DataTable, Dialog, Select, TextField } from '@/components/ds'
import { ItemPicker } from '../pharmacy/PharmacyParts'
import { usePharmacyCompanyChoice } from '../pharmacy/pharmacyData'
import { ConfirmDialog, FormFooter } from './FuelParts'
import { FUEL_TYPES, fuelKey, SETUP_MANAGE, useProducts } from './fuelData'

function ProductDialog({ open, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { company, companies, choose } = usePharmacyCompanyChoice()
  const [item, setItem] = useState(null)
  const [type, setType] = useState('petrol')
  const [grade, setGrade] = useState('')
  const save = useMutation({
    mutationFn: () => api.post('fuel/products', { company_id: company.id, item_id: item.id, fuel_type: type, grade: grade.trim() || null }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: fuelKey })
      setItem(null)
      setGrade('')
      onClose()
    },
  })
  const errors = formErrors(save.error, ['item_id', 'fuel_type', 'grade', 'company_id'])
  const close = () => {
    save.reset()
    onClose()
  }
  return (
    <Dialog open={open} title={t('fuel.setup.products.new')} onClose={close} footer={<FormFooter action={t('fuel.setup.products.create')} loading={save.isPending} disabled={!item || !company} onClose={close} onSubmit={() => save.mutate()} />}>
      <div className="flex flex-col gap-4">
        <p className="text-body text-ink-muted">{t('fuel.setup.products.help')}</p>
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        {companies.length > 1 ? <Select label={t('fuel.fields.company')} options={companies.map((entry) => ({ value: entry.id, label: entry.name }))} value={company?.id ?? ''} onChange={(event) => choose(event.target.value)} /> : null}
        <ItemPicker label={t('fuel.fields.item')} value={item} error={errors.fields.item_id} required onChange={setItem} />
        <Select label={t('fuel.fields.fuelType')} options={FUEL_TYPES.map((value) => ({ value, label: t(`fuel.types.${value}`) }))} value={type} error={errors.fields.fuel_type} required onChange={(event) => setType(event.target.value)} />
        <TextField label={t('fuel.fields.grade')} help={t('fuel.setup.products.gradeHelp')} value={grade} maxLength={60} error={errors.fields.grade} onChange={(event) => setGrade(event.target.value)} />
      </div>
    </Dialog>
  )
}

/** FUEL-04: the core stock items marked as fuel, with a type and a grade. */
export default function SetupProducts() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const { all, loading } = useProducts()
  const [creating, setCreating] = useState(false)
  const [archiving, setArchiving] = useState(null)
  const archive = useMutation({
    mutationFn: (product) => api.post(`fuel/products/${product.id}/archive`),
    onSuccess: async () => {
      setArchiving(null)
      await queryClient.invalidateQueries({ queryKey: fuelKey })
    },
  })
  const manage = can(SETUP_MANAGE)
  const columns = [
    { key: 'name', label: t('fuel.fields.item'), render: (row) => <span className="font-medium text-ink">{row.name}</span> },
    { key: 'type', label: t('fuel.fields.fuelType'), render: (row) => t(`fuel.types.${row.fuel_type}`, { defaultValue: row.fuel_type }) },
    { key: 'grade', label: t('fuel.fields.grade'), render: (row) => row.grade ?? '' },
    { key: 'state', label: t('fuel.columns.status'), render: (row) => (row.archived_at ? t('fuel.states.archived') : t('fuel.states.active')) },
    ...(manage
      ? [
          {
            key: 'actions',
            label: '',
            align: 'end',
            render: (row) =>
              row.archived_at ? null : (
                <Button variant="ghost" aria-label={t('fuel.setup.products.archiveLabel', { name: row.name })} onClick={() => setArchiving(row)}>
                  {t('fuel.actions.archive')}
                </Button>
              ),
          },
        ]
      : []),
  ]
  return (
    <section className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-body text-ink-muted">{t('fuel.setup.products.description')}</p>
        {manage ? (
          <Button variant="primary" icon="plus" onClick={() => setCreating(true)}>
            {t('fuel.setup.products.new')}
          </Button>
        ) : null}
      </div>
      <DataTable caption={t('fuel.setup.tabs.products')} columns={columns} rows={all} loading={loading} emptyText={t('fuel.setup.products.empty')} />
      <ProductDialog open={creating} onClose={() => setCreating(false)} />
      <ConfirmDialog
        open={Boolean(archiving)}
        title={t('fuel.setup.products.archiveTitle', { name: archiving?.name })}
        text={t('fuel.setup.products.archiveHelp')}
        action={t('fuel.actions.archive')}
        variant="danger"
        loading={archive.isPending}
        error={archive.error ? errorMessage(archive.error) : null}
        onClose={() => {
          archive.reset()
          setArchiving(null)
        }}
        onConfirm={() => archive.mutate(archiving)}
      />
    </section>
  )
}
