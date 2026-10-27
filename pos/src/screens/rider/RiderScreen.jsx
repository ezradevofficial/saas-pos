import { useCallback, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AppState, FlatList, Linking, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Alert } from '../../components/ds/Alert';
import { Button } from '../../components/ds/Button';
import { Dialog } from '../../components/ds/Dialog';
import { Money } from '../../components/ds/Money';
import { StatusBadge } from '../../components/ds/StatusBadge';
import { SyncStatus } from '../../components/ds/SyncStatus';
import { TextField } from '../../components/ds/TextField';
import { decimalsOf, formatAmount, toMinor } from '../../lib/money';
import { parseAmount } from '../../pos/input';
import { createLocationTracker } from '../../rider/locationTracker';
import { HousekeepingScreen } from '../../hotel/HousekeepingScreen';
import { ACTIVE } from '../../rider/rider';
import { useServices } from '../../services/services';
import { DeliveryPhoto, PHOTO_PROOF } from './DeliveryPhoto';

const TONES = { assigned: 'info', picked_up: 'warning', delivered: 'success', failed: 'danger' };
const REFRESH_MS = 30000;

let locationModule;
function loadLocation() {
  // Loaded when the rider's deliveries open; without it the rider still works, only no position is shared.
  if (locationModule === undefined) {
    try {
      locationModule = require('expo-location');
    } catch {
      locationModule = null;
    }
  }
  return locationModule;
}

/**
 * ECOM-29, KE DPA minimum data: the rider's position, shared in the
 * foreground only while a delivery is active. The OS prompt comes only after
 * the in-app explanation; "Not now" or a refusal leaves the rider working.
 * share: 'checking' | 'explain' | 'on' | 'off' | 'blocked' | 'unavailable'.
 */
function useRiderLocation(rider) {
  const location = loadLocation();
  const [share, setShare] = useState(location ? 'checking' : 'unavailable');
  const tracker = useRef(null);

  useEffect(() => {
    if (!location) return undefined;
    let active = true;
    const current = createLocationTracker({ rider, location });
    tracker.current = current;
    current.setForeground(AppState.currentState !== 'background' && AppState.currentState !== 'inactive');
    current.start().catch(() => {});
    const subscription = AppState.addEventListener('change', (next) => current.setForeground(next === 'active'));
    location
      .getForegroundPermissionsAsync()
      .then((permission) => {
        if (!active) return;
        if (permission?.granted) {
          current.setPermitted(true);
          setShare('on');
        } else setShare(permission?.status === 'denied' ? (permission.canAskAgain === false ? 'blocked' : 'off') : 'explain');
      })
      .catch(() => active && setShare('unavailable'));
    return () => {
      active = false;
      subscription?.remove?.();
      current.dispose();
      tracker.current = null;
    };
  }, [rider, location]);

  const allow = useCallback(async () => {
    try {
      const permission = await location.requestForegroundPermissionsAsync();
      tracker.current?.setPermitted(Boolean(permission?.granted));
      setShare(permission?.granted ? 'on' : permission?.canAskAgain === false ? 'blocked' : 'off');
    } catch {
      setShare('off');
    }
  }, [location]);

  return { share, allow, notNow: () => setShare('off') };
}

/** ECOM-29: the explanation before the OS prompt, and the quiet note once sharing is off. */
function LocationConsent({ share, onAllow, onNotNow }) {
  const { t } = useTranslation();
  if (share === 'explain') {
    return (
      <View testID="location-consent" className="gap-3 rounded-lg border border-border bg-surface-200 p-4">
        <Text accessibilityRole="header" className="font-sans text-body-lg font-medium text-ink">
          {t('rider.location.title')}
        </Text>
        <Text className="font-sans text-body text-ink-muted">{t('rider.location.body')}</Text>
        <View className="flex-row flex-wrap gap-3">
          <Button variant="primary" className="flex-1" onPress={onAllow}>
            {t('rider.location.allow')}
          </Button>
          <Button variant="ghost" className="flex-1" onPress={onNotNow}>
            {t('rider.location.notNow')}
          </Button>
        </View>
      </View>
    );
  }
  if (share === 'off' || share === 'blocked') {
    return (
      <View className="flex-row flex-wrap items-center gap-3">
        <Text className="min-w-0 flex-1 font-sans text-caption text-ink-muted">{t(share === 'off' ? 'rider.location.off' : 'rider.location.blocked')}</Text>
        <Button variant="ghost" onPress={share === 'off' ? onAllow : () => Linking.openSettings().catch(() => {})}>
          {t(share === 'off' ? 'rider.location.allow' : 'rider.location.openSettings')}
        </Button>
      </View>
    );
  }
  if (share === 'on') return <Text className="font-sans text-caption text-ink-muted">{t('rider.location.on')}</Text>;
  return null;
}

/** ECOM-28: the rider signs in as themselves with their email or phone and password (decision 7); email only in email-only mode (AUTH-01). */
function RiderSignIn({ onSignedIn, onBack }) {
  const { t } = useTranslation();
  const { rider } = useServices();
  const [login, setLogin] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  // AUTH-01: in email-only mode the field asks for an email; an existing phone login still signs in.
  const [sms, setSms] = useState(null);
  useEffect(() => {
    let live = true;
    rider.smsAvailable().then((available) => live && setSms(available));
    return () => {
      live = false;
    };
  }, [rider]);
  const emailOnly = sms === false;

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      const result = await rider.signIn({ login: login.trim(), password });
      if (result.ok) onSignedIn();
      else setError(t(`rider.errors.${result.error}`, { defaultValue: result.message ?? t('rider.errors.failed') }));
    } catch {
      setError(t('rider.errors.failed'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <ScrollView contentContainerClassName="flex-grow items-center justify-center p-6">
      <View className="w-full gap-5 rounded-lg border border-border bg-surface-200 p-6 md:w-1/2 xl:w-1/3">
        <View className="gap-2">
          <Text accessibilityRole="header" className="font-sans text-h2 font-semibold text-ink">
            {t('rider.signIn.title')}
          </Text>
          <Text className="font-sans text-body-lg text-ink-muted">{emailOnly ? t('rider.signIn.introEmail') : t('rider.signIn.intro')}</Text>
        </View>
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <TextField
          label={emailOnly ? t('rider.signIn.loginEmail') : t('rider.signIn.login')}
          value={login}
          onChangeText={setLogin}
          autoCapitalize="none"
          autoCorrect={false}
          autoComplete="username"
          keyboardType={emailOnly ? 'email-address' : 'default'}
        />
        <TextField label={t('rider.signIn.password')} value={password} onChangeText={setPassword} secureTextEntry autoComplete="password" onSubmitEditing={submit} />
        <Button variant="primary" block loading={busy} disabled={!login.trim() || !password} onPress={submit}>
          {t('rider.signIn.submit')}
        </Button>
        {onBack ? (
          <Button variant="ghost" block onPress={onBack}>
            {t('rider.signIn.back')}
          </Button>
        ) : null}
      </View>
    </ScrollView>
  );
}

/**
 * ECOM-28, ECOM-29: proof of delivery by the customer's code or a photo of
 * the parcel, and the cash collected on a cash on delivery order.
 */
function DeliveredDialog({ delivery, onClose, onConfirm }) {
  const { t, i18n } = useTranslation();
  const decimals = decimalsOf(delivery.currency);
  const due = toMinor(delivery.collect_minor);
  const [proof, setProof] = useState('code');
  const [code, setCode] = useState('');
  const [photo, setPhoto] = useState(null);
  const [collected, setCollected] = useState(due > 0n ? formatAmount(delivery.collect_minor, delivery.currency, i18n.language) : '');
  const collectedMinor = due > 0n ? parseAmount(collected, decimals) : null;
  const proven = proof === 'photo' ? Boolean(photo) : /^\d{6}$/.test(code);
  const valid = proven && (due === 0n || collectedMinor !== null);
  const collectedExtra = { collected_minor: due > 0n ? collectedMinor : null };

  function confirm() {
    if (proof === 'photo') onConfirm({ photo_uri: photo.uri, taken_at: photo.taken_at, ...collectedExtra });
    else onConfirm({ code, ...collectedExtra });
  }

  return (
    <Dialog
      open
      title={t('rider.delivered.title', { number: delivery.order_number })}
      onClose={onClose}
      footer={
        <Button variant="pay" block disabled={!valid} onPress={confirm}>
          {t('rider.delivered.confirm')}
        </Button>
      }
    >
      <View className="gap-4">
        {PHOTO_PROOF ? (
          <View className="gap-2">
            <Text className="font-sans text-body font-medium text-ink">{t('rider.delivered.proof.label')}</Text>
            <View accessibilityRole="radiogroup" className="flex-row flex-wrap gap-3">
              {['code', 'photo'].map((kind) => (
                <Button
                  key={kind}
                  variant={proof === kind ? 'secondary' : 'ghost'}
                  accessibilityRole="radio"
                  aria-checked={proof === kind}
                  className="flex-1"
                  onPress={() => setProof(kind)}
                >
                  {t(`rider.delivered.proof.${kind}`)}
                </Button>
              ))}
            </View>
          </View>
        ) : null}
        {proof === 'photo' ? (
          <DeliveryPhoto photo={photo} onChange={setPhoto} />
        ) : (
          <TextField
            label={t('rider.delivered.code')}
            help={t('rider.delivered.codeHelp')}
            value={code}
            onChangeText={(text) => setCode(text.replace(/\D/g, '').slice(0, 6))}
            keyboardType="number-pad"
            maxLength={6}
          />
        )}
        {due > 0n ? (
          <TextField
            label={t('rider.delivered.collected')}
            help={t('rider.delivered.collectedHelp')}
            error={collected && collectedMinor === null ? t('rider.delivered.collectedInvalid') : null}
            value={collected}
            onChangeText={setCollected}
            keyboardType="decimal-pad"
            prefix={delivery.currency}
          />
        ) : null}
      </View>
    </Dialog>
  );
}

function FailedDialog({ delivery, onClose, onConfirm }) {
  const { t } = useTranslation();
  const [note, setNote] = useState('');
  return (
    <Dialog
      open
      title={t('rider.failed.title', { number: delivery.order_number })}
      onClose={onClose}
      footer={
        <Button variant="danger" block disabled={!note.trim()} onPress={() => onConfirm({ note })}>
          {t('rider.failed.confirm')}
        </Button>
      }
    >
      <TextField label={t('rider.failed.reason')} help={t('rider.failed.reasonHelp')} value={note} onChangeText={setNote} maxLength={500} multiline />
    </Dialog>
  );
}

function DeliveryCard({ delivery, onPickedUp, onDelivered, onFailed }) {
  const { t } = useTranslation();
  const address = [delivery.address?.line, delivery.address?.area].filter(Boolean).join(', ');
  const collect = toMinor(delivery.collect_minor) > 0n;
  const open = !delivery.queued || delivery.status === 'assigned' || delivery.status === 'picked_up';

  return (
    <View testID={`delivery-${delivery.id}`} className="gap-3 rounded-lg border border-border bg-surface-200 p-4">
      <View className="flex-row flex-wrap items-center justify-between gap-2">
        <Text className="font-sans text-body-lg font-medium tabular-nums text-ink">{delivery.order_number}</Text>
        <StatusBadge tone={TONES[delivery.status] ?? 'neutral'}>{t(`rider.statuses.${delivery.status}`, { defaultValue: delivery.status })}</StatusBadge>
      </View>
      <View className="gap-1">
        <Text className="font-sans text-body text-ink">{delivery.customer_name}</Text>
        {delivery.customer_phone ? <Text className="font-sans text-body tabular-nums text-ink-muted">{delivery.customer_phone}</Text> : null}
        {address ? <Text className="font-sans text-body text-ink-muted">{address}</Text> : null}
      </View>
      {collect ? (
        <View className="flex-row items-center justify-between gap-2 border-t border-border pt-3">
          <Text className="font-sans text-body text-ink-muted">{t('rider.collect')}</Text>
          <Money amount={delivery.collect_minor} currency={delivery.currency} />
        </View>
      ) : null}
      {delivery.queued ? <Text className="font-sans text-caption text-ink-muted">{t('rider.queued')}</Text> : null}
      {open && delivery.status === 'assigned' ? (
        <View className="flex-row flex-wrap gap-3">
          <Button variant="primary" className="flex-1" onPress={() => onPickedUp(delivery)}>
            {t('rider.actions.pickedUp')}
          </Button>
          <Button variant="danger" className="flex-1" onPress={() => onFailed(delivery)}>
            {t('rider.actions.failed')}
          </Button>
        </View>
      ) : null}
      {open && delivery.status === 'picked_up' ? (
        <View className="flex-row flex-wrap gap-3">
          <Button variant="pay" className="flex-1" onPress={() => onDelivered(delivery)}>
            {t('rider.actions.delivered')}
          </Button>
          <Button variant="danger" className="flex-1" onPress={() => onFailed(delivery)}>
            {t('rider.actions.failed')}
          </Button>
        </View>
      ) : null}
    </View>
  );
}

function RiderDeliveries({ onSignedOut }) {
  const { t } = useTranslation();
  const { rider } = useServices();
  const [state, setState] = useState(null);
  const [name, setName] = useState(null);
  const [error, setError] = useState(null);
  const [dialog, setDialog] = useState(null);

  const refresh = useCallback(async () => {
    const result = await rider.refresh();
    if (!result.ok && result.error !== 'offline' && result.error !== 'signed_out') setError(t(`rider.errors.${result.error}`, { defaultValue: t('rider.errors.failed') }));
    else setError(null);
  }, [rider, t]);

  useEffect(() => {
    let active = true;
    const unsubscribe = rider.subscribe((next) => {
      if (!active) return;
      if (next.signedOut) onSignedOut();
      else setState(next);
    });
    rider.user().then((user) => active && setName(user?.name ?? null));
    rider.snapshot().then((next) => active && setState(next));
    refresh().catch(() => {});
    const timer = setInterval(() => refresh().catch(() => {}), REFRESH_MS);
    return () => {
      active = false;
      unsubscribe();
      clearInterval(timer);
    };
  }, [rider, refresh, onSignedOut]);

  async function signOut() {
    await rider.signOut();
    onSignedOut();
  }

  const record = (delivery, status, extra) => {
    setDialog(null);
    rider.record(delivery.id, status, extra).catch(() => setError(t('rider.errors.failed')));
  };

  const network = state?.network ?? 'online';
  const location = useRiderLocation(rider);
  const hasActive = (state?.deliveries ?? []).some((delivery) => ACTIVE.includes(delivery.status));

  return (
    <View className="flex-1">
      <View className="flex-row flex-wrap items-center gap-4 border-b border-border bg-surface-200 px-4 py-3 md:px-6">
        <View className="min-w-0 flex-1">
          <Text accessibilityRole="header" className="font-sans text-h3 text-ink">
            {t('rider.title')}
          </Text>
          {name ? <Text className="font-sans text-caption text-ink-muted">{name}</Text> : null}
        </View>
        <SyncStatus state={network} labels={{ online: t('rider.network.online'), syncing: t('rider.network.syncing'), offline: t('rider.network.offline') }} />
        {state?.pending ? <Text className="font-sans text-caption text-ink-muted">{t('rider.pending', { count: state.pending })}</Text> : null}
      </View>
      <FlatList
        data={state?.deliveries ?? []}
        keyExtractor={(delivery) => delivery.id}
        contentContainerClassName="gap-4 p-4 md:px-6"
        ListHeaderComponent={
          <View className="gap-3">
            {error ? <Alert tone="danger">{error}</Alert> : null}
            {hasActive ? <LocationConsent share={location.share} onAllow={location.allow} onNotNow={location.notNow} /> : null}
            {(state?.rejected ?? []).map((update) => (
              <Alert
                key={update.id}
                tone="warning"
                title={update.order_number ? t('rider.rejected.title', { number: update.order_number }) : t('rider.rejected.titleNoNumber')}
                action={
                  <Button variant="ghost" onPress={() => rider.dismiss(update.id)}>
                    {t('rider.rejected.dismiss')}
                  </Button>
                }
              >
                {t(`rider.rejected.${update.error}`, { defaultValue: update.message ?? t('rider.rejected.failed') })}
              </Alert>
            ))}
            <View className="flex-row flex-wrap gap-3">
              <Button variant="secondary" onPress={() => refresh().catch(() => {})}>
                {t('rider.refresh')}
              </Button>
              <Button variant="ghost" onPress={signOut}>
                {t('rider.signOut')}
              </Button>
            </View>
          </View>
        }
        ListEmptyComponent={state ? <Text className="font-sans text-body text-ink-muted">{t('rider.empty')}</Text> : null}
        renderItem={({ item }) => (
          <DeliveryCard
            delivery={item}
            onPickedUp={(delivery) => record(delivery, 'picked_up')}
            onDelivered={(delivery) => setDialog({ kind: 'delivered', delivery })}
            onFailed={(delivery) => setDialog({ kind: 'failed', delivery })}
          />
        )}
      />
      {dialog?.kind === 'delivered' ? <DeliveredDialog delivery={dialog.delivery} onClose={() => setDialog(null)} onConfirm={(extra) => record(dialog.delivery, 'delivered', extra)} /> : null}
      {dialog?.kind === 'failed' ? <FailedDialog delivery={dialog.delivery} onClose={() => setDialog(null)} onConfirm={(extra) => record(dialog.delivery, 'failed', extra)} /> : null}
    </View>
  );
}

/**
 * HOT-29: a signed-in user who holds hotel.housekeeping.update (asked of the
 * server, GET me/permissions) also gets their rooms: the rooms list opens
 * first and a switch moves between it and the deliveries. Everyone else sees
 * the deliveries only, as before.
 */
function SignedInHome({ onSignedOut }) {
  const { t } = useTranslation();
  const { housekeeping } = useServices();
  const [hotel, setHotel] = useState(false);
  const [section, setSection] = useState('rooms');

  useEffect(() => {
    let active = true;
    housekeeping.allowed().then((allowed) => active && setHotel(allowed)).catch(() => {});
    return () => {
      active = false;
    };
  }, [housekeeping]);

  if (!hotel) return <RiderDeliveries onSignedOut={onSignedOut} />;
  return (
    <View className="flex-1">
      <View accessibilityRole="tablist" className="flex-row gap-3 border-b border-border bg-surface-200 px-4 py-2 md:px-6">
        {[
          ['rooms', t('hotel.housekeeping.tab')],
          ['deliveries', t('rider.title')],
        ].map(([key, label]) => (
          <Button key={key} variant={section === key ? 'secondary' : 'ghost'} accessibilityRole="tab" aria-selected={section === key} className="flex-1" onPress={() => setSection(key)}>
            {label}
          </Button>
        ))}
      </View>
      {section === 'rooms' ? <HousekeepingScreen onSignedOut={onSignedOut} /> : <RiderDeliveries onSignedOut={onSignedOut} />}
    </View>
  );
}

/**
 * ECOM-28, ECOM-29, NFR-04: the rider's screen. Their open deliveries stay on
 * the phone; picked up, delivered (with the customer's code and the cash
 * collected) and could not deliver are queued on the phone first and upload
 * when the server is reached, so a rider works through a dead zone. A photo
 * of the parcel may stand in for the code (ECOM-28), and the rider's position
 * is shared, with consent, while a delivery is active (ECOM-29).
 */
export function RiderScreen({ onExit }) {
  const { riderCredentials } = useServices();
  const [signedIn, setSignedIn] = useState(null);

  useEffect(() => {
    let active = true;
    riderCredentials.token().then((token) => active && setSignedIn(Boolean(token)));
    return () => {
      active = false;
    };
  }, [riderCredentials]);

  return (
    <SafeAreaView className="flex-1 bg-surface-100">
      {signedIn === null ? null : signedIn ? (
        <SignedInHome onSignedOut={() => setSignedIn(false)} />
      ) : (
        <RiderSignIn onSignedIn={() => setSignedIn(true)} onBack={onExit} />
      )}
    </SafeAreaView>
  );
}
