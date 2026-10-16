// CRM-25: the customer 360 view on the customer page, through the party tab seam.
import { registerPartyTab } from '@/pages/contacts/partyTabs'
import { CUSTOMER_TAB } from './crmData'
import { CustomerSalesTab } from './CustomerSalesTab'

registerPartyTab({ value: 'sales', module: 'crm', permissions: CUSTOMER_TAB, roles: ['customer'], label: (t) => t('crm.customer.tab'), Component: CustomerSalesTab })
