/**
 * REST-19, RBAC-01: what the signed-in person may do on the floor, from their
 * synced staff row. The UI hides what they cannot do; the server decides
 * again on upload (a transfer without the right is kept and flagged).
 * An Owner holds every permission.
 */
export const REST = {
  floorView: 'restaurant.floor.view',
  create: 'restaurant.order.create',
  edit: 'restaurant.order.edit',
  send: 'restaurant.order.send',
  transfer: 'restaurant.order.transfer',
  merge: 'restaurant.order.merge',
  close: 'restaurant.order.close',
};

/** The POS modes whose home screen is the floor (concept note 3). */
export const FLOOR_MODES = ['restaurant', 'bar', 'hotel'];

export const can = (user, permission) => Boolean(user?.owner || user?.permissions?.includes(permission));

export const isFloorMode = (mode) => FLOOR_MODES.includes(mode);
