import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Text, View } from 'react-native';
import { Button } from '../components/ds/Button';
import { TextField } from '../components/ds/TextField';
import { usePosActions, usePosCart, usePosData } from '../pos/PosProvider';
import { useMoneyText } from '../screens/pos/format';
import { useServices } from '../services/services';
import { normaliseCode } from './engine';
import { creditTender, pointsTender } from './tenders';

/**
 * LOY-05, LOY-09, LOY-15 on the sale panel: the customer's points and
 * credit (offline: last synced, less this till's sales since) and the
 * coupons typed for the sale. Shown only while the module sent its
 * programme or promotions (RBAC-08).
 */
export function LoyaltyCartPanel() {
  const { t } = useTranslation();
  const money = useMoneyText();
  const { database } = useServices();
  const { catalogue, lastSale } = usePosData();
  const { cart } = usePosCart();
  const actions = usePosActions();
  const data = catalogue?.ext?.loyalty;
  const customerId = cart.customer?.id ?? null;
  const [balances, setBalances] = useState(null);
  const [code, setCode] = useState('');
  const coupons = cart.ext?.loyalty?.coupons ?? [];
  const hasCoupons = Boolean(data?.promotions.some((promotion) => promotion.coupon_codes != null));

  useEffect(() => {
    let active = true;
    if (!customerId || !data) {
      setBalances(null);
      return undefined;
    }
    (async () => {
      const points = data.programme?.active ? await pointsTender.available({ database, customerId }) : null;
      const credit = await creditTender.position({ database, customerId });
      if (active) setBalances({ points, credit });
    })().catch(() => active && setBalances(null));
    return () => {
      active = false;
    };
  }, [customerId, data, database, lastSale]);

  if (!data || (!data.programme && !data.promotions.length)) return null;

  const setCoupons = (next) => actions.setExtension('loyalty', { ...(cart.ext?.loyalty ?? {}), coupons: next });
  const add = () => {
    const normalised = normaliseCode(code);
    if (normalised && !coupons.includes(normalised)) setCoupons([...coupons, normalised]);
    setCode('');
  };
  const account = balances?.credit?.account;

  return (
    <View className="gap-2 border-b border-border px-4 py-3">
      {balances ? (
        <View className="flex-row flex-wrap gap-x-4 gap-y-1">
          {balances.points !== null ? <Text className="font-sans text-caption tabular-nums text-ink-muted">{t('pos.loyalty.points', { points: String(balances.points) })}</Text> : null}
          {account?.status === 'open' ? (
            <Text className="font-sans text-caption tabular-nums text-ink-muted">
              {balances.credit.limit
                ? t('pos.loyalty.creditOf', { owed: money(String(balances.credit.owed), account.currency), limit: money(balances.credit.limit.amount_minor, balances.credit.limit.currency) })
                : t('pos.loyalty.credit', { owed: money(String(balances.credit.owed), account.currency) })}
            </Text>
          ) : null}
        </View>
      ) : null}
      {hasCoupons ? (
        <View className="gap-2">
          <View className="flex-row items-end gap-2">
            <TextField className="min-w-0 flex-1" label={t('pos.loyalty.coupon')} autoCapitalize="characters" autoCorrect={false} value={code} onChangeText={setCode} returnKeyType="done" onSubmitEditing={add} />
            <Button variant="secondary" onPress={add} disabled={!code.trim()}>
              {t('pos.loyalty.applyCoupon')}
            </Button>
          </View>
          {coupons.map((coupon) => (
            <View key={coupon} className="flex-row items-center justify-between gap-2">
              <Text className="font-sans text-caption text-ink">{t('pos.loyalty.couponApplied', { code: coupon })}</Text>
              <Button variant="ghost" accessibilityLabel={t('pos.loyalty.removeCoupon', { code: coupon })} onPress={() => setCoupons(coupons.filter((entry) => entry !== coupon))}>
                {t('pos.loyalty.remove')}
              </Button>
            </View>
          ))}
        </View>
      ) : null}
    </View>
  );
}
