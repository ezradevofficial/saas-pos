import { useTranslation } from 'react-i18next'
import './i18n'

function App() {
  const { t } = useTranslation()

  return (
    <main>
      <h1>{t('app.name')}</h1>
    </main>
  )
}

export default App
