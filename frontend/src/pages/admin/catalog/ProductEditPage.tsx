import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Plus, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Controller, useFieldArray, useForm, useWatch } from 'react-hook-form'
import { Link, useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { z } from 'zod'
import { Button } from '@/components/ui/Button'
import { Badge, Card, PageHeader, StatusPill } from '@/components/ui/Display'
import { ErrorState, errorMessage, Notice, Spinner } from '@/components/ui/Feedback'
import { Checkbox, Field, Input, Select, Textarea } from '@/components/ui/Form'
import { api } from '@/lib/api'
import { allergenLabel } from '@/lib/format'
import type { Paginated, Product } from '@/types/api'
import { PRODUCT_STATUS_LABELS } from './labels'
import { type StagedPhoto, uploadPhoto } from './photos'
import { PhotoPicker, ProductPhotos } from './ProductPhotos'
import { CharCount, TagInput, Toggle } from './shared'
import { applyServerErrors, toNullableInt } from './utils'
import { useAdminCategories } from './useAdminCategories'

const ALLERGEN_SUGGESTIONS = ['lait', 'œufs', 'gluten', 'fruits à coque', 'arachides', 'soja', 'sésame']
const intString = (label: string) => z.string().trim().regex(/^\d*$/, `${label} : nombre entier positif attendu`)

const variantSchema = z.object({
  id: z.string().optional(),
  sku: z.string().trim().min(1, 'SKU requis').max(60).regex(/^[A-Z0-9\-_]+$/, 'Majuscules, chiffres, - et _ uniquement'),
  label: z.string().trim().min(1, 'Libellé requis').max(120),
  flavor: z.string().max(80),
  size_label: z.string().max(60),
  serves: intString('Parts'),
  price_xof: z.string().trim().regex(/^\d+$/, 'Prix entier en FCFA requis'),
  compare_at_price_xof: intString('Prix barré'),
  available: z.boolean(),
  prep_minutes: intString('Préparation'),
  stock_qty: intString('Stock'),
  max_per_order: intString('Maximum'),
  archived: z.boolean(),
})

const schema = z.object({
  category_id: z.string().min(1, 'Catégorie requise'),
  name: z.string().trim().min(2, 'Nom requis').max(160),
  slug: z.string().trim().max(160).regex(/^([a-z0-9]+(?:-[a-z0-9]+)*)?$/, 'Minuscules, chiffres et tirets'),
  tagline: z.string().max(200),
  description: z.string().max(5000),
  ingredients: z.string().max(3000),
  allergens: z.array(z.string()),
  storage_instructions: z.string().max(1000),
  shelf_life_hours: intString('Durée de conservation'),
  requires_cold_chain: z.boolean(),
  is_featured: z.boolean(),
  is_new: z.boolean(),
  is_signature: z.boolean(),
  position: intString('Position'),
  seo_title: z.string().max(70, '70 caractères maximum'),
  seo_description: z.string().max(170, '170 caractères maximum'),
  related_ids: z.array(z.string()),
  variants: z.array(variantSchema).min(1, 'Au moins un format est requis'),
})

type FormValues = z.infer<typeof schema>

const emptyVariant = (): FormValues['variants'][number] => ({
  sku: '', label: '', flavor: '', size_label: '', serves: '', price_xof: '', compare_at_price_xof: '', available: true, prep_minutes: '0', stock_qty: '', max_per_order: '', archived: false,
})

const s = (v: number | null | undefined) => (v === null || v === undefined ? '' : String(v))

function toForm(p?: Product): FormValues {
  return {
    category_id: p?.category_id ?? '',
    name: p?.name ?? '',
    slug: p?.slug ?? '',
    tagline: p?.tagline ?? '',
    description: p?.description ?? '',
    ingredients: p?.ingredients ?? '',
    allergens: p?.allergens ?? [],
    storage_instructions: p?.storage_instructions ?? '',
    shelf_life_hours: s(p?.shelf_life_hours),
    requires_cold_chain: p?.requires_cold_chain ?? false,
    is_featured: p?.is_featured ?? false,
    is_new: p?.is_new ?? false,
    is_signature: p?.is_signature ?? false,
    position: s(p?.position ?? 0),
    seo_title: p?.seo_title ?? '',
    seo_description: p?.seo_description ?? '',
    related_ids: p?.related_ids ?? [],
    variants: p?.variants?.length
      ? p.variants.map((v) => ({
          id: v.id, sku: v.sku, label: v.label, flavor: v.flavor ?? '', size_label: v.size_label ?? '', serves: s(v.serves), price_xof: s(v.price_xof),
          compare_at_price_xof: s(v.compare_at_price_xof), available: v.available_flag ?? v.available, prep_minutes: s(v.prep_minutes), stock_qty: s(v.stock_qty),
          max_per_order: s(v.max_per_order), archived: !!v.archived,
        }))
      : [emptyVariant()],
  }
}

function toPayload(v: FormValues) {
  return {
    ...v,
    slug: v.slug || null,
    tagline: v.tagline || null,
    description: v.description || null,
    ingredients: v.ingredients || null,
    storage_instructions: v.storage_instructions || null,
    seo_title: v.seo_title || null,
    seo_description: v.seo_description || null,
    shelf_life_hours: toNullableInt(v.shelf_life_hours),
    position: toNullableInt(v.position) ?? 0,
    variants: v.variants.map((x) => ({
      ...(x.id ? { id: x.id } : {}),
      sku: x.sku.toUpperCase(),
      label: x.label,
      flavor: x.flavor || null,
      size_label: x.size_label || null,
      serves: toNullableInt(x.serves),
      price_xof: Number(x.price_xof),
      compare_at_price_xof: toNullableInt(x.compare_at_price_xof),
      available: x.available,
      prep_minutes: toNullableInt(x.prep_minutes) ?? 0,
      stock_qty: toNullableInt(x.stock_qty),
      max_per_order: toNullableInt(x.max_per_order),
      archived: x.archived,
    })),
  }
}

export function ProductEditPage() {
  const { id } = useParams()
  const isNew = !id
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { data: categories } = useAdminCategories()

  const { data: product, isLoading, error, refetch } = useQuery({
    queryKey: ['admin', 'product', id],
    enabled: !isNew,
    queryFn: async () => (await api.get<{ data: Product }>(`/admin/products/${id}`)).data,
  })
  const { data: allProducts } = useQuery({
    queryKey: ['admin', 'products', 'all-for-related'],
    queryFn: async () => (await api.get<Paginated<Product>>('/admin/products?per_page=100')).data,
  })

  const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: toForm() })
  const { register, control, handleSubmit, reset, formState, setError } = form
  const [seoTitle, seoDescription, allergens, watchedVariants] = useWatch({ control, name: ['seo_title', 'seo_description', 'allergens', 'variants'] })
  const variants = useFieldArray({ control, name: 'variants' })
  useEffect(() => {
    if (product) reset(toForm(product))
  }, [product, reset])

  // Photos choisies avant la création du produit : envoyées juste après sa création.
  const [newPhotos, setNewPhotos] = useState<StagedPhoto[]>([])
  const save = useMutation({
    mutationFn: async (values: FormValues) => {
      if (!isNew) return { res: await api.patch<{ data: Product }>(`/admin/products/${id}`, toPayload(values)), failures: [] as string[] }
      const res = await api.post<{ data: Product }>('/admin/products', toPayload(values))
      const failures: string[] = []
      for (const [i, photo] of newPhotos.entries()) {
        try {
          await uploadPhoto(res.data.id, photo, `${res.data.name} (photo ${i + 1})`)
        } catch (err) {
          failures.push(`${photo.file.name} : ${errorMessage(err)}`)
        }
      }
      return { res, failures }
    },
    onSuccess: ({ res, failures }) => {
      const sent = isNew ? newPhotos.length - failures.length : 0
      toast.success(isNew ? `Produit créé (brouillon)${sent > 0 ? ` avec ${sent} photo(s)` : ''}` : 'Produit enregistré')
      failures.forEach((f) => toast.error(`Photo non envoyée — ${f}`))
      queryClient.invalidateQueries({ queryKey: ['admin', 'products'] })
      if (isNew) {
        queryClient.invalidateQueries({ queryKey: ['admin', 'product', res.data.id] })
        navigate(`/admin/catalogue/${res.data.id}`, { replace: true })
      } else {
        queryClient.setQueryData(['admin', 'product', res.data.id], res.data)
        reset(toForm(res.data))
      }
    },
    onError: (e) => applyServerErrors(e, setError),
  })

  const setStatus = useMutation({
    mutationFn: (status: string) => api.post<{ data: Product }>(`/admin/products/${id}/status`, { status }),
    onSuccess: (res) => {
      toast.success(res.data.status === 'published' ? 'Produit publié sur la boutique' : 'Produit désactivé')
      queryClient.setQueryData(['admin', 'product', id], res.data)
      queryClient.invalidateQueries({ queryKey: ['admin', 'products'] })
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  if (!isNew && isLoading) return <Spinner />
  if (!isNew && error) return <ErrorState error={error} onRetry={() => refetch()} />

  const e = formState.errors

  return (
    <div>
      <Link to="/admin/catalogue" className="mb-3 inline-flex items-center gap-1 text-sm text-cocoa-500 hover:text-cocoa-800">
        <ArrowLeft className="size-4" /> Catalogue
      </Link>
      <PageHeader
        title={isNew ? 'Nouveau produit' : (product?.name ?? 'Produit')}
        description={isNew ? 'Le produit est créé en brouillon : publiez-le ensuite pour le rendre commandable.' : undefined}
        actions={
          product && !product.archived ? (
            <div className="flex flex-wrap items-center gap-2">
              <StatusPill status={product.status ?? 'draft'} label={PRODUCT_STATUS_LABELS[product.status ?? 'draft']} />
              {product.status === 'published' ? (
                <Button variant="secondary" loading={setStatus.isPending} onClick={() => setStatus.mutate('inactive')}>
                  Désactiver
                </Button>
              ) : (
                <Button variant="accent" loading={setStatus.isPending} onClick={() => setStatus.mutate('published')}>
                  Publier
                </Button>
              )}
              {product.status === 'published' && (
                <Link to={`/produits/${product.slug}`} target="_blank" className="text-sm font-medium text-raspberry-600 hover:underline">
                  Voir en boutique
                </Link>
              )}
            </div>
          ) : null
        }
      />

      {product?.is_demo && (
        <Notice tone="warning" className="mb-4" title="Produit de démonstration">
          Données fictives : prix, ingrédients et allergènes doivent être remplacés par les informations officielles de MK Délices.
        </Notice>
      )}

      <form onSubmit={handleSubmit((v) => save.mutate(v))} noValidate className="grid gap-6 lg:grid-cols-[1fr_340px]">
        <div className="space-y-6">
          <Card className="space-y-4 p-5">
            <h2 className="text-lg font-semibold">Informations</h2>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Nom" required error={e.name?.message}>
                {(p) => <Input {...p} {...register('name')} />}
              </Field>
              <Field label="Catégorie" required error={e.category_id?.message}>
                {(p) => (
                  <Select {...p} {...register('category_id')}>
                    <option value="">Choisir…</option>
                    {categories?.map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.name}
                        {c.active === false ? ' (inactive)' : ''}
                      </option>
                    ))}
                  </Select>
                )}
              </Field>
              <Field label="Accroche" error={e.tagline?.message}>
                {(p) => <Input {...p} {...register('tagline')} placeholder="Ex. Le grand classique, café et cacao" />}
              </Field>
              <Field label="Adresse (slug)" error={e.slug?.message} hint="Générée automatiquement si vide.">
                {(p) => <Input {...p} {...register('slug')} placeholder="tiramisu-classique" />}
              </Field>
            </div>
            <Field label="Description" error={e.description?.message}>
              {(p) => <Textarea {...p} rows={4} {...register('description')} />}
            </Field>
          </Card>

          {isNew ? (
            <Card className="space-y-4 p-5">
              <h2 className="text-lg font-semibold">Photos</h2>
              <p className="text-sm text-cocoa-500">Les photos choisies ici sont envoyées dès la création du produit. La première sera la photo principale.</p>
              <PhotoPicker photos={newPhotos} onChange={setNewPhotos} disabled={save.isPending} />
            </Card>
          ) : (
            product && <ProductPhotos product={product} />
          )}

          <Card className="space-y-4 p-5">
            <h2 className="text-lg font-semibold">Composition, allergènes et conservation</h2>
            <Notice>Ingrédients et allergènes sont affichés avant l'ajout au panier ; ils sont obligatoires pour publier (CAT-001).</Notice>
            <Field label="Ingrédients" error={e.ingredients?.message}>
              {(p) => <Textarea {...p} rows={3} {...register('ingredients')} />}
            </Field>
            <Field label="Allergènes" hint="Entrée ou virgule pour ajouter ; suggestions ci-dessous.">
              {(p) => (
                <Controller
                  control={control}
                  name="allergens"
                  render={({ field }) => <TagInput id={p.id} value={field.value} onChange={field.onChange} suggestions={ALLERGEN_SUGGESTIONS} placeholder="Ajouter un allergène" />}
                />
              )}
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Conservation" error={e.storage_instructions?.message}>
                {(p) => <Textarea {...p} rows={2} {...register('storage_instructions')} />}
              </Field>
              <Field label="Durée de conservation (heures)" error={e.shelf_life_hours?.message}>
                {(p) => <Input {...p} inputMode="numeric" {...register('shelf_life_hours')} />}
              </Field>
            </div>
            <Controller control={control} name="requires_cold_chain" render={({ field }) => <Toggle checked={field.value} onChange={field.onChange} label="Chaîne du froid requise" description="Signalé au préparateur et au livreur." />} />
          </Card>

          <Card className="space-y-4 p-5">
            <div className="flex items-center justify-between gap-2">
              <h2 className="text-lg font-semibold">Formats et prix</h2>
              <Button type="button" size="sm" variant="secondary" icon={<Plus className="size-4" />} onClick={() => variants.append(emptyVariant())}>
                Ajouter un format
              </Button>
            </div>
            <Notice tone="warning">Les prix sont en francs CFA entiers. Une modification de prix ne modifie jamais les commandes déjà passées (prix figés).</Notice>
            {e.variants?.message && <p className="text-sm text-error-600">{e.variants.message}</p>}
            {variants.fields.map((f, i) => {
              const ve = e.variants?.[i]
              return (
                <fieldset key={f.id} className="rounded-[var(--radius-control)] border border-cocoa-100 p-4">
                  <legend className="px-1 text-sm font-semibold">
                    Format {i + 1} {watchedVariants?.[i]?.archived && <Badge>Archivé</Badge>}
                  </legend>
                  <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <Field label="SKU" required error={ve?.sku?.message}>
                      {(p) => <Input {...p} {...register(`variants.${i}.sku`, { setValueAs: (v: string) => v.toUpperCase() })} className="uppercase" />}
                    </Field>
                    <Field label="Libellé" required error={ve?.label?.message}>
                      {(p) => <Input {...p} {...register(`variants.${i}.label`)} placeholder="Coupe individuelle" />}
                    </Field>
                    <Field label="Prix (FCFA)" required error={ve?.price_xof?.message}>
                      {(p) => <Input {...p} inputMode="numeric" {...register(`variants.${i}.price_xof`)} />}
                    </Field>
                    <Field label="Parfum" error={ve?.flavor?.message}>
                      {(p) => <Input {...p} {...register(`variants.${i}.flavor`)} />}
                    </Field>
                    <Field label="Taille" error={ve?.size_label?.message}>
                      {(p) => <Input {...p} {...register(`variants.${i}.size_label`)} placeholder="4 parts" />}
                    </Field>
                    <Field label="Nombre de parts" error={ve?.serves?.message}>
                      {(p) => <Input {...p} inputMode="numeric" {...register(`variants.${i}.serves`)} />}
                    </Field>
                    <Field label="Prix barré" error={ve?.compare_at_price_xof?.message}>
                      {(p) => <Input {...p} inputMode="numeric" {...register(`variants.${i}.compare_at_price_xof`)} />}
                    </Field>
                    <Field label="Préparation (min)" error={ve?.prep_minutes?.message}>
                      {(p) => <Input {...p} inputMode="numeric" {...register(`variants.${i}.prep_minutes`)} />}
                    </Field>
                    <Field label="Stock" error={ve?.stock_qty?.message} hint="Vide = préparé sur commande">
                      {(p) => <Input {...p} inputMode="numeric" {...register(`variants.${i}.stock_qty`)} />}
                    </Field>
                    <Field label="Maximum par commande" error={ve?.max_per_order?.message}>
                      {(p) => <Input {...p} inputMode="numeric" {...register(`variants.${i}.max_per_order`)} />}
                    </Field>
                  </div>
                  <div className="mt-3 flex flex-wrap items-center gap-x-6">
                    <Checkbox label="Disponible à la vente" {...register(`variants.${i}.available`)} />
                    <Checkbox label="Archiver ce format" {...register(`variants.${i}.archived`)} />
                    {!f.id && variants.fields.length > 1 && (
                      <Button type="button" size="sm" variant="ghost" icon={<Trash2 className="size-4" />} onClick={() => variants.remove(i)}>
                        Retirer
                      </Button>
                    )}
                  </div>
                </fieldset>
              )
            })}
          </Card>

        </div>

        <div className="space-y-6">
          <Card className="space-y-2 p-5">
            <h2 className="mb-2 text-lg font-semibold">Mise en avant</h2>
            <Controller control={control} name="is_signature" render={({ field }) => <Toggle checked={field.value} onChange={field.onChange} label="Signature" description="Mis en avant sur « Nos tiramisus »." />} />
            <Controller control={control} name="is_featured" render={({ field }) => <Toggle checked={field.value} onChange={field.onChange} label="Produit populaire" />} />
            <Controller control={control} name="is_new" render={({ field }) => <Toggle checked={field.value} onChange={field.onChange} label="Nouveauté" />} />
            <Field label="Position d'affichage" error={e.position?.message}>
              {(p) => <Input {...p} inputMode="numeric" {...register('position')} />}
            </Field>
          </Card>

          <Card className="space-y-4 p-5">
            <h2 className="text-lg font-semibold">Référencement</h2>
            <Field label="Titre SEO" error={e.seo_title?.message} hint={<CharCount value={seoTitle} max={70} />}>
              {(p) => <Input {...p} {...register('seo_title')} />}
            </Field>
            <Field label="Description SEO" error={e.seo_description?.message} hint={<CharCount value={seoDescription} max={170} />}>
              {(p) => <Textarea {...p} rows={3} {...register('seo_description')} />}
            </Field>
          </Card>

          <Card className="space-y-3 p-5">
            <h2 className="text-lg font-semibold">Produits associés</h2>
            <Controller
              control={control}
              name="related_ids"
              render={({ field }) => {
                const options = (allProducts ?? []).filter((p) => p.id !== id)
                return options.length === 0 ? (
                  <p className="text-sm text-cocoa-500">Aucun autre produit.</p>
                ) : (
                  <ul className="max-h-60 space-y-1 overflow-y-auto">
                    {options.map((p) => (
                      <li key={p.id}>
                        <Checkbox
                          label={p.name}
                          checked={field.value.includes(p.id)}
                          onChange={(ev) => field.onChange(ev.target.checked ? [...field.value, p.id] : field.value.filter((x) => x !== p.id))}
                        />
                      </li>
                    ))}
                  </ul>
                )
              }}
            />
          </Card>

          <div className="sticky bottom-4 space-y-2">
            <Button type="submit" size="lg" className="w-full" loading={save.isPending} disabled={!isNew && !formState.isDirty}>
              {isNew ? (newPhotos.length ? `Créer le produit et envoyer ${newPhotos.length} photo(s)` : 'Créer le produit') : 'Enregistrer'}
            </Button>
            {allergens.length > 0 && <p className="text-center text-xs text-cocoa-500">Allergènes : {allergens.map(allergenLabel).join(', ')}</p>}
          </div>
        </div>
      </form>
    </div>
  )
}
