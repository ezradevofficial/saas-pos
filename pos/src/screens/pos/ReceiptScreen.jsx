import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Image, Platform, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button } from '../../components/ds/Button';
import { StatusBadge } from '../../components/ds/StatusBadge';
import { useLocale } from '../../lib/useLocale';
import { usePosData } from '../../pos/PosProvider';
import { vars } from 'nativewind';
import { themeVariables } from '../../theme/themes';
import { fetchFiscal, fiscalState } from '../../pos/fiscal';
import { receiptHtml } from '../../pos/receiptHtml';
import { useServices } from '../../services/services';
import { useSyncStatus } from '../../sync/useSyncStatus';
import { useMedia } from '../../theme/media';
import { useTillTheme } from '../../theme/TillTheme';
import { formatDateTime, useMoneyText } from './format';

// Printed documents are black on white in every theme: the receipt always takes the
// light token values (runtime theme variables, the one allowed inline style).
const PRINT_VARIABLES = themeVariables('light', null, Platform.OS);
const PRINT_THEME = vars(PRINT_VARIABLES);
// BR-02: the logo prints in black only (a silhouette in the print ink), never in brand colours.
const PRINT_INK = PRINT_VARIABLES['--ink'];

function Row({ label, value, strong }) {
  return (
    <View className="flex-row justify-between gap-4">
      <Text className={strong ? 'font-sans text-body-lg font-medium text-ink' : 'font-sans text-body text-ink'}>{label}</Text>
      <Text className={strong ? 'font-sans text-body-lg font-medium tabular-nums text-ink' : 'font-sans text-body tabular-nums text-ink'}>{value}</Text>
    </View>
  );
}

/**
 * POS-06: what a receipt says, once, for the screen and the printout:
 * every amount with its currency code first; when the company sells in
 * two currencies, the total in both as stored with the sale (CUR-05); the
 * fiscal section (KRA eTIMS, DRC DGI) locked, "pending" until the server
 * reports the authority's answer (POS-10).
 */
/**
 * POS-10: the sale's fiscal state, from its upload answer and, online,
 * from GET pos/sales/{id}/fiscal; refreshed after each upload.
 */
export function useFiscalState(sale, kind = 'sale') {
  const { engine, api } = useServices();
  const sync = useSyncStatus();
  const [state, setState] = useState({ state: 'waiting', invoiceNumber: null });
  useEffect(() => {
    let active = true;
    (async () => {
      const entry = await engine.store.entryFor(kind === 'refund' ? 'pos.refunds' : 'pos.sales', sale.id);
      let next = fiscalState(entry);
      if (active) setState(next);
      if (kind === 'sale' && next.state === 'pending' && sync.network !== 'offline') {
        const remote = await fetchFiscal(api, sale.id);
        if (remote) next = fiscalState(entry, remote);
        if (active) setState(next);
      }
    })().catch(() => {});
    return () => {
      active = false;
    };
  }, [api, engine, kind, sale.id, sync.lastPushedAt, sync.network]);
  return state;
}

export function useReceiptModel(sale, kind = 'sale') {
  const { t } = useTranslation();
  const fiscal = useFiscalState(sale, kind);
  const locale = useLocale();
  const money = useMoneyText();
  const { catalogue } = usePosData();
  const settings = catalogue?.settings;
  const company = settings?.company;
  const currency = sale.currency;
  const taxRates = [...new Set(sale.lines.map((line) => line.tax_rate).filter(Boolean))];
  const dual = kind === 'sale' && sale.local?.dual ? sale.local.dual : null;
  const { printLogo } = useTillTheme();
  const logo = useMedia(printLogo);
  return {
    lang: locale,
    logo,
    header: {
      title: company?.legal_name || company?.name || '',
      lines: [company?.tax_id ? t('pos.receipt.taxId', { id: company.tax_id }) : null, [settings?.branch?.name, settings?.location?.name].filter(Boolean).join(' · ')].filter(Boolean),
    },
    number: { label: kind === 'refund' ? t('pos.receipt.refundNumber') : t('pos.receipt.number'), value: sale.receipt_number },
    rows: [
      { label: t('pos.receipt.date'), value: formatDateTime(sale.sold_at ?? sale.refunded_at, catalogue?.timeZone, locale) },
      { label: t('pos.receipt.cashier'), value: sale.local?.cashier_name ?? '' },
      sale.local?.customer_name ? { label: t('pos.receipt.customer'), value: sale.local.customer_name } : null,
    ].filter(Boolean),
    lines: sale.lines.map((line) => ({
      id: line.id,
      name: line.item_name,
      detail: `${line.qty} × ${money(line.unit_price_minor, currency)}`,
      total: money(line.total_minor, currency),
      discount: line.discount_minor && line.discount_minor !== '0' ? { label: t('pos.receipt.discount'), value: money(`-${line.discount_minor}`, currency) } : null,
    })),
    totals: [
      { label: t('pos.receipt.subtotal'), value: money(sale.totals.subtotal_minor, currency) },
      sale.totals.discount_minor !== '0' ? { label: t('pos.receipt.discount'), value: money(`-${sale.totals.discount_minor}`, currency) } : null,
      { label: taxRates.length === 1 ? t('pos.receipt.taxAt', { rate: Number(taxRates[0]) }) : t('pos.receipt.tax'), value: money(sale.totals.tax_minor, currency) },
      { label: t('pos.receipt.total'), value: money(sale.totals.total_minor, currency), strong: true },
      dual ? { label: t('pos.receipt.totalIn', { currency: dual.currency }), value: money(dual.minor, dual.currency) } : null,
    ].filter(Boolean),
    payments: [
      ...sale.payments.map((payment) => ({ label: [sale.local?.methods?.[payment.id]?.name ?? t('pos.receipt.payment'), payment.provider_reference].filter(Boolean).join(' · '), value: money(payment.amount_minor, payment.currency) })),
      sale.change ? { label: t('pos.receipt.change'), value: money(sale.change.amount_minor, sale.change.currency) } : null,
    ].filter(Boolean),
    fiscal: {
      authority: company?.country === 'KE' ? t('pos.receipt.fiscalKe') : company?.country === 'CD' ? t('pos.receipt.fiscalCd') : t('pos.receipt.fiscal'),
      state: fiscal.state,
      status: [t(`pos.receipt.fiscalState.${fiscal.state}`), fiscal.invoiceNumber ? t('pos.receipt.fiscalState.invoice', { number: fiscal.invoiceNumber }) : null].filter(Boolean).join(' · '),
      help:
        fiscal.state === 'waiting'
          ? t('pos.receipt.fiscalState.waitingHelp')
          : fiscal.state === 'pending'
            ? t('pos.receipt.fiscalPendingHelp')
            : fiscal.state === 'rejected'
              ? t('pos.receipt.fiscalState.rejectedHelp')
              : fiscal.state === 'off'
                ? t('pos.receipt.fiscalState.offHelp')
                : '',
    },
  };
}

/** The receipt on screen: always the light theme (printed documents are black on white in every theme). */
export function Receipt({ sale, kind = 'sale' }) {
  const model = useReceiptModel(sale, kind);
  return (
    <View testID="receipt" style={PRINT_THEME} className="gap-3 rounded-md border border-border bg-surface-200 p-5">
      <View className="items-center gap-1">
        {model.logo ? <Image testID="receipt-logo" source={{ uri: model.logo }} tintColor={PRINT_INK} accessibilityIgnoresInvertColors resizeMode="contain" className="h-12 w-1/2" /> : null}
        <Text className="text-center font-sans text-body-lg font-semibold text-ink">{model.header.title}</Text>
        {model.header.lines.map((line) => (
          <Text key={line} className="font-sans text-caption text-ink">
            {line}
          </Text>
        ))}
      </View>
      <View className="gap-1 border-t border-border pt-3">
        <Row label={model.number.label} value={model.number.value} strong />
        {model.rows.map((entry) => (
          <Row key={entry.label} label={entry.label} value={entry.value} />
        ))}
      </View>
      <View className="gap-2 border-t border-border pt-3">
        {model.lines.map((line) => (
          <View key={line.id} className="gap-1">
            <Text className="font-sans text-body text-ink">{line.name}</Text>
            <Row label={line.detail} value={line.total} />
            {line.discount ? <Row label={line.discount.label} value={line.discount.value} /> : null}
          </View>
        ))}
      </View>
      <View className="gap-1 border-t border-border pt-3">
        {model.totals.map((entry) => (
          <Row key={entry.label} label={entry.label} value={entry.value} strong={entry.strong} />
        ))}
      </View>
      <View className="gap-1 border-t border-border pt-3">
        {model.payments.map((entry, index) => (
          <Row key={`${entry.label}-${index}`} label={entry.label} value={entry.value} />
        ))}
      </View>
      <View testID="fiscal" className="items-center gap-2 border-t border-border pt-3">
        <Text className="font-sans text-caption font-medium text-ink">{model.fiscal.authority}</Text>
        <StatusBadge tone={model.fiscal.state === 'accepted' ? 'success' : model.fiscal.state === 'rejected' ? 'danger' : model.fiscal.state === 'off' ? 'neutral' : 'warning'}>{model.fiscal.status}</StatusBadge>
        {model.fiscal.help ? <Text className="text-center font-sans text-caption text-ink">{model.fiscal.help}</Text> : null}
      </View>
    </View>
  );
}

/** Prints the receipt: the native print service with the HTML receipt (expo-print), the browser's print on the web preview. */
export async function printReceipt(model) {
  if (Platform.OS === 'web') {
    globalThis.print?.();
    return;
  }
  const Print = require('expo-print');
  await Print.printAsync({ html: receiptHtml(model) });
}

/** After a sale: the receipt, print and the next sale. */
export function ReceiptScreen({ sale, onNewSale }) {
  const { t } = useTranslation();
  const model = useReceiptModel(sale);
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
            <Button variant="secondary" className="shrink basis-1/3" onPress={() => printReceipt(model).catch(() => {})}>
              {t('pos.receipt.print')}
            </Button>
            <Button variant="primary" className="shrink grow" onPress={onNewSale}>
              {t('pos.receipt.newSale')}
            </Button>
          </View>
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}
