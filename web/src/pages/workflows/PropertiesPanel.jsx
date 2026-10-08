import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Alert, Button, Checkbox, Select, TextField } from '@/components/ds'
import { ConditionEditor } from './ConditionEditor'
import { displayName, kindLabel } from './describe'
import { roleRefsOf, toFrom, userIdsOf } from './notifyRecipients'
import { names as roleNamedBy } from './roleRefs'
import { PeoplePicker, RolesPicker } from './RolesPicker'
import { APPROVAL_MODES, DUE_UNITS, ESCALATE_TO, FINAL_ACTIONS, JOIN_MODES, MAX_CHAIN, MAX_REMINDERS, ON_CANCEL, OUTCOMES } from './workflowData'

/** A whole number of time units (1 to 10,000), or nothing: `{ amount, unit }` | null. */
function DurationField({ label, help, value, onChange }) {
  const { t } = useTranslation()
  const unit = value?.unit ?? 'business_hours'
  return (
    <div className="flex flex-col gap-1">
      <div className="grid grid-cols-2 items-end gap-3">
        <TextField
          label={label}
          type="number"
          inputMode="numeric"
          min={1}
          max={10000}
          step={1}
          value={value?.amount ?? ''}
          onChange={(event) => {
            const amount = Number.parseInt(event.target.value, 10)
            onChange(Number.isInteger(amount) && amount >= 1 ? { amount: Math.min(amount, 10000), unit } : null)
          }}
        />
        <Select
          label={t('workflows.fields.unit')}
          options={DUE_UNITS.map((one) => ({ value: one, label: t(`workflows.unitNames.${one}`) }))}
          value={unit}
          onChange={(event) => onChange(value?.amount ? { amount: value.amount, unit: event.target.value } : null)}
        />
      </div>
      {help ? <span className="text-caption text-ink-muted">{help}</span> : null}
    </div>
  )
}

/** A person, a role or "levels up" for an approver or escalation target. */
function ApproverParams({ approver, params, onChange, roles, users }) {
  const { t } = useTranslation()
  return (
    <>
      {params.includes('role') ? (
        <Select
          label={t('workflows.fields.role')}
          options={roles.map((role) => ({ value: role.id, label: role.name }))}
          placeholder={t('workflows.fields.chooseRole')}
          // A default flow names system roles by template (`template:admin`); show the tenant's role for it.
          value={roles.find((role) => roleNamedBy(approver.role, role))?.id ?? approver.role ?? ''}
          onChange={(event) => onChange({ ...approver, role: event.target.value })}
        />
      ) : null}
      {params.includes('user') ? (
        <Select
          label={t('workflows.fields.person')}
          options={users.map((user) => ({ value: user.id, label: user.name }))}
          placeholder={t('workflows.fields.choosePerson')}
          value={approver.user_id ?? ''}
          onChange={(event) => onChange({ ...approver, user_id: event.target.value })}
        />
      ) : null}
      {params.includes('levels') ? (
        <TextField
          label={t('workflows.fields.levels')}
          type="number"
          inputMode="numeric"
          min={1}
          max={10}
          value={approver.levels ?? 1}
          onChange={(event) => onChange({ ...approver, levels: Math.max(1, Math.min(10, Number.parseInt(event.target.value, 10) || 1)) })}
        />
      ) : null}
    </>
  )
}

/** Who approves: an approver type and its settings (role, person, levels). */
function ApproverPicker({ label, approver, onChange, context }) {
  const { t } = useTranslation()
  const { roles, users, approverTypes } = context
  const typeInfo = approverTypes.find((one) => one.key === approver.type)
  return (
    <>
      <Select
        label={label}
        options={approverTypes.map((one) => ({ value: one.key, label: one.label ?? t(`workflows.approverTypes.${one.key}`, { defaultValue: one.key }) }))}
        value={approver.type ?? ''}
        onChange={(event) => onChange({ type: event.target.value })}
      />
      <ApproverParams approver={approver} params={typeInfo?.params ?? []} roles={roles} users={users} onChange={onChange} />
    </>
  )
}

/**
 * APR-01: a sequential chain, each approver in turn (`approval.chain`, up
 * to MAX_CHAIN): add, remove and reorder; the mode applies within each step.
 */
function ApprovalChain({ chain, onChange, context }) {
  const { t } = useTranslation()
  const set = (index, approver) => onChange(chain.map((one, at) => (at === index ? approver : one)))
  const move = (index, by) => {
    const next = [...chain]
    next.splice(index + by, 0, ...next.splice(index, 1))
    onChange(next)
  }
  return (
    <div className="flex flex-col gap-3">
      <ol aria-label={t('workflows.fields.chain')} className="flex flex-col gap-3">
        {chain.map((approver, index) => {
          const step = t('workflows.fields.chainStep', { number: index + 1 })
          return (
            // The chain is a list of plain settings without ids; the position is its identity.
            <li key={index} className="flex flex-col gap-3 rounded-md border border-border p-3">
              <div className="flex items-center gap-1">
                <span className="flex-1 text-label text-ink">{step}</span>
                <Button variant="ghost" icon="up" className="size-icon-btn px-0" disabled={index === 0} onClick={() => move(index, -1)} aria-label={t('workflows.fields.chainUp', { step })} />
                <Button variant="ghost" icon="down" className="size-icon-btn px-0" disabled={index === chain.length - 1} onClick={() => move(index, 1)} aria-label={t('workflows.fields.chainDown', { step })} />
                <Button
                  variant="ghost"
                  icon="remove"
                  className="size-icon-btn px-0"
                  disabled={chain.length === 1}
                  onClick={() => onChange(chain.filter((_, at) => at !== index))}
                  aria-label={t('workflows.fields.chainRemove', { step })}
                />
              </div>
              <ApproverPicker label={t('workflows.fields.approver')} approver={approver} onChange={(next) => set(index, next)} context={context} />
            </li>
          )
        })}
      </ol>
      {chain.length < MAX_CHAIN ? (
        <Button icon="plus" className="self-start" onClick={() => onChange([...chain, { type: context.approverTypes[0]?.key }])}>
          {t('workflows.fields.chainAdd')}
        </Button>
      ) : (
        <span className="text-caption text-ink-muted">{t('workflows.fields.chainMax', { count: MAX_CHAIN })}</span>
      )}
    </div>
  )
}

/** APR-05: up to MAX_REMINDERS reminders, each after its own wait (`reminders: [{amount, unit}]`). */
function RemindersField({ value, onChange }) {
  const { t } = useTranslation()
  const reminders = Array.isArray(value) ? value : []
  // An empty row the user just added and has not filled in yet.
  const [blank, setBlank] = useState(false)
  const rows = blank || reminders.length === 0 ? [...reminders, null] : reminders
  const set = (index, reminder) => {
    if (index === reminders.length) {
      if (reminder) {
        setBlank(false)
        onChange([...reminders, reminder])
      }
      return
    }
    onChange(reminder ? reminders.map((one, at) => (at === index ? reminder : one)) : reminders.filter((_, at) => at !== index))
  }
  return (
    <div className="flex flex-col gap-3">
      {rows.map((reminder, index) => (
        // Reminders have no ids; the position is their identity.
        <div key={index} className="flex items-end gap-2">
          <div className="flex-1">
            <DurationField
              label={rows.length > 1 ? t('workflows.fields.reminderNumber', { number: index + 1 }) : t('workflows.fields.remindAfter')}
              value={reminder}
              onChange={(next) => set(index, next)}
            />
          </div>
          {rows.length > 1 ? (
            <Button
              variant="ghost"
              icon="remove"
              className="size-icon-btn px-0"
              onClick={() => (index === reminders.length ? setBlank(false) : onChange(reminders.filter((_, at) => at !== index)))}
              aria-label={t('workflows.fields.reminderRemove', { number: index + 1 })}
            />
          ) : null}
        </div>
      ))}
      {!blank && reminders.length > 0 && reminders.length < MAX_REMINDERS ? (
        <Button icon="plus" className="self-start" onClick={() => setBlank(true)}>
          {t('workflows.fields.reminderAdd')}
        </Button>
      ) : null}
    </div>
  )
}

function StageSettings({ node, change, context }) {
  const { t } = useTranslation()
  const { fields, roles } = context
  return (
    <>
      {node.type === 'stage' ? (
        <Checkbox
          label={t('workflows.fields.mandatory')}
          help={t('workflows.fields.mandatoryHelp')}
          checked={node.mandatory !== false}
          onChange={(event) => change({ mandatory: event.target.checked })}
        />
      ) : null}
      <ConditionEditor label={t('workflows.fields.entry')} help={t('workflows.fields.entryHelp')} value={node.entry ?? null} fields={fields} onChange={(entry) => change({ entry })} />
      <ConditionEditor label={t('workflows.fields.exit')} help={t('workflows.fields.exitHelp')} value={node.exit ?? null} fields={fields} onChange={(exit) => change({ exit })} />
      {node.type === 'stage' ? (
        <RolesPicker label={t('workflows.fields.enterRoles')} help={t('workflows.fields.rolesHelp')} roles={roles} value={node.enter_roles} onChange={(enter_roles) => change({ enter_roles })} />
      ) : null}
      <RolesPicker
        label={node.type === 'approval' ? t('workflows.fields.manualRoles') : t('workflows.fields.exitRoles')}
        help={t('workflows.fields.rolesHelp')}
        roles={roles}
        value={node.exit_roles}
        onChange={(exit_roles) => change({ exit_roles })}
      />
      {node.type === 'stage' ? (
        <>
          <DurationField label={t('workflows.fields.due')} help={t('workflows.fields.dueHelp')} value={node.due} onChange={(due) => change({ due })} />
          <DurationField
            label={t('workflows.fields.remindAfter')}
            value={node.reminders?.[0] ?? null}
            onChange={(reminder) => change({ reminders: reminder ? [reminder] : [] })}
          />
        </>
      ) : null}
    </>
  )
}

function ApprovalSettings({ node, change, context }) {
  const { t } = useTranslation()
  const { roles, users, approverTypes } = context
  const approval = node.approval ?? {}
  const approver = approval.approver ?? { type: approverTypes[0]?.key }
  // APR-01: `chain` (one after another) replaces `approver` when it has steps.
  const chain = Array.isArray(approval.chain) && approval.chain.length > 0 ? approval.chain : null
  const escalation = node.escalation ?? {}
  const to = escalation.to ?? { type: 'next_level' }
  const toInfo = ESCALATE_TO.find((one) => one.key === to.type) ?? ESCALATE_TO[0]
  const setApproval = (changes) => change({ approval: { ...approval, ...changes } })
  const setEscalation = (changes) => change({ escalation: { ...escalation, ...changes } })
  // Delegation and email default on; a reason is required only when asked (ApprovalConfig::normalise).
  const flag = (key) => (key === 'require_reason' ? approval[key] === true : approval[key] !== false)
  const setSequence = (sequence) => {
    const rest = { ...approval }
    delete rest.approver
    delete rest.chain
    change({ approval: sequence === 'chain' ? { ...rest, chain: [approver] } : { ...rest, approver: chain?.[0] ?? approver } })
  }

  return (
    <>
      <Select
        label={t('workflows.fields.sequence')}
        options={['single', 'chain'].map((one) => ({ value: one, label: t(`workflows.sequences.${one}`) }))}
        value={chain ? 'chain' : 'single'}
        onChange={(event) => setSequence(event.target.value)}
      />
      {chain ? (
        <ApprovalChain chain={chain} onChange={(next) => setApproval({ chain: next })} context={context} />
      ) : (
        <ApproverPicker label={t('workflows.fields.approver')} approver={approver} onChange={(next) => setApproval({ approver: next })} context={context} />
      )}
      <Select
        label={t('workflows.fields.mode')}
        help={chain ? t('workflows.fields.modeChainHelp') : undefined}
        options={APPROVAL_MODES.map((mode) => ({ value: mode, label: t(`workflows.modes.${mode}`) }))}
        value={approval.mode ?? 'any'}
        onChange={(event) => setApproval({ mode: event.target.value })}
      />
      <RemindersField value={node.reminders} onChange={(reminders) => change({ reminders })} />
      <DurationField
        label={t('workflows.fields.escalateAfter')}
        help={t('workflows.fields.businessHoursHelp')}
        value={escalation.after ?? null}
        onChange={(after) => setEscalation({ after })}
      />
      <Select
        label={t('workflows.fields.escalateTo')}
        options={ESCALATE_TO.map((one) => ({ value: one.key, label: t(`workflows.escalateTo.${one.key}`) }))}
        value={to.type}
        onChange={(event) => setEscalation({ to: { type: event.target.value } })}
      />
      <ApproverParams approver={to} params={toInfo.params} roles={roles} users={users} onChange={(next) => setEscalation({ to: next })} />
      <Select
        label={t('workflows.fields.final')}
        options={FINAL_ACTIONS.map((one) => ({ value: one, label: t(`workflows.finalActions.${one}`) }))}
        value={escalation.final ?? 'none'}
        onChange={(event) => setEscalation({ final: event.target.value === 'none' ? null : event.target.value })}
      />
      <Checkbox label={t('workflows.fields.allowDelegation')} checked={flag('allow_delegation')} onChange={(event) => setApproval({ allow_delegation: event.target.checked })} />
      <Checkbox label={t('workflows.fields.allowEmail')} checked={flag('allow_email')} onChange={(event) => setApproval({ allow_email: event.target.checked })} />
      <Checkbox label={t('workflows.fields.requireReason')} checked={flag('require_reason')} onChange={(event) => setApproval({ require_reason: event.target.checked })} />
      <Alert tone="info">{t('workflows.fields.noSelfApproval')}</Alert>
      <StageSettings node={node} change={change} context={context} />
    </>
  )
}

const BRANCH_KEY = /^[A-Za-z0-9_-]{1,64}$/

function ConditionSettings({ node, change, changeBranches, context }) {
  const { t } = useTranslation()
  const { fields } = context
  const several = Array.isArray(node.branches)
  const branches = several ? node.branches : []

  const nextKey = () => {
    let n = branches.length + 1
    while (branches.some((branch) => branch.key === `branch_${n}`)) n += 1
    return `branch_${n}`
  }

  return (
    <>
      <Select
        label={t('workflows.fields.conditionKind')}
        options={[
          { value: 'yesno', label: t('workflows.fields.yesNo') },
          { value: 'branches', label: t('workflows.fields.severalBranches') },
        ]}
        value={several ? 'branches' : 'yesno'}
        onChange={(event) =>
          event.target.value === 'branches'
            ? changeBranches({ branches: [{ key: 'branch_1', name: t('workflows.fields.branchDefault', { number: 1 }), condition: node.condition ?? null }], condition: undefined })
            : changeBranches({ branches: undefined, condition: branches[0]?.condition ?? null })
        }
      />
      {several ? (
        <>
          {branches.map((branch, index) => (
            <fieldset key={branch.key} className="flex flex-col gap-3 rounded-md border border-border p-3">
              <legend className="px-1 text-label text-ink">{branch.name || branch.key}</legend>
              <TextField
                label={t('workflows.fields.branchName')}
                value={branch.name ?? ''}
                maxLength={60}
                onChange={(event) => changeBranches({ branches: branches.map((one, i) => (i === index ? { ...one, name: event.target.value } : one)) })}
              />
              <ConditionEditor
                label={t('workflows.fields.branchRule')}
                value={branch.condition ?? null}
                fields={fields}
                onChange={(condition) => changeBranches({ branches: branches.map((one, i) => (i === index ? { ...one, condition } : one)) })}
              />
              <Button variant="ghost" icon="remove" className="self-start" disabled={branches.length === 1} onClick={() => changeBranches({ branches: branches.filter((_, i) => i !== index) })}>
                {t('workflows.fields.removeBranch')}
              </Button>
            </fieldset>
          ))}
          <Button
            icon="plus"
            className="self-start"
            onClick={() => {
              const key = nextKey()
              if (BRANCH_KEY.test(key)) changeBranches({ branches: [...branches, { key, name: t('workflows.fields.branchDefault', { number: branches.length + 1 }), condition: null }] })
            }}
          >
            {t('workflows.fields.addBranch')}
          </Button>
          <p className="text-caption text-ink-muted">{t('workflows.fields.elseHelp')}</p>
        </>
      ) : (
        <ConditionEditor label={t('workflows.fields.question')} help={t('workflows.fields.questionHelp')} value={node.condition ?? null} fields={fields} onChange={(condition) => change({ condition })} />
      )}
    </>
  )
}

function ActionSettings({ node, change, context }) {
  const { t } = useTranslation()
  const { nextDocuments, types, actionHandlers, roles, users } = context
  const config = node.config ?? {}
  const setConfig = (changes) => change({ config: { ...config, ...changes } })
  const next = nextDocuments.find((one) => one.key === config.mapping)
  const roleRefs = roleRefsOf(config.to)
  const userIds = userIdsOf(config.to)

  return (
    <>
      <Select
        label={t('workflows.fields.action')}
        options={actionHandlers.map((key) => ({ value: key, label: t(`workflows.actionNames.${key}`, { defaultValue: key }) }))}
        value={node.action ?? ''}
        onChange={(event) => change({ action: event.target.value, config: event.target.value === 'create_document' ? { on_cancel: 'keep' } : event.target.value === 'notify' ? { to: [] } : {} })}
      />
      {node.action === 'create_document' ? (
        <>
          <Select
            label={t('workflows.fields.mapping')}
            options={nextDocuments.map((one) => ({ value: one.key, label: types.find((type) => type.key === one.target)?.label ?? one.label }))}
            placeholder={nextDocuments.length ? t('workflows.fields.chooseDocument') : t('workflows.fields.noDocuments')}
            disabled={nextDocuments.length === 0}
            value={config.mapping ?? ''}
            onChange={(event) => setConfig({ mapping: event.target.value })}
          />
          {next ? (
            <div className="flex flex-col gap-1">
              <span className="text-label text-ink">{t('workflows.fields.copies')}</span>
              <ul className="flex flex-col gap-1 text-caption text-ink-muted">
                {Object.entries(next.fields ?? {}).map(([target, source]) => (
                  <li key={target}>{t('workflows.fields.copyLine', { source: context.fields.find((field) => field.name === source)?.label ?? source, target })}</li>
                ))}
              </ul>
            </div>
          ) : null}
          <Select
            label={t('workflows.fields.onCancel')}
            options={ON_CANCEL.map((one) => ({ value: one, label: t(`workflows.onCancel.${one}`) }))}
            value={config.on_cancel ?? 'keep'}
            onChange={(event) => setConfig({ on_cancel: event.target.value })}
          />
        </>
      ) : null}
      {node.action === 'notify' ? (
        <>
          <p className="text-caption text-ink-muted">{t('workflows.fields.notifyHelp')}</p>
          <RolesPicker label={t('workflows.fields.notifyRoles')} roles={roles} value={roleRefs} onChange={(next) => setConfig({ to: toFrom(next, userIds) })} />
          <PeoplePicker label={t('workflows.fields.notifyPeople')} users={users} value={userIds} onChange={(next) => setConfig({ to: toFrom(roleRefs, next) })} />
          <TextField
            label={t('workflows.fields.message')}
            help={t('workflows.fields.messageHelp')}
            value={config.message ?? ''}
            maxLength={500}
            onChange={(event) => {
              const { message: _old, ...rest } = config
              change({ config: event.target.value === '' ? rest : { ...rest, message: event.target.value } })
            }}
          />
        </>
      ) : null}
    </>
  )
}

/**
 * The selected step's settings (BoWorkflow design, right-hand panel):
 * its kind and name, then what that kind needs. Every change is one undo
 * step and is saved with the draft.
 */
export function PropertiesPanel({ graph, node, onChange, onChangeBranches, onDelete, context, readOnly }) {
  const { t } = useTranslation()

  if (!node) {
    return (
      <div className="flex flex-col gap-2">
        <h2 className="text-h3 text-ink">{t('workflows.panel.emptyTitle')}</h2>
        <p className="text-body text-ink-muted">{readOnly ? t('workflows.panel.emptyReadOnly') : t('workflows.panel.empty')}</p>
      </div>
    )
  }

  const change = (changes) => onChange(node.id, changes)
  const changeBranches = (changes) => onChangeBranches(node.id, changes)
  const parallels = graph.nodes.filter((one) => one.type === 'parallel')

  return (
    <fieldset disabled={readOnly} className="flex min-w-0 flex-col gap-4">
      <legend className="sr-only">{t('workflows.panel.legend', { name: displayName(t, node) })}</legend>
      <div className="flex flex-col gap-1">
        <span className="text-caption font-medium text-ink-muted uppercase">{t('workflows.panel.caption', { kind: kindLabel(t, node) })}</span>
        <h2 className="text-h3 text-ink">{displayName(t, node)}</h2>
      </div>
      <TextField label={t('workflows.fields.name')} value={node.name ?? ''} maxLength={120} required={['stage', 'approval', 'condition'].includes(node.type)} onChange={(event) => change({ name: event.target.value })} />

      {node.type === 'stage' ? <StageSettings node={node} change={change} context={context} /> : null}
      {node.type === 'approval' ? <ApprovalSettings node={node} change={change} context={context} /> : null}
      {node.type === 'condition' ? <ConditionSettings node={node} change={change} changeBranches={changeBranches} context={context} /> : null}
      {node.type === 'action' ? <ActionSettings node={node} change={change} context={context} /> : null}
      {node.type === 'parallel' ? <p className="text-body text-ink-muted">{t('workflows.fields.parallelHelp')}</p> : null}
      {node.type === 'join' ? (
        <>
          <Select
            label={t('workflows.fields.split')}
            options={parallels.map((one) => ({ value: one.id, label: displayName(t, one) }))}
            placeholder={t('workflows.fields.chooseSplit')}
            value={node.split ?? ''}
            onChange={(event) => change({ split: event.target.value })}
          />
          <Select
            label={t('workflows.fields.joinMode')}
            options={JOIN_MODES.map((one) => ({ value: one, label: t(`workflows.joinModes.${one}`) }))}
            value={node.mode ?? 'all'}
            onChange={(event) => change({ mode: event.target.value })}
          />
        </>
      ) : null}
      {node.type === 'end' ? (
        <Select
          label={t('workflows.fields.outcome')}
          options={OUTCOMES.map((one) => ({ value: one, label: t(`workflows.outcomes.${one}`) }))}
          value={node.outcome ?? 'completed'}
          onChange={(event) => change({ outcome: event.target.value })}
        />
      ) : null}

      {!readOnly ? (
        <Button variant="danger" icon="remove" className="self-start" onClick={() => onDelete(node.id)}>
          {t('workflows.panel.delete')}
        </Button>
      ) : null}
    </fieldset>
  )
}
