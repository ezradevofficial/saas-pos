import '@fontsource-variable/geist'
import '@fontsource-variable/geist-mono'
import { lazy, StrictMode, Suspense } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import './i18n'
import App from './App.jsx'
import { ThemeProvider } from './theme/ThemeProvider.jsx'

// The component gallery exists only in development (no router yet; Task 12 adds one).
const ComponentGallery = import.meta.env.DEV ? lazy(() => import('./dev/ComponentGallery.jsx')) : null
const showGallery = ComponentGallery && window.location.pathname === '/dev/components'

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <ThemeProvider>
      {showGallery ? (
        <Suspense fallback={null}>
          <ComponentGallery />
        </Suspense>
      ) : (
        <App />
      )}
    </ThemeProvider>
  </StrictMode>,
)
