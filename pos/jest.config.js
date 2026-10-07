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
  moduleNameMapper: {
    '^react$': react,
    '^react/(.*)$': `${react}/$1`,
    '^react-dom$': reactDom,
    '^react-dom/(.*)$': `${reactDom}/$1`,
    // global.css is compiled by NativeWind's Metro transformer; Jest only needs a module.
    '\\.css$': '<rootDir>/jest.css-stub.js',
  },
};
