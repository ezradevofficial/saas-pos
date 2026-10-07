process.env.EXPO_PUBLIC_APP_NAME = 'Test app';

// Safe-area insets need a native module; the library ships a jest mock.
jest.mock('react-native-safe-area-context', () => require('react-native-safe-area-context/jest/mock').default);

// Components translate through the app's i18next instance, as App.js does.
require('./src/i18n');
