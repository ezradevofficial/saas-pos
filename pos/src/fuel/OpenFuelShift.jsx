import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Text, View } from 'react-native';
import { useSession } from '../auth/session';
import { Alert } from '../components/ds/Alert';
import { Button } from '../components/ds/Button';
import { Dialog } from '../components/ds/Dialog';
import { TextField } from '../components/ds/TextField';
import { usePosData } from '../pos/PosProvider';
import { nozzlesByPump, productName } from './data';
import { parseCm, parseLitres } from './meters';
import { lastKnownReading } from './shifts';
import { Choice, Frame, Section } from './ui';
import { errorText, litresText } from './text';

/** Who can be put on a nozzle: staff of the till who may sell fuel (the Owner too), not locked. */
function attendantsOf(catalogue) {
  const staff = (catalogue?.staff ?? []).filter((member) => !member.locked);
  const eligible = staff.filter((member) => member.owner || member.permissions?.includes('fuel.sale.create'));
  return eligible.length ? eligible : staff;
}

function AttendantDialog({ staff, onChoose, onClose }) {
  const { t } = useTranslation();
  return (
    <Dialog open title={t('fuel.open.chooseAttendant')} onClose={onClose}>
      <View className="gap-2">
        {staff.map((member) => (
          <Choice key={member.id} onPress={() => onChoose(member)}>
            <Text className="font-sans text-body-lg text-ink">{member.name}</Text>
          </Choice>
        ))}
      </View>
    </Dialog>
  );
}

/**
 * FUEL-09, FUEL-16, FUEL-31: open the forecourt shift. Choose the nozzles in use, type the opening meter
 * reading of each (the last reading is shown; a lower one is refused here, as the server would), assign an
 * attendant to each and optionally take opening dips. Works offline; the shift is queued for upload.
 */
export function OpenFuelShift({ fuel, services, location, onBack, onDone }) {
  const { t } = useTranslation();
  const { user, actorProof } = useSession();
  const { catalogue } = usePosData();
  const staff = useMemo(() => attendantsOf(catalogue), [catalogue]);
  const groups = useMemo(() => nozzlesByPump(fuel), [fuel]);
  const [rows, setRows] = useState({});
  const [dips, setDips] = useState({});
  const [chooser, setChooser] = useState(null);
  const [errors, setErrors] = useState({});
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const [lastReadings, setLastReadings] = useState(null);

  // The last reading each nozzle is known to have, including shifts closed here since the last sync.
  useEffect(() => {
    let active = true;
    services.shifts.closed(10).then((closed) => active && setLastReadings(Object.fromEntries(fuel.nozzles.map((nozzle) => [nozzle.id, lastKnownReading(nozzle, closed)]))));
    return () => {
      active = false;
    };
  }, [services, fuel]);

  const update = (id, patch) => {
    setRows((current) => ({ ...current, [id]: { ...current[id], ...patch } }));
    setErrors((current) => ({ ...current, [id]: undefined }));
  };
  const toggle = (nozzle) => {
    if (rows[nozzle.id]?.on) update(nozzle.id, { on: false });
    else update(nozzle.id, { on: true, attendant: rows[nozzle.id]?.attendant ?? staff.find((member) => member.id === user?.id) ?? staff[0] ?? null, reading: rows[nozzle.id]?.reading ?? '' });
  };

  async function open() {
    setError(null);
    setErrors({});
    const chosen = fuel.nozzles.filter((nozzle) => rows[nozzle.id]?.on);
    const missing = {};
    for (const nozzle of chosen) if (parseLitres(rows[nozzle.id].reading) === null) missing[nozzle.id] = t('fuel.errors.reading_invalid');
    if (Object.keys(missing).length) return setErrors(missing);
    const dipRows = [];
    for (const tank of fuel.tanks) {
      const text = dips[tank.id];
      if (text && parseCm(text) === null) return setError(t('fuel.errors.dip_invalid'));
      if (text) dipRows.push({ tank, dipCm: text });
    }
    setBusy(true);
    try {
      await services.shifts.open({
        user,
        actorProof,
        station: fuel.station,
        rows: chosen.map((nozzle) => ({ nozzle, attendantId: rows[nozzle.id].attendant?.id, attendantName: rows[nozzle.id].attendant?.name, reading: rows[nozzle.id].reading })),
        dips: dipRows,
      });
      onDone();
    } catch (failure) {
      if (failure?.nozzleId) setErrors({ [failure.nozzleId]: errorText(t, failure) });
      else setError(errorText(t, failure));
    } finally {
      setBusy(false);
    }
  }

  const count = Object.values(rows).filter((row) => row?.on).length;
  return (
    <Frame title={t('fuel.open.title')} onBack={onBack} location={location} wide>
      <Text className="font-sans text-body-lg text-ink-muted">{t('fuel.open.intro')}</Text>
      {groups.map((pump) => (
        <Section key={pump.pumpId} title={`${pump.pumpCode} · ${pump.pumpName}`}>
          {pump.nozzles.map((nozzle) => {
            const row = rows[nozzle.id];
            const last = lastReadings?.[nozzle.id] ?? nozzle.last_reading_litres;
            return (
              <View key={nozzle.id} className="gap-3 rounded-md border border-border p-3">
                <Choice selected={Boolean(row?.on)} onPress={() => toggle(nozzle)} label={`${nozzle.pump_code} / ${nozzle.code}`}>
                  <View className="flex-row items-center justify-between gap-3">
                    <Text className="min-w-0 flex-1 font-sans text-body-lg text-ink">{`${nozzle.pump_code} / ${nozzle.code} · ${productName(catalogue, nozzle)}`}</Text>
                    <Text className="font-sans text-caption text-ink-muted">{row?.on ? t('fuel.open.inUse') : t('fuel.open.notUsed')}</Text>
                  </View>
                </Choice>
                {row?.on ? (
                  <>
                    <TextField
                      label={t('fuel.open.reading')}
                      help={last ? t('fuel.open.lastReading', { reading: litresText(last) }) : t('fuel.open.noLastReading')}
                      error={errors[nozzle.id]}
                      suffix="L"
                      keyboardType="decimal-pad"
                      value={row.reading}
                      onChangeText={(text) => update(nozzle.id, { reading: text })}
                    />
                    <Choice onPress={() => setChooser(nozzle.id)} label={t('fuel.open.attendant')}>
                      <Text className="font-sans text-body text-ink-muted">{t('fuel.open.attendant')}</Text>
                      <Text className="font-sans text-body-lg text-ink">{row.attendant?.name ?? t('fuel.open.chooseAttendant')}</Text>
                    </Choice>
                  </>
                ) : null}
              </View>
            );
          })}
        </Section>
      ))}
      {fuel.tanks.length ? (
        <Section title={t('fuel.open.dipsTitle')} help={t('fuel.open.dipsHelp')}>
          {fuel.tanks.map((tank) => (
            <TextField key={tank.id} label={`${tank.code} · ${tank.name}`} suffix="cm" keyboardType="decimal-pad" value={dips[tank.id] ?? ''} onChangeText={(text) => setDips((current) => ({ ...current, [tank.id]: text }))} />
          ))}
        </Section>
      ) : null}
      {error ? <Alert tone="danger">{error}</Alert> : null}
      <Button variant="pay" block loading={busy} disabled={!count} onPress={open}>
        {t('fuel.open.submit')}
      </Button>
      {chooser ? (
        <AttendantDialog
          staff={staff}
          onClose={() => setChooser(null)}
          onChoose={(member) => {
            update(chooser, { attendant: member });
            setChooser(null);
          }}
        />
      ) : null}
    </Frame>
  );
}
