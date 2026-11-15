import { useQuery } from '@tanstack/react-query'
import { ScrollText } from 'lucide-react'
import { useState } from 'react'
import { Card, PageHeader } from '@/components/ui/Display'
import { EmptyState, ErrorState, Notice, Skeleton } from '@/components/ui/Feedback'
import { Field, Input } from '@/components/ui/Form'
import { api, toQuery } from '@/lib/api'
import { formatDateTime } from '@/lib/format'
import { type LaravelPage, Pagination } from '../catalog/shared'

interface AuditLog {
  id: string
  actor: { id: string; name: string } | null
  action: string
  target_type: string | null
  target_id: string | null
  before_json: unknown
  after_json: unknown
  ip_address: string | null
  request_id: string | null
  created_at: string
}

function Json({ value }: { value: unknown }) {
  if (value === null || value === undefined) return <p className="text-xs text-cocoa-500">—</p>
  return <pre className="max-h-64 overflow-auto rounded-[var(--radius-control)] bg-cream-100 p-3 text-[11px] leading-relaxed whitespace-pre-wrap break-all">{JSON.stringify(value, null, 2)}</pre>
}

export function AuditPage() {
  const [filters, setFilters] = useState({ action: '', target_id: '', page: 1 })
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['admin', 'audit', filters],
    queryFn: () => api.get<LaravelPage<AuditLog>>(`/admin/audit-logs${toQuery(filters)}`),
  })

  return (
    <div>
      <PageHeader eyebrow="Administration" title="Journal d'audit" description="Journal immuable des opérations sensibles (prix, rôles, commandes, paiements, livraisons)." />
      <Notice className="mb-5">Les secrets, jetons et mots de passe sont expurgés avant enregistrement. Le journal ne peut être ni modifié ni supprimé (contrôle base de données).</Notice>
      <Card className="mb-5 grid gap-3 p-4 sm:grid-cols-2">
        <Field label="Action (préfixe)">
          {(p) => <Input {...p} placeholder="ex. order., payment., product." value={filters.action} onChange={(e) => setFilters({ ...filters, action: e.target.value, page: 1 })} />}
        </Field>
        <Field label="Identifiant de la cible">
          {(p) => <Input {...p} placeholder="UUID" value={filters.target_id} onChange={(e) => setFilters({ ...filters, target_id: e.target.value.trim(), page: 1 })} />}
        </Field>
      </Card>
      {error ? (
        <ErrorState error={error} onRetry={() => refetch()} />
      ) : isLoading ? (
        <Skeleton className="h-80" />
      ) : !data?.data.length ? (
        <EmptyState icon={<ScrollText />} title="Aucune entrée" description="Aucune opération ne correspond à ces filtres." />
      ) : (
        <ul className="space-y-2">
          {data.data.map((log) => (
            <li key={log.id}>
              <Card className="p-0">
                <details className="group">
                  <summary className="flex cursor-pointer list-none flex-col gap-1 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="min-w-0">
                      <p className="font-mono text-sm font-semibold">{log.action}</p>
                      <p className="truncate text-xs text-cocoa-500">
                        {log.actor?.name ?? 'Système'} · {log.target_type ?? '—'} {log.target_id ? `#${log.target_id.slice(0, 8)}` : ''}
                      </p>
                    </div>
                    <p className="shrink-0 text-xs text-cocoa-500">{formatDateTime(log.created_at, { second: '2-digit' })}</p>
                  </summary>
                  <div className="grid gap-3 border-t border-cocoa-100/60 px-4 py-3 md:grid-cols-2">
                    <div>
                      <p className="mb-1 text-xs font-semibold text-cocoa-500 uppercase">Avant</p>
                      <Json value={log.before_json} />
                    </div>
                    <div>
                      <p className="mb-1 text-xs font-semibold text-cocoa-500 uppercase">Après</p>
                      <Json value={log.after_json} />
                    </div>
                    <p className="text-xs text-cocoa-500 md:col-span-2">
                      Cible : {log.target_id ?? '—'} · Requête : {log.request_id ?? '—'} · IP : {log.ip_address ?? '—'}
                    </p>
                  </div>
                </details>
              </Card>
            </li>
          ))}
        </ul>
      )}
      {data && <Pagination page={data.current_page} lastPage={data.last_page} total={data.total} onPage={(page) => setFilters({ ...filters, page })} />}
    </div>
  )
}
