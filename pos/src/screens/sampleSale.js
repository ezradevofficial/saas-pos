// Sample catalogue from design/screens/Main.dc.html, prices in KES minor units.
// Product names are catalogue data (they will come from the API), not UI text.
export const SAMPLE_CURRENCY = 'KES';

export const SAMPLE_PRODUCTS = [
  { id: 'tusker', name: 'Tusker Lager 500ml', price: 25000, stock: 48 },
  { id: 'soda', name: 'Coca-Cola 500ml', price: 9000, stock: 120 },
  { id: 'water', name: 'Keringet Water 1L', price: 8000, stock: 4 },
  { id: 'milk', name: 'Brookside Milk 500ml', price: 6500, stock: 36 },
  { id: 'yoghurt', name: 'Bio Yoghurt 250ml', price: 11000, stock: 0 },
  { id: 'bread', name: 'Supaloaf White 400g', price: 6500, stock: 22 },
  { id: 'mandazi', name: 'Mandazi (6 pack)', price: 12000, stock: 15 },
  { id: 'unga', name: 'Jogoo Maize Flour 2kg', price: 18000, stock: 40 },
  { id: 'sugar', name: 'Kabras Sugar 1kg', price: 17500, stock: 3 },
  { id: 'oil', name: 'Fresh Fri Oil 1L', price: 38000, stock: 18 },
  { id: 'rice', name: 'Pishori Rice 2kg', price: 42000, stock: 25 },
  { id: 'soap', name: 'Menengai Bar Soap', price: 9500, stock: 60 },
];

export const SAMPLE_CART = { tusker: 2, bread: 1, milk: 3 };

const VAT_RATE_PERCENT = 16n;

/** Totals in minor units; prices include VAT, as on the design reference. */
export function saleTotals(cart, products = SAMPLE_PRODUCTS) {
  let total = 0n;
  let count = 0;
  for (const product of products) {
    const quantity = cart[product.id] ?? 0;
    total += BigInt(product.price) * BigInt(quantity);
    count += quantity;
  }
  const scaled = total * VAT_RATE_PERCENT * 2n;
  const divisor = (100n + VAT_RATE_PERCENT) * 2n;
  const tax = (scaled + divisor / 2n) / divisor; // rounded half up
  return { count, total: Number(total), tax: Number(tax), subtotal: Number(total - tax) };
}
