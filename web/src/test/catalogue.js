// Shared test data for the catalogue screens (tests only).
import { CD_COMPANY, mockRoutes, tenantWide } from '@/test/renderApp'

export const UOMS = [
  { id: 'u-ea', code: 'EA', name: 'Each', name_en: 'Each', name_fr: 'Pièce', kind: 'count', archived_at: null },
  { id: 'u-box', code: 'BOX', name: 'Box', name_en: 'Box', name_fr: 'Carton', kind: 'count', archived_at: null },
  { id: 'u-kg', code: 'KG', name: 'Kilogram', name_en: 'Kilogram', name_fr: 'Kilogramme', kind: 'weight', archived_at: null },
  { id: 'u-old', code: 'OLD', name: 'Old unit', name_en: 'Old unit', name_fr: 'Ancienne unité', kind: 'count', archived_at: '2026-10-01T00:00:00Z' },
]

export const CATEGORIES = [
  { id: 'cat-1', company_id: null, parent_id: null, name: 'Drinks', name_en: 'Drinks', name_fr: 'Boissons', archived_at: null },
  { id: 'cat-2', company_id: null, parent_id: 'cat-1', name: 'Sodas', name_en: 'Sodas', name_fr: 'Sodas', archived_at: null },
]

export const ITEM = {
  id: 'i-1',
  company_id: null,
  shared: true,
  code: 'SODA-500',
  name: 'Soda 500 ml',
  name_en: 'Soda 500 ml',
  name_fr: 'Soda 50 cl',
  type: 'stock',
  category_id: 'cat-2',
  base_uom_id: 'u-ea',
  tax_category_id: null,
  uoms: [{ uom_id: 'u-box', code: 'BOX', factor: '24', is_sales_default: false, is_purchase_default: true }],
  barcodes: [
    { barcode: '6001234567890', uom_id: null },
    { barcode: '6001234567891', uom_id: 'u-box' },
  ],
  images: [
    { id: 'img-1', position: 1, url: 'https://media.test/1.png', mime: 'image/png', size: 1000 },
    { id: 'img-2', position: 2, url: 'https://media.test/2.png', mime: 'image/png', size: 1000 },
  ],
  custom: {},
  archived_at: null,
}

export const ITEM_EDITOR = tenantWide([
  'core.company.view',
  'core.item.view',
  'core.item.create',
  'core.item.edit',
  'core.item.archive',
  'core.item_category.view',
  'core.item_category.create',
  'core.item_category.edit',
  'core.item_category.archive',
  'core.uom.view',
])

export function catalogue(api, { permissions = ITEM_EDITOR, item = ITEM, list = [ITEM], mode = 'shared', extra = [] } = {}) {
  mockRoutes(
    api,
    [
      ...extra,
      ['master-data/settings', { data: [{ data_type: 'items', mode }] }],
      ['uoms?status=all&per_page=200', { data: UOMS }],
      ['item-categories?status=all&per_page=200', { data: CATEGORIES }],
      [/^items\?/, { data: list, meta: { current_page: 1, last_page: 2 } }],
      [`items/${item.id}`, { data: item }],
      [/^history\/item\//, { data: [], meta: { current_page: 1, last_page: 1 } }],
    ],
    { permissions, companies: [CD_COMPANY] },
  )
}
