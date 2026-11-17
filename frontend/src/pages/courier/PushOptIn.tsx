import { Bell, BellOff } from 'lucide-react'
import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Display'
import { errorMessage, Notice } from '@/components/ui/Feedback'
import { currentPushState, disablePush, enablePush, type PushState } from './push'

/** Bandeau d'activation des notifications « nouvelle mission » (affiché seulement si le serveur est configuré). */
export function PushOptIn({ publicKey }: { publicKey: string | null }) {
  const [state, setState] = useState<PushState | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    let alive = true
    currentPushState()
      .then((s) => alive && setState(s))
      .catch(() => alive && setState('unsupported'))
    return () => {
      alive = false
    }
  }, [])

  if (!publicKey || state === null || state === 'unsupported') return null

  if (state === 'ios-install')
    return (
      <Notice tone="info" title="Recevoir les nouvelles missions sur iPhone">
        Touchez <strong>Partager</strong> puis <strong>Sur l'écran d'accueil</strong>, ouvrez MK Livreur depuis l'écran d'accueil et activez les notifications.
      </Notice>
    )

  if (state === 'denied')
    return (
      <Notice tone="warning" title="Notifications bloquées">
        Autorisez les notifications pour ce site dans les réglages du navigateur pour être prévenu des nouvelles missions.
      </Notice>
    )

  const toggle = async () => {
    setBusy(true)
    try {
      if (state === 'on') {
        await disablePush()
        setState('off')
        toast.success('Notifications désactivées sur cet appareil')
      } else {
        const next = await enablePush(publicKey)
        setState(next)
        if (next === 'on') toast.success('Vous serez prévenu des nouvelles missions')
      }
    } catch (e) {
      toast.error(errorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  if (state === 'on')
    return (
      <div className="flex items-center justify-between gap-3 text-sm text-cocoa-500">
        <span className="flex items-center gap-2">
          <Bell className="size-4 text-success-600" aria-hidden /> Notifications activées sur cet appareil
        </span>
        <Button variant="ghost" size="sm" loading={busy} onClick={toggle}>
          Désactiver
        </Button>
      </div>
    )

  return (
    <Card className="flex items-center gap-3 border-l-4 border-caramel-500 px-4 py-3">
      <BellOff className="size-5 shrink-0 text-cocoa-500" aria-hidden />
      <p className="flex-1 text-sm">Soyez prévenu d'une nouvelle mission, même application fermée.</p>
      <Button size="sm" loading={busy} onClick={toggle}>
        Activer les notifications
      </Button>
    </Card>
  )
}
