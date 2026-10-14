import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams, useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, DecimalInput, MultiSelect, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { Back, DetailState, LinesEditor, TextAreaField } from './ProcurementParts'
import { blankLine, lineBody, lineFieldNames, lineFromRecord, lineFromRequisition, lineReady } from './procurementHelpers'
import { companyById, companyOfBranch, fromLocalInput, namedOptions, procurementKey, TENDER_KINDS, toLocalInput, useCurrencies, usePlaces, useSourceRequisition, useUsers } from './procurementData'

const FIELDS = ['branch_id', 'requisition_id', 'title', 'description', 'kind', 'currency', 'deadline_at', 'opening_at', 'technical_weight', 'pass_mark', 'lines', 'criteria', 'openers', 'evaluators']

let nextCriterion = 0
const blankCriterion = (extra = {}) => ({ key: `criterion-${(nextCriterion += 1)}`, name: '', weight: '', max_score: '10', ...extra })

function Form({ tender, requisition }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const places = usePlaces()
  const users = useUsers()
  const editing = Boolean(tender)
  const [branch, setBranch] = useState(tender?.branch?.id ?? requisition?.branch?.id ?? '')
  const companyId = companyOfBranch(places, branch)
  const zone = companyById(places, companyId)?.timezone
  const currencies = useCurrencies(companyId)
  const [title, setTitle] = useState(tender?.title ?? requisition?.purpose ?? '')
  const [description, setDescription] = useState(tender?.description ?? '')
  const [kind, setKind] = useState(tender?.kind ?? 'invited')
  const [currency, setCurrency] = useState(tender?.currency ?? requisition?.currency ?? '')
  const [deadline, setDeadline] = useState(() => toLocalInput(tender?.deadline_at, zone))
  const [opening, setOpening] = useState(() => toLocalInput(tender?.opening_at, zone))
  const [technicalWeight, setTechnicalWeight] = useState(tender ? String(tender.technical_weight) : '70')
  const [passMark, setPassMark] = useState(tender?.pass_mark ?? '60')
  const [criteria, setCriteria] = useState(() =>
    tender?.criteria?.length ? tender.criteria.map((criterion) => blankCriterion({ name: criterion.name, weight: String(criterion.weight), max_score: String(criterion.max_score) })) : [blankCriterion()],
  )
  const [openers, setOpeners] = useState(() => (tender?.openers ?? []).map((user) => user.id))
  const [evaluators, setEvaluators] = useState(() => (tender?.evaluators ?? []).map((user) => user.id))
  const [lines, setLines] = useState(() => {
    if (tender?.lines?.length) return tender.lines.map((line) => lineFromRecord(line, null))
    if (requisition?.lines?.length) return requisition.lines.map(lineFromRequisition)
    return [blankLine()]
  })
  const [submitted, setSubmitted] = useState(false)
  const shownCurrency = currency || currencies.base
  const weightTotal = criteria.reduce((sum, criterion) => sum + (Number.parseInt(criterion.weight, 10) || 0), 0)
  const financialWeight = 100 - (Number.parseInt(technicalWeight, 10) || 0)
  const filledCriteria = criteria.filter((criterion) => criterion.name.trim())

  const save = useMutation({
    mutationFn: () => {
      const body = {
        title: title.trim(),
        description: description.trim() || null,
        kind,
        currency: shownCurrency,
        deadline_at: fromLocalInput(deadline, zone),
        opening_at: fromLocalInput(opening, zone),
        technical_weight: Number.parseInt(technicalWeight, 10) || 0,
        pass_mark: passMark || '0',
        lines: lines.map((line) => lineBody(line)),
        criteria: filledCriteria.map((criterion) => ({ name: criterion.name.trim(), weight: Number.parseInt(criterion.weight, 10) || 0, max_score: Number.parseInt(criterion.max_score, 10) || 10 })),
        openers,
        evaluators,
      }
      if (editing) return api.patch(`procurement/tenders/${tender.id}`, body)
      return api.post('procurement/tenders', { branch_id: branch, ...(requisition ? { requisition_id: requisition.id } : {}), ...body })
    },
    onSuccess: async (response) => {
      toast.success(t(editing ? 'procurement.tender.saved' : 'procurement.tender.created'))
      await queryClient.invalidateQueries({ queryKey: procurementKey })
      navigate(`/procurement/tenders/${response.data.id}`)
    },
  })
  const criteriaFields = criteria.flatMap((_, index) => ['name', 'weight', 'max_score'].map((name) => `criteria.${index}.${name}`))
  const errors = formErrors(save.error, [...FIELDS, ...criteriaFields, ...lineFieldNames(lines.length)])
  const weightsOk = filledCriteria.length === 0 || weightTotal === 100
  const ready = branch && title.trim() && deadline && opening && weightsOk && lines.every((line) => lineReady(line))
  const submit = (event) => {
    event.preventDefault()
    setSubmitted(true)
    if (ready) save.mutate()
  }
  const userOptions = namedOptions(users)
  const updateCriterion = (key, patch) => setCriteria((current) => current.map((criterion) => (criterion.key === key ? { ...criterion, ...patch } : criterion)))

  return (
    <form noValidate onSubmit={submit} className="flex flex-col gap-5">
      {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
      {requisition ? <Alert tone="info" title={t('procurement.fromRequisition', { number: requisition.number })} /> : null}
      <Card>
        <div className="grid gap-4 sm:grid-cols-2">
          <Select
            label={t('procurement.fields.branch')}
            placeholder={t('procurement.pickers.branch')}
            options={namedOptions(places.activeBranches)}
            value={branch}
            required
            disabled={editing || Boolean(requisition)}
            error={errors.fields.branch_id ?? (submitted && !branch ? t('procurement.validation.branch') : undefined)}
            onChange={(event) => setBranch(event.target.value)}
          />
          <TextField
            label={t('procurement.fields.title')}
            value={title}
            maxLength={200}
            required
            error={errors.fields.title ?? (submitted && !title.trim() ? t('procurement.validation.title') : undefined)}
            onChange={(event) => setTitle(event.target.value)}
          />
          <Select
            label={t('procurement.fields.kind')}
            options={TENDER_KINDS.map((value) => ({ value, label: t(`procurement.tenderKinds.${value}`) }))}
            value={kind}
            help={t(`procurement.tender.kindHelp.${kind}`)}
            error={errors.fields.kind}
            onChange={(event) => setKind(event.target.value)}
          />
          <Select label={t('procurement.fields.currency')} options={currencies.options} value={shownCurrency} error={errors.fields.currency} onChange={(event) => setCurrency(event.target.value)} />
          <TextField
            type="datetime-local"
            label={t('procurement.fields.deadline')}
            value={deadline}
            required
            error={errors.fields.deadline_at ?? (submitted && !deadline ? t('procurement.validation.deadline') : undefined)}
            onChange={(event) => setDeadline(event.target.value)}
          />
          <TextField
            type="datetime-local"
            label={t('procurement.fields.opening')}
            help={t('procurement.tender.openingHelp')}
            value={opening}
            required
            error={errors.fields.opening_at ?? (submitted && !opening ? t('procurement.validation.opening') : undefined)}
            onChange={(event) => setOpening(event.target.value)}
          />
          <DecimalInput
            label={t('procurement.fields.technicalWeight')}
            help={t('procurement.tender.financialWeight', { weight: financialWeight })}
            suffix="%"
            maxDecimals={0}
            maxIntegerDigits={3}
            positive={false}
            value={technicalWeight}
            error={errors.fields.technical_weight}
            onChange={(value) => setTechnicalWeight(value ?? '')}
          />
          <DecimalInput
            label={t('procurement.fields.passMark')}
            help={t('procurement.tender.passMarkHelp')}
            maxDecimals={2}
            maxIntegerDigits={3}
            positive={false}
            value={passMark}
            error={errors.fields.pass_mark}
            onChange={(value) => setPassMark(value ?? '')}
          />
          <TextAreaField label={t('procurement.fields.description')} value={description} maxLength={5000} error={errors.fields.description} onChange={(event) => setDescription(event.target.value)} className="sm:col-span-2" />
        </div>
      </Card>
      <LinesEditor lines={lines} setLines={setLines} companyId={companyId} errors={errors} submitted={submitted} />
      <section aria-label={t('procurement.tender.criteria')} className="flex flex-col gap-3">
        <h2 className="text-h3 text-ink">{t('procurement.tender.criteria')}</h2>
        <p className="text-body text-ink-muted">{t('procurement.tender.criteriaHelp', { total: weightTotal })}</p>
        {errors.fields.criteria || (submitted && !weightsOk) ? <Alert tone="danger" title={errors.fields.criteria ?? t('procurement.validation.criteriaWeights', { total: weightTotal })} /> : null}
        {criteria.map((criterion, index) => (
          <fieldset key={criterion.key} className="grid gap-3 rounded-md border border-border p-4 sm:grid-cols-4">
            <legend className="px-1 text-label text-ink">{t('procurement.tender.criterion', { number: index + 1 })}</legend>
            <TextField label={t('procurement.fields.criterionName')} value={criterion.name} maxLength={200} error={errors.fields[`criteria.${index}.name`]} onChange={(event) => updateCriterion(criterion.key, { name: event.target.value })} className="sm:col-span-2" />
            <DecimalInput label={t('procurement.fields.weight')} suffix="%" maxDecimals={0} maxIntegerDigits={3} value={criterion.weight} error={errors.fields[`criteria.${index}.weight`]} onChange={(value) => updateCriterion(criterion.key, { weight: value ?? '' })} />
            <DecimalInput label={t('procurement.fields.maxScore')} maxDecimals={0} maxIntegerDigits={4} value={criterion.max_score} error={errors.fields[`criteria.${index}.max_score`]} onChange={(value) => updateCriterion(criterion.key, { max_score: value ?? '' })} />
            {criteria.length > 1 ? (
              <div className="sm:col-span-4">
                <Button variant="ghost" icon="remove" aria-label={t('procurement.tender.removeCriterion', { number: index + 1 })} onClick={() => setCriteria((current) => current.filter((entry) => entry.key !== criterion.key))}>
                  {t('procurement.lines.removeShort')}
                </Button>
              </div>
            ) : null}
          </fieldset>
        ))}
        <div>
          <Button icon="plus" onClick={() => setCriteria((current) => [...current, blankCriterion()])}>
            {t('procurement.tender.addCriterion')}
          </Button>
        </div>
      </section>
      <Card title={t('procurement.tender.committee')} subtitle={t('procurement.tender.committeeHelp')}>
        <div className="grid gap-4 sm:grid-cols-2">
          <MultiSelect label={t('procurement.fields.openers')} placeholder={t('procurement.pickers.users')} options={userOptions} value={openers} error={errors.fields.openers} onChange={setOpeners} />
          <MultiSelect label={t('procurement.fields.evaluators')} placeholder={t('procurement.pickers.users')} options={userOptions} value={evaluators} error={errors.fields.evaluators} onChange={setEvaluators} />
        </div>
      </Card>
      <div className="flex flex-wrap gap-2">
        <Button variant="ghost" onClick={() => navigate(editing ? `/procurement/tenders/${tender.id}` : '/procurement/tenders')}>
          {t('common.cancel')}
        </Button>
        <Button type="submit" variant="primary" loading={save.isPending}>
          {t('procurement.tender.save')}
        </Button>
      </div>
    </form>
  )
}

/**
 * PRC-06: a new tender (blank, or from an approved requisition) or a
 * draft's changes: branch, title, description, kind (open or invited),
 * currency, submission deadline and opening time, the technical weight
 * (the financial weight is the rest) and pass mark, lines, technical
 * criteria with weights summing 100, and the committee (openers and
 * evaluators). It is published from its page.
 */
export default function TenderForm() {
  const { t } = useTranslation()
  const { tenderId } = useParams()
  const [params] = useSearchParams()
  const source = useSourceRequisition(tenderId ? null : params.get('requisition'))
  const query = useQuery({ queryKey: [...procurementKey, 'tender', tenderId], queryFn: () => api.get(`procurement/tenders/${tenderId}`), enabled: Boolean(tenderId) })
  const back = <Back to={tenderId ? `/procurement/tenders/${tenderId}` : '/procurement/tenders'}>{t('procurement.tenders.back')}</Back>
  if (tenderId && !query.data?.data) return <DetailState query={query} back={back} notFound={t('procurement.tender.notFound')} />
  if (source.waiting) return <p className="text-ink-muted">{t('common.loading')}</p>
  return (
    <>
      {back}
      <PageHeader title={t(tenderId ? 'procurement.tender.editTitle' : 'procurement.tender.newTitle')} description={t('procurement.tender.formHelp')} />
      <Form key={tenderId ?? source.requisition?.id ?? 'new'} tender={query.data?.data} requisition={source.requisition} />
    </>
  )
}
