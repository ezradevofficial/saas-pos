import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FlatList, Image, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button } from '../components/ds/Button';
import { displayChannel } from '../pos/customerDisplay';
import { ThemeProvider } from '../theme/ThemeProvider';

function Line({ line }) {
  return (
    <View className="min-h-12 flex-row items-center gap-4 border-b border-border px-6 py-3">
      <Text className="w-12 font-sans text-body-lg tabular-nums text-ink-muted">{line.qty} ×</Text>
      <Text numberOfLines={2} className="min-w-0 flex-1 font-sans text-body-lg text-ink">
        {line.name}
      </Text>
      <Text className="font-sans text-body-lg font-medium tabular-nums text-ink">{line.total}</Text>
    </View>
  );
}

function Row({ label, value, strong }) {
  return (
    <View className="flex-row items-baseline justify-between gap-4">
      <Text className={strong ? 'font-sans text-h3 font-medium text-ink' : 'font-sans text-body-lg text-ink-muted'}>{label}</Text>
      <Text className={strong ? 'font-sans text-amount-lg tabular-nums text-ink' : 'font-sans text-body-lg tabular-nums text-ink'}>{value}</Text>
    </View>
  );
}

/**
 * LAY-05: the customer display, read-only. It shows what the till
 * publishes (customerDisplay.js): the welcome text and logo while no sale
 * is open, then the lines (when the layout shows them), the totals and the
 * total in the second currency (when it shows them), in the till's theme.
 * It reads nothing else: no database, no session, no actions but closing
 * (in the app; a browser tab is closed by the browser).
 */
export function CustomerDisplayScreen({ channel = displayChannel(), onClose }) {
  const { t } = useTranslation();
  const [state, setState] = useState(null);

  useEffect(() => {
    const stop = channel.listen((message) => {
      if (message?.type === 'state' && message.state?.v === 1) setState(message.state);
    });
    channel.post({ type: 'hello' });
    return stop;
  }, [channel]);

  const theme = state?.theme ?? { theme: 'light', overrides: null };
  const empty = !state || state.lines.length === 0;

  return (
    <ThemeProvider theme={theme.theme} overrides={theme.overrides}>
      <SafeAreaView className="flex-1 bg-surface-100" accessibilityLabel={t('pos.display.title')}>
        <View className="flex-row items-center justify-between gap-4 border-b border-border bg-surface-200 px-6 py-4">
          {state?.logo ? (
            <Image testID="display-logo" source={{ uri: state.logo }} accessibilityLabel={state.company ?? t('app.name')} resizeMode="contain" className="h-12 w-1/4" />
          ) : (
            <Text accessibilityRole="header" className="font-sans text-h2 font-semibold text-ink">
              {state?.company ?? t('app.name')}
            </Text>
          )}
          {onClose ? (
            <Button variant="ghost" onPress={onClose}>
              {t('pos.display.close')}
            </Button>
          ) : null}
        </View>
        {empty ? (
          <View className="flex-1 items-center justify-center gap-4 p-10">
            <Text accessibilityRole="header" className="text-center font-sans text-display text-ink">
              {state?.welcome ?? t('pos.display.welcome')}
            </Text>
            {!state ? <Text className="text-center font-sans text-body-lg text-ink-muted">{t('pos.display.waiting')}</Text> : null}
          </View>
        ) : (
          <View className="min-h-0 flex-1 gap-6 p-6 md:flex-row">
            {state.showLines ? (
              <View className="min-h-0 flex-1 rounded-lg border border-border bg-surface-200">
                {state.customer ? <Text className="border-b border-border px-6 py-3 font-sans text-body text-ink-muted">{t('pos.display.customer', { name: state.customer })}</Text> : null}
                <FlatList data={state.lines} keyExtractor={(line) => line.id} renderItem={({ item }) => <Line line={item} />} />
              </View>
            ) : null}
            {state.totals ? (
              <View className="gap-3 rounded-lg border border-border bg-surface-200 p-6 md:w-1/3">
                <Row label={t('pos.display.items', { count: state.count })} value={state.totals.subtotal} />
                {state.totals.discount ? <Row label={t('pos.display.discount')} value={state.totals.discount} /> : null}
                <Row label={t('pos.sale.tax')} value={state.totals.tax} />
                <View className="h-px bg-border" />
                <Row strong label={t('pos.display.total')} value={state.totals.total} />
                {state.second ? <Text className="text-right font-sans text-body-lg tabular-nums text-ink-muted">{state.second}</Text> : null}
              </View>
            ) : null}
          </View>
        )}
      </SafeAreaView>
    </ThemeProvider>
  );
}
