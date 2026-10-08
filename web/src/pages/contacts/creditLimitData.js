// MD-01, WF-01: credit limit change requests (the API runs them through
// their flow and applies the limit on approval).
export const CREDIT_REQUEST = 'core.credit_limit.request'
export const CREDIT_SET_DIRECTLY = 'core.credit_limit.set_directly'
export const CREDIT_STATUSES = ['pending', 'applied', 'rejected', 'cancelled', 'approved', 'conflicted']
export const creditChangesKey = ['credit-limit-changes']
