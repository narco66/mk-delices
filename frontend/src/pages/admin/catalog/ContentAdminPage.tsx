import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { FileText, Pencil, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import Markdown from 'react-markdown'
import { toast } from 'sonner'
import { z } from 'zod'
import { Button } from '@/components/ui/Button'
import { Badge, Card, Modal, PageHeader, StatusPill } from '@/components/ui/Display'
import { EmptyState, ErrorState, errorMessage, Notice, Skeleton } from '@/components/ui/Feedback'
import { Field, Input, Select, Textarea } from '@/components/ui/Form'
import { api } from '@/lib/api'
import { cn } from '@/lib/cn'
import { formatDateTime } from '@/lib/format'
import { CharCount } from './shared'
import { applyServerErrors } from './utils'

interface ContentPage {
  id: string
  slug: string
  title: string
  excerpt: string | null
  body: string
  status: 'draft' | 'published'
  seo_title: string | null
  seo_description: string | null
  requires_validation: boolean
  published_at: string | null
  updated_at: string
}

interface ContentBlock {
  id: string
  key: string
  group: string
  data: Record<string, unknown>
  status: 'draft' | 'published'
  position: number
}

export function ContentAdminPage() {
  const [tab, setTab] = useState<'pages' | 'blocks'>('pages')
  return (
    <div>
      <PageHeader eyebrow="Site" title="Contenus du site" description="Pages et blocs éditoriaux modifiables sans déploiement (brouillon / publication)." />
      <div className="mb-5 flex gap-2" role="tablist">
        {(
          [
            ['pages', 'Pages'],
            ['blocks', "Blocs d'accueil et FAQ"],
          ] as const
        ).map(([key, label]) => (
          <button
            key={key}
            role="tab"
            type="button"
            aria-selected={tab === key}
            onClick={() => setTab(key)}
            className={cn('min-h-11 rounded-full px-4 text-sm font-medium', tab === key ? 'bg-cocoa-700 text-cream-50' : 'border border-cocoa-100 hover:bg-cream-200')}
          >
            {label}
          </button>
        ))}
      </div>
      {tab === 'pages' ? <PagesTab /> : <BlocksTab />}
    </div>
  )
}

// ---------------------------------------------------------------- Pages
const pageSchema = z.object({
  slug: z.string().trim().min(2, 'Slug requis').max(120).regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/, 'Minuscules, chiffres et tirets'),
  title: z.string().trim().min(2, 'Titre requis').max(200),
  excerpt: z.string().max(300),
  body: z.string().min(1, 'Contenu requis').max(50000),
  status: z.enum(['draft', 'published']),
  seo_title: z.string().max(70),
  seo_description: z.string().max(170),
})
type PageValues = z.infer<typeof pageSchema>

function PagesTab() {
  const [editing, setEditing] = useState<ContentPage | 'new' | null>(null)
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['admin', 'content', 'pages'],
    queryFn: async () => (await api.get<{ data: ContentPage[] }>('/admin/content/pages')).data,
  })

  if (error) return <ErrorState error={error} onRetry={() => refetch()} />
  if (isLoading) return <Skeleton className="h-40" />

  return (
    <>
      <div className="mb-4 flex justify-end">
        <Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>
          Nouvelle page
        </Button>
      </div>
      {!data?.length ? (
        <EmptyState icon={<FileText />} title="Aucune page" />
      ) : (
        <ul className="grid gap-3 md:grid-cols-2">
          {data.map((p) => (
            <li key={p.id}>
              <Card className="flex items-start justify-between gap-3 p-4">
                <div className="min-w-0">
                  <p className="font-semibold">{p.title}</p>
                  <p className="text-xs text-cocoa-500">
                    /{p.slug} · modifiée le {formatDateTime(p.updated_at)}
                  </p>
                  <div className="mt-2 flex flex-wrap gap-1.5">
                    <StatusPill status={p.status} label={p.status === 'published' ? 'Publiée' : 'Brouillon'} />
                    {p.requires_validation && <Badge tone="warning">À valider par MK Délices</Badge>}
                  </div>
                </div>
                <Button size="sm" variant="ghost" aria-label={`Modifier ${p.title}`} onClick={() => setEditing(p)}>
                  <Pencil className="size-4" />
                </Button>
              </Card>
            </li>
          ))}
        </ul>
      )}
      {editing && <PageEditor key={editing === 'new' ? 'new' : editing.id} page={editing} onClose={() => setEditing(null)} />}
    </>
  )
}

function PageEditor({ page, onClose }: { page: ContentPage | 'new' | null; onClose: () => void }) {
  const queryClient = useQueryClient()
  const current = page && page !== 'new' ? page : null
  const [preview, setPreview] = useState(false)
  // Monté avec une clé par page : les valeurs initiales suffisent, sans réinitialisation dans un effet.
  const { register, handleSubmit, control, setError, formState } = useForm<PageValues>({
    resolver: zodResolver(pageSchema),
    defaultValues: {
      slug: current?.slug ?? '',
      title: current?.title ?? '',
      excerpt: current?.excerpt ?? '',
      body: current?.body ?? '',
      status: current?.status ?? 'draft',
      seo_title: current?.seo_title ?? '',
      seo_description: current?.seo_description ?? '',
    },
  })
  const [body, seoTitle, seoDescription] = useWatch({ control, name: ['body', 'seo_title', 'seo_description'] })


  const save = useMutation({
    mutationFn: (v: PageValues) => {
      const body = { ...v, excerpt: v.excerpt || null, seo_title: v.seo_title || null, seo_description: v.seo_description || null }
      return current ? api.put(`/admin/content/pages/${current.id}`, body) : api.post('/admin/content/pages', body)
    },
    onSuccess: () => {
      toast.success('Page enregistrée')
      queryClient.invalidateQueries({ queryKey: ['admin', 'content', 'pages'] })
      onClose()
    },
    onError: (e) => applyServerErrors(e, setError),
  })
  const e = formState.errors

  return (
    <Modal
      open={!!page}
      onOpenChange={(o) => !o && onClose()}
      side="right"
      title={current ? `Modifier « ${current.title} »` : 'Nouvelle page'}
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
      <form className="space-y-4" noValidate onSubmit={handleSubmit((v) => save.mutate(v))}>
        {current?.requires_validation && <Notice tone="warning">Contenu juridique ou commercial à faire valider par MK Délices avant mise en production.</Notice>}
        <Field label="Titre" required error={e.title?.message}>
          {(p) => <Input {...p} {...register('title')} />}
        </Field>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Slug" required error={e.slug?.message}>
            {(p) => <Input {...p} {...register('slug')} />}
          </Field>
          <Field label="Statut">
            {(p) => (
              <Select {...p} {...register('status')}>
                <option value="draft">Brouillon</option>
                <option value="published">Publiée</option>
              </Select>
            )}
          </Field>
        </div>
        <Field label="Résumé" error={e.excerpt?.message}>
          {(p) => <Input {...p} {...register('excerpt')} />}
        </Field>
        <div>
          <div className="mb-1.5 flex items-center justify-between">
            <span className="text-sm font-medium">Contenu (Markdown)</span>
            <button type="button" className="text-xs font-medium text-raspberry-600 hover:underline" onClick={() => setPreview((v) => !v)}>
              {preview ? 'Modifier' : 'Aperçu'}
            </button>
          </div>
          {preview ? (
            <div className="prose-mk min-h-48 rounded-[var(--radius-control)] border border-cocoa-100 bg-white p-4">
              <Markdown>{body ?? ''}</Markdown>
            </div>
          ) : (
            <Field label="Contenu" error={e.body?.message} className="[&>label]:sr-only">
              {(p) => <Textarea {...p} rows={14} className="font-mono text-sm" {...register('body')} />}
            </Field>
          )}
        </div>
        <Field label="Titre SEO" error={e.seo_title?.message} hint={<CharCount value={seoTitle} max={70} />}>
          {(p) => <Input {...p} {...register('seo_title')} />}
        </Field>
        <Field label="Description SEO" error={e.seo_description?.message} hint={<CharCount value={seoDescription} max={170} />}>
          {(p) => <Textarea {...p} rows={2} {...register('seo_description')} />}
        </Field>
      </form>
    </Modal>
  )
}

// ---------------------------------------------------------------- Blocs
const BLOCK_LABELS: Record<string, string> = {
  'home.hero': "Bandeau principal de l'accueil",
  'home.arguments': 'Arguments commerciaux',
  'home.steps': 'Étapes de commande',
  'home.testimonials': 'Témoignages',
  'faq.items': 'Questions fréquentes',
}

/** Champs des éléments de liste selon le bloc. */
const LIST_FIELDS: Record<string, { key: string; label: string; multiline?: boolean }[]> = {
  'home.arguments': [
    { key: 'icon', label: 'Icône (hand, snowflake, truck, shield…)' },
    { key: 'title', label: 'Titre' },
    { key: 'text', label: 'Texte', multiline: true },
  ],
  'home.steps': [
    { key: 'title', label: 'Titre' },
    { key: 'text', label: 'Texte', multiline: true },
  ],
  'home.testimonials': [
    { key: 'author', label: 'Auteur' },
    { key: 'text', label: 'Témoignage', multiline: true },
    { key: 'stars', label: 'Note (1-5)' },
  ],
  'faq.items': [
    { key: 'q', label: 'Question' },
    { key: 'a', label: 'Réponse', multiline: true },
  ],
}

const HERO_FIELDS = [
  { key: 'eyebrow', label: 'Sur-titre' },
  { key: 'title', label: 'Titre' },
  { key: 'subtitle', label: 'Sous-titre' },
  { key: 'cta_label', label: 'Libellé du bouton' },
  { key: 'cta_href', label: 'Lien du bouton (ex. /nos-tiramisus)' },
]

function BlocksTab() {
  const [editing, setEditing] = useState<ContentBlock | null>(null)
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['admin', 'content', 'blocks'],
    queryFn: async () => (await api.get<{ data: ContentBlock[] }>('/admin/content/blocks')).data,
  })
  if (error) return <ErrorState error={error} onRetry={() => refetch()} />
  if (isLoading) return <Skeleton className="h-40" />
  if (!data?.length) return <EmptyState title="Aucun bloc éditorial" />

  return (
    <>
      <ul className="grid gap-3 md:grid-cols-2">
        {data.map((b) => (
          <li key={b.id}>
            <Card className="flex items-start justify-between gap-3 p-4">
              <div className="min-w-0">
                <p className="font-semibold">{BLOCK_LABELS[b.key] ?? b.key}</p>
                <p className="font-mono text-xs text-cocoa-500">{b.key}</p>
                <div className="mt-2 flex gap-1.5">
                  <StatusPill status={b.status} label={b.status === 'published' ? 'Publié' : 'Brouillon'} />
                  {Array.isArray(b.data.items) && <Badge>{(b.data.items as unknown[]).length} élément(s)</Badge>}
                </div>
              </div>
              <Button size="sm" variant="ghost" aria-label={`Modifier ${b.key}`} onClick={() => setEditing(b)}>
                <Pencil className="size-4" />
              </Button>
            </Card>
          </li>
        ))}
      </ul>
      {editing && <BlockEditor key={editing.id} block={editing} onClose={() => setEditing(null)} />}
    </>
  )
}

type Item = Record<string, string | number>

function BlockEditor({ block, onClose }: { block: ContentBlock; onClose: () => void }) {
  const queryClient = useQueryClient()
  const structured = block.key === 'home.hero' || block.key in LIST_FIELDS
  const [mode, setMode] = useState<'form' | 'json'>(structured ? 'form' : 'json')
  const [data, setData] = useState<Record<string, unknown>>(block.data)
  const [raw, setRaw] = useState(JSON.stringify(block.data, null, 2))
  const [jsonError, setJsonError] = useState<string | null>(null)
  const [status, setStatus] = useState(block.status)

  const save = useMutation({
    mutationFn: (payload: Record<string, unknown>) => api.put(`/admin/content/blocks/${block.key}`, { group: block.group, data: payload, status, position: block.position }),
    onSuccess: () => {
      toast.success('Bloc enregistré')
      queryClient.invalidateQueries({ queryKey: ['admin', 'content', 'blocks'] })
      onClose()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  const submit = () => {
    if (mode === 'json') {
      let parsed: unknown
      try {
        parsed = JSON.parse(raw)
      } catch {
        // Le message du navigateur (en anglais, technique) n'est pas affiché.
        setJsonError('Texte JSON invalide : vérifiez les guillemets, les virgules et les accolades.')
        return
      }
      if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
        setJsonError('Un objet JSON est attendu (entre accolades { … }).')
        return
      }
      setJsonError(null)
      save.mutate(parsed as Record<string, unknown>)
      return
    }
    save.mutate(data)
  }

  const switchMode = (next: 'form' | 'json') => {
    if (next === 'json') setRaw(JSON.stringify(data, null, 2))
    else {
      try {
        setData(JSON.parse(raw))
        setJsonError(null)
      } catch {
        setJsonError('Corrigez le JSON avant de revenir au formulaire.')
        return
      }
    }
    setMode(next)
  }

  const items = (Array.isArray(data.items) ? data.items : []) as Item[]
  const setItems = (next: Item[]) => setData({ ...data, items: next })
  const fields = LIST_FIELDS[block.key]

  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      side="right"
      title={BLOCK_LABELS[block.key] ?? block.key}
      description={block.key}
      footer={
        <div className="flex flex-wrap items-center justify-between gap-2">
          <Select aria-label="Statut du bloc" className="w-40" value={status} onChange={(e) => setStatus(e.target.value as 'draft' | 'published')}>
            <option value="published">Publié</option>
            <option value="draft">Brouillon</option>
          </Select>
          <div className="flex gap-2">
            <Button variant="secondary" onClick={onClose}>
              Annuler
            </Button>
            <Button loading={save.isPending} onClick={submit}>
              Enregistrer
            </Button>
          </div>
        </div>
      }
    >
      {structured && (
        <div className="mb-4 flex gap-2 text-xs">
          <button type="button" className={cn('rounded-full px-3 py-1.5', mode === 'form' ? 'bg-cocoa-700 text-cream-50' : 'border border-cocoa-100')} onClick={() => switchMode('form')}>
            Formulaire
          </button>
          <button type="button" className={cn('rounded-full px-3 py-1.5', mode === 'json' ? 'bg-cocoa-700 text-cream-50' : 'border border-cocoa-100')} onClick={() => switchMode('json')}>
            JSON
          </button>
        </div>
      )}
      {mode === 'json' ? (
        <Field label="Données JSON" error={jsonError ?? undefined}>
          {(p) => <Textarea {...p} rows={18} className="font-mono text-xs" value={raw} onChange={(e) => setRaw(e.target.value)} />}
        </Field>
      ) : block.key === 'home.hero' ? (
        <div className="space-y-4">
          {HERO_FIELDS.map((f) => (
            <Field key={f.key} label={f.label}>
              {(p) => <Input {...p} value={String(data[f.key] ?? '')} onChange={(e) => setData({ ...data, [f.key]: e.target.value })} />}
            </Field>
          ))}
        </div>
      ) : (
        <div className="space-y-4">
          {block.key === 'home.testimonials' && <Notice tone="warning">N'affichez que des témoignages réels, avec l'accord de leurs auteurs.</Notice>}
          {items.map((item, i) => (
            <fieldset key={i} className="space-y-3 rounded-[var(--radius-control)] border border-cocoa-100 p-3">
              <legend className="px-1 text-sm font-semibold">Élément {i + 1}</legend>
              {fields.map((f) => (
                <Field key={f.key} label={f.label}>
                  {(p) =>
                    f.multiline ? (
                      <Textarea {...p} rows={2} value={String(item[f.key] ?? '')} onChange={(e) => setItems(items.map((it, j) => (j === i ? { ...it, [f.key]: e.target.value } : it)))} />
                    ) : (
                      <Input
                        {...p}
                        value={String(item[f.key] ?? '')}
                        onChange={(e) => setItems(items.map((it, j) => (j === i ? { ...it, [f.key]: f.key === 'stars' ? Number(e.target.value) || 0 : e.target.value } : it)))}
                      />
                    )
                  }
                </Field>
              ))}
              <Button type="button" size="sm" variant="ghost" icon={<Trash2 className="size-4" />} onClick={() => setItems(items.filter((_, j) => j !== i))}>
                Retirer
              </Button>
            </fieldset>
          ))}
          <Button type="button" variant="secondary" size="sm" icon={<Plus className="size-4" />} onClick={() => setItems([...items, Object.fromEntries(fields.map((f) => [f.key, '']))])}>
            Ajouter un élément
          </Button>
        </div>
      )}
    </Modal>
  )
}
