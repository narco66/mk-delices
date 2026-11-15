import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Wallet } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/Button'
import { Card, PageHeader } from '@/components/ui/Display'
import { EmptyState, ErrorState, errorMessage, Notice, Skeleton } from '@/components/ui/Feedback'
import { api } from '@/lib/api'
import { formatDateTime, formatXof } from '@/lib/format'
import type { Assignment } from '@/types/api'

interface PartnerPayout {
  id: string
  display_name: string
  unsettled_missions: number
  amount_due_xof: number
  cash_held_xof: number
  delivered_total: number
  failed_total: number
}

interface PayoutsResponse {
  data: { partners: PartnerPayout[]; unsettled: Assignment[] }
}

export function PayoutsPage() {
  const queryClient = useQueryClient()
  const [selected, setSelected] = useState<string[]>([])
  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['admin', 'payouts'],
    queryFn: async () => (await api.get<PayoutsResponse>('/admin/delivery/payouts')).data,
  })

  const settle = useMutation({
    mutationFn: () => api.post<{ data: { settled: number } }>('/admin/delivery/payouts/settle', { assignment_ids: selected }),
    onSuccess: (res) => {
      toast.success(`${res.data.settled} mission(s) réglée(s)`)
      setSelected([])
      queryClient.invalidateQueries({ queryKey: ['admin', 'payouts'] })
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  if (isLoading) return <Skeleton className="h-96" />
  if (isError || !data) return <ErrorState error={error} onRetry={() => refetch()} />

  const toggle = (id: string) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]))
  const total = data.unsettled.filter((a) => selected.includes(a.id)).reduce((sum, a) => sum + a.partner_fee_xof, 0)

  return (
    <div className="space-y-8">
      <PageHeader eyebrow="Logistique" title="Règlements livreurs" description="Montants dus aux livreurs partenaires pour les missions livrées, et espèces encaissées à reverser." />
      <Notice>La rémunération provient du tarif livreur de la zone au moment de l'affectation. Les conditions définitives de rémunération restent à valider par MK Délices.</Notice>

      <section>
        <h2 className="mb-3 text-lg font-semibold">Par livreur</h2>
        {data.partners.length === 0 ? (
          <EmptyState title="Aucun livreur" />
        ) : (
          <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {data.partners.map((p) => (
              <li key={p.id}>
                <Card className="p-4">
                  <p className="font-semibold">{p.display_name}</p>
                  <p className="mt-2 font-display text-2xl font-semibold tabular-nums">{formatXof(p.amount_due_xof)}</p>
                  <p className="text-xs text-cocoa-500">dû pour {p.unsettled_missions} mission(s) non réglée(s)</p>
                  {p.cash_held_xof > 0 && <p className="mt-2 text-sm text-warning-600">Espèces encaissées à reverser : {formatXof(p.cash_held_xof)}</p>}
                  <p className="mt-2 text-xs text-cocoa-500">
                    Total : {p.delivered_total} livrée(s) · {p.failed_total} échec(s)
                  </p>
                </Card>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section>
        <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
          <h2 className="text-lg font-semibold">Missions livrées non réglées</h2>
          <Button disabled={selected.length === 0} loading={settle.isPending} onClick={() => settle.mutate()}>
            Marquer {selected.length || ''} réglée(s){selected.length ? ` · ${formatXof(total)}` : ''}
          </Button>
        </div>
        {data.unsettled.length === 0 ? (
          <EmptyState icon={<Wallet className="size-6" />} title="Tout est réglé" description="Aucune mission livrée en attente de règlement." />
        ) : (
          <Card className="overflow-x-auto">
            <table className="w-full min-w-[640px] text-sm">
              <thead className="border-b border-cocoa-100 bg-cream-100 text-left text-xs tracking-wide text-cocoa-500 uppercase">
                <tr>
                  <th className="px-4 py-3">
                    <input
                      type="checkbox"
                      aria-label="Tout sélectionner"
                      className="size-5 accent-cocoa-700"
                      checked={selected.length === data.unsettled.length}
                      onChange={(e) => setSelected(e.target.checked ? data.unsettled.map((a) => a.id) : [])}
                    />
                  </th>
                  <th className="px-4 py-3">Commande</th>
                  <th className="px-4 py-3">Livreur</th>
                  <th className="px-4 py-3">Livrée le</th>
                  <th className="px-4 py-3 text-right">Espèces</th>
                  <th className="px-4 py-3 text-right">Rémunération</th>
                </tr>
              </thead>
              <tbody>
                {data.unsettled.map((a) => (
                  <tr key={a.id} className="border-b border-cocoa-100/60 last:border-0">
                    <td className="px-4 py-3">
                      <input type="checkbox" className="size-5 accent-cocoa-700" aria-label={`Sélectionner ${a.order_reference} (${a.status === 'failed' ? 'course échouée' : 'livrée'})`} checked={selected.includes(a.id)} onChange={() => toggle(a.id)} />
                    </td>
                    <td className="px-4 py-3 font-semibold">
                      {a.order_reference}
                      {a.status === 'failed' && (
                        <span className="ml-2 rounded-full bg-warning-50 px-2 py-0.5 text-xs font-medium text-warning-600">Échec dû au client · part due</span>
                      )}
                    </td>
                    <td className="px-4 py-3">{a.partner?.name}</td>
                    <td className="px-4 py-3 text-xs">{formatDateTime(a.delivered_at ?? a.failed_at)}</td>
                    <td className="px-4 py-3 text-right tabular-nums">{a.cash_collected_xof ? formatXof(a.cash_collected_xof) : '—'}</td>
                    <td className="px-4 py-3 text-right font-semibold tabular-nums">{formatXof(a.partner_fee_xof)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Card>
        )}
      </section>
    </div>
  )
}
