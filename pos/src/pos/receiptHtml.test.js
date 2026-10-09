import { receiptHtml } from './receiptHtml';

jest.mock('expo-print', () => ({ printAsync: jest.fn(async () => undefined) }));

// POS-06: the printed receipt is black on white, escaped, with the fiscal section locked.
const model = {
  lang: 'en',
  header: { title: 'Duka <Ltd>', lines: ['Tax ID P051', 'Westlands · Till 2'] },
  number: { label: 'Receipt', value: 'R-WL2-000001' },
  rows: [{ label: 'Served by', value: 'Baraka Mwangi' }],
  lines: [{ id: 'l1', name: 'Tusker "Lager"', detail: '2 × KES 250.00', total: 'KES 500.00', discount: null }],
  totals: [{ label: 'Total', value: 'KES 565.00', strong: true }],
  payments: [{ label: 'Cash', value: 'KES 600.00' }],
  fiscal: { authority: 'KRA eTIMS', status: 'Pending', help: 'Added once accepted.' },
};

describe('receiptHtml', () => {
  it('prints black on white with every value escaped and the fiscal section', () => {
    const html = receiptHtml(model);
    expect(html).toContain('background: #fff; color: #000;');
    expect(html).toContain('Duka &lt;Ltd&gt;');
    expect(html).toContain('Tusker &quot;Lager&quot;');
    expect(html).toContain('<td class="amount">KES 565.00</td>');
    expect(html).toMatch(/KRA eTIMS<\/strong><\/div><div>Pending/);
    expect(html).not.toContain('var(--');
  });

  it('prints the logo in black and white, and only an image the till fetched (BR-02)', () => {
    const logo = 'data:image/png;base64,iVBORw0KGgo=';
    const html = receiptHtml({ ...model, logo });
    expect(html).toContain(`<img class="logo" alt="" src="${logo}">`);
    expect(html).toContain('filter: grayscale(1)');
    expect(receiptHtml({ ...model, logo: 'https://example.org/x.png' })).not.toContain('<img');
    expect(receiptHtml({ ...model, logo: 'data:image/svg+xml;base64,PHN2Zz4=' })).not.toContain('<img');
    expect(receiptHtml(model)).not.toContain('<img');
  });

  it('sends the HTML to the native print service', async () => {
    const { printReceipt } = require('../screens/pos/ReceiptScreen');
    await printReceipt(model);
    expect(require('expo-print').printAsync).toHaveBeenCalledWith({ html: receiptHtml(model) });
  });
});
