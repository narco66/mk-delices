import { api } from '@/lib/api'

/** Limites identiques à celles du serveur (ProductImageService et ProductController). */
export const ACCEPTED = ['image/jpeg', 'image/png', 'image/webp']
const MAX_BYTES = 6 * 1024 * 1024
const MIN_SIDE = 400
const MAX_SIDE = 5000
export const PHOTO_RULES = 'JPEG, PNG ou WebP · 6 Mo maximum · entre 400 et 5 000 pixels de côté'

/** Photo choisie, pas encore envoyée au serveur. */
export interface StagedPhoto {
  key: string
  file: File
  preview: string
  alt: string
  illustrative: boolean
}

/** Contrôle côté navigateur, avant tout envoi : message explicite au lieu d'un refus du serveur. */
export async function checkFile(file: File): Promise<string | null> {
  if (!ACCEPTED.includes(file.type)) return `« ${file.name} » : format non accepté (JPEG, PNG ou WebP).`
  if (file.size > MAX_BYTES) return `« ${file.name} » : fichier trop lourd (${(file.size / 1024 / 1024).toFixed(1)} Mo, 6 Mo maximum).`
  if (typeof createImageBitmap === 'function') {
    try {
      const bitmap = await createImageBitmap(file)
      const { width, height } = bitmap
      bitmap.close()
      if (width < MIN_SIDE || height < MIN_SIDE) return `« ${file.name} » : image trop petite (${width} × ${height} px, ${MIN_SIDE} px minimum).`
      if (width > MAX_SIDE || height > MAX_SIDE) return `« ${file.name} » : image trop grande (${width} × ${height} px, ${MAX_SIDE} px maximum).`
    } catch {
      return `« ${file.name} » : image illisible ou endommagée.`
    }
  }
  return null
}

/** Envoi d'une photo (le serveur la convertit en WebP et supprime ses métadonnées). */
export function uploadPhoto(productId: string, photo: StagedPhoto, fallbackAlt: string) {
  const fd = new FormData()
  fd.append('image', photo.file)
  fd.append('alt', photo.alt.trim().length >= 3 ? photo.alt.trim() : fallbackAlt)
  fd.append('is_illustrative', photo.illustrative ? '1' : '0')
  return api.post(`/admin/products/${productId}/images`, fd)
}
