<?php

namespace Database\Seeders;

use App\Domain\Shared\Settings;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/** Recopie les paramètres par défaut SANS écraser les valeurs déjà saisies en back-office. */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('mkdelices.settings') as $key => [$group, $default, $public, $validation, $description]) {
            $setting = Setting::query()->firstOrNew(['key' => $key]);
            if (! $setting->exists) {
                $setting->value = $default;
            }
            $setting->fill(['group' => $group, 'is_public' => $public, 'requires_validation' => $validation, 'description' => $description])->save();
        }
        app(Settings::class)->flush();
    }
}
