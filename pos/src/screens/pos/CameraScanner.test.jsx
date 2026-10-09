import { act, render, screen } from '@testing-library/react-native';
import { CAMERA_SCANNING, CameraScanner } from './CameraScanner';

jest.mock('expo-camera', () => {
  const { View } = require('react-native');
  return { CameraView: (props) => <View testID="camera" {...props} />, useCameraPermissions: () => [{ granted: true }, jest.fn()] };
});

// POS-01: camera scanning on native; one scan adds one item.
describe('CameraScanner', () => {
  it('adds each code once, and says when a code is unknown', async () => {
    expect(CAMERA_SCANNING).toBe(true); // Jest runs as a native platform; the web preview hides the button.
    const onScanned = jest.fn((code) => (code === '6001' ? null : 'code_unknown'));
    await render(<CameraScanner onScanned={onScanned} onClose={jest.fn()} />);
    const camera = screen.getByTestId('camera');
    expect(camera.props.barcodeScannerSettings.barcodeTypes).toContain('ean13');

    await act(() => camera.props.onBarcodeScanned({ data: '6001' }));
    await act(() => camera.props.onBarcodeScanned({ data: '6001' }));
    expect(onScanned).toHaveBeenCalledTimes(1);
    expect(screen.getByText('Added 6001. Scan the next item, or close.')).toBeOnTheScreen();

    await act(() => camera.props.onBarcodeScanned({ data: '999' }));
    expect(screen.getByText('No product has the code 999. Check the code, or search by name.')).toBeOnTheScreen();
  });
});
