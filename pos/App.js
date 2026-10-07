import { StatusBar } from 'expo-status-bar';
import { useTranslation } from 'react-i18next';
import { Text, View } from 'react-native';
import './src/i18n';

export default function App() {
  const { t } = useTranslation();

  return (
    <View>
      <Text accessibilityRole="header">{t('app.name')}</Text>
      <StatusBar style="auto" />
    </View>
  );
}
