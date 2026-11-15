<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Reporting\StatsService;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Settings;
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
use Illuminate\Validation\Rules\Password;
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

        return response()->json(['data' => $customer->toArray() + ['orders' => $orders]]);
    }

    // ---- Avis --------------------------------------------------------------------------------
    public function reviews(Request $request): JsonResponse
    {
        $reviews = Review::query()->with(['product:id,name', 'order:id,reference'])
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
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => [$user->exists ? 'nullable' : 'required', Password::min(12)->letters()->mixedCase()->numbers()],
            'is_active' => ['boolean'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in([User::ROLE_ADMIN, User::ROLE_COMMERCIAL, User::ROLE_PREPARATEUR, User::ROLE_RESP_LIVRAISON])],
        ]);
        if ($user->exists && $user->id === $request->user()->id && (! ($data['is_active'] ?? true) || ! in_array(User::ROLE_ADMIN, $data['roles'], true))) {
            abort(422, 'Vous ne pouvez pas retirer vos propres droits administrateur.');
        }
        $before = $user->exists ? ['roles' => $user->getRoleNames(), 'is_active' => $user->is_active] : null;
        DB::transaction(function () use ($user, $data) {
            $user->fill(['name' => $data['name'], 'email' => mb_strtolower($data['email']), 'phone' => $data['phone'] ?? null, 'is_active' => $data['is_active'] ?? true]);
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            $user->save();
            $user->syncRoles($data['roles']);
        });
        $this->audit->log($before ? 'user.updated' : 'user.created', $user, $before, ['roles' => $data['roles'], 'is_active' => $user->is_active]);

        return response()->json(['data' => $user->load('roles:id,name')], $before ? 200 : 201);
    }

    // ---- Paramètres --------------------------------------------------------------------------
    public function settings(): JsonResponse
    {
        $items = [];
        foreach (config('mkdelices.settings') as $key => [$group, , $public, $validation, $description]) {
            $items[] = ['key' => $key, 'group' => $group, 'value' => $this->settings->get($key), 'is_public' => $public, 'requires_validation' => $validation, 'description' => $description];
        }

        return response()->json(['data' => $items]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['values' => ['required', 'array']]);
        foreach ($data['values'] as $key => $value) {
            $before = $this->settings->get($key);
            $this->settings->set($key, $this->validateSetting($key, $value));
            $this->audit->log('setting.updated', null, [$key => $before], [$key => $value]);
        }

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
        $rules = match ($key) {
            'business.whatsapp_number' => ['nullable', 'regex:/^[1-9]\d{7,14}$/'],
            'business.email' => ['nullable', 'email'],
            'business.instagram_url', 'business.facebook_url', 'business.tiktok_url' => ['nullable', 'url:https'],
            'ordering.slot_duration_minutes' => ['required', 'integer', 'min:15', 'max:480'],
            'ordering.slot_capacity' => ['required', 'integer', 'min:1', 'max:500'],
            'ordering.min_lead_minutes', 'quality.cold_chain_max_minutes' => ['nullable', 'integer', 'min:0', 'max:20160'],
            'ordering.max_days_ahead' => ['required', 'integer', 'min:1', 'max:90'],
            'ordering.cart_ttl_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'ordering.tracking_token_ttl_days' => ['required', 'integer', 'min:1', 'max:365'],
            'ordering.max_qty_per_line' => ['required', 'integer', 'min:1', 'max:500'],
            'ordering.pickup_enabled', 'payments.require_payment_before_acceptance' => ['required', 'boolean'],
            'payments.methods' => ['required', 'array:wave,orange_money,card,cash_on_delivery'],
            'business.hours' => ['required', 'array:mon,tue,wed,thu,fri,sat,sun'],
            'ordering.closed_dates', 'delivery.proof_types', 'delivery.incident_reasons', 'delivery.client_fault_reasons' => ['present', 'array'],
            'delivery.failed_fee_percent' => ['required', 'integer', 'min:0', 'max:100'],
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
