import en from '@/locales/en.json'
import fr from '@/locales/fr.json'

// ONB-03: the module picker looks a description up by the catalogue key
// (`onboarding.moduleDescriptions.{key}`). A sellable module without one shows
// the raw key on the card, so every sellable module needs both languages.
const SELLABLE_MODULES = [
  'pos', 'inventory', 'loyalty', 'ecommerce', 'pharmacy', 'restaurant', 'hotel', 'fuel', 'van_sales', 'salon',
  'stores', 'procurement', 'fixed_assets', 'manufacturing', 'accounting_essentials', 'accounting_pro', 'crm', 'hr', 'payroll',
]

describe('Module picker descriptions (ONB-03)', () => {
  it.each([['en', en], ['fr', fr]])('describes every sellable module in %s', (_locale, messages) => {
    const missing = SELLABLE_MODULES.filter((key) => !messages.onboarding.moduleDescriptions[key])
    expect(missing).toEqual([])
  })
})
