/*
 * Service worker de l'espace livreur MK Délices (portée /livreur).
 * Rôle unique : afficher les notifications « nouvelle mission » envoyées par le serveur, même application
 * fermée, et ouvrir la mission au toucher. Aucune mise en cache : l'application reste toujours à jour.
 */
self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()))

self.addEventListener('push', (event) => {
  let data = {}
  try {
    data = event.data ? event.data.json() : {}
  } catch {
    data = { body: event.data ? event.data.text() : '' }
  }
  const title = data.title || 'MK Délices'
  event.waitUntil(
    self.registration.showNotification(title, {
      body: data.body || 'Nouvelle mission disponible.',
      tag: data.tag || 'mk-mission',
      renotify: true,
      requireInteraction: true,
      icon: '/favicon.svg',
      badge: '/favicon.svg',
      lang: 'fr',
      data: { url: data.url || '/livreur' },
    }),
  )
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  // Uniquement un chemin de l'espace livreur (jamais une adresse externe reçue dans le message).
  const raw = (event.notification.data && event.notification.data.url) || '/livreur'
  const path = typeof raw === 'string' && /^\/livreur(\/[A-Za-z0-9/_-]*)?$/.test(raw) ? raw : '/livreur'
  const target = new URL(path, self.location.origin).href
  event.waitUntil(
    self.clients.matchAll({ type: 'window' }).then(async (windows) => {
      // Un onglet de l'espace livreur déjà ouvert est réutilisé ; sinon une nouvelle fenêtre est ouverte.
      const open = windows.find((c) => new URL(c.url).pathname.startsWith('/livreur'))
      if (open) {
        try {
          await open.focus()
          await open.navigate(target)
          return
        } catch {
          // Onglet non contrôlé ou fermé entre-temps : ouverture d'une nouvelle fenêtre.
        }
      }
      await self.clients.openWindow(target)
    }),
  )
})
