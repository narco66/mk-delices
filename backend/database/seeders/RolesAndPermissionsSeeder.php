<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Matrice RBAC (SECURITY-RULES §2). Idempotent : peut être rejoué sans perte.
 * L'administrateur obtient toutes les permissions via Gate::before.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'catalog.manage', 'content.manage', 'promotions.manage', 'reviews.moderate',
        'orders.view', 'orders.manage', 'customers.view',
        'preparation.view', 'preparation.manage',
        'deliveries.view', 'deliveries.assign', 'deliveries.settle', 'partners.manage', 'zones.view', 'zones.manage',
        'payments.view', 'payments.manual', 'refunds.request', 'refunds.approve',
        'stats.view', 'users.manage', 'settings.manage', 'audit.view',
        'courier.missions',
    ];

    public const ROLES = [
        User::ROLE_COMMERCIAL => [
            'catalog.manage', 'content.manage', 'promotions.manage', 'reviews.moderate', 'orders.view', 'orders.manage',
            'customers.view', 'preparation.view', 'deliveries.view', 'zones.view', 'payments.view', 'payments.manual',
            'refunds.request', 'stats.view',
        ],
        User::ROLE_PREPARATEUR => ['preparation.view', 'preparation.manage'],
        User::ROLE_RESP_LIVRAISON => ['deliveries.view', 'deliveries.assign', 'deliveries.settle', 'partners.manage', 'zones.view', 'preparation.view'],
        User::ROLE_LIVREUR => ['courier.missions'],
        User::ROLE_CLIENT => [],
        User::ROLE_ADMIN => [],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        foreach (self::ROLES as $role => $permissions) {
            Role::findOrCreate($role, 'web')->syncPermissions($permissions);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
