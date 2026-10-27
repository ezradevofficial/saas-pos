import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Text } from 'react-native';
import { useSession } from '../auth/session';
import { Alert } from '../components/ds/Alert';
import { Button } from '../components/ds/Button';
import { TextField } from '../components/ds/TextField';
import { usePosData } from '../pos/PosProvider';
import { productName } from './data';
import { can, FUEL } from './permissions';
import { Choice, Frame, Section } from './ui';
import { errorText, litresText } from './text';

/**
 * FUEL-17: ask for a meter reset when a meter was replaced or rolled over. Supervisors only
 * (`fuel.meter.request_reset`): the reading before, the reading after and a reason. The till can only ask; a
 * different person approves it in the back office, and nothing changes here until then. Works offline.
 */
export function ResetScreen({ fuel, shift, services, location, onBack, onDone }) {
  const { t } = useTranslation();
  const { user, actorProof } = useSession();
  const { catalogue } = usePosData();
  const [nozzle, setNozzle] = useState(null);
  const [before, setBefore] = useState('');
  const [after, setAfter] = useState('0');
  const [reason, setReason] = useState('');
  const [error, setError] = useState(null);
  const [sent, setSent] = useState(false);
  const [busy, setBusy] = useState(false);
  const allowed = can(user, FUEL.requestReset);

  async function send() {
    setError(null);
    setBusy(true);
    try {
      await services.resets.request({ user, actorProof, nozzle, shift, before, after, reason });
      setSent(true);
    } catch (failure) {
      setError(errorText(t, failure));
    } finally {
      setBusy(false);
    }
  }

  if (!allowed) {
    return (
      <Frame title={t('fuel.reset.title')} onBack={onBack} location={location}>
        <Alert tone="warning">{t('fuel.reset.supervisorOnly')}</Alert>
      </Frame>
    );
  }
  if (sent) {
    return (
      <Frame title={t('fuel.reset.title')} location={location}>
        <Alert tone="success" title={t('fuel.reset.sentTitle')}>
          {t('fuel.reset.sent')}
        </Alert>
        <Button variant="primary" block onPress={onDone}>
          {t('fuel.common.done')}
        </Button>
      </Frame>
    );
  }
  return (
    <Frame title={t('fuel.reset.title')} onBack={onBack} location={location}>
      <Text className="font-sans text-body-lg text-ink-muted">{t('fuel.reset.intro')}</Text>
      <Section title={t('fuel.reset.nozzle')}>
        {fuel.nozzles.map((candidate) => (
          <Choice
            key={candidate.id}
            selected={nozzle?.id === candidate.id}
            onPress={() => {
              setNozzle(candidate);
              setBefore(candidate.last_reading_litres ?? '');
            }}
          >
            <Text className="font-sans text-body-lg text-ink">{`${candidate.pump_code} / ${candidate.code} · ${productName(catalogue, candidate)}`}</Text>
            {candidate.last_reading_litres ? <Text className="font-sans text-caption text-ink-muted">{t('fuel.open.lastReading', { reading: litresText(candidate.last_reading_litres) })}</Text> : null}
          </Choice>
        ))}
      </Section>
      <TextField label={t('fuel.reset.before')} suffix="L" keyboardType="decimal-pad" value={before} onChangeText={setBefore} />
      <TextField label={t('fuel.reset.after')} help={t('fuel.reset.afterHelp')} suffix="L" keyboardType="decimal-pad" value={after} onChangeText={setAfter} />
      <TextField label={t('fuel.reset.reason')} value={reason} onChangeText={setReason} maxLength={500} />
      {error ? <Alert tone="danger">{error}</Alert> : null}
      <Button variant="primary" block loading={busy} disabled={!nozzle} onPress={send}>
        {t('fuel.reset.submit')}
      </Button>
    </Frame>
  );
}
