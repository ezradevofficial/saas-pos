import { ACTIVE } from './rider';

/**
 * ECOM-29, NFR-04, KE DPA minimum data: shares the rider's position while,
 * and only while, all of these hold:
 * - the rider is signed in,
 * - at least one delivery is active (assigned or picked up),
 * - the rider allowed it (the screen explains first, then asks the OS),
 * - the app is in the foreground (never a background task).
 *
 * Every `intervalMs` (60 s) it reads one position at balanced accuracy and
 * hands it to rider.recordLocation(), which keeps at most one unsent point per
 * delivery. The location module (expo-location) and the timers are injected,
 * so tests drive it without a device.
 */
export const LOCATION_INTERVAL_MS = 60000;

export function createLocationTracker({ rider, location, intervalMs = LOCATION_INTERVAL_MS, timers = { setInterval, clearInterval } }) {
  let signedIn = true;
  let active = 0;
  let permitted = false;
  let foreground = true;
  let timer = null;
  let reading = false;
  let unsubscribe = null;
  let disposed = false;

  const shouldRun = () => !disposed && signedIn && permitted && foreground && active > 0;

  function update() {
    if (shouldRun() && !timer) {
      timer = timers.setInterval(() => {
        tick().catch(() => {});
      }, intervalMs);
      // The first point goes at once, not a minute later.
      tick().catch(() => {});
    } else if (!shouldRun() && timer) {
      timers.clearInterval(timer);
      timer = null;
    }
  }

  function seen(state) {
    if (state?.signedOut) {
      signedIn = false;
      active = 0;
    } else if (state?.deliveries) {
      active = state.deliveries.filter((delivery) => ACTIVE.includes(delivery.status)).length;
    }
    update();
  }

  /** One position, read and queued; skipped while the previous read is still going. */
  async function tick() {
    if (!shouldRun() || reading) return 0;
    reading = true;
    try {
      const position = await location.getCurrentPositionAsync({ accuracy: location.Accuracy?.Balanced ?? 3 });
      // Conditions may have changed while the phone looked for a fix.
      if (!shouldRun() || !position?.coords) return 0;
      const at = Number.isFinite(position.timestamp) ? new Date(position.timestamp).toISOString() : undefined;
      return await rider.recordLocation({ latitude: position.coords.latitude, longitude: position.coords.longitude, occurred_at: at });
    } catch {
      // No fix (indoors, location switched off): try again at the next tick.
      return 0;
    } finally {
      reading = false;
    }
  }

  return {
    /** Follow the rider's deliveries and sign-out. */
    async start() {
      unsubscribe = rider.subscribe(seen);
      seen(await rider.snapshot());
    },
    setPermitted(value) {
      permitted = Boolean(value);
      update();
    },
    setForeground(value) {
      foreground = Boolean(value);
      update();
    },
    running: () => Boolean(timer),
    tick,
    /** Stop for good (the screen unmounts). */
    dispose() {
      disposed = true;
      unsubscribe?.();
      unsubscribe = null;
      update();
    },
  };
}
