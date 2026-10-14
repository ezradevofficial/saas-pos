// The Loyalty back office (docs/modules/loyalty.md): who may open each
// page, the words and tones of statuses, and small shared hooks.
import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '@/api/client'
import { useCompanies, useCompanySelection } from '@/layouts/companySelection'

export const PROGRAMME_VIEW = ['loyalty.programme.view', 'loyalty.programme.edit']
export const PROMOTION_VIEW = ['loyalty.promotion.view', 'loyalty.promotion.create', 'loyalty.promotion.edit']
export const CREDIT_VIEW = 'loyalty.credit.view'
export const GIFT_CARD_VIEW = 'loyalty.gift_card.view'
export const CUSTOMER_TAB = ['loyalty.points.view', 'loyalty.credit.view']
export const SALE_VIEW = 'loyalty.sale.view'
/** LOY-12, H2: the flags a manager filters the review list by (the API's codes). */
export const REVIEW_FLAGS = ['points_overdrawn', 'gift_card_overdrawn', 'coupon_overused', 'promotion_differs', 'credit_not_permitted', 'credit_limit_exceeded', 'gift_card_sale_mismatch', 'gift_card_unknown', 'voided_before_processing', 'processing_failed']

export const PROMOTION_TYPES = ['percent', 'amount_off', 'buy_x_get_y', 'bundle']
export const PROMOTION_STATUSES = ['draft', 'active', 'archived']
export const PROMOTION_TONES = { draft: 'neutral', active: 'success', archived: 'neutral' }
export const GIFT_CARD_STATUSES = ['inactive', 'active', 'blocked']
export const GIFT_CARD_TONES = { inactive: 'neutral', active: 'success', blocked: 'danger' }
export const ACCOUNT_TONES = { open: 'success', closed: 'neutral' }
export const AGEING_BUCKETS = ['0_30', '31_60', '61_90', '90_plus']
export const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7]

/**
 * The company a loyalty page works on, picked as Labels does: the one
 * chosen on the page, else the switcher's, else the only (or first)
 * active company. `companies` are the active ones (a page offers a choice
 * when there are several).
 */
export function useLoyaltyCompanyChoice() {
  const { company: switcher } = useCompanySelection()
  const active = useCompanies().companies.filter((entry) => !entry.archived_at)
  const [chosen, setChosen] = useState('')
  const company = active.find((entry) => entry.id === chosen) ?? switcher ?? active[0] ?? null
  return { company, companies: active, choose: setChosen }
}

/** The company the loyalty pages work in (useLoyaltyCompanyChoice, without a choice on the page). */
export function useLoyaltyCompany() {
  return useLoyaltyCompanyChoice().company
}

/** The company's items and categories, for promotion targets (shared ones included). */
export function useCatalogueOptions() {
  const items = useQuery({ queryKey: ['loyalty', 'items'], queryFn: () => api.get('items?per_page=200') })
  const categories = useQuery({ queryKey: ['loyalty', 'categories'], queryFn: () => api.get('item-categories?per_page=200') })
  return {
    items: (items.data?.data ?? []).map((item) => ({ value: item.id, label: item.name })),
    categories: (categories.data?.data ?? []).map((category) => ({ value: category.id, label: category.name })),
  }
}

export function useBranches(companyId) {
  const query = useQuery({ queryKey: ['loyalty', 'branches', companyId], queryFn: () => api.get(`companies/${companyId}/branches?per_page=200`), enabled: Boolean(companyId) })
  return (query.data?.data ?? []).map((branch) => ({ value: branch.id, label: branch.name }))
}

/** The API's field errors as { field: first message } (422 validation_failed). */
export function fieldErrors(error) {
  return Object.fromEntries(Object.entries(error?.errors ?? {}).map(([key, messages]) => [key, Array.isArray(messages) ? messages[0] : String(messages)]))
}
