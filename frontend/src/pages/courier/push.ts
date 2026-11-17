import { api } from '@/lib/api'

/** Notifications du navigateur de l'espace livreur (service worker /sw.js, portée /livreur). */
const SW_URL = '/sw.js'
const SCOPE = '/livreur'

export type PushState = 'unsupported' | 'ios-install' | 'denied' | 'off' | 'on'

export function isPushSupported(): boolean {
  return typeof window !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
}

function isIos(): boolean {
  return /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
}

function isStandalone(): boolean {
  return window.matchMedia?.('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true
}

async function registration(): Promise<ServiceWorkerRegistration | undefined> {
  return navigator.serviceWorker.getRegistration(SCOPE)
}

export async function currentPushState(): Promise<PushState> {
  if (!isPushSupported()) {
    // Sur iPhone, les notifications n'existent que pour l'application ajoutée à l'écran d'accueil (iOS 16.4+).
    return isIos() && !isStandalone() ? 'ios-install' : 'unsupported'
  }
  if (Notification.permission === 'denied') return 'denied'
  const sub = await (await registration())?.pushManager.getSubscription()
  return sub && Notification.permission === 'granted' ? 'on' : 'off'
}

function keyToBytes(base64url: string): Uint8Array<ArrayBuffer> {
  const padded = (base64url + '='.repeat((4 - (base64url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/')
  const raw = atob(padded)
  const bytes = new Uint8Array(new ArrayBuffer(raw.length))
  for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i)
  return bytes
}

/** Demande l'autorisation (à appeler sur un geste de l'utilisateur), abonne l'appareil et l'enregistre côté serveur. */
export async function enablePush(publicKey: string): Promise<PushState> {
  const permission = await Notification.requestPermission()
  if (permission !== 'granted') return permission === 'denied' ? 'denied' : 'off'

  await navigator.serviceWorker.register(SW_URL, { scope: SCOPE })
  const reg = await navigator.serviceWorker.ready
  const options = { userVisibleOnly: true, applicationServerKey: keyToBytes(publicKey) }
  let sub: PushSubscription
  try {
    sub = await reg.pushManager.subscribe(options)
  } catch (e) {
    // Abonnement existant créé avec d'anciennes clés serveur : il est remplacé.
    const old = await reg.pushManager.getSubscription()
    if (!old) throw e
    await old.unsubscribe()
    sub = await reg.pushManager.subscribe(options)
  }
  await api.post('/courier/push-subscriptions', sub.toJSON())
  return 'on'
}

/** Désabonne cet appareil (bouton « Désactiver » et déconnexion : un téléphone partagé ne reçoit plus les missions). */
export async function disablePush(): Promise<void> {
  if (!isPushSupported()) return
  const sub = await (await registration())?.pushManager.getSubscription()
  if (!sub) return
  try {
    await api.delete('/courier/push-subscriptions', { body: { endpoint: sub.endpoint } })
  } finally {
    await sub.unsubscribe()
  }
}
