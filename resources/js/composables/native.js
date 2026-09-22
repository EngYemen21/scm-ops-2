// The native shells (Capacitor, Android / iOS). Everything here is a no-op in the browser, so the same client runs on the
// web and inside the apps. Loaded only when Capacitor reports a native platform.
import { Capacitor } from '@capacitor/core';

export const isNative = Capacitor.isNativePlatform();
export const platform = Capacitor.getPlatform(); // 'web' | 'android' | 'ios'

/** Status bar colour, splash screen, hardware back button, keyboard. Call once after the router is ready. */
export async function setupNative(router) {
  if (!isNative) return;
  const [{ App }, { StatusBar, Style }, { SplashScreen }] = await Promise.all([import('@capacitor/app'), import('@capacitor/status-bar'), import('@capacitor/splash-screen')]);
  try {
    await StatusBar.setStyle({ style: Style.Dark });
    if (platform === 'android') await StatusBar.setBackgroundColor({ color: '#1E2130' });
  } catch { /* status bar not available (e.g. iPad split view) */ }
  // Android back: close the current screen; on a home screen let the OS minimise the app.
  App.addListener('backButton', ({ canGoBack }) => {
    const openLayer = document.querySelector('.drawer, .modal-wrap, .sheet');
    if (openLayer) { openLayer.querySelector('.x-btn, .m-back, [aria-label="close"]')?.click(); return; }
    if (canGoBack && window.history.length > 1 && !['/dash', '/driver', '/wreceive', '/login'].includes(router.currentRoute.value.path)) router.back();
    else App.minimizeApp();
  });
  await router.isReady();
  await SplashScreen.hide({ fadeOutDuration: 250 });
}

/** The camera inside the app needs the OS permission before getUserMedia can open it. Resolves true when usable. */
export async function ensureCameraPermission() {
  if (!isNative) return true;
  try {
    const { Camera } = await import('@capacitor/camera');
    const now = await Camera.checkPermissions();
    if (now.camera === 'granted') return true;
    const asked = await Camera.requestPermissions({ permissions: ['camera'] });
    return asked.camera === 'granted' || asked.camera === 'limited';
  } catch { return true; }
}
