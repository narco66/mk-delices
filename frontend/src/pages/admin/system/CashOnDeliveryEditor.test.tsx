import { cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { CashOnDeliveryEditor } from './SettingsPage'

/** D-PAY-04 : activation et plafond du paiement à la livraison réglés ensemble dans les paramètres. */
describe('Paramètres : paiement à la livraison', () => {
  afterEach(cleanup)

  it('désactivé : le plafond ne peut pas être saisi, l’activation est proposée', async () => {
    const onEnabledChange = vi.fn()
    render(<CashOnDeliveryEditor enabled={false} maxXof={null} onEnabledChange={onEnabledChange} onMaxChange={vi.fn()} />)

    expect(screen.getByLabelText(/Montant maximal/)).toBeDisabled()
    expect(screen.getByText('Activez d’abord le paiement à la livraison.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('switch'))
    expect(onEnabledChange).toHaveBeenCalledWith(true)
  })

  it('activé : le plafond se saisit en FCFA, vide signifie sans plafond', async () => {
    const onMaxChange = vi.fn()
    const { rerender } = render(<CashOnDeliveryEditor enabled maxXof={null} onEnabledChange={vi.fn()} onMaxChange={onMaxChange} />)
    const input = screen.getByLabelText(/Montant maximal/)

    expect(input).toBeEnabled()
    await userEvent.type(input, '2')
    expect(onMaxChange).toHaveBeenLastCalledWith(2)

    rerender(<CashOnDeliveryEditor enabled maxXof={25000} onEnabledChange={vi.fn()} onMaxChange={onMaxChange} />)
    expect(screen.getByText(/Soit 25\s000\sFCFA maximum par commande/)).toBeInTheDocument()
    await userEvent.clear(screen.getByLabelText(/Montant maximal/))
    expect(onMaxChange).toHaveBeenLastCalledWith(null)
  })
})
