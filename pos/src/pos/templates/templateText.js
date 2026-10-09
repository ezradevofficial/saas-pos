/**
 * TPL-01: rendered template HTML reduced to its text, the same steps as
 * the server's tests/Support/Templates/TemplateText.php, so the two
 * renderers are compared on the shared fixtures.
 */
const ENTITIES = { amp: '&', lt: '<', gt: '>', quot: '"', '#039': "'", '#39': "'", apos: "'", nbsp: ' ' };

export function templateText(html) {
  let text = html.replace(/<(style|title)[^>]*>[\s\S]*?<\/\1>/g, '');
  text = text.replace(/<br\s*\/?>|<\/(div|tr|p|thead|tbody|table)>/gi, '\n');
  text = text.replace(/<\/t[dh]>/gi, ' ');
  text = text.replace(/<[^>]*>/g, '');
  text = text.replace(/&(#?\w+);/g, (match, name) => ENTITIES[name] ?? match);
  return text
    .split('\n')
    .map((line) => line.replace(/\s+/gu, ' ').trim())
    .filter((line) => line !== '')
    .join('\n');
}
