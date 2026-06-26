<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreditAccount;
use App\Models\CreditAccountUser;
use App\Models\CreditOrder;
use App\Models\MenuItem;
use App\Models\Order;
use App\Services\WaiterOrderService;
use Illuminate\Http\Request;
use RuntimeException;

class PublicCreditCardOrderController extends Controller
{
    public function __construct(private WaiterOrderService $waiterOrderService) {}

    public function validateCard(Request $request)
    {
        $data = $request->validate([
            'card_number' => ['required', 'string', 'max:255'],
        ]);

        return response()->json($this->validateCardValue((string) $data['card_number']));
    }

    public function menu(Request $request)
    {
        $query = MenuItem::query()
            ->with('category')
            ->where('is_active', true)
            ->where('is_available', true)
            ->orderBy('type')
            ->orderBy('name');

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('category', fn ($cat) => $cat->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('type') && in_array($request->type, ['food', 'drink'], true)) {
            $query->where('type', $request->type);
        }

        $items = $query->get()->map(fn ($item) => [
            'id' => $item->id,
            'name' => $item->name,
            'type' => $item->type,
            'price' => (float) $item->price,
            'description' => $item->description,
            'category' => optional($item->category)->name,
            'image_url' => $item->image ? url('storage/' . $item->image) : ($item->image_url ?? null),
            'is_available' => (bool) $item->is_available,
        ])->values();

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'card_number' => ['required', 'string', 'max:255'],
            'order_type' => ['nullable', 'in:takeaway'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer', 'exists:menu_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        $validatedCard = $this->validateCardValue((string) $data['card_number']);

        if (!($validatedCard['success'] ?? false)) {
            return response()->json($validatedCard, (int) ($validatedCard['status_code'] ?? 422));
        }

        $account = CreditAccount::lockForUpdate()->findOrFail($validatedCard['data']['credit_account_id']);
        $authorizedUser = !empty($validatedCard['data']['credit_account_user_id'])
            ? CreditAccountUser::find($validatedCard['data']['credit_account_user_id'])
            : null;
        $available = $this->availableLimitForCard($account, $authorizedUser, false);
        $total = $this->calculateTotal($data['items']);

        if ($available <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Credit account available balance is empty. Ask account holder to request additional credit limit.',
                'data' => ['available_limit' => $available],
            ], 422);
        }

        if ($total > $available) {
            return response()->json([
                'success' => false,
                'message' => 'Monthly credit limit exceeded. Remaining limit: ' . number_format($available, 2),
                'data' => ['available_limit' => $available, 'order_total' => $total],
            ], 422);
        }

        try {
            $systemUserId = (int) config('app.guest_user_id', 1);
            $payload = [
                'order_type' => 'takeaway',
                'waiter_id' => $systemUserId,
                'customer_name' => $data['customer_name'] ?? ($validatedCard['data']['authorized_user']['full_name'] ?? $account->name),
                'customer_phone' => $data['customer_phone'] ?? ($validatedCard['data']['authorized_user']['phone'] ?? $account->phone ?? null),
                'payment_type' => 'credit',
                'credit_account_id' => $account->id,
                'credit_account_user_id' => $validatedCard['data']['credit_account_user_id'] ?? null,
                'credit_notes' => 'Public kiosk credit card order',
                'notes' => $data['notes'] ?? null,
                'items' => $data['items'],
                '_source' => 'public_credit_kiosk',
            ];

            $order = $this->waiterOrderService->createOrder($payload, $systemUserId);
            $order->load(['items.menuItem', 'items.kitchenTicket', 'items.barTicket', 'bill', 'creditOrder.account', 'creditOrder.authorizedUser']);

            $ticketNumbers = $order->items->map(function ($item) {
                $ticket = $item->station === 'kitchen' ? $item->kitchenTicket : $item->barTicket;
                return [
                    'item_id' => $item->id,
                    'station' => $item->station,
                    'ticket_id' => $ticket?->id,
                    'ticket_number' => strtoupper($item->station) . '-' . str_pad((string) ($ticket?->id ?? $item->id), 5, '0', STR_PAD_LEFT),
                    'item_name' => optional($item->menuItem)->name,
                ];
            })->values();

            $freshAccount = $account->fresh();
            $freshUser = $authorizedUser?->fresh();
            $remainingAfter = $this->availableLimitForCard($freshAccount, $freshUser, false);

            return response()->json([
                'success' => true,
                'message' => 'Credit card order submitted successfully.',
                'data' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'bill_id' => $order->bill?->id,
                    'bill_number' => $order->bill?->bill_number,
                    'bill_status' => $order->bill?->status,
                    'total' => (float) $order->total,
                    'preparation_estimate_minutes' => $this->estimatePreparationMinutes($order),
                    'tickets' => $ticketNumbers,
                    'account' => [
                        'id' => $freshAccount->id,
                        'name' => $freshAccount->name,
                        'remaining_limit' => $remainingAfter,
                    ],
                    'items' => $order->items->map(fn ($item) => [
                        'name' => optional($item->menuItem)->name,
                        'quantity' => (float) $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'line_total' => (float) $item->line_total,
                        'station' => $item->station,
                        'status' => $item->item_status,
                    ])->values(),
                ],
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    private function validateCardValue(string $cardNumber): array
    {
        $parsed = $this->parseCard($cardNumber);

        if (!$parsed['credit_account_id']) {
            return ['success' => false, 'status_code' => 422, 'message' => 'Invalid card number.'];
        }

        $account = CreditAccount::with(['authorizedUsers'])->find($parsed['credit_account_id']);

        if (!$account) {
            return ['success' => false, 'status_code' => 404, 'message' => 'Credit account card was not found.'];
        }

        $isOrganization = strtolower((string) $account->account_type) === 'organization';
        $authorizedUser = null;

        if (!$account->is_credit_enabled || $account->status !== 'active') {
            return ['success' => false, 'status_code' => 422, 'message' => 'Credit account is blocked or credit is disabled.', 'data' => ['account' => $account, 'available_limit' => 0]];
        }

        if ($isOrganization) {
            if (!$parsed['authorized_user_id']) {
                return ['success' => false, 'status_code' => 422, 'message' => 'Organization card must include an authorized user.', 'data' => ['account' => $account, 'available_limit' => 0]];
            }

            $authorizedUser = CreditAccountUser::where('credit_account_id', $account->id)
                ->where('id', $parsed['authorized_user_id'])
                ->first();

            if (!$authorizedUser) {
                return ['success' => false, 'status_code' => 404, 'message' => 'Authorized user card was not found for this account.'];
            }

            if (!$authorizedUser->is_active) {
                return ['success' => false, 'status_code' => 422, 'message' => 'Authorized user is disabled for this credit account.', 'data' => ['account' => $account, 'authorized_user' => $authorizedUser, 'available_limit' => 0]];
            }
        }

        $available = $this->availableLimitForCard($account, $authorizedUser, false);
        $dailyRemaining = $authorizedUser ? $this->dailyRemainingForUser($authorizedUser) : null;

        if ($available <= 0) {
            return ['success' => false, 'status_code' => 422, 'message' => 'Monthly credit limit is empty. Ask account holder to request additional credit limit.', 'data' => ['account' => $account, 'authorized_user' => $authorizedUser, 'available_limit' => $available, 'daily_remaining' => $dailyRemaining]];
        }

        return [
            'success' => true,
            'message' => 'Credit card validated successfully.',
            'data' => [
                'account' => $account,
                'authorized_user' => $authorizedUser,
                'credit_account_id' => $account->id,
                'credit_account_user_id' => $authorizedUser?->id,
                'available_limit' => $available,
                'daily_remaining' => $dailyRemaining,
                'is_organization' => $isOrganization,
                'is_active' => true,
            ],
        ];
    }

    private function availableLimitForCard(CreditAccount $account, ?CreditAccountUser $authorizedUser = null, bool $enforceDailyLimit = false): float
    {
        $accountAvailable = max(0, (float) $account->credit_limit - (float) $account->current_balance);

        if (!$authorizedUser) {
            return round($accountAvailable, 2);
        }

        $monthUsed = (float) CreditOrder::where('credit_account_user_id', $authorizedUser->id)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->whereNotIn('status', ['cancelled', 'rejected', 'void'])
            ->sum('total_amount');

        $limits = [$accountAvailable];

        if ($authorizedUser->monthly_limit !== null && (float) $authorizedUser->monthly_limit > 0) {
            $limits[] = max(0, (float) $authorizedUser->monthly_limit - $monthUsed);
        }

        if ($enforceDailyLimit && $authorizedUser->daily_limit !== null && (float) $authorizedUser->daily_limit > 0) {
            $limits[] = $this->dailyRemainingForUser($authorizedUser);
        }

        return round(min($limits), 2);
    }

    private function dailyRemainingForUser(CreditAccountUser $authorizedUser): float
    {
        if ($authorizedUser->daily_limit === null || (float) $authorizedUser->daily_limit <= 0) {
            return INF;
        }

        $todayUsed = (float) CreditOrder::where('credit_account_user_id', $authorizedUser->id)
            ->whereDate('created_at', today())
            ->whereNotIn('status', ['cancelled', 'rejected', 'void'])
            ->sum('total_amount');

        return round(max(0, (float) $authorizedUser->daily_limit - $todayUsed), 2);
    }

    private function parseCard(string $value): array
    {
        $text = trim($value);
        $digitsOnly = preg_replace('/\D+/', '', $text);
        $accountId = null;
        $authorizedUserId = null;

        if (preg_match('/credit-account\s*:\s*(\d+)/i', $text, $match)) {
            $accountId = (int) $match[1];
        }

        if (preg_match('/authorized-user\s*:\s*(\d+)/i', $text, $match)) {
            $authorizedUserId = (int) $match[1];
        }

        if (!$accountId && preg_match('/owner\s*:\s*(\d+)/i', $text, $match)) {
            $accountId = (int) $match[1];
        }

        if ($accountId && $authorizedUserId) {
            return ['credit_account_id' => $accountId, 'authorized_user_id' => $authorizedUserId];
        }

        if ($accountId && !$authorizedUserId) {
            return ['credit_account_id' => $accountId, 'authorized_user_id' => null];
        }

        if ($authorizedUserId && !$accountId) {
            $user = CreditAccountUser::find($authorizedUserId);
            if ($user) {
                return ['credit_account_id' => (int) $user->credit_account_id, 'authorized_user_id' => (int) $user->id];
            }
        }

        if (preg_match('/CR-\d{4}-\d{4}-(\d+)/i', $text, $match)) {
            $digitsOnly = $match[1];
        }

        if ($digitsOnly !== '') {
            $id = (int) ltrim($digitsOnly, '0');

            if ($id > 0) {
                $user = CreditAccountUser::find($id);
                if ($user) {
                    return ['credit_account_id' => (int) $user->credit_account_id, 'authorized_user_id' => (int) $user->id];
                }

                $account = CreditAccount::find($id);
                if ($account) {
                    return ['credit_account_id' => (int) $account->id, 'authorized_user_id' => null];
                }
            }
        }

        return ['credit_account_id' => null, 'authorized_user_id' => null];
    }

    private function calculateTotal(array $items): float
    {
        $subtotal = 0;
        foreach ($items as $item) {
            $menuItem = MenuItem::findOrFail($item['menu_item_id']);
            if (!$menuItem->is_active || !$menuItem->is_available) {
                throw new RuntimeException("Item {$menuItem->name} is not available");
            }
            $subtotal += ((float) $menuItem->price) * ((int) $item['quantity']);
        }
        $tax = round($subtotal * 0.10, 2);
        $serviceCharge = round($subtotal * 0.05, 2);
        return round($subtotal + $tax + $serviceCharge, 2);
    }

    private function estimatePreparationMinutes(Order $order): int
    {
        $hasFood = $order->items->contains('station', 'kitchen');
        $hasDrink = $order->items->contains('station', 'bar');
        return ($hasFood ? 15 : 0) + ($hasDrink ? 5 : 0);
    }
}
