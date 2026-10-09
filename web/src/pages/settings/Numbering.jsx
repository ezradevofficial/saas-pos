import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Dialog, Select, Switch, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useErrorFocus } from '@/lib/useErrorFocus'
import { examplePattern, patternProblem, tokensFor } from './numbering/pattern'

const RESETS = ['never', 'yearly']
const FORMATS_KEY = ['numbering-formats']

/** Where a format applies, in words: the organisation, a company, or a branch of it. */
function scopeName(t, format, companies, branches) {
  if (format.branch_id) {
    const branch = branches.find((entry) => entry.id === format.branch_id)
    return t('numbering.scope.branch', { name: branch?.name ?? '' })
  }
  if (format.company_id) return t('numbering.scope.company', { name: companies.find((entry) => entry.id === format.company_id)?.name ?? '' })
  return t('numbering.scope.tenant')
}

/**
 * Set a type's format at a scope (NUM-01): the pattern with token chips
 * that insert at the cursor, a live example, when it restarts, and "no
 * gaps" where the type allows it (never for till ranges, NUM-02). The API's
 * refusals (a pattern another format prints, a number too long, a restart
 * locked by issued numbers) show on their field.
 */
function FormatDialog({ type, format, companies, branches, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const formId = useId()
  const formRef = useRef(null)
  const alertRef = useRef(null)
  const patternRef = useRef(null)
  const editing = Boolean(format?.id)
  const [values, setValues] = useState(() => ({
    company_id: format?.company_id ?? '',
    branch_id: format?.branch_id ?? '',
    pattern: format?.pattern ?? type.default.pattern,
    reset: format?.reset ?? type.default.reset,
    gapless: Boolean(format?.gapless),
  }))
  const set = (name, value) => setValues((current) => ({ ...current, [name]: value }))

  const save = useMutation({
    mutationFn: () =>
      api.put('numbering/formats', {
        document_type: type.document_type,
        company_id: values.company_id || null,
        branch_id: values.branch_id || null,
        pattern: values.pattern.trim(),
        reset: values.reset,
        gapless: type.ranged ? false : values.gapless,
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: FORMATS_KEY })
      onClose()
    },
  })
  const errors = formErrors(save.error, ['pattern', 'reset', 'gapless', 'company_id', 'branch_id'])
  useErrorFocus(formRef, alertRef, save.error)

  const problem = patternProblem(values.pattern.trim(), type.place_tokens, values.reset)
  const branch = branches.find((entry) => entry.id === values.branch_id)
  const example = problem ? null : examplePattern(values.pattern.trim(), { codes: { BRANCH: branch?.code } })

  const insert = (token) => {
    const input = patternRef.current
    const start = input?.selectionStart ?? values.pattern.length
    const end = input?.selectionEnd ?? values.pattern.length
    set('pattern', `${values.pattern.slice(0, start)}${token}${values.pattern.slice(end)}`)
    requestAnimationFrame(() => {
      input?.focus()
      input?.setSelectionRange(start + token.length, start + token.length)
    })
  }

  return (
    <Dialog
      open
      size="lg"
      title={editing ? t('numbering.editTitle', { name: type.name, scope: scopeName(t, format, companies, branches) }) : t('numbering.addTitle', { name: type.name })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" type="submit" form={formId} loading={save.isPending}>
            {t('numbering.save')}
          </Button>
        </>
      }
    >
      <form
        id={formId}
        ref={formRef}
        noValidate
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
        className="flex flex-col gap-4 pt-1"
      >
        {errors.form ? (
          <div ref={alertRef} tabIndex={-1} className="rounded-md">
            <Alert tone="danger" title={errorMessage(save.error)} />
          </div>
        ) : null}
        {editing ? null : (
          <div className="grid gap-4 sm:grid-cols-2">
            <Select
              label={t('numbering.fields.company')}
              options={[{ value: '', label: t('numbering.scope.tenant') }, ...companies.map((entry) => ({ value: entry.id, label: entry.name }))]}
              value={values.company_id}
              onChange={(event) => setValues((current) => ({ ...current, company_id: event.target.value, branch_id: '' }))}
              error={errors.fields.company_id}
            />
            <Select
              label={t('numbering.fields.branch')}
              options={[
                { value: '', label: t('numbering.allBranches') },
                ...branches.filter((entry) => entry.company_id === values.company_id).map((entry) => ({ value: entry.id, label: entry.name })),
              ]}
              value={values.branch_id}
              onChange={(event) => set('branch_id', event.target.value)}
              disabled={!values.company_id}
              error={errors.fields.branch_id}
            />
          </div>
        )}
        <TextField
          ref={patternRef}
          label={t('numbering.fields.pattern')}
          help={t('numbering.fields.patternHelp')}
          value={values.pattern}
          onChange={(event) => set('pattern', event.target.value)}
          error={errors.fields.pattern ?? (values.pattern.trim() && problem ? t(`numbering.errors.${problem}`) : undefined)}
          maxLength={60}
          spellCheck={false}
          autoComplete="off"
          className="font-mono"
          required
        />
        <div className="flex flex-col gap-2">
          <span className="text-caption text-ink-muted">{t('numbering.tokens')}</span>
          <div className="flex flex-wrap gap-2">
            {tokensFor(type.place_tokens).map((token) => (
              <button
                key={token}
                type="button"
                onClick={() => insert(token)}
                aria-label={t('numbering.insertToken', { token })}
                className="rounded-md border border-border bg-surface-100 px-2 py-1 font-mono text-caption text-ink hover:bg-surface-300"
              >
                {token}
              </button>
            ))}
          </div>
        </div>
        <div className="rounded-md border border-border bg-surface-100 px-4 py-3">
          <p className="text-caption text-ink-muted">{t('numbering.example')}</p>
          <output aria-live="polite" className="font-mono text-body text-ink">
            {example ?? '—'}
          </output>
        </div>
        <Select
          label={t('numbering.fields.reset')}
          options={RESETS.map((value) => ({ value, label: t(`numbering.resets.${value}`) }))}
          value={values.reset}
          onChange={(event) => set('reset', event.target.value)}
          error={errors.fields.reset}
        />
        {type.ranged ? (
          <p className="text-caption text-ink-muted">{t('numbering.rangedHelp')}</p>
        ) : (
          <div className="flex flex-col gap-1">
            <Switch label={t('numbering.fields.gapless')} checked={values.gapless} onChange={(checked) => set('gapless', checked)} />
            <p className="text-caption text-ink-muted">{errors.fields.gapless ?? t('numbering.gaplessHelp')}</p>
          </div>
        )}
      </form>
    </Dialog>
  )
}

/**
 * NUM-01: how each document type of the active modules is numbered, its
 * default and the formats set for the organisation, a company or a branch
 * (those the user reaches), with an editor for `core.numbering.edit`.
 */
export default function Numbering() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const [editing, setEditing] = useState(null) // { type, format? }
  const query = useQuery({ queryKey: FORMATS_KEY, queryFn: () => api.get('numbering/formats') })
  const companiesQuery = useQuery({ queryKey: ['companies', 'numbering'], queryFn: () => api.get('companies?per_page=200') })
  const branchesQuery = useQuery({ queryKey: ['branches', 'numbering'], queryFn: () => api.get('branches?per_page=200') })
  const companies = companiesQuery.data?.data ?? []
  const branches = branchesQuery.data?.data ?? []
  const canEdit = can('core.numbering.edit')
  const types = query.data?.data ?? []

  return (
    <>
      <PageHeader title={t('numbering.title')} description={t('numbering.description')} />
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} action={<Button onClick={() => query.refetch()}>{t('common.retry')}</Button>} /> : null}
      {query.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      {!query.isPending && !query.isError && types.length === 0 ? <p className="text-ink-muted">{t('numbering.empty')}</p> : null}
      <div className="flex flex-col gap-5">
        {types.map((type) => (
          <Card
            key={type.document_type}
            title={type.name}
            subtitle={t('numbering.default', { pattern: type.default.pattern, reset: t(`numbering.resets.${type.default.reset}`) })}
            actions={
              canEdit ? (
                <Button icon="plus" onClick={() => setEditing({ type })} aria-label={t('numbering.addFor', { name: type.name })}>
                  {t('numbering.add')}
                </Button>
              ) : null
            }
          >
            {type.formats.length === 0 ? (
              <p className="text-ink-muted">{t('numbering.usesDefault', { example: examplePattern(type.default.pattern) })}</p>
            ) : (
              <ul className="divide-y divide-border">
                {type.formats.map((format) => (
                  <li key={format.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div className="flex min-w-0 flex-col gap-1">
                      <span className="text-body text-ink">{scopeName(t, format, companies, branches)}</span>
                      <span className="font-mono text-caption text-ink">{format.pattern}</span>
                      <span className="text-caption text-ink-muted">
                        {[
                          t('numbering.exampleShort', { example: examplePattern(format.pattern, { codes: { BRANCH: branches.find((entry) => entry.id === format.branch_id)?.code } }) }),
                          t(`numbering.resets.${format.reset}`),
                          format.gapless ? t('numbering.noGaps') : null,
                        ]
                          .filter(Boolean)
                          .join(' · ')}
                      </span>
                    </div>
                    {canEdit ? (
                      <Button variant="ghost" icon="edit" onClick={() => setEditing({ type, format })} aria-label={t('numbering.editFor', { scope: scopeName(t, format, companies, branches) })}>
                        {t('numbering.edit')}
                      </Button>
                    ) : null}
                  </li>
                ))}
              </ul>
            )}
          </Card>
        ))}
      </div>
      {editing ? <FormatDialog type={editing.type} format={editing.format} companies={companies} branches={branches} onClose={() => setEditing(null)} /> : null}
    </>
  )
}
