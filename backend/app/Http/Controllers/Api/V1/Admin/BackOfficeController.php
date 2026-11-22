<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Customers\LoyaltyService;
use App\Domain\Reporting\StatsService;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Domain\Shared\Phone;
use App\Domain\Shared\Settings;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\AuditLog;
use App\Models\ContentBlock;
use App\Models\ContentPage;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/** Pilotage, clients, avis, contenus, utilisateurs, paramètres, journaux, notifications. */
class BackOfficeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly Settings $settings) {}

    // ---- Tableaux de bord --------------------------------------------------------------------
    public function dashboard(Request $request, StatsService $stats): JsonResponse
    {
        $data = ['operations' => $stats->operations()];
        if ($request->user()->can('stats.view')) {
            $tz = $this->settings->timezone();
            $data['last_30_days'] = $stats->summary(CarbonImmutable::now($tz)->subDays(29)->startOfDay(), CarbonImmutable::now($tz)->endOfDay());
            $data['recent_orders'] = OrderResource::collection(Order::query()->where('status', '!=', 'draft')->where('is_test', false)->latest()->limit(8)->get())->resolve();
        }

        return response()->json(['data' => $data]);
    }

    public function stats(Request $request, StatsService $stats): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $tz = $this->settings->timezone();
        $from = CarbonImmutable::parse($data['from'], $tz)->startOfDay();
        $to = CarbonImmutable::parse($data['to'], $tz)->endOfDay();
        abort_if($from->diffInDays($to) > 366, 422, 'Période limitée à un an.');

        return response()->json(['data' => $stats->summary($from, $to)]);
    }

    // ---- Clients -----------------------------------------------------------------------------
    public function customers(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:60']]);
        $customers = Customer::query()
            ->when($request->input('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('first_name', 'ilike', "%{$t}%")->orWhere('last_name', 'ilike', "%{$t}%")->orWhere('phone', 'like', '%'.preg_replace('/\D/', '', $t).'%')))
            ->withCount(['orders as orders_count' => fn ($q) => $q->where('status', '!=', 'draft')])
            ->withSum(['orders as revenue_xof' => fn ($q) => $q->whereIn('payment_status', ['paid', 'partially_refunded'])], 'paid_xof')
            ->latest()->paginate(30);

        return response()->json($customers);
    }

    public function customer(string $id): JsonResponse
    {
        $customer = Customer::query()->with(['addresses.zone:id,name', 'user:id,email'])->findOrFail($id);
        $orders = OrderResource::collection($customer->orders()->latest()->limit(50)->get())->resolve();

        // Club MK : l'équipe voit quand une surprise est due (remise manuelle, pas de remise automatique).
        return response()->json(['data' => $customer->toArray() + ['orders' => $orders, 'loyalty' => app(LoyaltyService::class)->card($customer)]]);
    }

    // ---- Avis --------------------------------------------------------------------------------
    public function reviews(Request $request): JsonResponse
    {
        $reviews = Review::query()->with(['product:id,name', 'order:id,reference'])
            ->where(fn ($r) => $r->whereNull('order_id')->orWhereHas('order', fn ($o) => $o->notTest()))
            ->when($request->input('status'), fn ($q, $s) => $q->where('moderation_status', $s))
            ->latest()->paginate(30);

        return response()->json($reviews);
    }

    public function moderateReview(Request $request, Review $review): JsonResponse
    {
        $data = $request->validate(['moderation_status' => ['required', 'in:approved,rejected']]);
        $review->update($data + ['moderated_by' => $request->user()->id, 'moderated_at' => now()]);
        $this->audit->log('review.moderated', $review, null, $data);

        return response()->json(['data' => $review]);
    }

    // ---- Contenus (CMS-008) ------------------------------------------------------------------
    public function pages(): JsonResponse
    {
        return response()->json(['data' => ContentPage::query()->orderBy('slug')->get()]);
    }

    public function savePage(Request $request, ?string $id = null): JsonResponse
    {
        $page = $id ? ContentPage::query()->findOrFail($id) : new ContentPage;
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('content_pages', 'slug')->ignore($page->id)],
            'title' => ['required', 'string', 'max:200'],
            'excerpt' => ['nullable', 'string', 'max:300'],
            'body' => ['required', 'string', 'max:50000'],
            'status' => ['required', 'in:draft,published'],
            'seo_title' => ['nullable', 'string', 'max:70'],
            'seo_description' => ['nullable', 'string', 'max:170'],
        ]);
        $before = $page->exists ? $page->only(['title', 'status']) : null;
        $page->fill($data + ['updated_by' => $request->user()->id, 'published_at' => $data['status'] === 'published' ? ($page->published_at ?? now()) : null])->save();
        $this->audit->log('content.page_saved', $page, $before, $page->only(['title', 'status']));

        return response()->json(['data' => $page], $before ? 200 : 201);
    }

    public function blocks(): JsonResponse
    {
        return response()->json(['data' => ContentBlock::query()->orderBy('group')->orderBy('position')->get()]);
    }

    public function saveBlock(Request $request, string $key): JsonResponse
    {
        $data = $request->validate([
            'group' => ['required', 'string', 'max:40'],
            'data' => ['required', 'array'],
            'status' => ['required', 'in:draft,published'],
            'position' => ['nullable', 'integer'],
        ]);
        abort_unless(preg_match('/^[a-z0-9_.]{2,80}$/', $key), 422);
        $block = ContentBlock::query()->updateOrCreate(['key' => $key], $data + ['updated_by' => $request->user()->id]);
        $this->audit->log('content.block_saved', $block, null, ['key' => $key, 'status' => $data['status']]);

        return response()->json(['data' => $block]);
    }

    // ---- Utilisateurs et rôles ---------------------------------------------------------------
    public function users(): JsonResponse
    {
        $users = User::query()->with('roles:id,name')->whereDoesntHave('roles', fn ($q) => $q->where('name', User::ROLE_CLIENT))
            ->orderBy('name')->get(['id', 'name', 'email', 'phone', 'is_active', 'last_login_at', 'created_at']);

        return response()->json(['data' => $users, 'meta' => ['roles' => Role::query()->where('name', '!=', User::ROLE_CLIENT)->pluck('name')]]);
    }

    public function saveUser(Request $request, ?string $id = null): JsonResponse
    {
        $user = $id ? User::query()->findOrFail($id) : new User;
        AuthController::lowercaseEmail($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => [$user->exists ? 'nullable' : 'required', AuthController::staffPasswordRule()],
            'is_active' => ['boolean'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in([User::ROLE_ADMIN, User::ROLE_COMMERCIAL, User::ROLE_PREPARATEUR, User::ROLE_RESP_LIVRAISON])],
        ]);
        if ($user->exists && $user->id === $request->user()->id && (! ($data['is_active'] ?? true) || ! in_array(User::ROLE_ADMIN, $data['roles'], true))) {
            abort(422, 'Vous ne pouvez pas retirer vos propres droits administrateur.');
        }
        // Audit lot 3 : cet écran gère l'équipe uniquement. Un compte client ou livreur ne peut pas y être
        // converti en compte d'équipe ni voir son mot de passe changé (livreurs : écran Livreurs).
        if ($user->exists && $user->hasAnyRole([User::ROLE_CLIENT, User::ROLE_LIVREUR])) {
            throw DomainException::unprocessable('not_staff_account', 'Ce compte n\'est pas un compte de l\'équipe : il se gère depuis l\'écran Livreurs ou par le client lui-même.');
        }
        // Audit S-13 : numéro normalisé et unique, comme à l'inscription (connexion par téléphone).
        $phone = null;
        if (filled($data['phone'] ?? null)) {
            $phone = Phone::normalizeSenegal($data['phone']) ?? throw DomainException::unprocessable('invalid_phone', 'Numéro de téléphone sénégalais invalide.');
            AuthController::assertPhoneFree($phone, $user->id);
        }
        $before = $user->exists ? ['roles' => $user->getRoleNames(), 'is_active' => $user->is_active] : null;
        DB::transaction(function () use ($user, $data, $phone) {
            $user->fill(['name' => $data['name'], 'email' => mb_strtolower($data['email']), 'phone' => $phone, 'is_active' => $data['is_active'] ?? true]);
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            $user->save();
            $user->syncRoles($data['roles']);
        });
        $this->audit->log($before ? 'user.updated' : 'user.created', $user, $before, ['roles' => $data['roles'], 'is_active' => $user->is_active, 'password_changed' => ! empty($data['password'])]);

        return response()->json(['data' => $user->load('roles:id,name')], $before ? 200 : 201);
    }

    // ---- Paramètres --------------------------------------------------------------------------
    /** Paramètres structurés modifiés par un écran dédié (validation propre), jamais par la page générique. */
    private const MANAGED_ELSEWHERE = ['delivery.service_area'];

    public function settings(): JsonResponse
    {
        $items = [];
        foreach (config('mkdelices.settings') as $key => [$group, , $public, $validation, $description]) {
            if (in_array($key, self::MANAGED_ELSEWHERE, true)) {
                continue;
            }
            $items[] = ['key' => $key, 'group' => $group, 'value' => $this->settings->get($key), 'is_public' => $public, 'requires_validation' => $validation, 'description' => $description];
        }

        return response()->json(['data' => $items]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['values' => ['required', 'array']]);
        // Tout ou rien (audit du 22/11/2026, W-04) : chaque valeur est d'abord validée, et l'erreur est rattachée
        // au paramètre concerné (« values.<clé> ») ; rien n'est enregistré si une seule est refusée.
        $errors = [];
        foreach ($data['values'] as $key => $value) {
            try {
                $this->validateSetting($key, $value);
            } catch (ValidationException $e) {
                $errors["values.{$key}"] = $e->errors()['value'] ?? [$e->getMessage()];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        DB::transaction(function () use ($data) {
            foreach ($data['values'] as $key => $value) {
                $before = $this->settings->get($key);
                $this->settings->set($key, $value);
                $this->audit->log('setting.updated', null, [$key => $before], [$key => $value]);
            }
        });

        return $this->settings();
    }

    // ---- Journaux et notifications -----------------------------------------------------------
    public function auditLogs(Request $request): JsonResponse
    {
        $logs = AuditLog::query()->with('actor:id,name')
            ->when($request->input('action'), fn ($q, $a) => $q->where('action', 'like', addcslashes($a, '%_\\').'%'))
            ->when($request->input('target_id'), fn ($q, $t) => $q->where('target_id', $t))
            ->latest('created_at')->paginate(50);

        return response()->json($logs);
    }

    public function notifications(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => $user->notifications()->limit(30)->get(['id', 'data', 'read_at', 'created_at']), 'meta' => ['unread' => $user->unreadNotifications()->count()]]);
    }

    public function markNotificationsRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['data' => ['unread' => 0]]);
    }

    private function validateSetting(string $key, mixed $value): mixed
    {
        if (in_array($key, self::MANAGED_ELSEWHERE, true)) {
            throw DomainException::unprocessable('setting_managed_elsewhere', "Ce paramètre se modifie dans Opérations > Zones : {$key}");
        }
        $rules = match ($key) {
            'business.whatsapp_number' => ['nullable', 'regex:/^[1-9]\d{7,14}$/'],
            'business.email' => ['nullable', 'email'],
            'business.instagram_url', 'business.facebook_url', 'business.tiktok_url' => ['nullable', 'url:https'],
            'ordering.slot_duration_minutes' => ['required', 'integer', 'min:15', 'max:480'],
            'ordering.slot_capacity' => ['required', 'integer', 'min:1', 'max:500'],
            'payments.cash_on_delivery_max_xof' => ['nullable', 'integer', 'min:1000', 'max:10000000'],
            'ordering.min_lead_minutes', 'quality.cold_chain_max_minutes' => ['nullable', 'integer', 'min:0', 'max:20160'],
            'ordering.max_days_ahead' => ['required', 'integer', 'min:1', 'max:90'],
            'ordering.cart_ttl_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'ordering.tracking_token_ttl_days' => ['required', 'integer', 'min:1', 'max:365'],
            'ordering.max_qty_per_line' => ['required', 'integer', 'min:1', 'max:500'],
            'ordering.pickup_enabled', 'payments.require_payment_before_acceptance', 'payments.online_enabled', 'loyalty.enabled' => ['required', 'boolean'],
            'loyalty.orders_per_reward' => ['required', 'integer', 'min:2', 'max:50'],
            'loyalty.reward_label' => ['required', 'string', 'min:3', 'max:120'],
            'payments.methods' => ['required', 'array:wave,orange_money,card,cash_on_delivery'],
            'business.hours' => ['required', 'array:mon,tue,wed,thu,fri,sat,sun'],
            'ordering.closed_dates', 'delivery.proof_types', 'delivery.incident_reasons', 'delivery.client_fault_reasons' => ['present', 'array'],
            'delivery.failed_fee_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'delivery.accept_timeout_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'delivery.code_enabled' => ['required', 'boolean'],
            'delivery.client_fault_min_wait_minutes' => ['required', 'integer', 'min:0', 'max:120'],
            'preparation.undo_window_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'invoicing.vat_enabled' => ['required', 'boolean'],
            'invoicing.vat_rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'invoicing.vat_exemption_note' => ['required', 'string', 'min:3', 'max:300'],
            'invoicing.issue_on' => ['required', 'in:delivered,paid'],
            'invoicing.footer' => ['nullable', 'string', 'max:1000'],
            default => ['nullable', 'string', 'max:5000'],
        };
        validator(['value' => $value], ['value' => $rules])->validate();
        if ($key === 'business.hours') {
            foreach ($value as $ranges) {
                foreach ((array) $ranges as $range) {
                    validator(['r' => $range], ['r' => ['array', 'size:2'], 'r.*' => ['date_format:H:i']])->validate();
                }
            }
        }

        return $value;
    }
}
