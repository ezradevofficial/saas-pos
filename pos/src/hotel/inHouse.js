import { Q } from '@nozbe/watermelondb';

/**
 * HOT-35, NFR-04: who is in house at this till's property, from the synced
 * `hotel_in_house` table, read locally. Null while the server does not offer
 * the entity (the Hotel module is off, or nothing has been pulled yet): the
 * room-charge tender then does not apply (H3). An empty list is not null:
 * the module is on and nobody is in house.
 *
 * Rows hold only what the cashier needs to confirm the guest: room number
 * and guest name (NFR-08), plus folio id, currency, whether charging is
 * allowed and the optional limit and balance (minor units, strings).
 */
export async function loadHotelData(database) {
  const state = await database.get('sync_state').query(Q.where('id', 'hotel_in_house')).fetch();
  if (!state.length) return null;
  const rooms = (await database.get('hotel_in_house').query().fetch()).map((record) => record.data).filter(Boolean);
  return { rooms };
}

/** A room number as compared: case and surrounding spaces ignored. */
export const roomKey = (text) => String(text ?? '').trim().toLowerCase();

/** The in-house row of a room number as typed, or null. */
export function findRoom(hotel, room) {
  const wanted = roomKey(room);
  if (!wanted) return null;
  return hotel?.rooms?.find((row) => roomKey(row.room_number) === wanted) ?? null;
}
