import { useTranslation } from 'react-i18next'
import { Checkbox, Select, TextField } from '@/components/ds'
import { useMoneyDefaults } from '@/lib/defaultCurrency'
import { ValueInput } from '@/pages/workflows/ConditionEditor'
import { emptyValue } from '@/pages/workflows/conditionValues'
import { DATE_WHEN, newTrigger, SCHEDULE_EVERY, STAGE_HOW, WEEK_DAYS } from './automationData'

const ANY = '__any'

/** A stage of the type's flow, else typed in when the type has no flow yet (stage ids are node ids). */
export function StagePicker({ label, value, onChange, stages, anyLabel, error, required }) {
  const { t } = useTranslation()
  if (stages.length === 0) {
    return (
      <TextField
        label={label}
        help={t('automation.trigger.stageHint')}
        value={value ?? ''}
        error={error}
        maxLength={64}
        required={required}
        onChange={(event) => onChange(event.target.value.trim() === '' ? undefined : event.target.value.trim())}
      />
    )
  }
  const known = !value || stages.some((stage) => stage.id === value)
  const options = [
    ...(anyLabel ? [{ value: ANY, label: anyLabel }] : []),
    ...stages.map((stage) => ({ value: stage.id, label: stage.name })),
    ...(known ? [] : [{ value, label: value }]),
  ]
  return (
    <Select
      label={label}
      options={options}
      value={value ?? (anyLabel ? ANY : '')}
      placeholder={anyLabel ? undefined : t('automation.trigger.chooseStage')}
      error={error}
      onChange={(event) => onChange(event.target.value === ANY ? undefined : event.target.value)}
    />
  )
}

/** A whole number field (days, a day of the month). */
function WholeNumber({ label, help, value, min, max, onChange, disabled }) {
  return (
    <TextField
      label={label}
      help={help}
      type="number"
      inputMode="numeric"
      min={min}
      max={max}
      step={1}
      disabled={disabled}
      value={value ?? ''}
      onChange={(event) => {
        const number = Number.parseInt(event.target.value, 10)
        onChange(Number.isInteger(number) ? Math.min(Math.max(number, min), max) : min)
      }}
    />
  )
}

/** An optional value of a field: ticked to compare with one value, else any value. */
function OptionalValue({ label, field, value, present, onChange }) {
  const { currency } = useMoneyDefaults()
  return (
    <div className="flex flex-col gap-2">
      <Checkbox label={label} checked={present} onChange={(event) => onChange(event.target.checked ? emptyValue(field, 'eq', currency) : undefined)} />
      {present ? <ValueInput key={field?.name} field={field} op="eq" label={label} value={value} onChange={onChange} /> : null}
    </div>
  )
}

/**
 * When the rule runs (AUTO-01): the trigger types the document type
 * allows, and each one's settings in plain terms.
 */
export function TriggerEditor({ trigger, onChange, info, stages, limits, error }) {
  const { t } = useTranslation()
  const { currency } = useMoneyDefaults()
  const fields = info?.fields ?? []
  const usable = info?.triggers ?? []
  const set = (changes) => {
    const next = { ...trigger, ...changes }
    for (const [key, value] of Object.entries(next)) if (value === undefined) delete next[key]
    onChange(next)
  }
  const field = fields.find((one) => one.name === trigger.field)

  return (
    <div className="flex flex-col gap-4">
      <Select
        label={t('automation.trigger.type')}
        options={usable.map((key) => ({ value: key, label: t(`automation.triggers.${key}`, { defaultValue: key }) }))}
        value={trigger.type ?? ''}
        error={error}
        onChange={(event) => onChange(newTrigger(event.target.value, info, currency))}
      />

      {trigger.type === 'record_updated' ? (
        <fieldset className="flex min-w-0 flex-col gap-2">
          <legend className="pb-1 text-label text-ink">{t('automation.trigger.watchFields')}</legend>
          <span className="text-caption text-ink-muted">{t('automation.trigger.watchFieldsHelp')}</span>
          <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
            {fields.map((one) => {
              const chosen = trigger.fields ?? []
              return (
                <Checkbox
                  key={one.name}
                  label={one.label}
                  checked={chosen.includes(one.name)}
                  onChange={(event) => {
                    const next = event.target.checked ? [...chosen, one.name] : chosen.filter((name) => name !== one.name)
                    set({ fields: next.length ? next : undefined })
                  }}
                />
              )
            })}
          </div>
        </fieldset>
      ) : null}

      {trigger.type === 'field_changed' ? (
        <>
          <Select
            label={t('automation.trigger.field')}
            options={fields.map((one) => ({ value: one.name, label: one.label }))}
            value={trigger.field ?? ''}
            onChange={(event) => onChange({ type: 'field_changed', field: event.target.value })}
          />
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <OptionalValue key={`from-${trigger.field}`} label={t('automation.trigger.from')} field={field} present={'from' in trigger} value={trigger.from} onChange={(from) => set({ from })} />
            <OptionalValue key={`to-${trigger.field}`} label={t('automation.trigger.to')} field={field} present={'to' in trigger} value={trigger.to} onChange={(to) => set({ to })} />
          </div>
        </>
      ) : null}

      {trigger.type === 'stage_entered' || trigger.type === 'stage_left' ? (
        <>
          <StagePicker label={t('automation.trigger.stage')} value={trigger.stage} stages={stages} anyLabel={t('automation.trigger.anyStage')} onChange={(stage) => set({ stage })} />
          {trigger.type === 'stage_left' ? (
            <Select
              label={t('automation.trigger.how')}
              options={[{ value: ANY, label: t('automation.trigger.anyWay') }, ...STAGE_HOW.map((how) => ({ value: how, label: t(`automation.stageHow.${how}`) }))]}
              value={trigger.how ?? ANY}
              onChange={(event) => set({ how: event.target.value === ANY ? undefined : event.target.value })}
            />
          ) : null}
        </>
      ) : null}

      {trigger.type === 'date' ? (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <WholeNumber
            label={t('automation.trigger.days')}
            value={trigger.when === 'on' ? 0 : trigger.days}
            min={0}
            max={limits.max_days}
            disabled={trigger.when === 'on'}
            onChange={(days) => set({ days })}
          />
          <Select
            label={t('automation.trigger.when')}
            options={DATE_WHEN.map((when) => ({ value: when, label: t(`automation.dateWhen.${when}`) }))}
            value={trigger.when ?? 'before'}
            onChange={(event) => set({ when: event.target.value, ...(event.target.value === 'on' ? { days: 0 } : {}) })}
          />
          <Select
            label={t('automation.trigger.dateField')}
            options={(info?.date_fields ?? []).map((name) => ({ value: name, label: fields.find((one) => one.name === name)?.label ?? name }))}
            value={trigger.field ?? ''}
            placeholder={t('automation.trigger.chooseField')}
            onChange={(event) => set({ field: event.target.value })}
          />
        </div>
      ) : null}

      {trigger.type === 'threshold' ? (
        <>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Select
              label={t('automation.trigger.field')}
              options={fields.filter((one) => one.type === 'number' || one.type === 'money').map((one) => ({ value: one.name, label: one.label }))}
              value={trigger.field ?? ''}
              placeholder={t('automation.trigger.chooseField')}
              onChange={(event) => {
                const next = fields.find((one) => one.name === event.target.value)
                set({ field: event.target.value, value: next?.type === 'money' ? { amount_minor: '', currency } : '' })
              }}
            />
            <Select
              label={t('automation.trigger.direction')}
              options={['down', 'up'].map((direction) => ({ value: direction, label: t(`automation.direction.${direction}`) }))}
              value={trigger.direction ?? 'down'}
              onChange={(event) => set({ direction: event.target.value })}
            />
          </div>
          {field ? <ValueInput key={field.name} field={field} op="lt" label={t('automation.trigger.level')} value={trigger.value} onChange={(value) => set({ value })} /> : null}
        </>
      ) : null}

      {trigger.type === 'schedule' ? (
        <>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Select
              label={t('automation.trigger.every')}
              options={SCHEDULE_EVERY.map((every) => ({ value: every, label: t(`automation.every.${every}`) }))}
              value={trigger.every ?? 'day'}
              onChange={(event) => {
                const every = event.target.value
                onChange({ type: 'schedule', every, time: trigger.time ?? '08:00', ...(every === 'week' ? { days: ['mon'] } : every === 'month' ? { day: 1 } : {}) })
              }}
            />
            <TextField label={t('automation.trigger.time')} type="time" value={trigger.time ?? ''} onChange={(event) => set({ time: event.target.value })} />
          </div>
          {trigger.every === 'week' ? (
            <fieldset className="flex min-w-0 flex-col gap-2">
              <legend className="pb-1 text-label text-ink">{t('automation.trigger.weekDays')}</legend>
              <div className="flex flex-wrap gap-x-4 gap-y-2">
                {WEEK_DAYS.map((day) => {
                  const days = trigger.days ?? []
                  return (
                    <Checkbox
                      key={day}
                      label={t(`automation.days.${day}`)}
                      checked={days.includes(day)}
                      onChange={(event) => set({ days: WEEK_DAYS.filter((one) => (one === day ? event.target.checked : days.includes(one))) })}
                    />
                  )
                })}
              </div>
            </fieldset>
          ) : null}
          {trigger.every === 'month' ? (
            <WholeNumber label={t('automation.trigger.monthDay')} help={t('automation.trigger.monthDayHelp')} value={trigger.day} min={1} max={31} onChange={(day) => set({ day })} />
          ) : null}
          <p className="text-caption text-ink-muted">{t('automation.trigger.scheduleHelp')}</p>
        </>
      ) : null}
    </div>
  )
}
