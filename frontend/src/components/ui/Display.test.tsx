import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { QuantityStepper, StatusPill } from './Display'

describe('composants UI', () => {
  it('le sélecteur de quantité respecte les bornes et reste accessible au clavier', async () => {
    const onChange = vi.fn()
    render(<QuantityStepper label="Quantité" value={1} onChange={onChange} min={1} max={2} />)
    expect(screen.getByRole('group', { name: 'Quantité' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Diminuer la quantité' })).toBeDisabled()
    await userEvent.click(screen.getByRole('button', { name: 'Augmenter la quantité' }))
    expect(onChange).toHaveBeenCalledWith(2)
  })

  it("un statut n'est jamais exprimé par la seule couleur", () => {
    render(<StatusPill status="paid" label="Payée" />)
    expect(screen.getByText('Payée')).toBeVisible()
  })
})
