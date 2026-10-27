import { taxRateFor, TaxRateNeeded } from '../pos/tax';

/**
 * REST-06, REST-07: pricing a menu item for an order line and choosing its
 * modifiers.
 *
 * A line is priced when it is added, as the till sells it (default price
 * list, today's tax rate); the order keeps that price whatever happens to
 * the list later. Modifier groups attach to items or categories and have
 * min and max choices; a modifier's price effect is 0 or more minor units.
 * The line's price is (unit price + effects) x quantity.
 */
export class MenuError extends Error {
  constructor(code, extra = {}) {
    super(code);
    this.name = 'MenuError';
    this.code = code;
    Object.assign(this, extra);
  }
}

/** The line fields for an item sold now: { itemId, name, unitPriceMinor, taxCodeId, taxRate, taxInclusive }. Throws MenuError. */
export function priceItem(catalogue, itemId) {
  const item = catalogue.itemById.get(itemId);
  const tile = catalogue.tiles.find((candidate) => candidate.id === itemId);
  if (!item || !tile) throw new MenuError('item_unknown');
  if (!tile.sellable) throw new MenuError(tile.reason ?? 'not_sellable');
  const list = catalogue.defaultList;
  const price = list ? catalogue.priceFor(item, catalogue.salesUom(item), list.id, '1') : null;
  if (!price) throw new MenuError('price_missing');
  let taxRate = null;
  try {
    taxRate = taxRateFor(catalogue.taxCodes.get(item.tax_code_id), catalogue.day).rate;
  } catch (error) {
    if (error instanceof TaxRateNeeded) throw new MenuError('tax_rate_needed');
    throw error;
  }
  return {
    itemId: item.id,
    name: item.name,
    unitPriceMinor: String(price.amountMinor),
    taxCodeId: item.tax_code_id ?? null,
    taxRate,
    taxInclusive: Boolean(list.tax_inclusive),
  };
}

/** The modifier groups that apply to an item: attached to it, or to its category. Archived modifiers are left out. */
export function groupsFor(groups, item) {
  return groups
    .filter((group) => !group.archived_at && ((group.item_ids ?? []).includes(item.id) || (item.category_id && (group.category_ids ?? []).includes(item.category_id))))
    .map((group) => ({ ...group, modifiers: (group.modifiers ?? []).filter((modifier) => !modifier.archived_at).sort((a, b) => (a.sort ?? 0) - (b.sort ?? 0)) }));
}

const maxOf = (group) => (Number(group.max_select) > 0 ? Number(group.max_select) : group.modifiers.length);

/**
 * Whether a choice satisfies the groups. `chosen` maps a group id to the
 * chosen modifier ids. Returns [{ groupId, problem: 'min' | 'max', limit }]
 * (empty when fine).
 */
export function choiceProblems(groups, chosen) {
  const problems = [];
  for (const group of groups) {
    const count = (chosen[group.id] ?? []).length;
    const min = Number(group.min_select) || 0;
    if (count < min) problems.push({ groupId: group.id, problem: 'min', limit: min });
    else if (count > maxOf(group)) problems.push({ groupId: group.id, problem: 'max', limit: maxOf(group) });
  }
  return problems;
}

/** The chosen modifiers as a line stores them: [{ id, name, price_minor }], in the groups' order. */
export function chosenModifiers(groups, chosen) {
  return groups.flatMap((group) => group.modifiers.filter((modifier) => (chosen[group.id] ?? []).includes(modifier.id)).map((modifier) => ({ id: modifier.id, name: modifier.name, price_minor: String(modifier.price_minor ?? 0) })));
}

/** Whether tapping an item must ask for a choice first (some group has a minimum, or any group exists). */
export const needsChoice = (groups) => groups.length > 0;

/** unit price plus the modifier effects, minor units as BigInt. */
export function unitWithModifiers(line) {
  return BigInt(line.unit_price_minor) + (line.modifiers ?? []).reduce((sum, modifier) => sum + BigInt(modifier.price_minor ?? 0), 0n);
}
