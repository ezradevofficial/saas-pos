import en from '../../locales/en.json';
import fr from '../../locales/fr.json';
import { buildCatalogue } from '../catalogue';
import { defaultReceiptTemplate, documentDate, receiptDocumentHtml } from './receiptDocument';
import { templateText } from './templateText';

jest.mock('expo-print', () => ({ printAsync: jest.fn(async () => undefined) }));

// POS-06, TPL-01, TPL-03, TPL-05: the till prints receipts from the synced template.
const catalogue = buildCatalogue({
  settings: {
    id: 'device',
    timezone: 'Africa/Nairobi',
    company: { name: 'Duka <Ltd>', legal_name: 'Duka <Ltd>', tax_id: 'P051', country: 'KE', base_currency: 'KES' },
    branch: { name: 'Westlands' },
    location: { name: 'Till 2' },
  },
  taxCodes: [{ id: 'vat', code: 'VAT_T', name: 'VAT test' }],
});

const sale = {
  id: 's1',
  receipt_number: 'R-WL2-000001',
  sold_at: '2026-10-09T11:32:00Z',
  currency: 'KES',
  customer_id: 'c1',
  lines: [{ id: 'l1', item_id: 'i1', item_name: 'Tusker "Lager"', qty: '2', unit_price_minor: '25000', discount_minor: '0', tax_code_id: 'vat', tax_rate: '12.5000', tax_minor: '5556', total_minor: '50000' }],
  totals: { subtotal_minor: '50000', discount_minor: '0', tax_minor: '5556', total_minor: '50000' },
  payments: [{ id: 'p1', currency: 'KES', amount_minor: '60000' }],
  change: { currency: 'KES', amount_minor: '10000' },
  local: { cashier_name: 'Baraka Mwangi', customer_name: 'Amina', methods: { p1: { name: 'Cash' } } },
};

describe('receiptDocumentHtml', () => {
  it('prints the tenant logo the till fetched in a logo block, black and white (BR-02)', () => {
    const logo = 'data:image/png;base64,iVBORw0KGgo=';
    const templateRow = { id: 'pos.receipt', payload: { paper: '80mm', blocks: [{ id: 'logo', type: 'logo' }] }, fiscal: { required: false, authority: null } };
    const html = receiptDocumentHtml({ sale, catalogue, templateRow, fiscal: { state: 'pending' }, logo });
    expect(html).toContain(`<img class="logo" src="${logo}"`);
    expect(receiptDocumentHtml({ sale, catalogue, templateRow, fiscal: { state: 'pending' } })).not.toContain('class="logo"');
  });

  it('prints the default receipt before any template is synced, nothing regressing', () => {
    const html = receiptDocumentHtml({ sale, catalogue, fiscal: { state: 'pending' } });
    expect(templateText(html)).toBe(
      [
        'Duka <Ltd>',
        'Tax ID P051',
        'Westlands · Till 2',
        'Receipt R-WL2-000001',
        'Date 2026-10-09 14:32',
        'Served by Baraka Mwangi',
        'Customer Amina',
        'Tusker "Lager"',
        '2 × KES 250.00 KES 500.00',
        'Subtotal KES 500.00',
        'VAT test 12.5% KES 55.56',
        'Total KES 500.00',
        'Cash KES 600.00',
        'Change KES 100.00',
        'KRA eTIMS',
        'Pending',
        'The authority’s code and QR code are added once the sale reaches the server and the authority accepts it.',
      ].join('\n'),
    );
    expect(html).toContain('Duka &lt;Ltd&gt;');
    expect(html).toContain('background: #fff; color: #000;');
  });

  it('uses the synced template, its variant for the customer, its wording and the locked fiscal block', () => {
    const templateRow = {
      id: 'pos.receipt',
      payload: {
        ...defaultReceiptTemplate('sale'),
        paper: '58mm',
        blocks: [{ id: 't', type: 'text', text: 'Hello {{customer.name}}' }],
        variants: [{ id: 'vip', name: 'VIP', applies_when: { customer_tags: ['vip'] }, language: 'fr', blocks: [{ id: 't', type: 'text', text: 'Karibu {{customer.name}}' }, { id: 'n', type: 'field', field: 'document.number' }] }],
      },
      fiscal: { required: true, authority: 'kra_etims' },
      // The server's wording, synced with the template (here with the receipt renamed).
      labels: { en: { ...en.templates.print, numbers: { pos_receipt: 'Slip' } }, fr: { ...fr.templates.print, numbers: { pos_receipt: 'Ticket' } } },
    };
    const accepted = { state: 'accepted', invoiceNumber: '1001', remote: { authority: { qr: 'https://etims.example/v' }, qr_svg: 'data:image/svg+xml;base64,QQ==' } };

    const plain = templateText(receiptDocumentHtml({ sale, catalogue, templateRow, customer: { id: 'c1', name: 'Amina', tags: [] }, fiscal: accepted }));
    // TPL-03: the totals (with the tax lines) and the fiscal block are added although the template lacks them.
    expect(plain.split('\n').slice(0, 6)).toEqual(['Hello Amina', 'VAT test 12.5% KES 55.56', 'Total KES 500.00', 'KRA eTIMS', 'Accepted', 'Fiscal invoice 1001']);

    const vipHtml = receiptDocumentHtml({ sale, catalogue, templateRow, customer: { id: 'c1', name: 'Amina', tags: ['VIP'] }, fiscal: accepted });
    expect(templateText(vipHtml)).toBe(['Karibu Amina', 'Ticket R-WL2-000001', 'VAT test 12.5 % KES 55,56', 'Total KES 500,00', 'KRA eTIMS', 'Accepté', 'Facture fiscale 1001'].join('\n'));
    expect(vipHtml).toContain('<img src="data:image/svg+xml;base64,QQ=="');
    expect(vipHtml).toContain('size: 58mm auto');
  });

  it('dates receipts in the branch time zone, as the server does', () => {
    expect(documentDate('2026-10-09T21:05:00Z', 'Africa/Kinshasa')).toBe('2026-10-09 22:05');
  });

  it('sends the HTML to the native print service', async () => {
    const { printReceipt } = require('../../screens/pos/ReceiptScreen');
    await printReceipt('<p>receipt</p>');
    expect(require('expo-print').printAsync).toHaveBeenCalledWith({ html: '<p>receipt</p>' });
  });
});
