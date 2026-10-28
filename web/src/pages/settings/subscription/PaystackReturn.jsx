import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'
import { Alert, Card } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { PaymentProgress } from '../Subscription'

const UUID = /^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/

/**
 * SUB-04: where Paystack sends the payer back (callback_url). The reference
 * in the address is our payment's id. The page only asks our API where the
 * payment stands (the API verifies it with Paystack); it never marks
 * anything paid itself.
 */
export default function PaystackReturn() {
  const { t } = useTranslation()
  const [params] = useSearchParams()
  const reference = params.get('reference') ?? params.get('trxref') ?? ''

  return (
    <>
      <PageHeader title={t('subscription.paystackReturn.title')} description={t('subscription.paystackReturn.description')} />
      <Card title={t('subscription.paystackReturn.card')}>
        <div className="flex flex-col gap-3 px-5 py-4">
          {UUID.test(reference) ? <PaymentProgress paymentId={reference} /> : <Alert tone="danger" title={t('subscription.paystackReturn.unknown')} />}
          <Link to="/settings/subscription" className="rounded-sm text-body font-medium text-primary underline-offset-2 hover:underline">
            {t('subscription.paystackReturn.back')}
          </Link>
        </div>
      </Card>
    </>
  )
}
