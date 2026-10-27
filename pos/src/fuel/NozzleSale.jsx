import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Pressable, Text, View } from 'react-native';
import { useSession } from '../auth/session';
import { Alert } from '../components/ds/Alert';
import { Button } from '../components/ds/Button';
import { Dialog } from '../components/ds/Dialog';
import { TextField } from '../components/ds/TextField';
import { cn } from '../lib/cn';
import { FOCUS_RING } from '../lib/focus';
import { searchCustomers } from '../pos/catalogue';
import { parseAmount } from '../pos/input';
import { usePosActions, usePosData } from '../pos/PosProvider';
import { useMoneyText } from '../screens/pos/format';
import { useServices } from '../services/services';
import { nozzlePrice, nozzlesByPump, productName, searchVehicles } from './data';
import { quote } from './meters';
import { buildFuelCart } from './sale';
import { Choice, Frame, Section } from './ui';
import { errorText, litresText } from './text';

/**
 * FUEL-20: choose the customer, then the vehicle (search by registration over the synced vehicles; the odometer is
 * optional). A blocked vehicle shows a clear warning, but the sale is still allowed: the server flags it (the
 * device wins, NFR-04).
 */
function FleetDialog({ onChoose, onClose }) {
  const { t } = useTranslation();
  const { database } = useServices();
  const [query, setQuery] = useState('');
  const [customers, setCustomers] = useState([]);
  const [customer, setCustomer] = useState(null);
  const [registration, setRegistration] = useState('');
  const [vehicles, setVehicles] = useState([]);
  const [vehicle, setVehicle] = useState(null);
  const [odometer, setOdometer] = useState('');

  useEffect(() => {
    let active = true;
    if (!customer) searchCustomers(database, query).then((rows) => active && setCustomers(rows));
    return () => {
      active = false;
    };
  }, [database, query, customer]);

  useEffect(() => {
    let active = true;
    if (customer) searchVehicles(database, registration, { partyId: customer.id }).then((rows) => active && setVehicles(rows));
    return () => {
      active = false;
    };
  }, [database, customer, registration]);

  return (
    <Dialog
      open
      title={customer ? t('fuel.fleet.vehicleTitle', { name: customer.name }) : t('fuel.fleet.customerTitle')}
      onClose={onClose}
      footer={
        vehicle ? (
          <Button variant="primary" onPress={() => onChoose({ customer, vehicle: { registration: vehicle.registration, odometer }, status: vehicle.status })}>
            {t('fuel.fleet.use')}
          </Button>
        ) : null
      }
    >
      {!customer ? (
        <>
          <TextField label={t('fuel.fleet.searchCustomer')} value={query} onChangeText={setQuery} autoCorrect={false} />
          <View className="rounded-md border border-border">
            {customers.length ? (
              customers.map((row) => (
                <Pressable key={row.id} accessibilityRole="button" onPress={() => setCustomer(row)} className={cn('min-h-12 justify-center border-b border-border px-4 py-3 hover:bg-surface-300 active:bg-surface-300', FOCUS_RING)}>
                  <Text className="font-sans text-body-lg text-ink">{row.name}</Text>
                </Pressable>
              ))
            ) : (
              <Text className="p-4 text-center font-sans text-body text-ink-muted">{t('fuel.fleet.noCustomers')}</Text>
            )}
          </View>
        </>
      ) : (
        <>
          <TextField label={t('fuel.fleet.searchVehicle')} value={registration} onChangeText={setRegistration} autoCapitalize="characters" autoCorrect={false} />
          <View className="gap-2">
            {vehicles.length ? (
              vehicles.map((row) => (
                <Choice key={row.id} selected={vehicle?.id === row.id} onPress={() => setVehicle(row)}>
                  <Text className="font-sans text-body-lg text-ink">{row.registration}</Text>
                  {row.label ? <Text className="font-sans text-caption text-ink-muted">{row.label}</Text> : null}
                </Choice>
              ))
            ) : (
              <Text className="p-2 font-sans text-body text-ink-muted">{t('fuel.fleet.noVehicles')}</Text>
            )}
          </View>
          {vehicle?.status === 'blocked' ? (
            <Alert tone="warning" title={t('fuel.fleet.blockedTitle')}>
              {t('fuel.fleet.blocked')}
            </Alert>
          ) : null}
          {vehicle ? <TextField label={t('fuel.fleet.odometer')} help={t('fuel.fleet.odometerHelp')} suffix="km" keyboardType="number-pad" value={odometer} onChangeText={setOdometer} /> : null}
          <Button variant="ghost" onPress={() => { setCustomer(null); setVehicle(null); }}>
            {t('fuel.fleet.otherCustomer')}
          </Button>
        </>
      )}
    </Dialog>
  );
}

/**
 * FUEL-18, FUEL-19, FUEL-20: the nozzle quick sale. Pick a nozzle (grouped by pump, with its product, grade and the
 * price per litre now), type the litres or an amount, and see the litres and total as the server rounds them. Charge
 * puts a normal sale in the cart (the nozzle's item, quantity = litres, the price per litre, no override) carrying
 * `extensions.fuel`, and the POS payment screens take it from there. Everything works offline.
 */
export function NozzleSale({ fuel, shift, location, onBack, onPay }) {
  const { t } = useTranslation();
  const money = useMoneyText();
  const { user } = useSession();
  const { catalogue } = usePosData();
  const actions = usePosActions();
  const currency = fuel.station.currency;
  const decimals = catalogue.money.decimals(currency);
  const [nozzle, setNozzle] = useState(null);
  const [mode, setMode] = useState('litres');
  const [text, setText] = useState('');
  const [fleet, setFleet] = useState(null);
  const [fleetOpen, setFleetOpen] = useState(false);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  const onShift = useMemo(() => new Set((shift?.nozzles ?? []).map((row) => row.nozzle_id)), [shift]);
  const mine = useMemo(() => new Set((shift?.nozzles ?? []).filter((row) => row.attendant_id === user?.id).map((row) => row.nozzle_id)), [shift, user]);
  const groups = useMemo(() => nozzlesByPump({ nozzles: fuel.nozzles.filter((candidate) => onShift.has(candidate.id)) }), [fuel, onShift]);
  const price = nozzle ? nozzlePrice(fuel, nozzle) : null;

  const priced = useMemo(() => {
    if (!nozzle || price == null || text.trim() === '') return null;
    try {
      return quote({ mode, litres: text, amountMinor: mode === 'amount' ? parseAmount(text, decimals) : null, priceMinor: price });
    } catch {
      return { error: true };
    }
  }, [nozzle, price, mode, text, decimals]);

  async function charge() {
    setError(null);
    setBusy(true);
    try {
      const cart = buildFuelCart({
        catalogue,
        fuel,
        shift,
        nozzle,
        entry: { mode, litres: text, amountMinor: mode === 'amount' ? parseAmount(text, decimals) : null },
        customer: fleet?.customer ?? null,
        vehicle: fleet?.vehicle ?? null,
      });
      const refused = await actions.loadCart(cart);
      if (refused) return setError(t('fuel.sale.cartLocked'));
      onPay();
    } catch (failure) {
      setError(errorText(t, failure));
    } finally {
      setBusy(false);
    }
  }

  const ready = priced && !priced.error;
  return (
    <Frame title={t('fuel.sale.title')} onBack={onBack} location={location} wide>
      {!shift ? <Alert tone="warning">{t('fuel.errors.no_shift')}</Alert> : null}
      {groups.map((pump) => (
        <Section key={pump.pumpId} title={`${pump.pumpCode} · ${pump.pumpName}`}>
          <View className="flex-row flex-wrap gap-3">
            {pump.nozzles.map((candidate) => {
              const now = nozzlePrice(fuel, candidate);
              return (
                <Pressable
                  key={candidate.id}
                  accessibilityRole="button"
                  accessibilityState={{ selected: nozzle?.id === candidate.id }}
                  accessibilityLabel={`${candidate.pump_code} / ${candidate.code}, ${productName(catalogue, candidate)}`}
                  onPress={() => {
                    setNozzle(candidate);
                    setError(null);
                  }}
                  className={cn('min-h-24 w-44 justify-between gap-1 rounded-md border p-3', nozzle?.id === candidate.id ? 'border-primary bg-primary-tint' : 'border-border-strong bg-surface-200 hover:bg-surface-300 active:bg-surface-300', FOCUS_RING)}
                >
                  <Text className="font-sans text-body-lg font-medium text-ink">{`${candidate.pump_code} / ${candidate.code}`}</Text>
                  <Text numberOfLines={1} className="font-sans text-body text-ink">
                    {productName(catalogue, candidate)}
                  </Text>
                  <Text className="font-sans text-caption tabular-nums text-ink-muted">{now ? t('fuel.sale.perLitre', { price: money(now, currency) }) : t('fuel.sale.noPrice')}</Text>
                  {mine.has(candidate.id) ? <Text className="font-sans text-caption text-primary">{t('fuel.sale.mine')}</Text> : null}
                </Pressable>
              );
            })}
          </View>
        </Section>
      ))}

      {nozzle ? (
        <Section title={`${nozzle.pump_code} / ${nozzle.code} · ${productName(catalogue, nozzle)}`}>
          {price == null ? <Alert tone="danger">{t('fuel.errors.price_missing')}</Alert> : null}
          <View accessibilityRole="radiogroup" className="flex-row gap-2">
            {['litres', 'amount'].map((value) => (
              <Choice key={value} selected={mode === value} onPress={() => { setMode(value); setText(''); }}>
                <Text className="font-sans text-body font-medium text-ink">{t(`fuel.sale.by.${value}`)}</Text>
              </Choice>
            ))}
          </View>
          <TextField
            label={mode === 'litres' ? t('fuel.sale.litres') : t('fuel.sale.amount')}
            prefix={mode === 'amount' ? currency : undefined}
            suffix={mode === 'litres' ? 'L' : undefined}
            keyboardType="decimal-pad"
            value={text}
            onChangeText={(value) => { setText(value); setError(null); }}
            error={priced?.error ? t('fuel.sale.invalid') : undefined}
          />
          {ready ? (
            <View className="gap-1 rounded-md border border-border p-4">
              <Text className="font-sans text-body-lg tabular-nums text-ink">{`${litresText(priced.litres)} × ${money(priced.priceMinor, currency)}`}</Text>
              <Text className="font-sans text-h3 font-medium tabular-nums text-ink">{money(priced.totalMinor, currency)}</Text>
              {mode === 'amount' ? <Text className="font-sans text-caption text-ink-muted">{t('fuel.sale.roundedHelp')}</Text> : null}
            </View>
          ) : null}
          <View className="flex-row flex-wrap items-center gap-3">
            <Button variant="secondary" onPress={() => setFleetOpen(true)}>
              {fleet ? t('fuel.sale.fleetChange') : t('fuel.sale.fleet')}
            </Button>
            {fleet ? (
              <>
                <Text className="font-sans text-body text-ink">{`${fleet.customer.name} · ${fleet.vehicle.registration}`}</Text>
                <Button variant="ghost" onPress={() => setFleet(null)}>
                  {t('fuel.sale.fleetRemove')}
                </Button>
              </>
            ) : null}
          </View>
          {fleet?.status === 'blocked' ? <Alert tone="warning" title={t('fuel.fleet.blockedTitle')}>{t('fuel.fleet.blocked')}</Alert> : null}
        </Section>
      ) : (
        <Text className="font-sans text-body text-ink-muted">{t('fuel.sale.pick')}</Text>
      )}

      {error ? <Alert tone="danger">{error}</Alert> : null}
      <Button variant="pay" block loading={busy} disabled={!ready || !shift} onPress={charge}>
        {ready ? t('fuel.sale.charge', { total: money(priced.totalMinor, currency) }) : t('fuel.sale.chargeEmpty')}
      </Button>
      {fleetOpen ? (
        <FleetDialog
          onClose={() => setFleetOpen(false)}
          onChoose={(chosen) => {
            setFleet(chosen);
            setFleetOpen(false);
          }}
        />
      ) : null}
    </Frame>
  );
}
