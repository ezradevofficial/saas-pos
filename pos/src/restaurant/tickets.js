import i18n from '../i18n';

/**
 * REST-08, REST-09, REST-10, REST-11, REST-12: the ticket a station gets
 * when lines are sent. The server makes the ticket the kitchen display
 * shows; the till prints the same content to the station's printer.
 *
 * Routing: an item category belongs to one station per location; items of
 * a category no station lists go to the default station (else the first).
 *
 * Printing: the till has no printer driver yet. Tickets go through
 * `ticketPrinter`; the default is a STUB that only keeps them in memory
 * (`stubPrinter.queued`), so the waiter sees "sent" and the kitchen display
 * carries the order. A real printer registers itself with
 * registerTicketPrinter({ name, print(ticket) }).
 */
export const stubPrinter = {
  name: 'stub',
  stub: true,
  queued: [],
  async print(ticket) {
    stubPrinter.queued.push(ticket);
  },
};

let printer = stubPrinter;

export function registerTicketPrinter(next) {
  printer = next ?? stubPrinter;
}

export const isStubPrinter = () => Boolean(printer.stub);

/** The station a line goes to: its category's, else the default, else the first; null when the location has none. */
export function stationFor(line, { stations, catalogue }) {
  if (!stations.length) return null;
  const categoryId = catalogue?.itemById.get(line.item_id)?.category_id ?? null;
  return (categoryId && stations.find((station) => (station.category_ids ?? []).includes(categoryId))) || stations.find((station) => station.is_default) || stations[0];
}

/** Lines grouped by station, in the stations' order: [{ station, lines }] (station null when there are none). */
export function routeLines(lines, context) {
  const groups = new Map();
  for (const line of lines) {
    const station = stationFor(line, context);
    const key = station?.id ?? '';
    if (!groups.has(key)) groups.set(key, { station, lines: [] });
    groups.get(key).lines.push(line);
  }
  return [...groups.values()];
}

const pad = (value) => String(value).padStart(2, '0');

/** The ticket's text for a station: where, who, which course and round, each line with modifiers and notes. */
export function ticketText({ order, lines, station, kind = 'order', round = null, at = new Date(), tableLabel = null }) {
  const word = (key, options) => i18n.t(`restaurant.ticket.${key}`, options);
  const where = order.kind === 'tab' ? word('tab', { name: order.tab_name ?? '' }) : word('table', { name: tableLabel ?? order.local?.table_name ?? '' });
  const time = `${pad(at.getHours())}:${pad(at.getMinutes())}`;
  const rows = [`${station?.name ?? word('kitchen')}${kind === 'order' ? '' : ` - ${word(kind)}`}`, `${where}  ${word('covers', { count: order.covers })}`, `${word('round', { round: round ?? '' })}  ${time}`];
  if (order.local?.waiter_name) rows.push(word('waiter', { name: order.local.waiter_name }));
  rows.push('');
  const courses = [...new Set(lines.map((line) => line.course))].sort((a, b) => a - b);
  for (const course of courses) {
    rows.push(word('course', { course }));
    for (const line of lines.filter((candidate) => candidate.course === course)) {
      rows.push(`${kind === 'void' ? '- ' : ''}${line.qty} x ${line.name}${line.seat ? ` (${word('seat', { seat: line.seat })})` : ''}`);
      for (const modifier of line.modifiers ?? []) rows.push(`    + ${modifier.name}`);
      if (line.note) rows.push(`    * ${line.note}`);
    }
  }
  return rows.join('\n');
}

/**
 * Builds and prints one ticket per station for `lines` of `order`.
 * Resolves [{ stationId, text, kind }]. A printer that fails never fails the
 * send: the lines are sent, the ticket exists on the server and can be re-sent.
 */
export async function printTickets({ order, lines, kind = 'order', round = null, context, at = new Date() }) {
  const tickets = routeLines(lines, context).map(({ station, lines: stationLines }) => ({
    stationId: station?.id ?? null,
    stationName: station?.name ?? null,
    printer: station?.printer ?? null,
    kind,
    text: ticketText({ order, lines: stationLines, station, kind, round, at }),
  }));
  for (const ticket of tickets) {
    try {
      await printer.print(ticket);
    } catch {
      // The kitchen display has the ticket; the waiter can re-send.
    }
  }
  return tickets;
}
