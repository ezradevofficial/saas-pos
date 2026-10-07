// Display name comes from APP_NAME; app.json holds everything else.
module.exports = ({ config }) => ({
  ...config,
  name: process.env.EXPO_PUBLIC_APP_NAME || config.name,
});
