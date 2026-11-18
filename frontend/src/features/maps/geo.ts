import { api } from '@/lib/api'
import type { GeoPlace, GeoResolution } from '@/types/api'

/** Précision (m) au-delà de laquelle on demande au client de vérifier le repère. */
export const ACCURACY_WARNING_M = 50

export type GeolocateError = 'unsupported' | 'denied' | 'unavailable' | 'timeout'

export const GEOLOCATE_MESSAGES: Record<GeolocateError, string> = {
  unsupported: 'Votre navigateur ne permet pas la localisation. Indiquez votre adresse ci-dessous.',
  denied:
    "Localisation refusée. Vous pouvez l'autoriser dans les réglages du navigateur, ou indiquer votre adresse ci-dessous.",
  unavailable:
    'Position introuvable pour le moment (GPS désactivé ou signal faible). Indiquez votre adresse ci-dessous.',
  timeout: 'La localisation prend trop de temps. Réessayez à l’extérieur ou indiquez votre adresse ci-dessous.',
}

/** Position actuelle, demandée UNIQUEMENT sur action du client (bouton). */
export function geolocate(): Promise<{ lat: number; lng: number; accuracy_m: number }> {
  return new Promise((resolve, reject) => {
    if (typeof navigator === 'undefined' || !('geolocation' in navigator))
      return reject('unsupported' satisfies GeolocateError)
    navigator.geolocation.getCurrentPosition(
      (pos) =>
        resolve({
          lat: round6(pos.coords.latitude),
          lng: round6(pos.coords.longitude),
          accuracy_m: Math.round(pos.coords.accuracy),
        }),
      (err) =>
        reject(
          (err.code === err.PERMISSION_DENIED
            ? 'denied'
            : err.code === err.TIMEOUT
              ? 'timeout'
              : 'unavailable') satisfies GeolocateError,
        ),
      { enableHighAccuracy: true, timeout: 15_000, maximumAge: 60_000 },
    )
  })
}

export function round6(n: number): number {
  return Math.round(n * 1e6) / 1e6
}

/** Zone (calculée par le serveur) et adresse suggérée pour une position. */
export async function resolveLocation(lat: number, lng: number, zoneId?: string | null): Promise<GeoResolution> {
  return (await api.post<{ data: GeoResolution }>('/geo/resolve', { lat, lng, zone_id: zoneId || null })).data
}

export async function searchPlaces(q: string, near?: { lat: number; lng: number } | null): Promise<GeoPlace[]> {
  const params = new URLSearchParams({ q })
  if (near) {
    params.set('lat', String(near.lat))
    params.set('lng', String(near.lng))
  }
  return (await api.get<{ data: GeoPlace[] }>(`/geo/search?${params}`)).data
}

/** Lien d'itinéraire universel (ouvre l'application de navigation du téléphone). */
export function directionsUrl(lat: number, lng: number): string {
  return `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`
}
