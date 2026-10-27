/**
 * VAN-24, NFR-08: the optional GPS check-in. Only when the van row says
 * `gps_checkin: 'optional'`. The screen explains first (in the app), then the
 * phone asks; one position is read at arrival, in the foreground, never in the
 * background and never as a trail. Refusing leaves the rep working.
 * `location` is expo-location (injected, so tests drive it without a device).
 */
let locationModule;
export function loadLocation() {
  if (locationModule === undefined) {
    try {
      locationModule = require('expo-location');
    } catch {
      locationModule = null;
    }
  }
  return locationModule;
}

export const checkInOn = (van) => van?.gps_checkin === 'optional';

/** 'granted' | 'explain' (not asked yet) | 'denied' | 'blocked' | 'unavailable'. */
export async function checkInPermission(location) {
  if (!location) return 'unavailable';
  try {
    const permission = await location.getForegroundPermissionsAsync();
    if (permission?.granted) return 'granted';
    if (permission?.status === 'denied') return permission.canAskAgain === false ? 'blocked' : 'denied';
    return 'explain';
  } catch {
    return 'unavailable';
  }
}

/** After the in-app explanation: the phone's own question. Resolves the same states. */
export async function askCheckInPermission(location) {
  try {
    const permission = await location.requestForegroundPermissionsAsync();
    if (permission?.granted) return 'granted';
    return permission?.canAskAgain === false ? 'blocked' : 'denied';
  } catch {
    return 'denied';
  }
}

/** One position at balanced accuracy, or null (no fix: the rep carries on without it). */
export async function readOnePosition(location) {
  try {
    const position = await location.getCurrentPositionAsync({ accuracy: location.Accuracy?.Balanced ?? 3 });
    return position?.coords ? position : null;
  } catch {
    return null;
  }
}
