import { StatusBar } from 'expo-status-bar';
import { Text, View } from 'react-native';
import { appName } from './src/config';

export default function App() {
  return (
    <View>
      <Text accessibilityRole="header">{appName}</Text>
      <StatusBar style="auto" />
    </View>
  );
}
