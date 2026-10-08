import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Checkbox, Select, Switch, TextField } from '@/components/ds'
import { TextAreaField } from '@/pages/approvals/TextAreaField'
import { ValueInput } from '@/pages/workflows/ConditionEditor'
import { emptyValue } from '@/pages/workflows/conditionValues'
import { useMoneyDefaults } from '@/lib/defaultCurrency'
import { cn } from '@/lib/utils'
import { needsDocument, newAction } from './automationData'
import { RecipientsPicker } from './RecipientsPicker'
import { StagePicker } from './TriggerEditor'
import { WebhookSecretPanel } from './WebhookSecretPanel'

const SKIP = '__skip'
const FIXED = '__fixed'

/** A field's starting value; money starts in the rule's company's currency. */
const startValue = (field, currency) => emptyValue(field, 'eq', currency) ?? ''

function UpdateFieldForm({ action, set, info }) {
  const { t } = useTranslation()
  const { currency } = useMoneyDefaults()
  const fields = info?.fields ?? []
  const writable = (info?.writable_fields ?? []).map((name) => fields.find((field) => field.name === name) ?? { name, label: name, type: 'string' })
  const field = writable.find((one) => one.name === action.field)
  const clears = action.value === null
  return (
    <>
      <Select
        label={t('automation.actions.field')}
        options={writable.map((one) => ({ value: one.name, label: one.label }))}
        value={action.field ?? ''}
        placeholder={writable.length ? t('automation.trigger.chooseField') : t('automation.actions.noWritable')}
        disabled={writable.length === 0}
        onChange={(event) => set({ field: event.target.value, value: startValue(writable.find((one) => one.name === event.target.value), currency) })}
      />
      {field ? (
        <>
          <Checkbox label={t('automation.actions.clearField')} checked={clears} onChange={(event) => set({ value: event.target.checked ? null : startValue(field, currency) })} />
          {clears ? null : <ValueInput key={field.name} field={field} op="eq" label={t('automation.actions.newValue')} value={action.value} onChange={(value) => set({ value })} />}
        </>
      ) : null}
    </>
  )
}

function ChangeStageForm({ action, set, stages }) {
  const { t } = useTranslation()
  const returning = action.mode === 'return'
  return (
    <>
      <Select
        label={t('automation.actions.stageMode')}
        options={['move', 'return'].map((mode) => ({ value: mode, label: t(`automation.stageModes.${mode}`) }))}
        value={action.mode ?? 'move'}
        onChange={(event) => set(event.target.value === 'return' ? { mode: 'return', stage: action.stage ?? '', reason: action.reason ?? '' } : { mode: 'move', reason: undefined })}
      />
      <StagePicker
        label={returning ? t('automation.actions.returnTo') : t('automation.actions.completeStage')}
        value={action.stage || undefined}
        stages={stages}
        anyLabel={returning ? undefined : t('automation.actions.currentStage')}
        required={returning}
        onChange={(stage) => set({ stage: returning ? (stage ?? '') : stage })}
      />
      {returning ? <TextAreaField label={t('automation.actions.reason')} value={action.reason ?? ''} maxLength={500} required onChange={(event) => set({ reason: event.target.value })} /> : null}
    </>
  )
}

function AssignUserForm({ action, set, info, users }) {
  const { t } = useTranslation()
  const fields = info?.fields ?? []
  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
      <Select
        label={t('automation.actions.assignField')}
        options={(info?.assignable_fields ?? []).map((name) => ({ value: name, label: fields.find((field) => field.name === name)?.label ?? name }))}
        value={action.field ?? ''}
        placeholder={t('automation.trigger.chooseField')}
        onChange={(event) => set({ field: event.target.value })}
      />
      <Select
        label={t('automation.actions.person')}
        options={users.map((user) => ({ value: user.id, label: user.name }))}
        value={action.user ?? ''}
        placeholder={t('automation.actions.choosePerson')}
        onChange={(event) => set({ user: event.target.value })}
      />
    </div>
  )
}

/** Subject and message with placeholder chips that insert `{name}` where the cursor was (as the template editor does). */
function NotifyForm({ action, set, info, context, withDocument }) {
  const { t } = useTranslation()
  const subjectRef = useRef(null)
  const messageRef = useRef(null)
  const last = useRef('message')
  const fields = info?.fields ?? []
  const placeholders = [...(withDocument ? fields.map((field) => ({ name: field.name, label: field.label })) : []), ...context.placeholders.map((name) => ({ name, label: t(`automation.placeholders.${name}`, { defaultValue: name }) }))]

  const insert = (name) => {
    const token = `{${name}}`
    const key = last.current === 'subject' ? 'subject' : 'message'
    const element = key === 'subject' ? subjectRef.current : messageRef.current
    const text = action[key] ?? ''
    const start = element?.selectionStart ?? text.length
    const end = element?.selectionEnd ?? start
    set({ [key]: text.slice(0, start) + token + text.slice(end) })
    const caret = start + token.length
    requestAnimationFrame(() => {
      element?.focus()
      element?.setSelectionRange?.(caret, caret)
    })
  }

  return (
    <>
      <RecipientsPicker
        value={action.to}
        onChange={(to) => set({ to })}
        roles={context.roles}
        users={context.users}
        userFields={info?.user_fields ?? []}
        fields={fields}
        withDocument={withDocument}
      />
      <p className="text-caption text-ink-muted">{t('automation.notify.singleLanguage')}</p>
      <TextField
        ref={subjectRef}
        label={t('automation.notify.subject')}
        value={action.subject ?? ''}
        maxLength={context.limits.subject}
        onFocus={() => {
          last.current = 'subject'
        }}
        onChange={(event) => set({ subject: event.target.value })}
      />
      <TextAreaField
        ref={messageRef}
        label={t('automation.notify.message')}
        value={action.message ?? ''}
        rows={4}
        maxLength={context.limits.message}
        onFocus={() => {
          last.current = 'message'
        }}
        onChange={(event) => set({ message: event.target.value })}
      />
      <div className="flex flex-col gap-2">
        <span className="text-label text-ink">{t('automation.notify.placeholders')}</span>
        <span className="text-caption text-ink-muted">{t('automation.notify.placeholdersHelp')}</span>
        <ul className="flex flex-wrap gap-2">
          {placeholders.map((placeholder) => (
            <li key={placeholder.name}>
              <button
                type="button"
                onMouseDown={(event) => event.preventDefault()}
                onClick={() => insert(placeholder.name)}
                aria-label={t('automation.notify.insert', { placeholder: `{${placeholder.name}}` })}
                title={placeholder.label}
                className="rounded-md border border-border bg-surface-100 px-2 py-1 font-mono text-caption text-ink transition-colors hover:bg-surface-300"
              >
                {`{${placeholder.name}}`}
              </button>
            </li>
          ))}
        </ul>
      </div>
    </>
  )
}

/** A draft of another document type, each of its fields copied from this document, set to a fixed value, or left empty. */
function CreateDocumentForm({ action, set, info, context, withDocument }) {
  const { t } = useTranslation()
  const { currency } = useMoneyDefaults()
  const targets = context.types.filter((type) => (type.capabilities ?? []).includes('create_drafts'))
  const target = targets.find((type) => type.key === action.target)
  const mapping = action.mapping ?? {}
  const values = action.values ?? {}
  const sources = info?.fields ?? []

  const choose = (field, choice) => {
    const nextMapping = { ...mapping }
    const nextValues = { ...values }
    delete nextMapping[field.name]
    delete nextValues[field.name]
    if (choice === FIXED) nextValues[field.name] = startValue(field, currency)
    else if (choice !== SKIP) nextMapping[field.name] = choice
    set({ mapping: nextMapping, values: nextValues })
  }

  return (
    <>
      <Select
        label={t('automation.actions.target')}
        options={targets.map((type) => ({ value: type.key, label: type.label }))}
        value={action.target ?? ''}
        placeholder={targets.length ? t('automation.actions.chooseTarget') : t('automation.actions.noTargets')}
        disabled={targets.length === 0}
        onChange={(event) => set({ target: event.target.value, mapping: {}, values: {} })}
      />
      {target ? (
        <fieldset className="flex min-w-0 flex-col gap-3">
          <legend className="pb-1 text-label text-ink">{t('automation.actions.mapping')}</legend>
          <span className="text-caption text-ink-muted">{withDocument ? t('automation.actions.mappingHelp') : t('automation.actions.mappingNoDocument')}</span>
          {target.fields.map((field) => {
            const matching = withDocument ? sources.filter((source) => source.type === field.type && (source.reference ?? null) === (field.reference ?? null)) : []
            const choice = field.name in mapping ? mapping[field.name] : field.name in values ? FIXED : SKIP
            return (
              <div key={field.name} className="grid grid-cols-1 items-start gap-3 rounded-md border border-border bg-surface-100 p-3 sm:grid-cols-2">
                <Select
                  label={field.label}
                  options={[
                    { value: SKIP, label: t('automation.actions.leaveEmpty') },
                    ...matching.map((source) => ({ value: source.name, label: t('automation.actions.copyFrom', { field: source.label }) })),
                    { value: FIXED, label: t('automation.actions.fixedValue') },
                  ]}
                  value={choice}
                  onChange={(event) => choose(field, event.target.value)}
                />
                {choice === FIXED ? (
                  <ValueInput
                    key={field.name}
                    field={field}
                    op="eq"
                    label={t('automation.actions.valueFor', { field: field.label })}
                    value={values[field.name]}
                    onChange={(value) => set({ values: { ...values, [field.name]: value } })}
                  />
                ) : null}
              </div>
            )
          })}
        </fieldset>
      ) : null}
    </>
  )
}

function CreditHoldForm({ action, set }) {
  const { t } = useTranslation()
  return (
    <>
      <Switch label={action.hold === false ? t('automation.actions.liftHold') : t('automation.actions.putOnHold')} checked={action.hold !== false} onChange={(hold) => set({ hold })} />
      <TextField label={t('automation.actions.holdReason')} value={action.reason ?? ''} maxLength={255} onChange={(event) => set({ reason: event.target.value })} />
    </>
  )
}

function WebhookForm({ action, set, context }) {
  const { t } = useTranslation()
  const insecure = typeof action.url === 'string' && action.url !== '' && !/^https:\/\//i.test(action.url)
  // A saved webhook comes back without its address (only `url_display`, host
  // and path, and `has_url`): it shows read-only and is saved without `url`
  // (its `id` kept, so the server keeps the stored address) unless retyped.
  const stored = Boolean(action.has_url) && !('url' in action)
  return (
    <>
      {stored ? (
        <div className="flex flex-col gap-2">
          <span className="text-label text-ink">{t('automation.actions.url')}</span>
          <div className="flex flex-wrap items-center gap-2">
            <span data-testid="webhook-url" className="min-w-0 flex-1 rounded-md border border-border bg-surface-300 px-3 py-2 font-mono text-caption break-all text-ink">
              {action.url_display}
            </span>
            <Button icon="edit" onClick={() => set({ url: '' })}>
              {t('automation.actions.changeUrl')}
            </Button>
          </div>
          <span className="text-caption text-ink-muted">{t('automation.actions.urlStored')}</span>
        </div>
      ) : (
        <div className="flex flex-col gap-2">
          <TextField
            label={action.has_url ? t('automation.actions.newUrl') : t('automation.actions.url')}
            type="url"
            inputMode="url"
            placeholder="https://"
            help={t('automation.actions.urlHelp')}
            error={insecure ? t('automation.actions.httpsOnly') : undefined}
            value={action.url ?? ''}
            maxLength={2000}
            onChange={(event) => set({ url: event.target.value.trim() })}
          />
          {action.has_url ? (
            <Button variant="ghost" className="self-start" onClick={() => set({ url: undefined })}>
              {t('automation.actions.keepUrl')}
            </Button>
          ) : null}
        </div>
      )}
      <WebhookSecretPanel {...context.webhook} />
    </>
  )
}

const FORMS = {
  update_field: UpdateFieldForm,
  change_stage: ChangeStageForm,
  assign_user: AssignUserForm,
  notify: NotifyForm,
  create_document: CreateDocumentForm,
  set_credit_hold: CreditHoldForm,
  webhook: WebhookForm,
}

/**
 * What the rule does (AUTO-03): an ordered list of up to 20 actions, each
 * with its own settings. Actions that need a document are not offered on
 * a schedule.
 */
export function ActionsEditor({ actions, onChange, info, stages, withDocument, context, errors = {}, sectionError }) {
  const { t } = useTranslation()
  const offered = (info?.actions ?? []).filter((key) => withDocument || !needsDocument(context.actionsMeta, key))
  const [adding, setAdding] = useState('')
  const kind = offered.includes(adding) ? adding : (offered[0] ?? '')
  const full = actions.length >= context.limits.actions

  const write = (next) => onChange(next)
  const change = (index, changes) =>
    write(
      actions.map((action, i) => {
        if (i !== index) return action
        const next = { ...action, ...changes }
        for (const [key, value] of Object.entries(next)) if (value === undefined) delete next[key]
        return next
      }),
    )
  const move = (index, by) => {
    const next = [...actions]
    const [item] = next.splice(index, 1)
    next.splice(index + by, 0, item)
    write(next)
  }

  return (
    <div className="flex flex-col gap-4">
      {sectionError ? <p className="text-caption text-danger">{sectionError}</p> : null}
      {actions.length === 0 ? <p className="text-body text-ink-muted">{t('automation.actions.none')}</p> : null}
      <ol className="flex flex-col gap-4">
        {actions.map((action, index) => {
          const Form = FORMS[action.type]
          const name = t(`automation.actionNames.${action.type}`, { defaultValue: action.type })
          const error = errors[index]
          const misplaced = !withDocument && needsDocument(context.actionsMeta, action.type)
          return (
            <li
              key={index}
              aria-label={t('automation.actions.numbered', { number: index + 1, name })}
              data-path={`actions.${index}`}
              className={cn('flex flex-col gap-4 rounded-md border bg-surface-200 p-4', error ? 'border-danger' : 'border-border')}
            >
              <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-label text-ink">
                  <span className="text-ink-muted tabular-nums">{index + 1}.</span> {name}
                </h3>
                <div className="flex gap-1">
                  <Button variant="ghost" icon="up" aria-label={t('automation.actions.moveUp', { number: index + 1 })} disabled={index === 0} onClick={() => move(index, -1)} className="size-icon-btn px-0" />
                  <Button
                    variant="ghost"
                    icon="down"
                    aria-label={t('automation.actions.moveDown', { number: index + 1 })}
                    disabled={index === actions.length - 1}
                    onClick={() => move(index, 1)}
                    className="size-icon-btn px-0"
                  />
                  <Button variant="ghost" icon="remove" aria-label={t('automation.actions.remove', { number: index + 1 })} onClick={() => write(actions.filter((_, i) => i !== index))} className="size-icon-btn px-0" />
                </div>
              </div>
              {error ? (
                <ul role="alert" className="flex flex-col gap-1 text-caption text-danger">
                  {error.map((message) => (
                    <li key={message}>{message}</li>
                  ))}
                </ul>
              ) : null}
              {misplaced ? <p className="text-caption text-danger">{t('automation.actions.needsDocument')}</p> : null}
              {Form ? <Form action={action} set={(changes) => change(index, changes)} info={info} stages={stages} users={context.users} context={context} withDocument={withDocument} /> : null}
            </li>
          )
        })}
      </ol>
      {offered.length ? (
        <div className="flex flex-wrap items-end gap-2">
          <Select
            label={t('automation.actions.addLabel')}
            options={offered.map((key) => ({ value: key, label: t(`automation.actionNames.${key}`, { defaultValue: key }) }))}
            value={kind}
            disabled={full}
            onChange={(event) => setAdding(event.target.value)}
            className="w-full sm:w-palette"
          />
          <Button icon="plus" disabled={full || !kind} onClick={() => write([...actions, newAction(kind, info)])}>
            {t('automation.actions.add')}
          </Button>
        </div>
      ) : null}
      {full ? <p className="text-caption text-ink-muted">{t('automation.actions.limit', { count: context.limits.actions })}</p> : null}
    </div>
  )
}
