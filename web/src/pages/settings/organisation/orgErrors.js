// TEN-06: the archive and restore refusals, in words that name the level
// ("This company still has active branches") instead of "this record".
import { errorMessage } from '@/api/errorMessage'
import i18n from '@/i18n'

/** @param {'company'|'branch'|'location'|'device'} level the record acted on */
export function orgErrorMessage(error, level) {
  if (!error) return null
  const overrides = {}
  for (const [code, key] of [
    ['last_active', 'lastActive'],
    ['has_active_children', 'hasActiveChildren'],
    ['parent_archived', 'parentArchived'],
  ]) {
    const path = `organisation.errors.${key}.${level}`
    if (i18n.exists(path)) overrides[code] = i18n.t(path)
  }
  return errorMessage(error, overrides)
}
