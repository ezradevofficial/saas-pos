// CUR-03: how the exchange rates page lists pairs.

// Rates are read "1 USD = CDF 2,850": the US dollar, when in a pair, is
// its base, so the figure is above 1 and needs no tiny decimals.
const ANCHOR = 'USD'

/**
 * The pairs of the tenant's active currencies, each once: USD as the base
 * when it is in the pair, otherwise the company's base currency as the
 * quote (KES/CDF for a CDF company). Pairs that touch the company's base
 * currency come first.
 */
export function ratePairs(codes, baseCurrency) {
  const pairs = []
  for (let i = 0; i < codes.length; i++) {
    for (let j = i + 1; j < codes.length; j++) {
      const [a, b] = [codes[i], codes[j]]
      if (a === ANCHOR || b === ANCHOR) pairs.push(`${ANCHOR}/${a === ANCHOR ? b : a}`)
      else if (a === baseCurrency) pairs.push(`${b}/${a}`)
      else pairs.push(`${a}/${b}`)
    }
  }
  const touchesBase = (pair) => pair.split('/').includes(baseCurrency)
  return pairs.sort((x, y) => Number(!touchesBase(x)) - Number(!touchesBase(y)) || x.localeCompare(y))
}

/**
 * The pairs of a company's stored rates, each once and read the same way
 * as ratePairs (USD as the base, else the company's base currency as the
 * quote): for a user who may read rates but not the currency settings.
 */
export function pairsFromRates(rates, baseCurrency) {
  const codes = new Map()
  for (const rate of rates) {
    const key = [rate.base, rate.quote].sort().join('/')
    codes.set(key, [rate.base, rate.quote])
  }
  const pairs = new Set()
  for (const [a, b] of codes.values()) {
    for (const pair of ratePairs([a, b], baseCurrency)) pairs.add(pair)
  }
  const touchesBase = (pair) => pair.split('/').includes(baseCurrency)
  return [...pairs].sort((x, y) => Number(!touchesBase(x)) - Number(!touchesBase(y)) || x.localeCompare(y))
}
