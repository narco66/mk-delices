import type { FieldValues, Path, UseFormSetError } from 'react-hook-form'
import { toast } from 'sonner'
import { errorMessage } from '@/components/ui/Feedback'
import { ApiError } from '@/lib/api'

/** Reporte les erreurs de validation serveur (422) sur les champs du formulaire, sinon toast. */
export function applyServerErrors<T extends FieldValues>(error: unknown, setError?: UseFormSetError<T>) {
  if (setError && error instanceof ApiError && error.code === 'validation_failed') {
    Object.entries(error.fieldErrors).forEach(([field, messages]) => setError(field as Path<T>, { type: 'server', message: messages[0] }))
    toast.error('Certaines informations sont invalides.')
    return
  }
  toast.error(errorMessage(error))
}

export function toNullableInt(v: unknown): number | null {
  if (v === '' || v === null || v === undefined) return null
  const n = Number(v)
  return Number.isFinite(n) ? Math.trunc(n) : null
}

