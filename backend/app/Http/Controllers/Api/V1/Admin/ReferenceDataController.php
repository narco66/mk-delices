<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Domain\Shared\Phone;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\DeliveryPartner;
use App\Models\DeliveryZone;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Référentiels administrables : catégories, zones, livreurs partenaires, promotions. */
class ReferenceDataController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    // ---- Catégories -------------------------------------------------------------------------
    public function categories(): JsonResponse
    {
        return response()->json(['data' => Category::query()->withCount('products')->orderBy('position')->get()]);
    }

    public function saveCategory(Request $request, ?string $id = null): JsonResponse
    {
        $category = $id ? Category::query()->findOrFail($id) : new Category;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:140', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('categories', 'slug')->ignore($category->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'active' => ['boolean'],
            'position' => ['nullable', 'integer'],
        ]);
        $before = $category->exists ? $category->toArray() : null;
        $category->fill($data + ['slug' => $data['slug'] ?? $category->slug ?? Str::slug($data['name'])])->save();
        $this->audit->log($before ? 'category.updated' : 'category.created', $category, $before, $category->toArray());

        return response()->json(['data' => $category], $before ? 200 : 201);
    }

    // ---- Zones de livraison -----------------------------------------------------------------
    public function zones(): JsonResponse
    {
        return response()->json(['data' => DeliveryZone::query()->withCount('partners')->orderBy('position')->orderBy('name')->get()]);
    }

    public function saveZone(Request $request, ?string $id = null): JsonResponse
    {
        $zone = $id ? DeliveryZone::query()->findOrFail($id) : new DeliveryZone;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'districts' => ['nullable', 'string', 'max:2000'],
            'active' => ['boolean'],
            'delivery_fee_xof' => ['required', 'integer', 'min:0', 'max:1000000'],
            'min_order_xof' => ['nullable', 'integer', 'min:0'],
            'partner_fee_xof' => ['required', 'integer', 'min:0', 'max:1000000'],
            'extra_lead_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'position' => ['nullable', 'integer'],
        ]);
        $before = $zone->exists ? $zone->toArray() : null;
        $zone->fill($data + ['slug' => $zone->slug ?? Str::slug($data['name']).'-'.Str::lower(Str::random(4))])->save();
        // Les frais des commandes déjà confirmées restent figés (snapshot) — APP-FLOW §3.
        $this->audit->log($before ? 'zone.updated' : 'zone.created', $zone, $before, $zone->toArray());

        return response()->json(['data' => $zone], $before ? 200 : 201);
    }

    // ---- Livreurs partenaires ---------------------------------------------------------------
    public function partners(): JsonResponse
    {
        $partners = DeliveryPartner::query()->with(['zones:id,name', 'user:id,email,is_active,last_login_at'])->orderBy('display_name')->get();

        return response()->json(['data' => $partners]);
    }

    public function savePartner(Request $request, ?string $id = null): JsonResponse
    {
        $partner = $id ? DeliveryPartner::query()->with('user')->findOrFail($id) : null;
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')->ignore($partner?->user_id)],
            'password' => [$partner ? 'nullable' : 'required', Password::min(10)->letters()->numbers()],
            'transport_mode' => ['required', 'in:moto,voiture,velo,a_pied'],
            'active' => ['boolean'],
            'available' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'zone_ids' => ['required', 'array', 'min:1'],
            'zone_ids.*' => ['uuid', 'exists:delivery_zones,id'],
        ]);
        $phone = Phone::normalizeSenegal($data['phone']) ?? throw DomainException::unprocessable('invalid_phone', 'Numéro de téléphone sénégalais invalide.');

        $partner = DB::transaction(function () use ($partner, $data, $phone) {
            $user = $partner?->user ?? new User;
            $user->fill(['name' => $data['display_name'], 'email' => mb_strtolower($data['email']), 'phone' => $phone, 'is_active' => $data['active'] ?? true]);
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            $user->save();
            $user->syncRoles([User::ROLE_LIVREUR]);
            $partner ??= new DeliveryPartner(['user_id' => $user->id]);
            $partner->fill([
                'display_name' => $data['display_name'], 'phone' => $phone, 'transport_mode' => $data['transport_mode'],
                'active' => $data['active'] ?? true, 'available' => $data['available'] ?? true, 'notes' => $data['notes'] ?? null,
            ])->save();
            $partner->zones()->sync($data['zone_ids']);

            return $partner;
        });
        $this->audit->log($id ? 'partner.updated' : 'partner.created', $partner, null, ['display_name' => $partner->display_name, 'zones' => $data['zone_ids'], 'active' => $partner->active, 'password_changed' => ! empty($data['password'])]);

        return response()->json(['data' => $partner->load('zones:id,name', 'user:id,email,is_active')], $id ? 200 : 201);
    }

    // ---- Promotions --------------------------------------------------------------------------
    public function promotions(): JsonResponse
    {
        return response()->json(['data' => Promotion::query()->latest()->get()]);
    }

    public function savePromotion(Request $request, ?string $id = null): JsonResponse
    {
        $promotion = $id ? Promotion::query()->findOrFail($id) : new Promotion;
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9\-_]+$/', Rule::unique('promotions', 'code')->ignore($promotion->id)],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'discount_type' => ['required', 'in:percent,fixed'],
            'discount_value' => ['required', 'integer', 'min:1', $request->input('discount_type') === 'percent' ? 'max:100' : 'max:10000000'],
            'min_subtotal_xof' => ['nullable', 'integer', 'min:0'],
            'max_discount_xof' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'active' => ['boolean'],
            'is_public' => ['boolean'],
        ]);
        $before = $promotion->exists ? $promotion->toArray() : null;
        $promotion->fill($data)->save();
        $this->audit->log($before ? 'promotion.updated' : 'promotion.created', $promotion, $before, $promotion->toArray());

        return response()->json(['data' => $promotion], $before ? 200 : 201);
    }
}
