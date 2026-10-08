const { execFileSync } = require('node:child_process');
const path = require('node:path');

// Build dist once before any test file reads it. Each file building on its
// own raced in CI: one file read a file another was still writing.
module.exports = function setup() {
  execFileSync('node', [path.join(__dirname, '..', 'build.mjs')], { stdio: 'pipe' });
};
