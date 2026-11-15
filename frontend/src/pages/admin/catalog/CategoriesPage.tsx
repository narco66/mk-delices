import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Pencil, Plus } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { Button } from '@/components/ui/Button'
import { Card, Modal, PageHeader, StatusPill } from '@/components/ui/Display'
import { EmptyState, ErrorState, Skeleton } from '@/components/ui/Feedback'
import { Field, Input, Textarea } from '@/components/ui/Form'
import { api } from '@/lib/api'
import type { Category } from '@/types/api'
import { Toggle } from './shared'
import { applyServerErrors } from './utils'
import { useAdminCategories } from './useAdminCategories'

const schema = z.object({
  name: z.string().trim().min(2, 'Nom requis').max(120),
  slug: z.string().trim().max(140).regex(/^([a-z0-9]+(?:-[a-z0-9]+)*)?$/, 'Minuscules, chiffres et tirets'),
  description: z.string().max(1000),
  active: z.boolean(),
  position: z.string().regex(/^-?\d*$/, 'Nombre entier'),
})
type FormValues = z.infer<typeof schema>

export function CategoriesPage() {
  const { data, isLoading, error, refetch } = useAdminCategories()
  const [editing, setEditing] = useState<Category | 'new' | null>(null)

  return (
    <div>
      <PageHeader
        eyebrow="Catalogue"
        title="Catégories"
        description="Une catégorie inactive masque tous ses produits de la boutique."
        actions={
          <Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>
            Nouvelle catégorie
          </Button>
        }
      />
      {error ? (
        <ErrorState error={error} onRetry={() => refetch()} />
      ) : isLoading ? (
        <Skeleton className="h-40" />
      ) : !data?.length ? (
        <EmptyState title="Aucune catégorie" action={<Button onClick={() => setEditing('new')}>Créer une catégorie</Button>} />
      ) : (
        <ul className="grid gap-3 sm:grid-cols-2">
          {data.map((c) => (
            <li key={c.id}>
              <Card className="flex items-start justify-between gap-3 p-4">
                <div className="min-w-0">
                  <p className="font-semibold">{c.name}</p>
                  <p className="text-xs text-cocoa-500">
                    /{c.slug} · {c.products_count ?? 0} produit(s) · position {c.position ?? 0}
                  </p>
                  {c.description && <p className="mt-1 line-clamp-2 text-sm text-cocoa-700">{c.description}</p>}
                  <div className="mt-2">
                    <StatusPill status={c.active ? 'published' : 'inactive'} label={c.active ? 'Active' : 'Inactive'} />
                  </div>
                </div>
                <Button size="sm" variant="ghost" aria-label={`Modifier ${c.name}`} onClick={() => setEditing(c)}>
                  <Pencil className="size-4" />
                </Button>
              </Card>
            </li>
          ))}
        </ul>
      )}
      <CategoryModal category={editing} onClose={() => setEditing(null)} />
    </div>
  )
}

function CategoryModal({ category, onClose }: { category: Category | 'new' | null; onClose: () => void }) {
  const queryClient = useQueryClient()
  const current = category && category !== 'new' ? category : null
  const { register, handleSubmit, reset, control, setError, formState } = useForm<FormValues>({ resolver: zodResolver(schema) })

  useEffect(() => {
    if (category) reset({ name: current?.name ?? '', slug: current?.slug ?? '', description: current?.description ?? '', active: current?.active ?? true, position: String(current?.position ?? 0) })
  }, [category, current, reset])

  const save = useMutation({
    mutationFn: (v: FormValues) => {
      const body = { ...v, slug: v.slug || null, description: v.description || null, position: Number(v.position || 0) }
      return current ? api.put(`/admin/categories/${current.id}`, body) : api.post('/admin/categories', body)
    },
    onSuccess: () => {
      toast.success('Catégorie enregistrée')
      queryClient.invalidateQueries({ queryKey: ['admin', 'categories'] })
      queryClient.invalidateQueries({ queryKey: ['categories'] })
      onClose()
    },
    onError: (e) => applyServerErrors(e, setError),
  })
  const e = formState.errors

  return (
    <Modal
      open={!!category}
      onOpenChange={(o) => !o && onClose()}
      title={current ? 'Modifier la catégorie' : 'Nouvelle catégorie'}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Annuler
          </Button>
          <Button loading={save.isPending} onClick={handleSubmit((v) => save.mutate(v))}>
            Enregistrer
          </Button>
        </div>
      }
    >
      <form className="space-y-4" onSubmit={handleSubmit((v) => save.mutate(v))} noValidate>
        <Field label="Nom" required error={e.name?.message}>
          {(p) => <Input {...p} {...register('name')} />}
        </Field>
        <Field label="Adresse (slug)" error={e.slug?.message} hint="Générée depuis le nom si vide.">
          {(p) => <Input {...p} {...register('slug')} />}
        </Field>
        <Field label="Description" error={e.description?.message}>
          {(p) => <Textarea {...p} {...register('description')} />}
        </Field>
        <Field label="Position" error={e.position?.message}>
          {(p) => <Input {...p} inputMode="numeric" {...register('position')} />}
        </Field>
        <Controller control={control} name="active" render={({ field }) => <Toggle checked={!!field.value} onChange={field.onChange} label="Active" />} />
      </form>
    </Modal>
  )
}
