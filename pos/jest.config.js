const path = require('path');

// pos pins React to Expo SDK 57's version (19.2.3) while web uses 19.3; npm may
// hoist either copy (docs/adr/005-styling.md). Resolve React from pos so the
// app, react-native and the test renderer share one copy whether or not pos
// has a nested node_modules/react.
const packageDir = (name) => path.dirname(require.resolve(`${name}/package.json`, { paths: [__dirname] }));
const react = packageDir('react');
const reactDom = packageDir('react-dom');

/** @type {import('jest').Config} */
module.exports = {
  preset: 'jest-expo',
  setupFiles: ['./jest.setup.js'],
  // jest-expo's list, plus @noble/hashes (ES modules only) and WatermelonDB.
  transformIgnorePatterns: [
    '/node_modules/(?!(.pnpm|react-native|@react-native|@react-native-community|expo|@expo|@expo-google-fonts|react-navigation|@react-navigation|@sentry/react-native|native-base|standard-navigation|@noble|@nozbe))',
    '/node_modules/react-native-reanimated/plugin/',
    '/node_modules/@react-native/babel-preset/',
  ],
  moduleNameMapper: {
    '^react$': react,
    '^react/(.*)$': `${react}/$1`,
    '^react-dom$': reactDom,
    '^react-dom/(.*)$': `${reactDom}/$1`,
    // global.css is compiled by NativeWind's Metro transformer; Jest only needs a module.
    '\\.css$': '<rootDir>/jest.css-stub.js',
  },
};
