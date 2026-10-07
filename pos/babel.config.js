// NativeWind compiles className to styles through its JSX runtime and babel
// plugin. Jest cannot compute those styles, so the test env keeps className as a
// plain prop and tests assert the token classes instead.
module.exports = function (api) {
  const test = api.env('test');
  return {
    presets: test
      ? ['babel-preset-expo']
      : [['babel-preset-expo', { jsxImportSource: 'nativewind' }], 'nativewind/babel'],
  };
};
