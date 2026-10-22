import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Image, Platform, Text, View } from 'react-native';
import { Alert } from '../../components/ds/Alert';
import { Button } from '../../components/ds/Button';
import { pickPictureSize } from '../../rider/rider';
import { loadCamera } from '../pos/CameraScanner';

/** ECOM-28: a photo proof needs the device camera (native only; the web preview keeps the code). */
export const PHOTO_PROOF = Platform.OS !== 'web';

/**
 * ECOM-28: the delivery photo. The back camera opens in place; the rider
 * takes the photo, sees it, and chooses "Retake" or "Use photo". The photo
 * stays a file on the phone (quality 0.5, no EXIF, a moderate size) until the
 * rider's queue uploads it.
 */
export function DeliveryPhoto({ photo, onChange }) {
  const { t } = useTranslation();
  const { CameraView, useCameraPermissions } = loadCamera();
  const [permission, requestPermission] = useCameraPermissions();
  const [taken, setTaken] = useState(null);
  const [busy, setBusy] = useState(false);
  const [failed, setFailed] = useState(false);
  const [pictureSize, setPictureSize] = useState(undefined);
  const camera = useRef(null);

  async function ready() {
    try {
      setPictureSize(pickPictureSize(await camera.current?.getAvailablePictureSizesAsync?.()));
    } catch {
      // The camera's default size is used.
    }
  }

  async function take() {
    setBusy(true);
    setFailed(false);
    try {
      const picture = await camera.current?.takePictureAsync({ quality: 0.5, exif: false });
      if (picture?.uri) setTaken({ uri: picture.uri, taken_at: new Date().toISOString() });
      else setFailed(true);
    } catch {
      setFailed(true);
    } finally {
      setBusy(false);
    }
  }

  const shown = photo ?? taken;
  if (shown) {
    return (
      <View className="gap-3">
        <Image source={{ uri: shown.uri }} accessibilityLabel={t('rider.delivered.photo.preview')} className="aspect-square w-full rounded-md border border-border" />
        {photo ? <Text className="font-sans text-body text-ink-muted">{t('rider.delivered.photo.ready')}</Text> : null}
        <View className="flex-row flex-wrap gap-3">
          <Button
            variant="secondary"
            className="flex-1"
            onPress={() => {
              setTaken(null);
              onChange(null);
            }}
          >
            {t('rider.delivered.photo.retake')}
          </Button>
          {photo ? null : (
            <Button variant="primary" className="flex-1" onPress={() => onChange(taken)}>
              {t('rider.delivered.photo.use')}
            </Button>
          )}
        </View>
      </View>
    );
  }

  if (!permission) return null;
  if (!permission.granted) {
    return (
      <View className="gap-3">
        <Text className="font-sans text-body-lg text-ink-muted">{t('rider.delivered.photo.permission')}</Text>
        <Button variant="secondary" onPress={requestPermission}>
          {t('rider.delivered.photo.allow')}
        </Button>
      </View>
    );
  }

  return (
    <View className="gap-3">
      <Text className="font-sans text-body text-ink-muted">{t('rider.delivered.photo.help')}</Text>
      <View className="aspect-square w-full overflow-hidden rounded-md border border-border">
        <CameraView ref={camera} className="flex-1" facing="back" pictureSize={pictureSize} onCameraReady={ready} />
      </View>
      {failed ? <Alert tone="warning">{t('rider.delivered.photo.failed')}</Alert> : null}
      <Button variant="secondary" block loading={busy} onPress={take}>
        {t('rider.delivered.photo.take')}
      </Button>
    </View>
  );
}
