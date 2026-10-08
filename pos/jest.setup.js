process.env.EXPO_PUBLIC_APP_NAME = 'Test app';

// Safe-area insets need a native module; the library ships a jest mock.
jest.mock('react-native-safe-area-context', () => require('react-native-safe-area-context/jest/mock').default);

// NetInfo needs a native module; the library ships a jest mock.
jest.mock('@react-native-community/netinfo', () => require('@react-native-community/netinfo/jest/netinfo-mock.js'));

// Components translate through the app's i18next instance, as App.js does.
require('./src/i18n');

// WatermelonDB logs every LokiJS start; keep test output readable.
require('@nozbe/watermelondb/utils/common/logger').default.silence();
