import { registerRootComponent } from 'expo';

import App from './App';
import { initSentry } from './src/lib/sentry';

// NFR-10: error tracking, only when the build has a DSN (src/lib/sentry.js).
initSentry();

// registerRootComponent calls AppRegistry.registerComponent('main', () => App);
// It also ensures that whether you load the app in Expo Go or in a native build,
// the environment is set up appropriately
registerRootComponent(App);
