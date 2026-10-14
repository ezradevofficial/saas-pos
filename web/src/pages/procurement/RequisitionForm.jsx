import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { Back, DetailState, LinesEditor } from './ProcurementParts'
import { blankLine, clearPrices, lineBody, lineFieldNames, lineFromRecord, lineReady } from './procurementHelpers'
import { companyOfBranch, locationsOf, namedOptions, procurementKey, useCurrencies, useDimension, usePlaces } from './procurementData'

const FIELDS = ['branch_id', 'location_id', 'department_id', 'purpose', 'needed_by', 'currency', 'lines']

function Form({ requisition }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const places = usePlaces()
  const editing = Boolean(requisition)
  const [branch, setBranch] = useState(requisition?.branch?.id ?? '')
  const [location, setLocation] = useState(requisition?.location?.id ?? '')
  const [department, setDepartment] = useState(requisition?.department?.id ?? '')
  const [purpose, setPurpose] = useState(requisition?.purpose ?? '')
  const [neededBy, setNeededBy] = useState(requisition?.needed_by ?? '')
  const [currency, setCurrency] = useState(requisition?.currency ?? '')
  const [lines, setLines] = useState(() => (requisition?.lines?.length ? requisition.lines.map((line) => lineFromRecord(line, 'estimated_unit_price')) : [blankLine()]))
  const [submitted, setSubmitted] = useState(false)
  const companyId = companyOfBranch(places, branch)
  const currencies = useCurrencies(companyId)
  const departments = useDimension(companyId, 'departments')
  const shownCurrency = currency || currencies.base

  const save = useMutation({
    mutationFn: () => {
      const body = {
        location_id: location || null,
        department_id: department || null,
        purpose: purpose.trim() || null,
        needed_by: neededBy || null,
        currency: shownCurrency,
        lines: lines.map((line) => lineBody(line, { priceKey: 'estimated_unit_price_minor', dimensions: true })),
      }
      return editing ? api.patch(`procurement/requisitions/${requisition.id}`, body) : api.post('procurement/requisitions', { branch_id: branch, ...body })
    },
    onSuccess: async (response) => {
      toast.success(t(editing ? 'procurement.requisition.saved' : 'procurement.requisition.created'))
      await queryClient.invalidateQueries({ queryKey: procurementKey })
      navigate(`/procurement/requisitions/${response.data.id}`)
    },
  })
  const errors = formErrors(save.error, [...FIELDS, ...lineFieldNames(lines.length)])
  const ready = branch && lines.every((line) => lineReady(line))
  const submit = (event) => {
    event.preventDefault()
    setSubmitted(true)
    if (ready) save.mutate()
  }

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
              setLocation('')
              setDepartment('')
            }}
          />
          <Select
            label={t('procurement.fields.location')}
            options={[{ value: '', label: t('procurement.pickers.none') }, ...namedOptions(locationsOf(places, { branchId: branch }))]}
            value={location}
            disabled={!branch}
            error={errors.fields.location_id}
            onChange={(event) => setLocation(event.target.value)}
          />
          <Select
            label={t('procurement.fields.department')}
            options={[{ value: '', label: t('procurement.pickers.none') }, ...namedOptions(departments)]}
            value={department}
            disabled={!branch}
            error={errors.fields.department_id}
            onChange={(event) => setDepartment(event.target.value)}
          />
          <TextField type="date" label={t('procurement.fields.neededBy')} value={neededBy} error={errors.fields.needed_by} onChange={(event) => setNeededBy(event.target.value)} />
          <Select label={t('procurement.fields.currency')} options={currencies.options} value={shownCurrency} error={errors.fields.currency} onChange={(event) => {
              if (event.target.value !== shownCurrency) setLines(clearPrices)
              setCurrency(event.target.value)
            }}
          />
          <TextField label={t('procurement.fields.purpose')} value={purpose} maxLength={1000} error={errors.fields.purpose} onChange={(event) => setPurpose(event.target.value)} className="sm:col-span-2" />
        </div>
      </Card>
      <LinesEditor lines={lines} setLines={setLines} currency={shownCurrency} price="estimated" dimensions companyId={companyId} errors={errors} submitted={submitted} />
      <div className="flex flex-wrap gap-2">
        <Button variant="ghost" onClick={() => navigate(editing ? `/procurement/requisitions/${requisition.id}` : '/procurement/requisitions')}>
          {t('common.cancel')}
        </Button>
        <Button type="submit" variant="primary" loading={save.isPending}>
          {t('procurement.requisition.save')}
        </Button>
      </div>
    </form>
  )
}

/**
 * PRC-02: a new requisition, or a draft's changes: branch (optionally a
 * location and department), purpose, needed-by date, currency and lines
 * with an item or description, unit, quantity, estimated unit price, cost
 * centre and project. Saved as a draft; it is submitted from its page.
 */
export default function RequisitionForm() {
  const { t } = useTranslation()
  const { requisitionId } = useParams()
  const query = useQuery({ queryKey: [...procurementKey, 'requisition', requisitionId], queryFn: () => api.get(`procurement/requisitions/${requisitionId}`), enabled: Boolean(requisitionId) })
  const back = <Back to={requisitionId ? `/procurement/requisitions/${requisitionId}` : '/procurement/requisitions'}>{t('procurement.requisitions.back')}</Back>
  if (requisitionId && !query.data?.data) return <DetailState query={query} back={back} notFound={t('procurement.requisition.notFound')} />
  return (
    <>
      {back}
      <PageHeader title={t(requisitionId ? 'procurement.requisition.editTitle' : 'procurement.requisition.newTitle')} description={t('procurement.requisition.formHelp')} />
      <Form key={requisitionId ?? 'new'} requisition={query.data?.data} />
    </>
  )
}
