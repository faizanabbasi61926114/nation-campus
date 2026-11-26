import type { CapacitorConfig } from '@capacitor/cli'

/**
 * The APK bundles this same Vite build; it is not a wrapper around a hosted site.
 * `http` + the patched manifest are what let the WebView reach a plain-HTTP Laravel
 * on the local network — see the android-apk workflow.
 */
const config: CapacitorConfig = {
  appId: 'pk.nationcampus.app',
  appName: 'Nation Campus',
  webDir: 'dist',
  android: {
    allowMixedContent: true,
  },
  server: {
    androidScheme: 'http',
    cleartext: true,
  },
}

export default config
