import fs from 'fs';
import path from 'path';
import en from '../../locales/en.json';
import fr from '../../locales/fr.json';
import { code128Widths } from './code128';
import { renderTemplate } from './renderTemplate';
import { applyVariant, matches } from './resolve';
import { templateText } from './templateText';

// TPL-01..TPL-03: the till renders the shared fixtures to the same text as the server
// (api/tests/Feature/Core/DocumentTemplates/TemplateFixturesTest.php).
const FIXTURES = path.resolve(__dirname, '../../../../api/tests/Fixtures/templates');
const labels = { en: en.templates.print, fr: fr.templates.print };
const files = fs.readdirSync(FIXTURES).filter((file) => file.endsWith('.json'));

describe('renderTemplate', () => {
  it('has fixtures to compare', () => {
    expect(files.length).toBeGreaterThanOrEqual(4);
  });

  it.each(files)('renders %s to the same text as the server', (file) => {
    const fixture = JSON.parse(fs.readFileSync(path.join(FIXTURES, file), 'utf8'));
    const expected = fs.readFileSync(path.join(FIXTURES, file.replace(/\.json$/, '.expected.txt')), 'utf8').replace(/\n$/, '');
    const html = renderTemplate(fixture.type, fixture.template, fixture.data, { fiscalRequired: fixture.fiscal_required, labels });
    expect(templateText(html)).toBe(expected);
  });

  it('prints black on white, escaped, without theme tokens, with the server-drawn fiscal QR', () => {
    const fixture = JSON.parse(fs.readFileSync(path.join(FIXTURES, 'receipt-80mm-en.json'), 'utf8'));
    const data = { ...fixture.data, fiscal: { ...fixture.data.fiscal, qr_svg: 'data:image/svg+xml;base64,AAAA' } };
    const html = renderTemplate(fixture.type, fixture.template, data, { fiscalRequired: true, labels });
    expect(html).toContain('background: #fff; color: #000;');
    expect(html).not.toContain('var(--');
    expect(html).toContain('Duka &quot;Wholesale&quot; Ltd');
    expect(html).toContain('<img src="data:image/svg+xml;base64,AAAA"');
    expect(html).toContain('@page { size: 80mm auto;');
  });

  it('adds the locked fiscal block when the template lacks it and skips unknown blocks', () => {
    const html = renderTemplate('pos.receipt', { paper: '80mm', language: 'en', blocks: [{ id: 'x', type: 'carousel' }] }, { fiscal: { authority: 'dgi', status: 'pending' } }, { fiscalRequired: true, labels });
    expect(templateText(html)).toBe(['DGI fiscal receipt', 'Pending', labels.en.fiscal.help.pending].join('\n'));
  });
});

describe('images the till prints', () => {
  const fiscal = { authority: 'kra_etims', status: 'accepted', qr: 'https://etims.example/v' };
  const render = (data, qrSvg) => renderTemplate('pos.receipt', { paper: '80mm', language: 'en', blocks: [{ id: 'q', type: 'qr', content: 'x' }, { id: 'f', type: 'fiscal' }] }, data, { labels, qrSvg });

  it('prints only base64 SVG data URIs as QR codes, escaped', () => {
    const ok = 'data:image/svg+xml;base64,QUJD';
    const html = render({ fiscal: { ...fiscal, qr_svg: ok } }, () => ok);
    expect(html.match(/<img src="data:image\/svg\+xml;base64,QUJD"/g)).toHaveLength(2);

    const hostile = 'data:image/svg+xml;base64,AA" onerror="alert(1)';
    const refused = render({ fiscal: { ...fiscal, qr_svg: 'javascript:alert(1)' } }, () => hostile);
    expect(refused).not.toContain('<img');
    expect(refused).not.toContain('onerror');
  });

  it('prints PNG and JPEG logos only (no SVG)', () => {
    const logo = (uri) => renderTemplate('letter', { paper: 'A4', blocks: [{ id: 'l', type: 'logo' }] }, { company: { logo: uri } }, { labels });
    expect(logo('data:image/png;base64,iVBORw0KGgo=')).toContain('<img class="logo" src="data:image/png;base64,iVBORw0KGgo="');
    // BR-02: black and white on paper.
    expect(logo('data:image/png;base64,iVBORw0KGgo=')).toContain('.logo { filter: grayscale(1)');
    expect(logo('data:image/svg+xml;base64,PHN2Zy8+')).not.toContain('<img');
  });
});

describe('Code 128', () => {
  it('encodes printable ASCII with its checksum and stop pattern', () => {
    // "PJJ123C": start B, 7 symbols and the checksum (11 modules each), then the stop (13).
    const widths = code128Widths('PJJ123C');
    expect(widths.reduce((a, b) => a + b, 0)).toBe(11 * 9 + 13);
    // Every symbol pattern is 11 modules wide (the stop 13).
    expect(code128Widths(" ~").slice(0, 6).reduce((a, b) => a + b, 0)).toBe(11);
    expect(code128Widths('Zoë')).toBeNull();
  });
});

describe('variants (TPL-05)', () => {
  const payload = {
    paper: '80mm',
    language: 'en',
    blocks: [{ id: 'a', type: 'text', text: 'Base' }],
    variants: [
      { id: 'vip', name: 'VIP', applies_when: { customer_tags: ['VIP'] }, blocks: [{ id: 'a', type: 'text', text: 'VIP' }] },
      { id: 'big', name: 'Big', applies_when: { conditions: [{ field: 'totals.total', op: 'gte', value: '10000.00' }] }, language: 'fr', blocks: [] },
    ],
  };
  const data = (tags, minor) => ({ currencies: { KES: 2 }, customer: { tags }, totals: { total: { minor, currency: 'KES' } } });

  it('picks the first matching variant, else the template', () => {
    expect(applyVariant(payload, data(['vip'], '100')).variant).toBe('vip');
    expect(applyVariant(payload, data([], '1000000')).template.language).toBe('fr');
    expect(applyVariant(payload, data(['vip'], '1000000')).variant).toBe('vip');
    const base = applyVariant(payload, data([], '999999'));
    expect([base.variant, base.template.blocks[0].text, 'variants' in base.template]).toEqual([null, 'Base', false]);
  });

  it('checks every operator like the server', () => {
    const d = { customer: { name: 'Amina', tags: ['vip', 'staff'] }, document: { number: 'R-1' }, totals: { total: { minor: '5000', currency: 'CDF' } }, currencies: { CDF: 0 } };
    expect(matches({ conditions: [{ field: 'totals.total', op: 'eq', value: '5000' }] }, d)).toBe(true);
    expect(matches({ conditions: [{ field: 'customer.name', op: 'ne', value: 'amina' }] }, d)).toBe(false);
    expect(matches({ conditions: [{ field: 'customer.tags', op: 'contains', value: 'STAFF' }] }, d)).toBe(true);
    expect(matches({ conditions: [{ field: 'customer.email', op: 'empty' }] }, d)).toBe(true);
    expect(matches({ conditions: [{ field: 'document.number', op: 'not_empty' }, { field: 'totals.total', op: 'lt', value: 5000 }] }, d)).toBe(false);
  });
});
