import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ArrowUp, ImagePlus, Star, Trash2, Upload, X } from 'lucide-react'
import { useEffect, useId, useRef, useState } from 'react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/Button'
import { Badge, Card } from '@/components/ui/Display'
import { errorMessage, Notice } from '@/components/ui/Feedback'
import { Checkbox, Input } from '@/components/ui/Form'
import { api } from '@/lib/api'
import { cn } from '@/lib/cn'
import type { Product } from '@/types/api'
import { ACCEPTED, checkFile, PHOTO_RULES, type StagedPhoto, uploadPhoto } from './photos'

/** Zone de dépôt + liste des photos en attente (aperçu, texte alternatif, photo d'illustration). */
export function PhotoPicker({ photos, onChange, disabled = false }: { photos: StagedPhoto[]; onChange: (photos: StagedPhoto[]) => void; disabled?: boolean }) {
  const inputId = useId()
  const input = useRef<HTMLInputElement>(null)
  const [dragging, setDragging] = useState(false)
  const [errors, setErrors] = useState<string[]>([])
  const latest = useRef(photos)
  useEffect(() => {
    latest.current = photos
  }, [photos])

  // Aperçus libérés quand le composant disparaît (évite les fuites de mémoire du navigateur).
  useEffect(() => () => latest.current.forEach((p) => URL.revokeObjectURL(p.preview)), [])

  const add = async (files: FileList | File[]) => {
    const list = Array.from(files)
    const checks = await Promise.all(list.map(checkFile))
    setErrors(checks.filter((c): c is string => c !== null))
    const accepted = list
      .filter((_, i) => checks[i] === null)
      .map((file) => ({ key: `${file.name}-${file.size}-${Math.random()}`, file, preview: URL.createObjectURL(file), alt: '', illustrative: false }))
    if (accepted.length) onChange([...latest.current, ...accepted])
  }
  const update = (key: string, patch: Partial<StagedPhoto>) => onChange(photos.map((p) => (p.key === key ? { ...p, ...patch } : p)))
  const remove = (key: string) => {
    const photo = photos.find((p) => p.key === key)
    if (photo) URL.revokeObjectURL(photo.preview)
    onChange(photos.filter((p) => p.key !== key))
  }

  return (
    <div className="space-y-3">
      <label
        htmlFor={inputId}
        onDragOver={(ev) => {
          ev.preventDefault()
          setDragging(true)
        }}
        onDragLeave={() => setDragging(false)}
        onDrop={(ev) => {
          ev.preventDefault()
          setDragging(false)
          if (!disabled && ev.dataTransfer.files.length) void add(ev.dataTransfer.files)
        }}
        className={cn(
          'flex cursor-pointer flex-col items-center justify-center gap-2 rounded-[var(--radius-control)] border-2 border-dashed px-4 py-6 text-center transition',
          dragging ? 'border-raspberry-500 bg-raspberry-50' : 'border-cocoa-200 bg-cream-50 hover:border-cocoa-400',
          disabled && 'pointer-events-none opacity-60',
        )}
      >
        <ImagePlus className="size-7 text-caramel-600" aria-hidden />
        <span className="font-medium">Glissez vos photos ici ou cliquez pour les choisir</span>
        <span className="text-xs text-cocoa-500">{PHOTO_RULES}. Plusieurs photos possibles.</span>
        <input
          ref={input}
          id={inputId}
          type="file"
          multiple
          accept={ACCEPTED.join(',')}
          aria-label="Choisir des photos du produit"
          className="sr-only"
          disabled={disabled}
          onChange={(ev) => {
            if (ev.target.files?.length) void add(ev.target.files)
            ev.target.value = '' // permet de re-choisir le même fichier après l'avoir retiré
          }}
        />
      </label>

      {errors.length > 0 && (
        <Notice tone="error" title="Photo(s) refusée(s)">
          <ul className="list-disc pl-4">
            {errors.map((e) => (
              <li key={e}>{e}</li>
            ))}
          </ul>
        </Notice>
      )}

      {photos.length > 0 && (
        <ul className="grid gap-3 sm:grid-cols-2" aria-label="Photos en attente d'envoi">
          {photos.map((p, i) => (
            <li key={p.key} className="overflow-hidden rounded-[var(--radius-control)] border border-cocoa-100 bg-white">
              <div className="relative">
                <img src={p.preview} alt="" className="aspect-[4/3] w-full object-cover" />
                <span className="absolute top-2 left-2 rounded-full bg-white/90 px-2 py-0.5 text-xs font-medium text-cocoa-700">En attente{i === 0 ? ' · principale' : ''}</span>
                <button type="button" onClick={() => remove(p.key)} className="absolute top-2 right-2 grid size-8 place-items-center rounded-full bg-white/90 text-cocoa-700 hover:bg-white" aria-label={`Retirer ${p.file.name}`}>
                  <X className="size-4" />
                </button>
              </div>
              <div className="space-y-2 p-3">
                <Input aria-label={`Texte alternatif de ${p.file.name}`} value={p.alt} placeholder="Décrivez la photo (ex. tiramisu en coupe individuelle)" onChange={(ev) => update(p.key, { alt: ev.target.value })} />
                <Checkbox label="Photo d'illustration (pas le produit exact)" checked={p.illustrative} onChange={(ev) => update(p.key, { illustrative: ev.target.checked })} />
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

/** Photos d'un produit existant : photos en ligne (principale, ordre, texte alternatif, suppression) + ajout. */
export function ProductPhotos({ product }: { product: Product }) {
  const queryClient = useQueryClient()
  const [staged, setStaged] = useState<StagedPhoto[]>([])
  const images = product.images ?? []
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['admin', 'product', product.id] })

  const upload = useMutation({
    mutationFn: async () => {
      const failures: string[] = []
      for (const [i, photo] of staged.entries()) {
        try {
          await uploadPhoto(product.id, photo, `${product.name} (photo ${images.length + i + 1})`)
        } catch (e) {
          failures.push(`${photo.file.name} : ${errorMessage(e)}`)
        }
      }
      return failures
    },
    onSuccess: (failures) => {
      const sent = staged.length - failures.length
      if (sent > 0) toast.success(`${sent} photo(s) ajoutée(s), converties en WebP et sans métadonnées`)
      failures.forEach((f) => toast.error(f))
      staged.forEach((p) => URL.revokeObjectURL(p.preview))
      setStaged([])
      refresh()
    },
  })
  const updateImage = useMutation({
    mutationFn: ({ imageId, alt, sort_order }: { imageId: string; alt: string; sort_order?: number }) => api.patch(`/admin/products/${product.id}/images/${imageId}`, { alt, sort_order }),
    onError: (e) => toast.error(errorMessage(e)),
  })
  const makeMain = useMutation({
    mutationFn: async (imageId: string) => {
      const ordered = [images.find((i) => i.id === imageId)!, ...images.filter((i) => i.id !== imageId)]
      for (const [index, img] of ordered.entries()) await api.patch(`/admin/products/${product.id}/images/${img.id}`, { alt: img.alt, sort_order: index })
    },
    onSuccess: () => {
      toast.success('Photo principale mise à jour')
      refresh()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  const remove = useMutation({
    mutationFn: (imageId: string) => api.delete(`/admin/products/${product.id}/images/${imageId}`),
    onSuccess: () => {
      toast.success('Photo supprimée')
      refresh()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  return (
    <Card className="space-y-4 p-5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-lg font-semibold">Photos</h2>
        <span className="text-sm text-cocoa-500">{images.length} photo(s) en ligne</span>
      </div>
      {images.length === 0 && <Notice>Aucune photo : la boutique affiche une illustration générique. Ajoutez au moins une photo avant de publier.</Notice>}
      {images.length > 0 && (
        <ul className="grid gap-3 sm:grid-cols-2" aria-label="Photos en ligne">
          {images.map((img, i) => (
            <li key={img.id} className={cn('overflow-hidden rounded-[var(--radius-control)] border bg-white', i === 0 ? 'border-caramel-500' : 'border-cocoa-100')}>
              <div className="relative">
                <img src={img.url} alt={img.alt} className="aspect-[4/3] w-full object-cover" loading="lazy" />
                {i === 0 && (
                  <span className="absolute top-2 left-2 inline-flex items-center gap-1 rounded-full bg-caramel-500 px-2 py-0.5 text-xs font-semibold text-white">
                    <Star className="size-3" aria-hidden /> Principale
                  </span>
                )}
              </div>
              <div className="space-y-2 p-3">
                {img.is_illustrative && <Badge tone="warning">Photo d'illustration</Badge>}
                <Input
                  aria-label="Texte alternatif"
                  defaultValue={img.alt}
                  onBlur={(ev) => {
                    const alt = ev.target.value.trim()
                    if (alt === img.alt) return
                    if (alt.length < 3) return toast.error('Le texte alternatif doit compter au moins 3 caractères.')
                    updateImage.mutate({ imageId: img.id, alt }, { onSuccess: () => toast.success('Texte alternatif mis à jour') })
                  }}
                />
                <div className="flex flex-wrap gap-1">
                  {i > 0 && (
                    <Button type="button" size="sm" variant="ghost" icon={<ArrowUp className="size-4" />} loading={makeMain.isPending && makeMain.variables === img.id} onClick={() => makeMain.mutate(img.id)}>
                      Mettre en premier
                    </Button>
                  )}
                  <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    icon={<Trash2 className="size-4" />}
                    loading={remove.isPending && remove.variables === img.id}
                    onClick={() => window.confirm('Supprimer cette photo ? Elle disparaîtra de la boutique.') && remove.mutate(img.id)}
                  >
                    Supprimer
                  </Button>
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}
      <PhotoPicker photos={staged} onChange={setStaged} disabled={upload.isPending} />
      {staged.length > 0 && (
        <Button type="button" icon={<Upload className="size-4" />} loading={upload.isPending} onClick={() => upload.mutate()}>
          Envoyer {staged.length} photo(s)
        </Button>
      )}
      <p className="text-xs text-cocoa-500">Les photos doivent appartenir à MK Délices ou être sous licence libre ; cochez « Photo d'illustration » si elle ne montre pas le produit exact.</p>
    </Card>
  )
}
