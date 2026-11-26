import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import { Capacitor } from '@capacitor/core'
import './index.css'
import App from './App.tsx'

const native = Capacitor.isNativePlatform()

// The WebView always opens the bundle root, so send the launcher straight to the phone
// shell instead of the desk-sized workspace.
if (native && window.location.pathname === '/') window.history.replaceState(null, '', '/app')

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <BrowserRouter>
      <App />
    </BrowserRouter>
  </StrictMode>,
)

// Only the built bundle is registered: a dev-time service worker would serve the
// previous compile and hide hot reload. Natively the assets are already inside the APK,
// and a cached shell would outlive the app it belongs to.
if (import.meta.env.PROD && !native && 'serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    void navigator.serviceWorker.register('/sw.js')
  })
}
