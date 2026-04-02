<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CustomerDashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $hasPhoneColumn = Schema::hasColumn('users', 'phone');
        $hasProfileImageColumn = Schema::hasColumn('users', 'profile_image');

        $userPhone = $hasPhoneColumn ? $user->phone : null;
        $profileImage = $hasProfileImageColumn ? $user->profile_image : null;

        $orders = Order::with([
            'bill',
            'items.menuItem',
            'table',
        ])
            ->where(function ($q) use ($user, $userPhone) {
                $q->where('created_by', $user->id)
                  ->orWhere('customer_name', $user->name);

                if (!empty($userPhone)) {
                    $q->orWhere('customer_phone', $userPhone);
                }
            })
            ->latest()
            ->get();

        $totalOrders = $orders->count();

        $pendingOrders = $orders->whereIn('status', [
            'pending',
            'confirmed',
            'preparing',
            'processing',
            'submitted',
        ])->count();

        $completedOrders = $orders->whereIn('status', [
            'completed',
            'served',
            'delivered',
            'paid',
        ])->count();

        $totalSpent = $orders->sum(function ($order) {
            return (float) optional($order->bill)->paid_amount;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'customer' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $userPhone,
                    'profile_image' => ($hasProfileImageColumn && $profileImage)
                        ? asset('storage/' . $profileImage)
                        : null,
                ],
                'summary' => [
                    'total_orders' => $totalOrders,
                    'pending_orders' => $pendingOrders,
                    'completed_orders' => $completedOrders,
                    'total_spent' => (float) $totalSpent,
                ],
                'orders' => $orders->map(function ($order) {
                    return [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'order_type' => $order->order_type,
                        'status' => $order->status,
                        'customer_name' => $order->customer_name,
                        'customer_phone' => $order->customer_phone,
                        'customer_address' => $order->customer_address,
                        'ordered_at' => $order->ordered_at,
                        'items_count' => $order->items->count(),
                        'table' => $order->table ? [
                            'id' => $order->table->id,
                            'table_number' => $order->table->table_number,
                        ] : null,
                        'bill' => $order->bill ? [
                            'id' => $order->bill->id,
                            'status' => $order->bill->status,
                            'total' => (float) $order->bill->total,
                            'paid_amount' => (float) $order->bill->paid_amount,
                            'balance' => (float) $order->bill->balance,
                        ] : null,
                        'items' => $order->items->map(function ($item) {
                            return [
                                'id' => $item->id,
                                'name' => $item->menuItem->name ?? 'Item',
                                'quantity' => (int) $item->quantity,
                                'unit_price' => (float) $item->unit_price,
                                'line_total' => (float) $item->line_total,
                                'item_status' => $item->item_status,
                                'station' => $item->station,
                            ];
                        })->values(),
                    ];
                })->values(),
            ],
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $hasPhoneColumn = Schema::hasColumn('users', 'phone');
        $hasProfileImageColumn = Schema::hasColumn('users', 'profile_image');

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ];

        if ($hasPhoneColumn) {
            $rules['phone'] = [
                'nullable',
                'string',
                'max:20',
                Rule::unique('users', 'phone')->ignore($user->id),
            ];
        }

        if ($hasProfileImageColumn) {
            $rules['profile_image'] = [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:4096',
            ];
        }

        $validated = $request->validate($rules);

        $oldName = $user->name;
        $oldPhone = $hasPhoneColumn ? $user->phone : null;

        $updateData = [
            'name' => $validated['name'],
            'email' => $validated['email'],
        ];

        if ($hasPhoneColumn) {
            $updateData['phone'] = $validated['phone'] ?? null;
        }

        if ($hasProfileImageColumn && $request->hasFile('profile_image')) {
            if ($user->profile_image && Storage::disk('public')->exists($user->profile_image)) {
                Storage::disk('public')->delete($user->profile_image);
            }

            $updateData['profile_image'] = $request->file('profile_image')->store('users/profile', 'public');
        }

        $user->update($updateData);
        $user->refresh();

        Order::query()
            ->where('created_by', $user->id)
            ->where(function ($q) use ($oldName, $oldPhone) {
                $q->where('customer_name', $oldName);

                if (!empty($oldPhone)) {
                    $q->orWhere('customer_phone', $oldPhone);
                }
            })
            ->update([
                'customer_name' => $user->name,
                'customer_phone' => $hasPhoneColumn ? $user->phone : null,
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'data' => [
                'customer' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $hasPhoneColumn ? $user->phone : null,
                    'profile_image' => ($hasProfileImageColumn && $user->profile_image)
                        ? asset('storage/' . $user->profile_image)
                        : null,
                ],
            ],
        ]);
    }
}