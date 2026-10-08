// A rule in plain words for the editor's summary line (AUTO-01..AUTO-03):
// "Every week on Monday and Thursday at 08:00", "30 days before Contract
// end", then the conditions and what the rule does.
import { describeCondition } from '@/pages/workflows/describe'

const LOCALES = { en: 'en', fr: 'fr' }

/** "Monday and Thursday" in the UI language. */
export function joinList(items, locale = 'en') {
  if (items.length <= 1) return items.join('')
  try {
    return new Intl.ListFormat(LOCALES[locale] ?? 'en', { style: 'long', type: 'conjunction' }).format(items)
  } catch {
    return items.join(', ')
  }
}

const fieldLabel = (info, name) => info?.fields?.find((field) => field.name === name)?.label ?? name ?? '…'
const stageLabel = (stages, id) => stages.find((stage) => stage.id === id)?.name ?? id

/** The trigger in words; `info` is the document type's catalogue entry, `stages` its flow stages. */
export function describeTrigger(t, trigger, { info, stages = [], locale = 'en' } = {}) {
  if (!trigger?.type) return t('automation.describe.noTrigger')
  const document = info?.label ?? ''
  switch (trigger.type) {
    case 'record_created':
    case 'record_archived':
      return t(`automation.describe.${trigger.type}`, { document })
    case 'record_updated':
      return Array.isArray(trigger.fields) && trigger.fields.length
        ? t('automation.describe.record_updated_fields', { document, fields: joinList(trigger.fields.map((name) => fieldLabel(info, name)), locale) })
        : t('automation.describe.record_updated', { document })
    case 'field_changed':
      return t('automation.describe.field_changed', { document, field: fieldLabel(info, trigger.field) })
    case 'stage_entered':
    case 'stage_left':
      return trigger.stage
        ? t(`automation.describe.${trigger.type}`, { document, stage: stageLabel(stages, trigger.stage) })
        : t(`automation.describe.${trigger.type}_any`, { document })
    case 'date': {
      const field = fieldLabel(info, trigger.field)
      if (trigger.when === 'on') return t('automation.describe.date_on', { field })
      return t(`automation.describe.date_${trigger.when === 'after' ? 'after' : 'before'}`, { count: Number(trigger.days) || 0, field })
    }
    case 'threshold':
      return t(`automation.describe.threshold_${trigger.direction === 'up' ? 'up' : 'down'}`, { field: fieldLabel(info, trigger.field) })
    case 'schedule': {
      const time = trigger.time || '…'
      if (trigger.every === 'week') {
        const days = (trigger.days ?? []).map((day) => t(`automation.days.${day}`))
        return t('automation.describe.schedule_week', { days: days.length ? joinList(days, locale) : '…', time })
      }
      if (trigger.every === 'month') return t('automation.describe.schedule_month', { day: trigger.day ?? '…', time })
      return t('automation.describe.schedule_day', { time })
    }
    default:
      return trigger.type
  }
}

/** The rule's one-line summary: when, only if, then. */
export function summarizeRule(t, draft, { info, stages = [], locale = 'en' } = {}) {
  const when = describeTrigger(t, draft.trigger, { info, stages, locale })
  const condition = describeCondition(t, locale, draft.conditions, info?.fields ?? [])
  const actions = (draft.actions ?? []).map((action) => t(`automation.actionNames.${action.type}`, { defaultValue: action.type }).toLocaleLowerCase(locale))
  const then = actions.length ? joinList(actions, locale) : t('automation.describe.nothing')
  return condition ? t('automation.describe.summaryIf', { when, condition, then }) : t('automation.describe.summary', { when, then })
}
