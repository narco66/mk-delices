<?php

namespace App\Domain\Shared;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Paramètres métier éditables (table settings) avec repli sur config/mkdelices.php.
 */
class Settings
{
    private const CACHE_KEY = 'mk:settings:all';

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        if (array_key_exists($key, $all)) {
            return $all[$key];
        }
        $definition = config('mkdelices.settings')[$key] ?? null;

        return $definition !== null ? $definition[1] : $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => Setting::query()->pluck('value', 'key')->all());
    }

    /** @return array<string, mixed> */
    public function public(): array
    {
        $values = [];
        foreach (config('mkdelices.settings') as $key => $definition) {
            if ($definition[2]) {
                $values[$key] = $this->get($key);
            }
        }

        return $values;
    }

    public function set(string $key, mixed $value): void
    {
        $definition = config('mkdelices.settings')[$key] ?? null;
        if ($definition === null) {
            throw DomainException::unprocessable('unknown_setting', "Paramètre inconnu : {$key}");
        }
        Setting::query()->updateOrCreate(['key' => $key], [
            'group' => $definition[0],
            'value' => $value,
            'is_public' => $definition[2],
            'requires_validation' => $definition[3],
            'description' => $definition[4],
        ]);
        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function timezone(): string
    {
        return config('mkdelices.business_timezone');
    }
}
