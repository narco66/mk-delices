<?php

namespace App\Domain\Customers;

use App\Domain\Orders\OrderStatus;
use App\Domain\Shared\Settings;
use App\Models\Customer;

/**
 * Club MK (maquette mobile) : carte de fidélité à tampons. Chaque commande livrée compte un tampon ; toutes les
 * N commandes (paramètre), le client gagne la récompense annoncée, remise par l'équipe MK Délices.
 * Calculé à partir des commandes livrées : rien à stocker, rien à resynchroniser.
 */
class LoyaltyService
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @return array{enabled:bool, delivered_orders:int, orders_per_reward:int, stamps:int, remaining:int, rewards_earned:int, reward_label:string}|array{enabled:false}
     */
    public function card(Customer $customer): array
    {
        if (! (bool) $this->settings->get('loyalty.enabled', false)) {
            return ['enabled' => false];
        }
        $perReward = max(2, (int) $this->settings->get('loyalty.orders_per_reward', 6));
        $delivered = $customer->orders()->notTest()->where('status', OrderStatus::Delivered->value)->count();
        $stamps = $delivered % $perReward;

        return [
            'enabled' => true,
            'delivered_orders' => $delivered,
            'orders_per_reward' => $perReward,
            // Tampons de la carte en cours (0 à N-1) et commandes restantes avant la prochaine récompense.
            'stamps' => $stamps,
            'remaining' => $perReward - $stamps,
            'rewards_earned' => intdiv($delivered, $perReward),
            'reward_label' => (string) $this->settings->get('loyalty.reward_label', 'une surprise MK Délices'),
        ];
    }
}
