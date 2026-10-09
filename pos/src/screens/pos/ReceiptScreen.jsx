import { useTranslation } from 'react-i18next';
import { Platform, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button } from '../../components/ds/Button';
import { StatusBadge } from '../../components/ds/StatusBadge';
import { useLocale } from '../../lib/useLocale';
import { usePosData } from '../../pos/PosProvider';
import { vars } from 'nativewind';
import { themeVariables } from '../../theme/themes';
import { formatDateTime, useMoneyText } from './format';

// Printed documents are black on white in every theme: the receipt always takes the
// light token values (runtime theme variables, the one allowed inline style).
const PRINT_THEME = vars(themeVariables('light', null, Platform.OS));

function Row({ label, value, strong }) {
  return (
    <View className="flex-row justify-between gap-4">
      <Text className={strong ? 'font-sans text-body-lg font-medium text-ink' : 'font-sans text-body text-ink'}>{label}</Text>
      <Text className={strong ? 'font-sans text-body-lg font-medium tabular-nums text-ink' : 'font-sans text-body tabular-nums text-ink'}>{value}</Text>
    </View>
  );
}

/**
 * POS-06: the receipt as printed. Always the light theme (printed
 * documents are black on white in every theme); every amount shows its
 * currency code first and, when the company sells in two currencies, the
 * total in both (CUR-05). The fiscal section (KRA eTIMS, DRC DGI) is
 * locked: it says "pending" until the server reports the authority's
 * answer (POS-10).
 */
export function Receipt({ sale, kind = 'sale' }) {
  const { t } = useTranslation();
  const locale = useLocale();
  const money = useMoneyText();
  const { catalogue } = usePosData();
  const settings = catalogue?.settings;
  const company = settings?.company;
  const currency = sale.currency;
  // CUR-05: the second-currency total stored with the sale (never recomputed at a later rate).
  const dual = kind === 'sale' && sale.local?.dual ? { text: money(sale.local.dual.minor, sale.local.dual.currency), currency: sale.local.dual.currency } : null;
  const taxRates = [...new Set(sale.lines.map((line) => line.tax_rate).filter(Boolean))];
  const fiscalAuthority = company?.country === 'KE' ? t('pos.receipt.fiscalKe') : company?.country === 'CD' ? t('pos.receipt.fiscalCd') : t('pos.receipt.fiscal');

  return (
    <View testID="receipt" style={PRINT_THEME} className="gap-3 rounded-md border border-border bg-surface-200 p-5">
        <View className="items-center gap-1">
          <Text className="text-center font-sans text-body-lg font-semibold text-ink">{company?.legal_name || company?.name}</Text>
          {company?.tax_id ? <Text className="font-sans text-caption text-ink">{t('pos.receipt.taxId', { id: company.tax_id })}</Text> : null}
          <Text className="font-sans text-caption text-ink">{[settings?.branch?.name, settings?.location?.name].filter(Boolean).join(' · ')}</Text>
        </View>
        <View className="gap-1 border-t border-border pt-3">
          <Row label={kind === 'refund' ? t('pos.receipt.refundNumber') : t('pos.receipt.number')} value={sale.receipt_number} strong />
          <Row label={t('pos.receipt.date')} value={formatDateTime(sale.sold_at ?? sale.refunded_at, catalogue?.timeZone, locale)} />
          <Row label={t('pos.receipt.cashier')} value={sale.local?.cashier_name ?? ''} />
          {sale.local?.customer_name ? <Row label={t('pos.receipt.customer')} value={sale.local.customer_name} /> : null}
        </View>
        <View className="gap-2 border-t border-border pt-3">
          {sale.lines.map((line) => (
            <View key={line.id} className="gap-1">
              <Text className="font-sans text-body text-ink">{line.item_name}</Text>
              <Row label={`${line.qty} × ${money(line.unit_price_minor, currency)}`} value={money(line.total_minor, currency)} />
              {line.discount_minor && line.discount_minor !== '0' ? <Row label={t('pos.receipt.discount')} value={money(`-${line.discount_minor}`, currency)} /> : null}
            </View>
          ))}
        </View>
        <View className="gap-1 border-t border-border pt-3">
          <Row label={t('pos.receipt.subtotal')} value={money(sale.totals.subtotal_minor, currency)} />
          {sale.totals.discount_minor !== '0' ? <Row label={t('pos.receipt.discount')} value={money(`-${sale.totals.discount_minor}`, currency)} /> : null}
          <Row label={taxRates.length === 1 ? t('pos.receipt.taxAt', { rate: Number(taxRates[0]) }) : t('pos.receipt.tax')} value={money(sale.totals.tax_minor, currency)} />
          <Row label={t('pos.receipt.total')} value={money(sale.totals.total_minor, currency)} strong />
          {dual ? <Row label={t('pos.receipt.totalIn', { currency: dual.currency })} value={dual.text} /> : null}
        </View>
        <View className="gap-1 border-t border-border pt-3">
          {sale.payments.map((payment) => (
            <Row key={payment.id} label={[sale.local?.methods?.[payment.id]?.name ?? t('pos.receipt.payment'), payment.provider_reference].filter(Boolean).join(' · ')} value={money(payment.amount_minor, payment.currency)} />
          ))}
          {sale.change ? <Row label={t('pos.receipt.change')} value={money(sale.change.amount_minor, sale.change.currency)} /> : null}
        </View>
        <View testID="fiscal" className="items-center gap-2 border-t border-border pt-3">
          <Text className="font-sans text-caption font-medium text-ink">{fiscalAuthority}</Text>
          <StatusBadge tone="warning">{t('pos.receipt.fiscalPending')}</StatusBadge>
          <Text className="text-center font-sans text-caption text-ink">{t('pos.receipt.fiscalPendingHelp')}</Text>
        </View>
    </View>
  );
}

/** After a sale: the receipt, print (web preview) and the next sale. */
export function ReceiptScreen({ sale, onNewSale }) {
  const { t } = useTranslation();
  const money = useMoneyText();
  return (
    <SafeAreaView className="flex-1">
      <ScrollView className="flex-1" contentContainerClassName="items-center gap-4 p-4 md:p-6">
        <View className="w-full gap-4 md:w-1/2 xl:w-1/3">
          <Text accessibilityRole="header" className="font-sans text-h2 font-semibold text-ink">
            {t('pos.receipt.done')}
          </Text>
          {sale.change ? (
            <View className="gap-1 rounded-md bg-success-tint p-4">
              <Text className="font-sans text-body-lg text-ink">{t('pos.receipt.giveChange')}</Text>
              <Text className="font-sans text-amount-lg tabular-nums text-ink">{money(sale.change.amount_minor, sale.change.currency)}</Text>
            </View>
          ) : null}
          <Receipt sale={sale} />
          <View className="flex-row gap-3">
            {Platform.OS === 'web' && typeof globalThis.print === 'function' ? (
              <Button variant="secondary" className="shrink basis-1/3" onPress={() => globalThis.print()}>
                {t('pos.receipt.print')}
              </Button>
            ) : null}
            <Button variant="primary" className="shrink grow" onPress={onNewSale}>
              {t('pos.receipt.newSale')}
            </Button>
          </View>
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}
