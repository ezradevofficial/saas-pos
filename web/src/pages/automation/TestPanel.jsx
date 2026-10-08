import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Select, StatusBadge, TextField } from '@/components/ds'
import { formatWhen } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'
import { describeCondition } from '@/pages/workflows/describe'
import { ValueInput } from '@/pages/workflows/ConditionEditor'
import { CHANGE_TRIGGERS, filledValues, hasDocument, ruleBody } from './automationData'

/** The fields whose value before the change the trigger compares. */
function changedFields(trigger, fields) {
  if (trigger?.type === 'field_changed' || trigger?.type === 'threshold') return fields.filter((field) => field.name === trigger.field)
  if (trigger?.type === 'record_updated' && Array.isArray(trigger.fields)) return fields.filter((field) => trigger.fields.includes(field.name))
  return fields
}

function SampleInputs({ legend, fields, values, onChange }) {
  return (
    <fieldset className="flex min-w-0 flex-col gap-3">
      <legend className="pb-1 text-label text-ink">{legend}</legend>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        {fields.map((field) => (
          <ValueInput key={field.name} field={field} op="eq" label={field.label} value={values[field.name]} onChange={(value) => onChange({ ...values, [field.name]: value })} />
        ))}
      </div>
    </fieldset>
  )
}

function TriggerResult({ trigger, timeZone }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const verdict = trigger.matches === true ? ['success', 'matches'] : trigger.matches === false ? ['danger', 'noMatch'] : ['neutral', 'unknown']
  return (
    <section aria-labelledby="test-trigger" className="flex flex-col gap-2">
      <h3 id="test-trigger" className="text-label text-ink">
        {t('automation.test.trigger')}
      </h3>
      <p className="text-body text-ink">{trigger.description}</p>
      <StatusBadge tone={verdict[0]}>{t(`automation.test.${verdict[1]}`)}</StatusBadge>
      {trigger.next_run_at ? <p className="text-caption text-ink-muted">{t('automation.test.nextRun', { when: formatWhen(trigger.next_run_at, locale, timeZone) })}</p> : null}
    </section>
  )
}

function ConditionsResult({ conditions, fields }) {
  const { t } = useTranslation()
  const locale = useLocale()
  if (!conditions) return null
  return (
    <section aria-labelledby="test-conditions" className="flex flex-col gap-2">
      <h3 id="test-conditions" className="text-label text-ink">
        {t('automation.test.conditions')}
      </h3>
      {conditions.checks.length === 0 ? (
        <p className="text-body text-ink-muted">{t('automation.test.noConditions')}</p>
      ) : (
        <ul className="flex flex-col gap-2">
          {conditions.checks.map((check, index) => (
            <li key={index} className="flex flex-wrap items-center justify-between gap-2 border-b border-border pb-2 last:border-b-0">
              <span className="text-body text-ink">{describeCondition(t, locale, { field: check.field, op: check.op, value: check.expected, ...(check.other ? { other: check.other } : {}) }, fields)}</span>
              <StatusBadge tone={check.passed ? 'success' : 'danger'}>{check.passed ? t('automation.test.passed') : t('automation.test.failed')}</StatusBadge>
            </li>
          ))}
        </ul>
      )}
      {conditions.reasons?.length ? (
        <ul className="flex list-disc flex-col gap-1 pl-5 text-caption text-ink-muted">
          {conditions.reasons.map((reason) => (
            <li key={reason}>{reason}</li>
          ))}
        </ul>
      ) : null}
    </section>
  )
}

/**
 * Test mode (AUTO-04): sample values (and the values before a change, for
 * change triggers) or a real document, run against the editor's rule as
 * it stands. Shows whether the trigger matches, each condition check and
 * what each action would do. Nothing is changed and no run is logged.
 */
export function TestPanel({ draft, info, ruleId, dirty, timeZone }) {
  const { t } = useTranslation()
  const fields = info?.fields ?? []
  const withDocument = hasDocument(draft.trigger)
  const [source, setSource] = useState('sample')
  const [values, setValues] = useState({})
  const [oldValues, setOldValues] = useState({})
  const [documentId, setDocumentId] = useState('')
  const showOld = CHANGE_TRIGGERS.has(draft.trigger?.type)

  const run = useMutation({
    mutationFn: () => {
      const body = {}
      if (withDocument && source === 'document') body.document_id = documentId.trim()
      else if (withDocument) {
        body.values = filledValues(values)
        if (showOld) body.old_values = filledValues(oldValues)
      }
      // A saved rule without edits is tested as saved; otherwise the editor's version, naming the saved
      // rule (rule_id) so webhooks sent without their address use the stored one.
      return ruleId && !dirty ? api.post(`automation-rules/${ruleId}/test`, body) : api.post('automation-rules/test', { ...ruleBody(draft), ...(ruleId ? { rule_id: ruleId } : {}), ...body })
    },
  })
  const result = run.data?.data

  return (
    <div className="flex flex-col gap-5">
      <p className="text-body text-ink-muted">{t('automation.test.intro')}</p>
      {withDocument ? (
        <>
          <Select
            label={t('automation.test.source')}
            options={[
              { value: 'sample', label: t('automation.test.sample') },
              { value: 'document', label: t('automation.test.document') },
            ]}
            value={source}
            onChange={(event) => setSource(event.target.value)}
            className="w-full sm:w-palette"
          />
          {source === 'document' ? (
            <TextField
              label={t('automation.test.documentId')}
              help={t('automation.test.documentIdHelp')}
              value={documentId}
              error={run.error?.errors?.document_id?.[0]}
              onChange={(event) => setDocumentId(event.target.value)}
              className="font-mono"
            />
          ) : (
            <>
              <SampleInputs legend={t('automation.test.values')} fields={fields} values={values} onChange={setValues} />
              {showOld ? <SampleInputs legend={t('automation.test.oldValues')} fields={changedFields(draft.trigger, fields)} values={oldValues} onChange={setOldValues} /> : null}
            </>
          )}
        </>
      ) : (
        <p className="text-caption text-ink-muted">{t('automation.test.scheduleNote')}</p>
      )}
      <Button variant="primary" icon="sync" className="self-start" loading={run.isPending} disabled={withDocument && source === 'document' && documentId.trim() === ''} onClick={() => run.mutate()}>
        {t('automation.test.run')}
      </Button>
      {run.isError && !run.error?.errors?.document_id ? <Alert tone="danger" title={errorMessage(run.error)} /> : null}
      {result ? (
        <div aria-live="polite" className="flex flex-col gap-5 rounded-md border border-border bg-surface-100 p-4">
          <Alert tone="info" title={t('automation.test.nothingChanged')} />
          <TriggerResult trigger={result.trigger} timeZone={timeZone} />
          <ConditionsResult conditions={result.conditions} fields={fields} />
          <section aria-labelledby="test-outcome" className="flex flex-col gap-2">
            <h3 id="test-outcome" className="text-label text-ink">
              {t('automation.test.outcome')}
            </h3>
            <StatusBadge tone={result.would_run ? 'success' : 'neutral'}>{result.would_run ? t('automation.test.wouldRun') : t('automation.test.wouldNotRun')}</StatusBadge>
            <ol className="flex list-decimal flex-col gap-1 pl-5 text-body text-ink">
              {result.actions.map((action, index) => (
                <li key={index}>{action.description}</li>
              ))}
            </ol>
          </section>
        </div>
      ) : null}
    </div>
  )
}
