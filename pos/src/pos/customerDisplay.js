/**
 * LAY-05: the customer display. The till publishes what the customer may
 * see (the current sale, already formatted, plus the layout's display
 * settings, the theme and the logo) on a channel; the display only listens
 * and never touches the till's database, so it can run in a second browser
 * tab (the web preview, a second monitor) or as an in-app screen turned to
 * face the customer.
 *
 * - On the web, a BroadcastChannel joins the tabs of one origin. A display
 *   that opens says `hello`; the till answers with the latest state.
 * - Elsewhere (native, Jest), one in-memory channel per app. A native second
 *   screen (Android Presentation) needs a native module and comes later.
 */
export const CHANNEL_NAME = 'app-customer-display';

/** The display's query flag in the web preview: ?display=customer. */
export const DISPLAY_QUERY = 'display=customer';

function memoryChannel() {
  const listeners = new Set();
  return {
    post(message) {
      // Synchronous, to every listener (a copy, so one that stops listening meanwhile is safe).
      for (const listener of Array.from(listeners)) listener(message);
    },
    listen(listener) {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },
    close() {
      listeners.clear();
    },
  };
}

function broadcastChannel(name) {
  const sender = new globalThis.BroadcastChannel(name);
  return {
    post(message) {
      sender.postMessage(message);
    },
    listen(listener) {
      // A channel object never hears its own messages: listen on a second one.
      const receiver = new globalThis.BroadcastChannel(name);
      receiver.onmessage = (event) => listener(event.data);
      return () => receiver.close();
    },
    close() {
      sender.close();
    },
  };
}

let shared = null;

/** The app's display channel (one per JS context). */
export function displayChannel() {
  if (!shared) shared = typeof globalThis.BroadcastChannel === 'function' ? broadcastChannel(CHANNEL_NAME) : memoryChannel();
  return shared;
}

/** Tests: a fresh in-memory channel. */
export function resetDisplayChannel(channel = memoryChannel()) {
  shared?.close();
  shared = channel;
  return shared;
}

/**
 * What the customer sees, as plain JSON: lines and totals already in words
 * ("KES 1,125.00"), the welcome text, the logo (a data URI) and the theme
 * the till draws in. `text(minor, currency)` formats money; `second` is the
 * total in the second currency, in words (CUR-05).
 */
export function displayState({ catalogue, computed, cart, display, text, theme, logo, second = null }) {
  const currency = catalogue?.saleCurrency ?? null;
  const lines = (computed?.lines ?? []).map((line) => ({
    id: line.id,
    name: line.name,
    qty: String(line.qty),
    total: line.amounts?.blocked || !currency ? '—' : text(line.amounts.totalMinor, currency),
  }));
  const totals = computed?.totals ?? null;
  const has = lines.length > 0 && totals && currency;
  return {
    v: 1,
    company: catalogue?.settings?.company?.name ?? null,
    welcome: display?.welcome ?? null,
    logo: display?.show_logo === false ? null : (logo ?? null),
    theme: theme ?? null,
    showLines: display?.show_lines !== false,
    customer: cart?.customer?.name ?? null,
    count: computed?.itemCount ?? 0,
    lines,
    totals: has
      ? {
          subtotal: text(totals.subtotal_minor, currency),
          discount: totals.discount_minor && totals.discount_minor !== '0' ? text(totals.discount_minor, currency) : null,
          tax: text(totals.tax_minor, currency),
          total: text(totals.total_minor, currency),
        }
      : null,
    second: has && display?.show_second_currency !== false && second ? second : null,
  };
}
