import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Platform, Text, View } from 'react-native';
import { Alert } from '../../components/ds/Alert';
import { Button } from '../../components/ds/Button';
import { Dialog } from '../../components/ds/Dialog';

/**
 * POS-01: scan barcodes with the device camera (expo-camera, native only).
 * Keyboard and hardware scanners keep working through the search field;
 * on the web preview the camera button is hidden. Each code read is added
 * like a typed one; the same code is ignored for a moment so one scan adds
 * one item.
 */
export const CAMERA_SCANNING = Platform.OS !== 'web';

const BARCODES = ['ean13', 'ean8', 'upc_a', 'upc_e', 'code128', 'code39', 'qr'];
const REPEAT_MS = 1500;

let camera = null;
function loadCamera() {
  // Loaded on first use only: the native module is not needed until someone scans.
  if (!camera) {
    const module = require('expo-camera');
    const { cssInterop } = require('nativewind');
    cssInterop(module.CameraView, { className: 'style' });
    camera = module;
  }
  return camera;
}

export function CameraScanner({ onScanned, onClose }) {
  const { t } = useTranslation();
  const { CameraView, useCameraPermissions } = loadCamera();
  const [permission, requestPermission] = useCameraPermissions();
  const [last, setLast] = useState(null);
  const recent = useRef({ code: null, at: 0 });

  function scanned({ data }) {
    const now = Date.now();
    if (!data || (recent.current.code === data && now - recent.current.at < REPEAT_MS)) return;
    recent.current = { code: data, at: now };
    setLast({ code: data, problem: onScanned(data) });
  }

  return (
    <Dialog open title={t('pos.scan.title')} onClose={onClose}>
      {!permission ? null : !permission.granted ? (
        <View className="gap-3">
          <Text className="font-sans text-body-lg text-ink-muted">{t('pos.scan.permission')}</Text>
          <Button variant="primary" onPress={requestPermission}>
            {t('pos.scan.allow')}
          </Button>
        </View>
      ) : (
        <View className="aspect-square w-full overflow-hidden rounded-md border border-border">
          <CameraView className="flex-1" facing="back" barcodeScannerSettings={{ barcodeTypes: BARCODES }} onBarcodeScanned={scanned} />
        </View>
      )}
      {last ? (
        <Alert tone={last.problem ? 'warning' : 'success'}>
          {!last.problem
            ? t('pos.scan.added', { code: last.code })
            : last.problem === 'code_unknown'
              ? t('pos.search.unknown', { code: last.code })
              : t(`pos.unsellable.${last.problem}`, { defaultValue: t('pos.unsellable.not_sellable') })}
        </Alert>
      ) : null}
    </Dialog>
  );
}
