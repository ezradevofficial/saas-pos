// SUB-02..SUB-06: query keys and small helpers of Settings → Subscription
// and the status banner.

export const SUBSCRIPTION_KEY = ['subscription']
export const STATUS_KEY = ['subscription', 'status']
export const METHODS_KEY = ['subscription', 'payment-methods']
export const INVOICES_KEY = ['subscription', 'invoices']

/** Status as a small dot and a word: the tone of each subscription status. */
export const STATUS_TONES = { trial: 'info', active: 'success', past_due: 'warning', read_only: 'danger', suspended: 'danger', cancelled: 'neutral' }
export const INVOICE_TONES = { open: 'warning', paid: 'success', void: 'neutral' }
export const PAYMENT_FINAL = ['succeeded', 'failed', 'cancelled', 'timeout']

/** Units a plan is counted in, in the order the editor asks for them (never `tenant`: always one). */
export const UNITS = ['outlet', 'device', 'employee', 'company', 'online_store', 'warehouse']

/** A plan as the API takes it, from a subscription or a pending change. */
export function planOf(source) {
  return {
    modules: [...(source?.modules ?? [])].sort(),
    quantities: Object.fromEntries(Object.entries(source?.quantities ?? {}).sort(([a], [b]) => a.localeCompare(b))),
    cycle: source?.cycle ?? 'monthly',
    bundle: source?.bundle ?? null,
  }
}

/** The units the selected modules (and bundle) are counted in. */
export function unitsFor(plan, catalogue) {
  const used = new Set(plan.bundle ? ['outlet'] : [])
  for (const key of plan.modules) {
    const module = catalogue?.modules?.find((entry) => entry.key === key)
    if (module && module.unit !== 'tenant') used.add(module.unit)
    if (module?.includes?.pos_device != null) used.add('device')
  }
  return UNITS.filter((unit) => used.has(unit))
}

/** What a unit counts before the owner types a number: POS devices start at those the outlets include, the rest at one. */
export function defaultQuantity(unit, plan, catalogue) {
  if (unit !== 'device') return 1
  const pos = catalogue?.modules?.find((entry) => entry.key === 'pos')
  return (pos?.includes?.pos_device ?? 0) * Math.max(1, Number(plan.quantities.outlet ?? 1) || 1)
}

/**
 * The plan with only the quantities its units use, ready to quote. POS
 * devices are sent only once the owner sets them (the outlets include
 * some), so an untouched plan stays the subscription's own.
 */
export function cleanPlan(plan, catalogue) {
  const units = unitsFor(plan, catalogue).filter((unit) => unit !== 'device' || plan.quantities.device !== undefined)
  const quantities = Object.fromEntries(units.map((unit) => [unit, Math.max(0, Number(plan.quantities[unit] ?? 1) || 0)]))
  return { ...plan, quantities: Object.fromEntries(Object.entries(quantities).sort(([a], [b]) => a.localeCompare(b))) }
}

export const samePlan = (a, b) => JSON.stringify(planOf(a)) === JSON.stringify(planOf(b))
