import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, Select, TextField } from '@/components/ds'
import { useCompanySelection } from '@/layouts/companySelection'
import { PageHeader } from '@/layouts/PageHeader'
import { usePlaces } from '@/pages/inventory/inventoryData'
import { blankLine, LineEditor, lineFieldNames, linesReady, VersionFields, versionBody } from './BomLines'
import { Back, ItemPicker } from './ManufacturingParts'
import { BOM_KINDS, kindLabel, manufacturingKey } from './manufacturingData'

/**
 * MFG-02 to MFG-04: a new BOM or recipe: its company, output item and
 * kind, a name, and its first version (draft): what one batch yields,
 * labour minutes and the lines. Publishing stays a separate act.
 */
export default function BomForm() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const places = usePlaces()
  const { company: switcherCompany } = useCompanySelection()
  const companies = places.companies.filter((company) => !company.archived_at)
  const [chosenCompany, setCompany] = useState('')
  const company = chosenCompany || switcherCompany?.id || (companies.length === 1 ? companies[0].id : '')
  const [kind, setKind] = useState('production')
  const [item, setItem] = useState(null)
  const [name, setName] = useState('')
  const [version, setVersion] = useState({ output_qty: '1', output_uom_id: '', labour_minutes: '', note: '' })
  const [lines, setLines] = useState(() => [blankLine()])
  const [submitted, setSubmitted] = useState(false)

  const save = useMutation({
    mutationFn: () =>
      api.post('manufacturing/boms', {
        company_id: company,
        item_id: item.id,
        kind,
        ...(name.trim() ? { name: name.trim() } : {}),
        version: versionBody(version, lines),
      }),
    onSuccess: async (response) => {
      toast.success(t('manufacturing.boms.created'))
      await queryClient.invalidateQueries({ queryKey: manufacturingKey })
      const draft = response?.data?.draft_id
      navigate(draft ? `/manufacturing/bom-versions/${draft}` : `/manufacturing/boms/${response.data.id}`)
    },
  })
  const versionFields = ['output_qty', 'output_uom_id', 'labour_minutes', 'note', 'lines'].map((field) => `version.${field}`)
  const errors = formErrors(save.error, ['company_id', 'item_id', 'kind', 'name', ...versionFields, ...lineFieldNames('version.', lines.length)])
  const ready = company && item && version.output_qty && lines.length && linesReady(lines)

  const submit = (event) => {
    event.preventDefault()
    setSubmitted(true)
    if (ready) save.mutate()
  }

  return (
    <>
      <Back to="/manufacturing/boms">{t('manufacturing.boms.back')}</Back>
      <PageHeader title={t('manufacturing.boms.newTitle')} description={t('manufacturing.boms.newHelp')} />
      <form noValidate onSubmit={submit} className="flex flex-col gap-5">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <Card>
          <div className="grid gap-4 sm:grid-cols-2">
            <Select
              label={t('manufacturing.fields.company')}
              placeholder={t('manufacturing.pickers.company')}
              options={companies.map((entry) => ({ value: entry.id, label: entry.name }))}
              value={company}
              required
              error={errors.fields.company_id ?? (submitted && !company ? t('manufacturing.validation.company') : undefined)}
              onChange={(event) => setCompany(event.target.value)}
            />
            <Select
              label={t('manufacturing.fields.kind')}
              options={BOM_KINDS.map((value) => ({ value, label: kindLabel(t, value) }))}
              value={kind}
              help={t(`manufacturing.boms.kindHelp.${kind}`)}
              error={errors.fields.kind}
              onChange={(event) => {
                setKind(event.target.value)
                setItem(null)
              }}
            />
            <ItemPicker
              label={t('manufacturing.fields.outputItem')}
              placeholder={t('manufacturing.pickers.item')}
              type={kind === 'production' ? 'stock' : null}
              value={item}
              required
              error={errors.fields.item_id ?? (submitted && !item ? t('manufacturing.validation.item') : undefined)}
              onChange={(next) => {
                setItem(next)
                setVersion((current) => ({ ...current, output_uom_id: next?.base_uom_id ?? '' }))
              }}
            />
            <TextField label={t('manufacturing.fields.name')} value={name} maxLength={160} error={errors.fields.name} onChange={(event) => setName(event.target.value)} />
          </div>
        </Card>
        <Card title={t('manufacturing.versions.first')} subtitle={t('manufacturing.versions.firstHelp')}>
          <VersionFields item={item} values={version} onChange={setVersion} submitted={submitted} errors={errors.fields} prefix="version." />
        </Card>
        <LineEditor lines={lines} onChange={setLines} submitted={submitted} errors={errors.fields} prefix="version." />
        {submitted && !lines.length ? <Alert tone="danger" title={t('manufacturing.validation.lines')} /> : null}
        <div className="flex flex-wrap gap-2">
          <Button variant="ghost" onClick={() => navigate('/manufacturing/boms')}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" variant="primary" loading={save.isPending}>
            {t('manufacturing.boms.create')}
          </Button>
        </div>
      </form>
    </>
  )
}
