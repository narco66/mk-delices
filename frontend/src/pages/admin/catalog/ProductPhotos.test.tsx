import { cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'
import type { StagedPhoto } from './photos'
import { PhotoPicker } from './ProductPhotos'

/** Ajout de photos dès la création d'un produit : contrôle avant envoi, aperçu, texte alternatif. */
function Harness({ onPhotos }: { onPhotos?: (p: StagedPhoto[]) => void }) {
  const [photos, setPhotos] = useState<StagedPhoto[]>([])
  return (
    <PhotoPicker
      photos={photos}
      onChange={(p) => {
        setPhotos(p)
        onPhotos?.(p)
      }}
    />
  )
}

const file = (name: string, type: string, bytes = 1000) => new File([new Uint8Array(bytes)], name, { type })

describe('Photos du produit (back-office)', () => {
  beforeAll(() => {
    // jsdom ne fournit ni aperçu d'image ni décodage : simulés.
    URL.createObjectURL = vi.fn(() => 'blob:apercu')
    URL.revokeObjectURL = vi.fn()
  })
  afterEach(cleanup)

  it('met en attente plusieurs photos acceptées, avec aperçu et texte alternatif', async () => {
    const onPhotos = vi.fn()
    render(<Harness onPhotos={onPhotos} />)
    await userEvent.upload(screen.getByLabelText('Choisir des photos du produit'), [file('a.jpg', 'image/jpeg'), file('b.webp', 'image/webp')])

    expect(await screen.findAllByText(/En attente/)).toHaveLength(2)
    expect(screen.getByText(/En attente · principale/)).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('Texte alternatif de a.jpg'), 'Tiramisu en coupe')
    expect(onPhotos.mock.lastCall![0][0].alt).toBe('Tiramisu en coupe')

    await userEvent.click(screen.getByRole('button', { name: 'Retirer b.webp' }))
    expect(screen.getAllByText(/En attente/)).toHaveLength(1)
  })

  it('refuse avant tout envoi un format non accepté ou un fichier trop lourd', async () => {
    render(<Harness />)
    const input = screen.getByLabelText('Choisir des photos du produit')
    await userEvent.upload(input, [file('photo.gif', 'image/gif'), file('enorme.jpg', 'image/jpeg', 7 * 1024 * 1024)], { applyAccept: false })

    expect(await screen.findByText('Photo(s) refusée(s)')).toBeInTheDocument()
    expect(screen.getByText(/« photo\.gif » : format non accepté/)).toBeInTheDocument()
    expect(screen.getByText(/« enorme\.jpg » : fichier trop lourd \(7\.0 Mo, 6 Mo maximum\)/)).toBeInTheDocument()
    expect(screen.queryByText(/En attente/)).not.toBeInTheDocument()
  })
})
