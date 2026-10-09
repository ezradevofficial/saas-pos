// NUM-01: a number format's pattern, as the API reads it (core
// Numbering\Pattern): {BRANCH}, {LOCATION}, {DEVICE}, {YYYY}, {YY}, {MM}
// and exactly one counter, zeros then a 1 ({000001}), whose length is the
// minimum width. Literal text is letters, digits and - / _ .

export const PLACE_TOKENS = ['LOCATION', 'BRANCH', 'DEVICE']
export const DATE_TOKENS = ['YYYY', 'YY', 'MM']
export const COUNTER = '{000001}'
export const MAX_LENGTH = 60

const TOKEN = /\{([A-Z]+|0{0,11}1)\}/g

/** The tokens a type's pattern may use: its place tokens, the date tokens and the counter. */
export const tokensFor = (placeTokens = []) => [...PLACE_TOKENS.filter((token) => placeTokens.includes(token)).map((token) => `{${token}}`), ...DATE_TOKENS.map((token) => `{${token}}`), COUNTER]

/**
 * The first problem the API would report, as a translation key under
 * `numbering.errors`, or null. The API checks again (and checks collisions
 * and the longest number).
 */
export function patternProblem(pattern, placeTokens = [], reset = 'never') {
  if (!pattern || pattern.length > MAX_LENGTH) return 'length'
  if (!/^[A-Za-z0-9\-/_.]*$/.test(pattern.replace(TOKEN, ''))) return 'characters'
  const tokens = [...pattern.matchAll(TOKEN)].map((match) => match[1])
  const counters = tokens.filter((token) => /^0*1$/.test(token))
  const named = tokens.filter((token) => !/^0*1$/.test(token))
  if (named.some((token) => ![...PLACE_TOKENS, ...DATE_TOKENS].includes(token))) return 'token'
  if (named.some((token) => PLACE_TOKENS.includes(token) && !placeTokens.includes(token))) return 'unavailable'
  if (counters.length !== 1) return 'counter'
  if (reset === 'yearly' && !named.some((token) => token === 'YYYY' || token === 'YY')) return 'yearly'
  return null
}

/**
 * An example number: the place codes given (or samples), today's date and
 * the counter at `counter`, padded to the pattern's width.
 */
export function examplePattern(pattern, { codes = {}, date = new Date(), counter = 1 } = {}) {
  const year = String(date.getFullYear())
  const values = {
    BRANCH: codes.BRANCH || 'BR1',
    LOCATION: codes.LOCATION || 'L01',
    DEVICE: codes.DEVICE || 'T01',
    YYYY: year,
    YY: year.slice(2),
    MM: String(date.getMonth() + 1).padStart(2, '0'),
  }
  return String(pattern ?? '').replace(TOKEN, (whole, token) => (/^0*1$/.test(token) ? String(counter).padStart(token.length, '0') : (values[token] ?? whole)))
}
