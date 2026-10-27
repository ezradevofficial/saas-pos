import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Text } from 'react-native';
import { useSession } from '../auth/session';
import { Alert } from '../components/ds/Alert';
import { Button } from '../components/ds/Button';
import { TextField } from '../components/ds/TextField';
import { dipToLitres } from './meters';
import { Choice, Frame, Section } from './ui';
import { errorText, litresText } from './text';

/**
 * FUEL-06, FUEL-23: record a tank dip in cm, offline. The litres come from the synced calibration chart in force
 * now (a dip outside the chart is refused here, as the server refuses it). The dip is kept with the open shift and
 * goes up with its close. The server converts again and wins.
 */
export function DipScreen({ fuel, shift, services, location, onBack, onDone }) {
  const { t } = useTranslation();
  const { user } = useSession();
  const [tankId, setTankId] = useState(fuel.tanks[0]?.id ?? null);
  const [dip, setDip] = useState('');
  const [water, setWater] = useState('');
  const [error, setError] = useState(null);
  const [saved, setSaved] = useState(null);
  const [busy, setBusy] = useState(false);
  const [now] = useState(() => Date.now());
  const tank = fuel.tanks.find((candidate) => candidate.id === tankId) ?? null;

  // The litres for what is typed so far, or the reason it cannot be converted.
  const converted = useMemo(() => {
    if (!tank || dip.trim() === '') return null;
    try {
      return { litres: dipToLitres(tank, dip, now).litres };
    } catch (failure) {
      return { failure };
    }
  }, [tank, dip, now]);

  async function record() {
    setError(null);
    setBusy(true);
    try {
      const result = await services.shifts.addDip({ user, shift, tank, dipCm: dip, waterCm: water });
      setSaved({ tank: tank.code, litres: result.litres });
      setDip('');
      setWater('');
    } catch (failure) {
      setError(errorText(t, failure));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Frame title={t('fuel.dip.title')} onBack={onBack} location={location}>
      {!shift ? <Alert tone="warning">{t('fuel.dip.needShift')}</Alert> : <Text className="font-sans text-body-lg text-ink-muted">{t('fuel.dip.intro')}</Text>}
      <Section title={t('fuel.dip.tank')}>
        {fuel.tanks.map((candidate) => (
          <Choice key={candidate.id} selected={candidate.id === tankId} onPress={() => { setTankId(candidate.id); setSaved(null); }}>
            <Text className="font-sans text-body-lg text-ink">{`${candidate.code} · ${candidate.name}`}</Text>
            <Text className="font-sans text-caption text-ink-muted">{t('fuel.dip.capacity', { capacity: litresText(candidate.capacity_litres) })}</Text>
          </Choice>
        ))}
      </Section>
      <TextField label={t('fuel.dip.cm')} suffix="cm" keyboardType="decimal-pad" value={dip} onChangeText={(text) => { setDip(text); setSaved(null); setError(null); }} error={converted?.failure ? errorText(t, converted.failure) : undefined} />
      <TextField label={t('fuel.dip.water')} suffix="cm" keyboardType="decimal-pad" value={water} onChangeText={setWater} />
      {converted?.litres ? (
        <Text accessibilityLiveRegion="polite" className="font-sans text-h3 font-medium tabular-nums text-ink">
          {litresText(converted.litres)}
        </Text>
      ) : null}
      {saved ? <Alert tone="success">{t('fuel.dip.saved', { tank: saved.tank, litres: litresText(saved.litres) })}</Alert> : null}
      {error ? <Alert tone="danger">{error}</Alert> : null}
      <Button variant="primary" block loading={busy} disabled={!shift || !tank || !converted?.litres} onPress={record}>
        {t('fuel.dip.submit')}
      </Button>
      {saved ? (
        <Button variant="ghost" block onPress={onDone}>
          {t('fuel.common.done')}
        </Button>
      ) : null}
    </Frame>
  );
}
