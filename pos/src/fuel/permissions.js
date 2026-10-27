/**
 * FUEL-31, FUEL-32, RBAC-01: what the signed-in person may do on the
 * forecourt, from their synced staff row. The UI hides what they cannot do;
 * the server decides again on upload (a shift by someone without the right is
 * kept and flagged). An Owner holds every permission.
 */
export const FUEL = {
  sell: 'fuel.sale.create',
  shiftOpen: 'fuel.shift.open',
  shiftClose: 'fuel.shift.close',
  dip: 'fuel.dip.record',
  requestReset: 'fuel.meter.request_reset',
  redeem: 'fuel.voucher.redeem',
};

export const can = (user, permission) => Boolean(user?.owner || user?.permissions?.includes(permission));

/** The POS mode of a forecourt till (the tenant's module key is `fuel`). */
export const FUEL_MODE = 'fuel_station';

export const isFuelMode = (mode) => mode === FUEL_MODE;
