import '@fontsource-variable/geist'
import '@fontsource-variable/geist-mono'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import { i18nReady } from './i18n'
import App from './App.jsx'
import { initSentry } from './lib/sentry'

// NFR-10: error tracking, only when the build has a DSN (src/lib/sentry.js).
initSentry()

// NFR-02: the active language's catalogue is its own chunk; paint once it is in.
i18nReady.then(() => {
  createRoot(document.getElementById('root')).render(
    <StrictMode>
      <App />
    </StrictMode>,
  )
})
