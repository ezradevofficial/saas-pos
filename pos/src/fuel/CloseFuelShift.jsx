import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Text, View } from 'react-native';
import { useSession } from '../auth/session';
import { Alert } from '../components/ds/Alert';
import { Button } from '../components/ds/Button';
import { TextField } from '../components/ds/TextField';
import { parseAmount } from '../pos/input';
import { usePosData } from '../pos/PosProvider';
import { useMoneyText } from '../screens/pos/format';
import { meterLitres, parseLitres } from './meters';
import { attendantsOf, previewVariance, resetFloor } from './shifts';
import { KINDS } from './tenders';
import { Figure, Frame, Section } from './ui';
import { errorText, litresText, minorToText } from './text';

/** A typed amount in minor units: '' when empty, null when it is not an amount in this currency. */
const minorOf = (text, decimals) => (String(text ?? '').trim() === '' ? '' : parseAmount(text, decimals));

/**
 * FUEL-10, FUEL-11, FUEL-13: close the forecourt shift. A closing reading for every nozzle (and the litres
 * poured back in pump tests), then what each attendant hands in: the cash counted and the other tenders
 * (prefilled with what this till recorded). Closing is blocked until every nozzle has a reading and every
 * attendant has declared cash, as the server would refuse it (`close_incomplete`). The variance shown is a
 * preview from this till's sales; the server recounts and decides.
 */
export function CloseFuelShift({ fuel, shift, running, services, location, onBack, onDone }) {
  const { t } = useTranslation();
  const money = useMoneyText();
  const { user, actorProof } = useSession();
  const { catalogue } = usePosData();
  const currency = fuel.station.currency;
  const decimals = catalogue.money.decimals(currency);
  const attendants = useMemo(() => attendantsOf(shift), [shift]);
  const [readings, setReadings] = useState({});
  const [declared, setDeclared] = useState(() =>
    Object.fromEntries(attendants.map((attendant) => [attendant.id, Object.fromEntries(KINDS.filter((kind) => kind !== 'cash' && running?.attendants?.[attendant.id]?.tenders?.[kind] > 0n).map((kind) => [kind, minorToText(running.attendants[attendant.id].tenders[kind], decimals)]))])),
  );
  const [dips, setDips] = useState({});
  const [note, setNote] = useState('');
  const [errors, setErrors] = useState({});
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const [closed, setClosed] = useState(null);
  const [now] = useState(() => Date.now());

  const setReading = (id, patch) => {
    setReadings((current) => ({ ...current, [id]: { ...current[id], ...patch } }));
    setErrors((current) => ({ ...current, [id]: undefined }));
    setError(null);
  };
  const setDeclaredKind = (id, kind, text) => {
    setDeclared((current) => ({ ...current, [id]: { ...current[id], [kind]: text } }));
    setError(null);
  };

  const declaredMinor = useMemo(() => {
    const out = {};
    for (const [id, kinds] of Object.entries(declared)) {
      out[id] = {};
      for (const [kind, text] of Object.entries(kinds)) {
        const minor = minorOf(text, decimals);
        if (minor !== null) out[id][kind] = minor;
      }
    }
    return out;
  }, [declared, decimals]);

  const preview = useMemo(
    () => (running ? previewVariance({ shift, summary: running, readings, declared: declaredMinor, fuel, at: now }) : []),
    [shift, running, readings, declaredMinor, fuel, now],
  );

  async function close() {
    setError(null);
    for (const attendant of attendants) {
      for (const kind of KINDS) {
        if (minorOf(declared[attendant.id]?.[kind], decimals) === null) return setError(t('fuel.close.amountInvalid', { name: attendant.name, kind: t(`fuel.tender.${kind}`) }));
      }
    }
    const dipRows = fuel.tanks.filter((tank) => dips[tank.id]).map((tank) => ({ tank, dipCm: dips[tank.id] }));
    setBusy(true);
    try {
      const result = await services.shifts.close({ user, actorProof, shift, nozzles: fuel.nozzles, readings, declared: declaredMinor, note: note.trim() || undefined, dips: dipRows });
      setClosed({ result, preview });
    } catch (failure) {
      if (failure?.nozzleId) setErrors({ [failure.nozzleId]: errorText(t, failure) });
      else setError(errorText(t, failure));
    } finally {
      setBusy(false);
    }
  }

  if (closed) {
    return (
      <Frame title={t('fuel.close.doneTitle')} location={location}>
        {closed.preview.map((row) => (
          <View key={row.attendantId} className="gap-1 rounded-md border border-border p-4">
            <Text className="font-sans text-body-lg font-medium text-ink">{row.name}</Text>
            <Figure label={t('fuel.close.expected')} value={money(String(row.expectedMinor), currency)} />
            <Figure label={t('fuel.close.collected')} value={row.collectedMinor === null ? '' : money(String(row.collectedMinor), currency)} />
            <Figure strong label={t('fuel.close.variance')} value={row.varianceMinor === null ? '' : money(String(row.varianceMinor), currency)} tone={row.varianceMinor ? 'danger' : undefined} />
          </View>
        ))}
        <Text className="font-sans text-caption text-ink-muted">{t('fuel.close.serverDecides')}</Text>
        <Button variant="primary" block onPress={onDone}>
          {t('fuel.close.done')}
        </Button>
      </Frame>
    );
  }

  return (
    <Frame title={t('fuel.close.title')} onBack={onBack} location={location} wide>
      <Text className="font-sans text-body-lg text-ink-muted">{t('fuel.close.intro')}</Text>
      <Section title={t('fuel.close.readings')}>
        {shift.nozzles.map((row) => {
          const entry = readings[row.nozzle_id] ?? {};
          const reading = parseLitres(entry.reading);
          const tests = parseLitres(entry.tests || '0');
          const nozzle = fuel.nozzles.find((candidate) => candidate.id === row.nozzle_id);
          const afterReset = nozzle ? resetFloor(nozzle, row, shift) !== null : false;
          const moved = reading !== null && tests !== null && !afterReset ? meterLitres({ opening: row.opening_reading, closing: reading, tests }) : null;
          return (
            <View key={row.nozzle_id} className="gap-3 rounded-md border border-border p-3">
              <Text className="font-sans text-body-lg text-ink">{`${row.label} · ${row.attendant_name}`}</Text>
              <TextField
                label={t('fuel.close.reading')}
                help={t('fuel.close.openingWas', { reading: litresText(row.opening_reading) })}
                error={errors[row.nozzle_id]}
                suffix="L"
                keyboardType="decimal-pad"
                value={entry.reading ?? ''}
                onChangeText={(text) => setReading(row.nozzle_id, { reading: text })}
              />
              <TextField label={t('fuel.close.tests')} help={t('fuel.close.testsHelp')} suffix="L" keyboardType="decimal-pad" value={entry.tests ?? ''} onChangeText={(text) => setReading(row.nozzle_id, { tests: text })} placeholder="0" />
              {moved !== null ? <Figure label={t('fuel.close.metered')} value={litresText(moved)} /> : null}
            </View>
          );
        })}
      </Section>

      <Section title={t('fuel.close.handedIn')} help={t('fuel.close.handedInHelp')}>
        {attendants.map((attendant, index) => {
          const row = preview[index];
          return (
            <View key={attendant.id} className="gap-3 rounded-md border border-border p-3">
              <Text className="font-sans text-body-lg text-ink">{attendant.name}</Text>
              <TextField label={t('fuel.tender.cash')} prefix={currency} keyboardType="decimal-pad" value={declared[attendant.id]?.cash ?? ''} onChangeText={(text) => setDeclaredKind(attendant.id, 'cash', text)} placeholder="0" />
              {KINDS.filter((kind) => kind !== 'cash').map((kind) => (
                <TextField key={kind} label={t(`fuel.tender.${kind}`)} help={t('fuel.close.recordedHelp')} prefix={currency} keyboardType="decimal-pad" value={declared[attendant.id]?.[kind] ?? ''} onChangeText={(text) => setDeclaredKind(attendant.id, kind, text)} placeholder="0" />
              ))}
              {row ? (
                <View className="gap-1 border-t border-border pt-3">
                  <Figure label={t('fuel.close.expected')} value={money(String(row.expectedMinor), currency)} />
                  <Figure label={t('fuel.close.collected')} value={row.collectedMinor === null ? '-' : money(String(row.collectedMinor), currency)} />
                  <Figure strong label={t('fuel.close.variance')} value={row.varianceMinor === null ? '-' : money(String(row.varianceMinor), currency)} tone={row.varianceMinor ? 'danger' : undefined} />
                </View>
              ) : null}
            </View>
          );
        })}
        <Text className="font-sans text-caption text-ink-muted">{t('fuel.close.serverDecides')}</Text>
      </Section>

      {fuel.tanks.length ? (
        <Section title={t('fuel.close.dipsTitle')} help={shift.dips?.length ? t('fuel.close.dipsTaken', { count: shift.dips.length }) : t('fuel.close.dipsHelp')}>
          {fuel.tanks.map((tank) => (
            <TextField key={tank.id} label={`${tank.code} · ${tank.name}`} suffix="cm" keyboardType="decimal-pad" value={dips[tank.id] ?? ''} onChangeText={(text) => setDips((current) => ({ ...current, [tank.id]: text }))} />
          ))}
        </Section>
      ) : null}

      <TextField label={t('fuel.close.note')} value={note} onChangeText={setNote} maxLength={500} />
      {error ? <Alert tone="danger">{error}</Alert> : null}
      <Button variant="pay" block loading={busy} onPress={close}>
        {t('fuel.close.submit')}
      </Button>
    </Frame>
  );
}
