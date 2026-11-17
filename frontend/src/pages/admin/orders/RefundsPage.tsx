import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Undo2 } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router'
import { toast } from 'sonner'
import { Button } from '@/components/ui/Button'
import { Badge, Card, Modal, PageHeader, StatusPill } from '@/components/ui/Display'
import { EmptyState, ErrorState, errorMessage, Notice, Skeleton } from '@/components/ui/Feedback'
import { Field, Input, Select, Textarea } from '@/components/ui/Form'
import { useAuth } from '@/features/auth/AuthContext'
import { api, toQuery } from '@/lib/api'
import { formatDateTime, formatXof } from '@/lib/format'
import type { Refund } from '@/types/api'
import { Pagination, type PaginatedResponse } from './shared'
import { REFUND_STATUS_LABELS } from './labels'

type Dialog = { refund: Refund; kind: 'reject' | 'complete' } | null

export function RefundsPage() {
  const { me, can } = useAuth()
  const queryClient = useQueryClient()
  const [status, setStatus] = useState('requested,approved')
  const [page, setPage] = useState(1)
  const [dialog, setDialog] = useState<Dialog>(null)
  const [text, setText] = useState('')
  const canApprove = can('refunds.approve')

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['admin', 'refunds', status, page],
    queryFn: () => api.get<PaginatedResponse<Refund>>(`/admin/refunds${toQuery({ status, page })}`),
    placeholderData: keepPreviousData,
  })

  const act = useMutation({
    mutationFn: ({ refund, action, body }: { refund: Refund; action: 'approve' | 'reject' | 'complete'; body?: Record<string, string> }) => api.post(`/admin/refunds/${refund.id}/${action}`, body),
    onSuccess: (_, { action }) => {
      toast.success(action === 'approve' ? 'Remboursement approuvé' : action === 'reject' ? 'Remboursement rejeté' : 'Remboursement marqué comme effectué')
      setDialog(null)
      setText('')
      queryClient.invalidateQueries({ queryKey: ['admin', 'refunds'] })
      // Un remboursement effectué émet un avoir et modifie la commande et ses paiements.
      queryClient.invalidateQueries({ queryKey: ['admin', 'invoices'] })
      queryClient.invalidateQueries({ queryKey: ['admin', 'order'] })
      queryClient.invalidateQueries({ queryKey: ['admin', 'payments'] })
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Finances" title="Remboursements" />
      <Notice tone="warning" title="Double contrôle">
        La personne qui demande un remboursement ne peut pas l'approuver. Le montant cumulé ne peut jamais dépasser le montant réellement encaissé.
      </Notice>

      <Card className="p-4">
        <Field label="Statut" className="max-w-xs">
          {(p) => (
            <Select
              {...p}
              value={status}
              onChange={(e) => {
                setStatus(e.target.value)
                setPage(1)
              }}
            >
              <option value="requested,approved">À traiter</option>
              <option value="">Tous</option>
              {Object.entries(REFUND_STATUS_LABELS).map(([k, v]) => (
                <option key={k} value={k}>
                  {v}
                </option>
              ))}
            </Select>
          )}
        </Field>
      </Card>

      {isLoading ? (
        <Skeleton className="h-48" />
      ) : isError || !data ? (
        <ErrorState error={error} onRetry={() => refetch()} />
      ) : data.data.length === 0 ? (
        <EmptyState icon={<Undo2 className="size-6" />} title="Aucun remboursement" description="Aucune demande ne correspond à ce filtre." />
      ) : (
        <>
          <ul className="space-y-3">
            {data.data.map((r) => {
              const own = r.requested_by_id !== null && r.requested_by_id === me?.id
              return (
                <li key={r.id}>
                  <Card className="flex flex-col gap-4 p-4 md:flex-row md:items-center md:justify-between">
                    <div>
                      <div className="flex flex-wrap items-center gap-2">
                        <Link to={`/admin/commandes/${r.order_id}`} className="font-semibold hover:underline">
                          {r.order_reference ?? 'Commande'}
                        </Link>
                        <StatusPill status={r.status} label={REFUND_STATUS_LABELS[r.status] ?? r.status} />
                        {r.kind === 'overpayment' && <Badge tone="warning">Trop-perçu</Badge>}
                        <span className="font-semibold tabular-nums">{formatXof(r.amount_xof)}</span>
                      </div>
                      <p className="mt-1 text-sm text-cocoa-700">{r.reason}</p>
                      {r.status === 'approved' && r.execution_note && <p className="mt-1.5 rounded bg-warning-50 px-2 py-1 text-xs text-warning-600">{r.execution_note}</p>}
                      <p className="mt-1 text-xs text-cocoa-500">
                        Demandé le {formatDateTime(r.created_at)} par {r.requested_by ?? 'le système'}
                        {r.approved_by && ` · traité par ${r.approved_by} le ${formatDateTime(r.approved_at)}`}
                        {r.provider_ref && ` · réf. ${r.provider_ref}`}
                      </p>
                    </div>
                    {canApprove && (
                      <div className="flex flex-wrap gap-2">
                        {r.status === 'requested' && (
                          <>
                            <Button size="sm" disabled={own} title={own ? 'Double contrôle : vous avez créé cette demande' : undefined} loading={act.isPending && act.variables?.refund.id === r.id && act.variables.action === 'approve'} onClick={() => act.mutate({ refund: r, action: 'approve' })}>
                              Approuver
                            </Button>
                            <Button size="sm" variant="secondary" onClick={() => setDialog({ refund: r, kind: 'reject' })}>
                              Rejeter
                            </Button>
                          </>
                        )}
                        {r.status === 'approved' && (
                          <Button size="sm" variant="accent" onClick={() => setDialog({ refund: r, kind: 'complete' })}>
                            Confirmer l'exécution
                          </Button>
                        )}
                        {own && r.status === 'requested' && <p className="w-full text-xs text-cocoa-500">Approbation réservée à une autre personne.</p>}
                      </div>
                    )}
                  </Card>
                </li>
              )
            })}
          </ul>
          <Pagination meta={data.meta} onPage={setPage} />
        </>
      )}

      {dialog && (
        <Modal
          open
          onOpenChange={(o) => !o && setDialog(null)}
          title={dialog.kind === 'reject' ? 'Rejeter le remboursement' : "Confirmer l'exécution du remboursement"}
          description={dialog.kind === 'complete' ? "Indiquez la référence de l'opération chez le prestataire ou la pièce justificative." : undefined}
          footer={
            <div className="flex justify-end gap-2">
              <Button variant="secondary" onClick={() => setDialog(null)}>
                Annuler
              </Button>
              <Button
                disabled={text.trim().length < 3}
                loading={act.isPending}
                onClick={() => act.mutate({ refund: dialog.refund, action: dialog.kind, body: dialog.kind === 'reject' ? { reason: text.trim() } : { provider_ref: text.trim() } })}
              >
                Valider
              </Button>
            </div>
          }
        >
          <Field label={dialog.kind === 'reject' ? 'Motif' : 'Référence de remboursement'} required>
            {(p) => (dialog.kind === 'reject' ? <Textarea {...p} value={text} onChange={(e) => setText(e.target.value)} /> : <Input {...p} value={text} onChange={(e) => setText(e.target.value)} />)}
          </Field>
        </Modal>
      )}
    </div>
  )
}
