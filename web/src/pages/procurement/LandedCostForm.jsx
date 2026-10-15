import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, MoneyInput, MultiSelect, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { todayIn } from '@/lib/dates'
import { Back, DetailState } from './ProcurementParts'
import { companyById, companyOfBranch, LANDED_COST_METHODS, namedOptions, procurementKey, statusLabel, useCurrencies, usePlaces, useSuppliers } from './procurementData'

let nextCharge = 0
const blankCharge = (extra = {}) => ({ key: `charge-${(nextCharge += 1)}`, description: '', party_id: '', bill_reference: '', currency: '', amount_minor: '', ...extra })

/** PRC-17: the draft or posted GRNs of a company a landed cost may apply to. */
function useReceiptsOf(companyId) {
  const query = useQuery({
    queryKey: [...procurementKey, 'receipts', 'for-landed-cost', companyId],
    queryFn: () => api.get(`procurement/receipts?company=${companyId}&status=all&per_page=100&sort=-created_at`),
    enabled: Boolean(companyId),
  })
  return (query.data?.data ?? []).filter((receipt) => receipt.status === 'draft' || receipt.status === 'posted')
}

function Form({ landedCost }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const places = usePlaces()
  const suppliers = useSuppliers()
  const editing = Boolean(landedCost)
  const [branch, setBranch] = useState(landedCost?.branch?.id ?? '')
  const companyId = companyOfBranch(places, branch) ?? landedCost?.company?.id ?? null
  const currencies = useCurrencies(companyId)
  const receipts = useReceiptsOf(companyId)
  const [costDate, setCostDate] = useState(landedCost?.cost_date ?? '')
  const [method, setMethod] = useState(landedCost?.method ?? 'value')
  const [description, setDescription] = useState(landedCost?.description ?? '')
  const [charges, setCharges] = useState(() =>
    landedCost?.charges?.length
      ? landedCost.charges.map((charge) => blankCharge({ description: charge.description, party_id: charge.party?.id ?? '', bill_reference: charge.bill_reference ?? '', currency: charge.amount?.currency ?? '', amount_minor: charge.amount?.amount_minor ?? '' }))
      : [blankCharge()],
  )
  const [receiptIds, setReceiptIds] = useState(() => (landedCost?.receipts ?? []).map((receipt) => receipt.id))
  const [submitted, setSubmitted] = useState(false)
  const shownDate = costDate || todayIn(companyById(places, companyId)?.timezone)
  const updateCharge = (key, patch) => setCharges((current) => current.map((charge) => (charge.key === key ? { ...charge, ...patch } : charge)))

  const save = useMutation({
    mutationFn: () => {
      const body = {
        cost_date: shownDate,
        method,
        description: description.trim() || null,
        charges: charges.map((charge) => ({
          description: charge.description.trim(),
          ...(charge.party_id ? { party_id: charge.party_id } : {}),
          ...(charge.bill_reference.trim() ? { bill_reference: charge.bill_reference.trim() } : {}),
          currency: charge.currency || currencies.base,
          amount_minor: charge.amount_minor,
        })),
        receipt_ids: receiptIds,
      }
      return editing ? api.patch(`procurement/landed-costs/${landedCost.id}`, body) : api.post('procurement/landed-costs', { branch_id: branch, ...body })
    },
    onSuccess: async (response) => {
      toast.success(t(editing ? 'procurement.landedCost.saved' : 'procurement.landedCost.created'))
      await queryClient.invalidateQueries({ queryKey: procurementKey })
      navigate(`/procurement/landed-costs/${response.data.id}`)
    },
  })
  const chargeFields = charges.flatMap((_, index) => ['description', 'party_id', 'bill_reference', 'currency', 'amount_minor'].map((name) => `charges.${index}.${name}`))
  const errors = formErrors(save.error, ['branch_id', 'cost_date', 'method', 'description', 'charges', 'receipt_ids', ...chargeFields])
  const chargesReady = charges.every((charge) => charge.description.trim() && charge.amount_minor && charge.amount_minor !== '0')
  const ready = branch && chargesReady && receiptIds.length > 0
  const submit = (event) => {
    event.preventDefault()
    setSubmitted(true)
    if (ready) save.mutate()
  }
  const receiptOptions = receipts.map((receipt) => ({
    value: receipt.id,
    label: [receipt.number ?? t('procurement.draftNumber'), receipt.party?.name, receipt.order?.number, statusLabel(t, receipt.status)].filter(Boolean).join(' · '),
  }))

  return (
    <form noValidate onSubmit={submit} className="flex flex-col gap-5">
      {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
      <Card>
        <div className="grid gap-4 sm:grid-cols-2">
          <Select
            label={t('procurement.fields.branch')}
            placeholder={t('procurement.pickers.branch')}
            options={namedOptions(places.activeBranches)}
            value={branch}
            required
            disabled={editing}
            error={errors.fields.branch_id ?? (submitted && !branch ? t('procurement.validation.branch') : undefined)}
            onChange={(event) => {
              setBranch(event.target.value)
              setReceiptIds([])
            }}
          />
          <TextField type="date" label={t('procurement.fields.costDate')} help={t('procurement.landedCost.dateHelp')} value={shownDate} required error={errors.fields.cost_date} onChange={(event) => setCostDate(event.target.value)} />
          <Select
            label={t('procurement.fields.method')}
            options={LANDED_COST_METHODS.map((value) => ({ value, label: t(`procurement.methods.${value}`) }))}
            value={method}
            help={t(`procurement.landedCost.methodHelp.${method}`)}
            error={errors.fields.method}
            onChange={(event) => setMethod(event.target.value)}
          />
          <TextField label={t('procurement.fields.description')} value={description} maxLength={200} error={errors.fields.description} onChange={(event) => setDescription(event.target.value)} />
          <MultiSelect
            label={t('procurement.fields.receipts')}
            placeholder={branch ? t('procurement.pickers.receipts') : t('procurement.pickers.branchFirst')}
            options={receiptOptions}
            value={receiptIds}
            required
            disabled={!branch}
            error={errors.fields.receipt_ids ?? (submitted && !receiptIds.length ? t('procurement.validation.receipts') : undefined)}
            onChange={setReceiptIds}
            className="sm:col-span-2"
          />
        </div>
      </Card>
      <section aria-label={t('procurement.landedCost.charges')} className="flex flex-col gap-3">
        <h2 className="text-h3 text-ink">{t('procurement.landedCost.charges')}</h2>
        {errors.fields.charges ? <Alert tone="danger" title={errors.fields.charges} /> : null}
        {charges.map((charge, index) => {
          const currency = charge.currency || currencies.base
          return (
            <fieldset key={charge.key} className="grid gap-3 rounded-md border border-border p-4 sm:grid-cols-2 lg:grid-cols-4">
              <legend className="px-1 text-label text-ink">{t('procurement.landedCost.charge', { number: index + 1 })}</legend>
              <TextField
                label={t('procurement.fields.chargeDescription')}
                help={t('procurement.landedCost.chargeExamples')}
                value={charge.description}
                maxLength={200}
                required
                error={errors.fields[`charges.${index}.description`] ?? (submitted && !charge.description.trim() ? t('procurement.validation.chargeDescription') : undefined)}
                onChange={(event) => updateCharge(charge.key, { description: event.target.value })}
              />
              <Select
                label={t('procurement.fields.chargeSupplier')}
                options={[{ value: '', label: t('procurement.pickers.none') }, ...namedOptions(suppliers.active)]}
                value={charge.party_id}
                error={errors.fields[`charges.${index}.party_id`]}
                onChange={(event) => updateCharge(charge.key, { party_id: event.target.value })}
              />
              <TextField
                label={t('procurement.fields.billReference')}
                help={t('procurement.landedCost.billReferenceHelp')}
                value={charge.bill_reference}
                maxLength={100}
                error={errors.fields[`charges.${index}.bill_reference`]}
                onChange={(event) => updateCharge(charge.key, { bill_reference: event.target.value })}
              />
              <Select label={t('procurement.fields.currency')} options={currencies.options} value={currency} error={errors.fields[`charges.${index}.currency`]} onChange={(event) => updateCharge(charge.key, { currency: event.target.value, amount_minor: '' })} />
              <MoneyInput
                key={currency}
                label={t('procurement.fields.amount')}
                currency={currency}
                value={charge.amount_minor}
                required
                showErrors={submitted}
                error={errors.fields[`charges.${index}.amount_minor`] ?? (submitted && !charge.amount_minor ? t('procurement.validation.amount') : undefined)}
                onChange={(value) => updateCharge(charge.key, { amount_minor: value ?? '' })}
              />
              {charges.length > 1 ? (
                <div className="sm:col-span-2 lg:col-span-4">
                  <Button variant="ghost" icon="remove" aria-label={t('procurement.landedCost.removeCharge', { number: index + 1 })} onClick={() => setCharges((current) => current.filter((entry) => entry.key !== charge.key))}>
                    {t('procurement.lines.removeShort')}
                  </Button>
                </div>
              ) : null}
            </fieldset>
          )
        })}
        <div>
          <Button icon="plus" onClick={() => setCharges((current) => [...current, blankCharge()])}>
            {t('procurement.landedCost.addCharge')}
          </Button>
        </div>
      </section>
      <div className="flex flex-wrap gap-2">
        <Button variant="ghost" onClick={() => navigate(editing ? `/procurement/landed-costs/${landedCost.id}` : '/procurement/landed-costs')}>
          {t('common.cancel')}
        </Button>
        <Button type="submit" variant="primary" loading={save.isPending}>
          {t('procurement.landedCost.save')}
        </Button>
      </div>
    </form>
  )
}

/**
 * PRC-17: a new landed cost or a draft's changes: branch, date, the
 * allocation method (value, quantity or weight), description, charges
 * (description, optional supplier, currency and amount) and the GRNs of
 * the company it applies to. It is posted from its page.
 */
export default function LandedCostForm() {
  const { t } = useTranslation()
  const { landedCostId } = useParams()
  const query = useQuery({ queryKey: [...procurementKey, 'landed-cost', landedCostId], queryFn: () => api.get(`procurement/landed-costs/${landedCostId}`), enabled: Boolean(landedCostId) })
  const back = <Back to={landedCostId ? `/procurement/landed-costs/${landedCostId}` : '/procurement/landed-costs'}>{t('procurement.landedCosts.back')}</Back>
  if (landedCostId && !query.data?.data) return <DetailState query={query} back={back} notFound={t('procurement.landedCost.notFound')} />
  return (
    <>
      {back}
      <PageHeader title={t(landedCostId ? 'procurement.landedCost.editTitle' : 'procurement.landedCost.newTitle')} description={t('procurement.landedCost.formHelp')} />
      <Form key={landedCostId ?? 'new'} landedCost={query.data?.data} />
    </>
  )
}
