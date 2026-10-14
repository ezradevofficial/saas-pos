// LOY-27: the Loyalty module on the customer page, through the party tab seam.
import { registerPartyTab } from '@/pages/contacts/partyTabs'
import { CustomerLoyaltyTab } from './CustomerLoyaltyTab'
import { CUSTOMER_TAB } from './loyaltyData'

registerPartyTab({ value: 'loyalty', module: 'loyalty', permissions: CUSTOMER_TAB, roles: ['customer'], label: (t) => t('loyalty.customer.tab'), Component: CustomerLoyaltyTab })
