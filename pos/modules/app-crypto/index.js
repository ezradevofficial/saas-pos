import { requireOptionalNativeModule } from 'expo';

/**
 * The local AppCrypto native module (android/, ios/), or null where it is
 * not built in: the Expo web preview, Jest, Expo Go. Callers fall back to
 * WebCrypto, then @noble/hashes (src/auth/pinCrypto.js).
 */
export default requireOptionalNativeModule('AppCrypto');
