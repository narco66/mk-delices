import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Archive, Eye, EyeOff, PackagePlus, RotateCcw, Search } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router'
import { toast } from 'sonner'
import { DessertArt } from '@/components/brand/Brand'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Badge, Card, Modal, PageHeader, StatusPill } from '@/components/ui/Display'
import { EmptyState, ErrorState, errorMessage, Skeleton } from '@/components/ui/Feedback'
import { Input, Select } from '@/components/ui/Form'
import { useAdminCategories } from './useAdminCategories'
import { api, toQuery } from '@/lib/api'
import { formatXof } from '@/lib/format'
import type { Paginated, Product } from '@/types/api'
import { PRODUCT_STATUS_LABELS } from './labels'
import { Pagination } from './shared'


function priceRange(p: Product): string {
  const prices = (p.variants ?? []).filter((v) => !v.archived).map((v) => v.price_xof)
  if (!prices.length) return '—'
  const min = Math.min(...prices)
  const max = Math.max(...prices)
  return min === max ? formatXof(min) : `${formatXof(min)} – ${formatXof(max)}`
}

function stockLabel(p: Product): string {
  const tracked = (p.variants ?? []).filter((v) => !v.archived && v.stock_qty !== null && v.stock_qty !== undefined)
  if (!tracked.length) return 'Sur commande'
  return `${tracked.reduce((s, v) => s + (v.stock_qty ?? 0), 0)} en stock`
}

export function ProductsPage() {
  const queryClient = useQueryClient()
  const { data: categories } = useAdminCategories()
  const [filters, setFilters] = useState({ q: '', status: '', category_id: '', archived: false, page: 1 })
  const [confirmArchive, setConfirmArchive] = useState<Product | null>(null)

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['admin', 'products', filters],
    queryFn: () => api.get<Paginated<Product>>(`/admin/products${toQuery({ ...filters, archived: filters.archived ? 1 : undefined })}`),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['admin', 'products'] })
  const setStatus = useMutation({
    mutationFn: ({ id, status }: { id: string; status: string }) => api.post(`/admin/products/${id}/status`, { status }),
    onSuccess: (_, v) => {
      toast.success(v.status === 'published' ? 'Produit publié' : 'Produit désactivé : il n\'est plus commandable')
      invalidate()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  const archive = useMutation({
    mutationFn: (id: string) => api.delete(`/admin/products/${id}`),
    onSuccess: () => {
      toast.success('Produit archivé')
      setConfirmArchive(null)
      invalidate()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  const restore = useMutation({
    mutationFn: (id: string) => api.post(`/admin/products/${id}/restore`),
    onSuccess: () => {
      toast.success('Produit restauré (désactivé)')
      invalidate()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  const update = (patch: Partial<typeof filters>) => setFilters((f) => ({ ...f, ...patch, page: patch.page ?? 1 }))
  const products = data?.data ?? []

  return (
    <div>
      <PageHeader
        eyebrow="Commerce"
        title="Catalogue"
        description="Créez, publiez, désactivez ou archivez les produits. Un produit non publié n'est jamais commandable."
        actions={
          <>
            <ButtonLink to="/admin/catalogue/categories" variant="secondary">
              Catégories
            </ButtonLink>
            <ButtonLink to="/admin/catalogue/nouveau" icon={<PackagePlus className="size-4" />}>
              Nouveau produit
            </ButtonLink>
          </>
        }
      />

      <Card className="mb-5 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[1fr_180px_200px_auto]">
        <div className="relative">
          <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-cocoa-400" aria-hidden />
          <Input className="pl-9" placeholder="Rechercher un produit" aria-label="Rechercher un produit" value={filters.q} onChange={(e) => update({ q: e.target.value })} />
        </div>
        <Select aria-label="Filtrer par statut" value={filters.status} onChange={(e) => update({ status: e.target.value })} disabled={filters.archived}>
          <option value="">Tous les statuts</option>
          <option value="published">Publiés</option>
          <option value="draft">Brouillons</option>
          <option value="inactive">Désactivés</option>
        </Select>
        <Select aria-label="Filtrer par catégorie" value={filters.category_id} onChange={(e) => update({ category_id: e.target.value })}>
          <option value="">Toutes les catégories</option>
          {categories?.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
            </option>
          ))}
        </Select>
        <label className="flex min-h-11 items-center gap-2 text-sm">
          <input type="checkbox" className="size-5 accent-cocoa-700" checked={filters.archived} onChange={(e) => update({ archived: e.target.checked, status: '' })} />
          Archivés
        </label>
      </Card>

      {error ? (
        <ErrorState error={error} onRetry={() => refetch()} />
      ) : isLoading ? (
        <div className="space-y-3">
          {Array.from({ length: 5 }).map((_, i) => (
            <Skeleton key={i} className="h-20" />
          ))}
        </div>
      ) : products.length === 0 ? (
        <EmptyState icon={<PackagePlus />} title="Aucun produit" description="Aucun produit ne correspond à ces critères." action={<ButtonLink to="/admin/catalogue/nouveau">Créer un produit</ButtonLink>} />
      ) : (
        <ul className="space-y-3">
          {products.map((p) => (
            <li key={p.id}>
              <Card className="flex flex-col gap-3 p-3 sm:flex-row sm:items-center">
                <Link to={`/admin/catalogue/${p.id}`} className="flex min-w-0 flex-1 items-center gap-3">
                  {p.images?.[0] ? (
                    <img src={p.images[0].url} alt="" className="size-16 shrink-0 rounded-[var(--radius-control)] object-cover" loading="lazy" />
                  ) : (
                    <DessertArt seed={p.slug} kind={p.category?.slug} label={false} className="size-16 shrink-0 rounded-[var(--radius-control)]" />
                  )}
                  <div className="min-w-0">
                    <p className="truncate font-semibold">{p.name}</p>
                    <p className="text-xs text-cocoa-500">
                      {p.category?.name ?? '—'} · {(p.variants ?? []).filter((v) => !v.archived).length} format(s) · {stockLabel(p)}
                    </p>
                    <div className="mt-1 flex flex-wrap gap-1.5">
                      <StatusPill status={p.archived ? 'archived' : (p.status ?? 'draft')} label={PRODUCT_STATUS_LABELS[p.archived ? 'archived' : (p.status ?? 'draft')]} />
                      {p.is_signature && <Badge tone="raspberry">Signature</Badge>}
                      {p.is_featured && <Badge tone="caramel">Mis en avant</Badge>}
                      {p.is_demo && <Badge tone="warning">Démo</Badge>}
                    </div>
                  </div>
                </Link>
                <div className="flex items-center justify-between gap-2 sm:justify-end">
                  <span className="text-sm font-semibold tabular-nums">{priceRange(p)}</span>
                  <div className="flex gap-1">
                    {p.archived ? (
                      <Button size="sm" variant="secondary" icon={<RotateCcw className="size-4" />} loading={restore.isPending && restore.variables === p.id} onClick={() => restore.mutate(p.id)}>
                        Restaurer
                      </Button>
                    ) : (
                      <>
                        {p.status === 'published' ? (
                          <Button size="sm" variant="secondary" icon={<EyeOff className="size-4" />} onClick={() => setStatus.mutate({ id: p.id, status: 'inactive' })}>
                            Désactiver
                          </Button>
                        ) : (
                          <Button size="sm" variant="secondary" icon={<Eye className="size-4" />} onClick={() => setStatus.mutate({ id: p.id, status: 'published' })}>
                            Publier
                          </Button>
                        )}
                        <Button size="sm" variant="ghost" aria-label={`Archiver ${p.name}`} onClick={() => setConfirmArchive(p)}>
                          <Archive className="size-4" />
                        </Button>
                      </>
                    )}
                  </div>
                </div>
              </Card>
            </li>
          ))}
        </ul>
      )}
      {data && <Pagination page={data.meta.current_page} lastPage={data.meta.last_page} total={data.meta.total} onPage={(page) => update({ page })} />}

      <Modal
        open={!!confirmArchive}
        onOpenChange={(o) => !o && setConfirmArchive(null)}
        title="Archiver ce produit ?"
        description={confirmArchive?.name}
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setConfirmArchive(null)}>
              Annuler
            </Button>
            <Button variant="danger" loading={archive.isPending} onClick={() => confirmArchive && archive.mutate(confirmArchive.id)}>
              Archiver
            </Button>
          </div>
        }
      >
        <p className="text-sm text-cocoa-700">Le produit disparaît de la boutique et ne peut plus être commandé. L'historique des commandes est conservé ; il pourra être restauré.</p>
      </Modal>
    </div>
  )
}
