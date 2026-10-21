const fs = require('fs');
const path = require('path');

// NFR-11: the POS app runs on Android 8 (API 26) and later. The minimum SDK
// comes from React Native's default (gradle/libs.versions.toml) unless the
// app config overrides it; this fails if either would drop Android 8.
const ANDROID_8_API = 26;

describe('Android support (NFR-11)', () => {
  it('keeps the minimum Android SDK at or below Android 8', () => {
    const toml = fs.readFileSync(require.resolve('react-native/gradle/libs.versions.toml', { paths: [path.join(__dirname, '..')] }), 'utf8');
    const minSdk = Number(/^minSdk\s*=\s*"(\d+)"/m.exec(toml)?.[1]);
    expect(minSdk).toBeGreaterThan(0);
    expect(minSdk).toBeLessThanOrEqual(ANDROID_8_API);

    const app = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'app.json'), 'utf8')).expo;
    const overrides = (app.plugins ?? []).filter((plugin) => Array.isArray(plugin) && plugin[0] === 'expo-build-properties').map((plugin) => plugin[1]?.android?.minSdkVersion);
    for (const value of overrides) expect(value).toBeLessThanOrEqual(ANDROID_8_API);
  });
});
