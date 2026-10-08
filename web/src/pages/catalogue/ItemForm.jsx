import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Checkbox, DecimalInput, Icon, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useCompanies, useCompanySelection } from '@/layouts/companySelection'
import { perCompany, useSharingModes } from '@/lib/masterData'
import { useErrorFocus } from '@/lib/useErrorFocus'
import { categoryOptions, ITEM_TYPES, uomLabel, useItemCategories, useTaxCategories, useUoms } from './catalogueData'

let rowKey = 0
const nextKey = () => `row-${++rowKey}`

function initialValues(item, defaults) {
  return {
    company_id: item?.company_id ?? defaults.companyId ?? '',
    code: item?.code ?? '',
    name: item?.name ?? '',
    type: item?.type ?? 'stock',
    category_id: item?.category_id ?? '',
    base_uom_id: item?.base_uom_id ?? defaults.baseUomId ?? '',
    tax_category_id: item?.tax_category_id ?? '',
    uoms: (item?.uoms ?? []).map((uom) => ({
      key: nextKey(),
      uom_id: uom.uom_id,
      factor: uom.factor,
      is_sales_default: Boolean(uom.is_sales_default),
      is_purchase_default: Boolean(uom.is_purchase_default),
    })),
    barcodes: (item?.barcodes ?? []).map((barcode) => ({ key: nextKey(), barcode: barcode.barcode, uom_id: barcode.uom_id ?? '' })),
  }
}

/**
 * MD-02: an item's details, units and barcodes; `item` null to create.
 * The other units and the barcodes are always sent in full, so a change
 * of base unit carries them (base_change_needs_units). A field hidden by
 * field rules (RBAC-05) is not in the item: it is neither shown nor sent.
 */
export function ItemForm({ item, readOnly = false, onSaved }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const formRef = useRef(null)
  const alertRef = useRef(null)
  const { modes } = useSharingModes()
  const { companies } = useCompanies()
  const { company: selected } = useCompanySelection()
  const uoms = useUoms()
  const categories = useItemCategories()
  const taxCategories = useTaxCategories()
  const activeCompanies = companies.filter((company) => !company.archived_at)
  const defaultUom = uoms.active.find((uom) => uom.code === 'EA') ?? uoms.active[0]
  const [values, setValues] = useState(() => initialValues(item, { companyId: selected?.id, baseUomId: defaultUom?.id }))
  const [submitted, setSubmitted] = useState(false)
  const shows = (field) => !item || field in item
  const creating = !item
  const keptPerCompany = perCompany(modes, 'items')
  // Companies load after the form: the switcher's company, or the only one, is the default.
  const chosenCompany = values.company_id || selected?.id || (activeCompanies.length === 1 ? activeCompanies[0].id : '')
  const companyId = creating ? (keptPerCompany ? chosenCompany || null : null) : (item.company_id ?? null)
  // Units load after the form: the first active "EA" (or first unit) is the default base.
  const baseUomId = values.base_uom_id || (creating ? (defaultUom?.id ?? '') : '')

  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }))
  const setRow = (list, key, patch) => setValues((current) => ({ ...current, [list]: current[list].map((row) => (row.key === key ? { ...row, ...patch } : row)) }))
  const removeRow = (list, key) => setValues((current) => ({ ...current, [list]: current[list].filter((row) => row.key !== key) }))
  const setDefault = (key, flag, checked) =>
    setValues((current) => ({ ...current, uoms: current.uoms.map((row) => ({ ...row, [flag]: row.key === key ? checked : checked ? false : row[flag] })) }))

  const body = () => {
    const data = {}
    if (creating && keptPerCompany) data.company_id = chosenCompany || null
    if (shows('code')) data.code = values.code.trim()
    if (shows('name')) data.name = values.name.trim()
    if (shows('type')) data.type = values.type
    if (shows('category_id')) data.category_id = values.category_id || null
    if (shows('base_uom_id')) data.base_uom_id = baseUomId
    if (shows('tax_category_id') && taxCategories.allowed) data.tax_category_id = values.tax_category_id || null
    if (shows('uoms')) {
      data.uoms = values.uoms.map((row) => ({
        uom_id: row.uom_id,
        factor: row.factor,
        is_sales_default: row.is_sales_default,
        is_purchase_default: row.is_purchase_default,
      }))
    }
    if (shows('barcodes')) {
      data.barcodes = values.barcodes
        .filter((row) => row.barcode.trim() !== '')
        .map((row) => ({ barcode: row.barcode.trim(), uom_id: row.uom_id && row.uom_id !== baseUomId ? row.uom_id : null }))
    }
    return data
  }

  const mutation = useMutation({
    mutationFn: () => (creating ? api.post('items', body()) : api.patch(`items/${item.id}`, body())),
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: ['items'] })
      if (!creating) {
        queryClient.setQueryData(['items', 'detail', item.id], { data: response.data })
        queryClient.invalidateQueries({ queryKey: ['history', 'item', item.id] })
      }
      onSaved?.(response)
    },
  })
  const rowFields = [
    ...values.uoms.flatMap((_, index) => [`uoms.${index}.uom_id`, `uoms.${index}.factor`]),
    ...values.barcodes.flatMap((_, index) => [`barcodes.${index}.barcode`, `barcodes.${index}.uom_id`]),
  ]
  const errors = formErrors(mutation.error, ['company_id', 'code', 'name', 'type', 'category_id', 'base_uom_id', 'tax_category_id', 'uoms', 'barcodes', ...rowFields])
  useErrorFocus(formRef, alertRef, mutation.error)

  const baseUom = uoms.all.find((uom) => uom.id === baseUomId)
  const unitChoices = (row) =>
    uoms.active
      .filter((uom) => uom.id !== baseUomId && (uom.id === row.uom_id || !values.uoms.some((other) => other.uom_id === uom.id)))
      .map((uom) => ({ value: uom.id, label: uomLabel(uom) }))
  const sentBarcodes = values.barcodes.filter((row) => row.barcode.trim() !== '')
  const barcodeError = (row) => {
    const index = sentBarcodes.indexOf(row)
    return index < 0 ? undefined : (errors.fields[`barcodes.${index}.barcode`] ?? errors.fields[`barcodes.${index}.uom_id`])
  }
  const barcodeUnits = [
    { value: '', label: baseUom ? t('items.form.baseUnitOption', { unit: baseUom.code }) : t('items.form.baseUnit') },
    ...values.uoms.filter((row) => row.uom_id).map((row) => ({ value: row.uom_id, label: uoms.all.find((uom) => uom.id === row.uom_id)?.code ?? '' })),
  ]
  const keep = (options, current, label) => (current && !options.some((option) => option.value === current) ? [...options, { value: current, label }] : options)

  return (
    <form
      ref={formRef}
      noValidate
      className="flex flex-col gap-5"
      onSubmit={(event) => {
        event.preventDefault()
        setSubmitted(true)
        if (values.uoms.some((row) => !row.uom_id || !row.factor)) return
        mutation.mutate()
      }}
    >
      {errors.form ? (
        <div ref={alertRef} tabIndex={-1} className="rounded-md">
          <Alert tone="danger" title={errorMessage(mutation.error)} />
        </div>
      ) : null}
      <fieldset disabled={readOnly} className="flex min-w-0 flex-col gap-5">
        <Card title={t('items.form.details')}>
          <div className="grid gap-4 sm:grid-cols-2">
            {creating && keptPerCompany ? (
              <Select
                label={t('items.form.company')}
                help={t('items.form.companyHelp')}
                options={activeCompanies.map((company) => ({ value: company.id, label: company.name }))}
                placeholder={t('items.form.chooseCompany')}
                value={chosenCompany}
                onChange={(event) => setValues((current) => ({ ...current, company_id: event.target.value, category_id: '', tax_category_id: '' }))}
                error={errors.fields.company_id}
                required
                className="sm:col-span-2"
              />
            ) : null}
            {shows('code') ? (
              <TextField
                label={t('items.form.code')}
                help={t('items.form.codeHelp')}
                value={values.code}
                onChange={set('code')}
                maxLength={40}
                autoComplete="off"
                error={errors.fields.code}
                required
              />
            ) : null}
            {shows('type') ? (
              <Select
                label={t('items.form.type')}
                options={ITEM_TYPES.map((type) => ({ value: type, label: t(`items.types.${type}`) }))}
                value={values.type}
                onChange={set('type')}
                error={errors.fields.type}
                required
              />
            ) : null}
            {shows('name') ? (
              <TextField
                label={t('items.form.name')}
                value={values.name}
                onChange={set('name')}
                maxLength={255}
                error={errors.fields.name}
                required
                className="sm:col-span-2"
              />
            ) : null}
            {shows('category_id') ? (
              <Select
                label={t('items.form.category')}
                options={keep(
                  [{ value: '', label: t('items.form.noCategory') }, ...categoryOptions(categories.all, companyId)],
                  values.category_id,
                  categories.all.find((category) => category.id === values.category_id)?.name ?? t('items.form.unknownCategory'),
                )}
                value={values.category_id}
                onChange={set('category_id')}
                error={errors.fields.category_id}
              />
            ) : null}
            {shows('tax_category_id') && taxCategories.allowed ? (
              <Select
                label={t('items.form.taxCategory')}
                options={keep(
                  [
                    { value: '', label: t('items.form.noTaxCategory') },
                    ...taxCategories.all
                      .filter((category) => !category.archived_at && (category.company_id ?? null) === companyId)
                      .map((category) => ({ value: category.id, label: category.name })),
                  ],
                  values.tax_category_id,
                  taxCategories.all.find((category) => category.id === values.tax_category_id)?.name ?? t('items.form.unknownCategory'),
                )}
                value={values.tax_category_id}
                onChange={set('tax_category_id')}
                error={errors.fields.tax_category_id}
              />
            ) : null}
          </div>
        </Card>

        {shows('base_uom_id') ? (
          <Card title={t('items.form.units')} subtitle={t('items.form.unitsHelp')}>
            <div className="flex flex-col gap-4">
              <Select
                label={t('items.form.baseUnit')}
                help={!creating && item.base_uom_id !== baseUomId ? t('items.form.baseChanged') : t('items.form.baseUnitHelp')}
                className="max-w-field"
                options={keep(
                  uoms.active.map((uom) => ({ value: uom.id, label: uomLabel(uom) })),
                  baseUomId,
                  uomLabel(uoms.all.find((uom) => uom.id === baseUomId)) || t('items.form.unknownUnit'),
                )}
                placeholder={uoms.isPending ? t('common.loading') : t('items.form.chooseUnit')}
                value={baseUomId}
                onChange={(event) => {
                  const next = event.target.value
                  // The new base cannot also be another unit of the item.
                  setValues((current) => ({ ...current, base_uom_id: next, uoms: current.uoms.filter((row) => row.uom_id !== next) }))
                }}
                error={errors.fields.base_uom_id}
                required
              />
              {shows('uoms') ? (
                <>
                  {errors.fields.uoms ? <p className="text-caption text-danger">{errors.fields.uoms}</p> : null}
                  {values.uoms.length ? (
                    <ul aria-label={t('items.form.otherUnits')} className="flex flex-col divide-y divide-border border-y border-border">
                      {values.uoms.map((row, index) => {
                        const unit = uoms.all.find((uom) => uom.id === row.uom_id)
                        return (
                          <li key={row.key} className="flex flex-wrap items-end gap-3 py-3">
                            <Select
                              label={t('items.form.unit')}
                              className="min-w-0 grow basis-full sm:basis-0"
                              options={keep(unitChoices(row), row.uom_id, uomLabel(unit) || t('items.form.unknownUnit'))}
                              placeholder={t('items.form.chooseUnit')}
                              value={row.uom_id}
                              onChange={(event) => setRow('uoms', row.key, { uom_id: event.target.value })}
                              error={errors.fields[`uoms.${index}.uom_id`] ?? (submitted && !row.uom_id ? t('items.form.unitRequired') : undefined)}
                              required
                            />
                            <DecimalInput
                              label={t('items.form.factor', { base: baseUom?.code ?? '' })}
                              className="min-w-0 grow basis-full sm:basis-0"
                              value={row.factor}
                              onChange={(factor) => setRow('uoms', row.key, { factor })}
                              showErrors={submitted}
                              error={errors.fields[`uoms.${index}.factor`] ?? (submitted && row.factor === '' ? t('items.form.factorRequired') : undefined)}
                              required
                            />
                            <div className="flex flex-col gap-1 pb-2">
                              <Checkbox
                                label={t('items.form.salesDefault')}
                                checked={row.is_sales_default}
                                onChange={(event) => setDefault(row.key, 'is_sales_default', event.target.checked)}
                              />
                              <Checkbox
                                label={t('items.form.purchaseDefault')}
                                checked={row.is_purchase_default}
                                onChange={(event) => setDefault(row.key, 'is_purchase_default', event.target.checked)}
                              />
                            </div>
                            {readOnly ? null : (
                              <Button
                                variant="ghost"
                                icon="remove"
                                onClick={() => {
                                  removeRow('uoms', row.key)
                                  // Barcodes of a removed unit fall back to the base unit.
                                  setValues((current) => ({ ...current, barcodes: current.barcodes.map((barcode) => (barcode.uom_id === row.uom_id ? { ...barcode, uom_id: '' } : barcode)) }))
                                }}
                                aria-label={t('items.form.removeUnit', { unit: unit?.code ?? t('items.form.unitN', { n: index + 1 }) })}
                              >
                                {t('items.form.remove')}
                              </Button>
                            )}
                          </li>
                        )
                      })}
                    </ul>
                  ) : (
                    <p className="text-ink-muted">{t('items.form.noOtherUnits')}</p>
                  )}
                  {readOnly ? null : (
                    <div>
                      <Button
                        icon="plus"
                        onClick={() =>
                          setValues((current) => ({
                            ...current,
                            uoms: [...current.uoms, { key: nextKey(), uom_id: '', factor: '', is_sales_default: false, is_purchase_default: false }],
                          }))
                        }
                      >
                        {t('items.form.addUnit')}
                      </Button>
                    </div>
                  )}
                </>
              ) : null}
            </div>
          </Card>
        ) : null}

        {shows('barcodes') ? (
          <Card title={t('items.form.barcodes')} subtitle={t('items.form.barcodesHelp')}>
            <div className="flex flex-col gap-4">
              {errors.fields.barcodes ? <p className="text-caption text-danger">{errors.fields.barcodes}</p> : null}
              {values.barcodes.length ? (
                <ul aria-label={t('items.form.barcodes')} className="flex flex-col divide-y divide-border border-y border-border">
                  {values.barcodes.map((row, index) => (
                    <li key={row.key} className="flex flex-wrap items-end gap-3 py-3">
                      <TextField
                        label={t('items.form.barcode')}
                        className="min-w-0 grow basis-full sm:basis-0"
                        inputMode="numeric"
                        autoComplete="off"
                        value={row.barcode}
                        onChange={(event) => setRow('barcodes', row.key, { barcode: event.target.value })}
                        error={barcodeError(row)}
                      />
                      <Select
                        label={t('items.form.barcodeUnit')}
                        className="min-w-0 grow basis-full sm:basis-0"
                        options={barcodeUnits}
                        value={row.uom_id && row.uom_id !== baseUomId ? row.uom_id : ''}
                        onChange={(event) => setRow('barcodes', row.key, { uom_id: event.target.value })}
                      />
                      {readOnly ? null : (
                        <Button
                          variant="ghost"
                          icon="remove"
                          onClick={() => removeRow('barcodes', row.key)}
                          aria-label={t('items.form.removeBarcode', { barcode: row.barcode || t('items.form.barcodeN', { n: index + 1 }) })}
                        >
                          {t('items.form.remove')}
                        </Button>
                      )}
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="text-ink-muted">{t('items.form.noBarcodes')}</p>
              )}
              {readOnly ? null : (
                <div>
                  <Button icon="plus" onClick={() => setValues((current) => ({ ...current, barcodes: [...current.barcodes, { key: nextKey(), barcode: '', uom_id: '' }] }))}>
                    {t('items.form.addBarcode')}
                  </Button>
                </div>
              )}
            </div>
          </Card>
        ) : null}
      </fieldset>
      {readOnly ? null : (
        <div className="flex flex-wrap gap-2">
          <Button variant="primary" type="submit" loading={mutation.isPending}>
            {creating ? t('items.form.create') : t('common.save')}
          </Button>
        </div>
      )}
    </form>
  )
}

/** MD-02: a new item; once saved, its page (with any possible duplicates named there). */
export default function NewItem() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()

  return (
    <>
      <Link to="/catalogue/items" className="flex w-fit items-center gap-2 text-label text-primary hover:text-primary-hover">
        <Icon name="back" />
        {t('items.back')}
      </Link>
      <PageHeader title={t('items.newTitle')} description={t('items.newText')} />
      {can('core.item.create') ? (
        <ItemForm
          item={null}
          onSaved={(response) =>
            navigate(`/catalogue/items/${response.data.id}`, { state: { created: true, duplicates: response.meta?.possible_duplicates ?? [] } })
          }
        />
      ) : null}
    </>
  )
}
