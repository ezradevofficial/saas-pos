import { QueryClient } from '@tanstack/react-query'
import { invalidateOrganisation } from './orgTree'

describe('invalidateOrganisation (TEN-04, TEN-05)', () => {
  it('refreshes every location picker, whichever way its query is keyed', async () => {
    const client = new QueryClient()
    const keys = [
      ['locations', 'organisation'],
      ['locations', 'pharmacy-places'],
      ['ecommerce-options', 'locations', 'company-1'],
      ['branches', 'organisation'],
      ['ecommerce-options', 'price-lists', 'company-1'],
    ]
    for (const key of keys) client.setQueryData(key, { data: [] })

    await invalidateOrganisation(client)

    const stale = Object.fromEntries(keys.map((key) => [key.join('/'), client.getQueryState(key).isInvalidated]))
    expect(stale).toEqual({
      'locations/organisation': true,
      'locations/pharmacy-places': true,
      'ecommerce-options/locations/company-1': true,
      'branches/organisation': true,
      'ecommerce-options/price-lists/company-1': false,
    })
  })
})
