const { defineConfig } = require('vitest/config');

module.exports = defineConfig({
  test: { include: ['test/**/*.test.js'], root: __dirname, globalSetup: ['test/global-setup.js'] },
});
