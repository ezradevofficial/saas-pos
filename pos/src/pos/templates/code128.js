/**
 * TPL-01: Code 128 (set B: printable ASCII) as an SVG data URI, black on
 * white, for the till's printed templates. The server draws the same
 * symbology with picqer/php-barcode-generator; both print the content as
 * text under the bars.
 */
const PATTERNS = [
  '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
  '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
  '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
  '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
  '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
  '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
  '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
  '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
  '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
  '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
  '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
];
const START_B = 104;
const STOP = 106;

/** The bar and space widths (modules) of $content, or null outside printable ASCII. */
export function code128Widths(content) {
  if (!/^[\x20-\x7E]{1,80}$/.test(content)) return null;
  const codes = [START_B, ...[...content].map((char) => char.charCodeAt(0) - 32)];
  const checksum = codes.reduce((sum, code, index) => sum + code * (index === 0 ? 1 : index), 0) % 103;
  return [...codes, checksum, STOP].flatMap((code) => [...PATTERNS[code]].map(Number));
}

export function code128Svg(content) {
  const widths = code128Widths(content);
  if (widths === null) return null;
  const quiet = 10;
  let x = quiet;
  const bars = [];
  widths.forEach((width, index) => {
    if (index % 2 === 0) bars.push(`<rect x="${x}" y="0" width="${width}" height="40" fill="#000"/>`);
    x += width;
  });
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${x + quiet} 40" width="${(x + quiet) * 2}" height="40"><rect width="100%" height="100%" fill="#fff"/>${bars.join('')}</svg>`;
  return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
}
