// Expo's default config already watches the npm workspace root, so Metro
// resolves @app/tokens from packages/tokens (monorepo support since SDK 52).
const { getDefaultConfig } = require('expo/metro-config');
const { withNativeWind } = require('nativewind/metro');

const config = getDefaultConfig(__dirname);

module.exports = withNativeWind(config, { input: './global.css' });
