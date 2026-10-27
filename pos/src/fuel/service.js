import { createFuelResets } from './resets';
import { createFuelShifts } from './shifts';

/**
 * The Fuel station module's services (FUEL-09, FUEL-10, FUEL-17, FUEL-22):
 * `shifts` keeps the forecourt shift on the device and queues its open and
 * close; `resets` queues meter reset requests. Both write locally and to the
 * outbox in one step and never wait on the network.
 */
export function createFuel({ engine, store, database, now }) {
  return {
    shifts: createFuelShifts({ engine, store, database, now }),
    resets: createFuelResets({ engine, now }),
  };
}
