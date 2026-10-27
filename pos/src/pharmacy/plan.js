import { check } from '../pos/authority';
import { allocate, prescriptionProblem, rulesOf, toBase } from './engine';

/**
 * The pharmacy's reading of a sale, shared by the cart adjuster (what blocks
 * the sale, what goes to the server) and the cart panel (what to show).
 * Needs `catalogue.ext.pharmacy` (pharmacy.js loader); null without it.
 *
 * Per line: the rules that apply (rules), the lots it takes (alloc) and why
 * it cannot be charged yet (problem). The sale-wide `sale.rx`,
 * `sale.pharmacist` and `sale.buyer` say what the cashier still has to supply.
 */
export function planSale({ lines, cart, catalogue, at, user }) {
  const data = catalogue?.ext?.pharmacy;
  if (!data?.profiles?.size) return null;
  const mine = cart.ext?.pharmacy ?? {};
  const today = catalogue.dayAt ? catalogue.dayAt(at ?? Date.now()) : catalogue.day;
  const settings = data.settings ?? {};
  const perLine = new Map();
  const sale = { rx: false, pharmacist: false, buyer: false };

  for (const line of lines) {
    const profile = data.profiles.get(line.itemId);
    const rules = rulesOf(profile, settings);
    if (!profile || !(rules.rx || rules.pharmacist || rules.lots || rules.notes)) continue;
    const item = catalogue.itemById?.get(line.itemId);
    const entry = { line, profile, rules, alloc: null, problem: null };
    if (rules.lots) {
      entry.alloc = allocate({ lots: data.lots.get(line.itemId) ?? [], used: data.used, settings, today, qty: toBase(item, line.uomId, line.qty), picks: mine.lots?.[line.id] ?? [] });
      entry.problem = entry.alloc.reason;
    }
    sale.rx ||= rules.rx;
    sale.pharmacist ||= rules.pharmacist;
    sale.buyer ||= rules.buyer;
    perLine.set(line.id, entry);
  }
  if (!perLine.size) return null;

  const prescription = mine.prescription ?? null;
  const rxProblem = sale.rx ? prescriptionProblem(prescription, settings, today) : null;
  const own = check(user, 'dispense').allowed;
  const approved = Boolean(mine.approval) || own;
  for (const entry of perLine.values()) {
    if (entry.problem) continue;
    if (entry.rules.rx && rxProblem) entry.problem = rxProblem;
    else if (entry.rules.pharmacist && !approved) entry.problem = 'pharmacist_needed';
    else if (entry.rules.buyer && !cart.customer) entry.problem = 'buyer_needed';
  }
  return { perLine, today, settings, sale, prescription, rxProblem, ownApproval: own, approval: mine.approval ?? null, picks: mine.lots ?? {} };
}

/** The cart adjuster (extensions.js): blocks lines that cannot be sold yet, sends the batches, prescription and approval with the sale. */
export function pharmacyAdjuster(context) {
  const plan = planSale(context);
  if (!plan) return {};
  const blocked = new Map();
  const lines = [];
  for (const [lineId, entry] of plan.perLine) {
    if (entry.problem) blocked.set(lineId, entry.problem);
    if (entry.alloc) lines.push({ line_id: lineId, lots: entry.alloc.taken.map((row) => ({ lot_id: row.lot_id, qty: row.qty })) });
  }
  const extension = { lines };
  if (plan.sale.rx && plan.prescription && !plan.rxProblem) extension.prescription = plan.prescription;
  if (plan.sale.pharmacist && plan.approval) extension.approval = plan.approval;
  return { blocked, extension };
}
