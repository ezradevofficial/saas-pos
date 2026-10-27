import { buildCatalogue } from '../pos/catalogue';

/** Test data for the Restaurant module on the till: a small menu, a floor, stations, modifiers and settings. */
export const id = (n) => `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`;

export const IDS = {
  uom: id(7),
  list: id(2),
  vat: id(3),
  exempt: id(5),
  burger: id(101),
  beer: id(102),
  salad: id(103),
  service: id(110),
  tip: id(111),
  mains: id(201),
  drinks: id(202),
  company: id(1),
  location: id(300),
  waiter: id(10),
  waiter2: id(11),
  manager: id(12),
  area: id(400),
  table1: id(401),
  table2: id(402),
  table3: id(403),
  kitchen: id(501),
  bar: id(502),
  cooking: id(601),
  extras: id(602),
  rare: id(611),
  well: id(612),
  cheese: id(613),
  bacon: id(614),
};

const item = (itemId, code, name, categoryId, taxCodeId = IDS.vat) => ({ id: itemId, code, name, category_id: categoryId, base_uom_id: IDS.uom, tax_code_id: taxCodeId, sellable: true, uoms: [], barcodes: [], images: [] });
const price = (n, itemId, amountMinor) => ({ id: id(700 + n), price_list_id: IDS.list, item_id: itemId, uom_id: IDS.uom, amount_minor: String(amountMinor), currency: 'KES', effective_from: '2026-01-01', min_quantity: '1' });

export const menuRows = {
  settings: { id: 'device', timezone: 'Africa/Nairobi', pos_mode: 'restaurant', device: { id: 'device-1', name: 'Till 2' }, location: { id: IDS.location, name: 'Westlands' }, company: { id: IDS.company, name: 'Duka', country: 'KE', base_currency: 'KES', reporting_currencies: [] } },
  currencies: [{ id: 'KES', code: 'KES', decimals: 2, cash_rounding_minor: '100' }],
  taxCodes: [
    { id: IDS.vat, code: 'VAT16', kind: 'standard', rates: [{ rate: '16.0000', effective_from: '2020-01-01', effective_to: null, needs_confirmation: false }] },
    { id: IDS.exempt, code: 'EX', kind: 'exempt', rates: [] },
  ],
  priceLists: [{ id: IDS.list, name: 'Menu', currency: 'KES', tax_inclusive: false, is_default: true }],
  paymentMethods: [{ id: id(4), type: 'cash', name: 'Cash', currency: 'KES', position: 1 }],
  categories: [
    { id: IDS.mains, name: 'Mains' },
    { id: IDS.drinks, name: 'Drinks' },
  ],
  items: [
    item(IDS.burger, '100', 'Burger', IDS.mains),
    item(IDS.beer, '200', 'Tusker', IDS.drinks),
    item(IDS.salad, '300', 'Salad', IDS.mains),
    item(IDS.service, '900', 'Service charge', null, IDS.vat),
    item(IDS.tip, '901', 'Tip', null, IDS.exempt),
  ],
  prices: [price(1, IDS.burger, 50000), price(2, IDS.beer, 30000), price(3, IDS.salad, 12000), price(4, IDS.service, 1), price(5, IDS.tip, 1)],
  uoms: [{ id: IDS.uom, code: 'pc', name: 'Piece' }],
};

export function menuCatalogue(overrides = {}) {
  return buildCatalogue({ ...menuRows, ...overrides, now: Date.parse('2026-11-02T10:00:00Z') });
}

export const tableRows = [
  { id: IDS.table1, area_id: IDS.area, area_name: 'Main hall', name: 'T1', seats: 4, x: 1, y: 1, width: 2, height: 2, shape: 'square', status: 'free' },
  { id: IDS.table2, area_id: IDS.area, area_name: 'Main hall', name: 'T2', seats: 2, x: 4, y: 1, width: 2, height: 2, shape: 'round', status: 'reserved' },
  { id: IDS.table3, area_id: IDS.area, area_name: 'Terrace', name: 'T3', seats: 6, x: 1, y: 4, width: 3, height: 2, shape: 'rect', status: 'blocked' },
];

export const stationRows = [
  { id: IDS.kitchen, name: 'Kitchen', kind: 'kitchen', printer: null, is_default: true, category_ids: [IDS.mains] },
  { id: IDS.bar, name: 'Bar', kind: 'bar', printer: null, is_default: false, category_ids: [IDS.drinks] },
];

export const modifierRows = [
  {
    id: IDS.cooking,
    company_id: IDS.company,
    name: 'Cooking',
    min_select: 1,
    max_select: 1,
    currency: 'KES',
    item_ids: [IDS.burger],
    category_ids: [],
    modifiers: [
      { id: IDS.rare, name: 'Rare', price_minor: '0', sort: 1 },
      { id: IDS.well, name: 'Well done', price_minor: '0', sort: 2 },
    ],
  },
  {
    id: IDS.extras,
    company_id: IDS.company,
    name: 'Extras',
    min_select: 0,
    max_select: 2,
    currency: 'KES',
    item_ids: [],
    category_ids: [IDS.mains],
    modifiers: [
      { id: IDS.cheese, name: 'Cheese', price_minor: '5000', sort: 1 },
      { id: IDS.bacon, name: 'Bacon', price_minor: '7000', sort: 2 },
    ],
  },
];

export const settingsRow = { id: IDS.company, service_charge_percent: '10.0000', service_charge_item_id: IDS.service, tip_item_id: IDS.tip, tip_presets: [5, 10] };

export const waiter = (extra = {}) => ({ id: IDS.waiter, name: 'Amina Otieno', permissions: ['pos.sale.create', 'restaurant.order.create', 'restaurant.order.edit', 'restaurant.order.send', 'restaurant.order.close', 'restaurant.order.transfer', 'restaurant.order.merge', 'restaurant.floor.view'], limits: {}, ...extra });
