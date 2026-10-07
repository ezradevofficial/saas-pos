// Expo's default config already watches the npm workspace root, so Metro
// resolves @app/tokens from packages/tokens (monorepo support since SDK 52).
const path = require('path');
const { getDefaultConfig } = require('expo/metro-config');
const { withNativeWind } = require('nativewind/metro');

const config = getDefaultConfig(__dirname);

// pos pins React to Expo SDK 57's 19.2.3 while web uses 19.3 (docs/adr/005-styling.md).
// react/react-dom are excluded from autolinking (package.json "expo.autolinking")
// so expo-doctor's duplicate check passes, which also drops autolinking's React
// dedupe; resolve every react/react-dom import from pos instead, so the bundle
// holds exactly one React, the version React Native requires.
const upstream = config.resolver.resolveRequest;
config.resolver.resolveRequest = (context, moduleName, platform) => {
  const resolve = upstream ?? context.resolveRequest;
  if (/^react(-dom)?(\/|$)/.test(moduleName)) {
    return resolve({ ...context, originModulePath: path.join(__dirname, 'index.js') }, moduleName, platform);
  }
  return resolve(context, moduleName, platform);
};

module.exports = withNativeWind(config, { input: './global.css' });
