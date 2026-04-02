<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotificationService
{
    public function notifyUserIds(array $userIds, string $title, string $message, array $data = []): void
    {
        $userIds = collect($userIds)->filter()->unique()->values();
        if ($userIds->isEmpty()) {
            return;
        }

        $payload = $this->buildPayload($title, $message, $data);
        $dedupeKey = $payload['dedupe_key'] ?? null;
        $now = now();

        $rows = $userIds
            ->reject(function ($userId) use ($dedupeKey) {
                return $dedupeKey ? $this->hasRecentUnreadDuplicate((int) $userId, $dedupeKey) : false;
            })
            ->map(function ($userId) use ($payload, $now) {
                return [
                    'id' => (string) Str::uuid(),
                    'type' => 'App\\Notifications\\SystemNotification',
                    'notifiable_type' => User::class,
                    'notifiable_id' => $userId,
                    'data' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'read_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })
            ->values()
            ->all();

        if (! empty($rows)) {
            DB::table('notifications')->insert($rows);
        }
    }

    public function notifyUsersByPermission(string $permission, string $title, string $message, array $data = []): void
    {
        $userIds = User::permission($permission)->pluck('id')->all();
        $this->notifyUserIds($userIds, $title, $message, $data);
    }

    public function notifyUser(?int $userId, string $title, string $message, array $data = []): void
    {
        if (! $userId) {
            return;
        }

        $this->notifyUserIds([$userId], $title, $message, $data);
    }

    private function buildPayload(string $title, string $message, array $data = []): array
    {
        $identity = Arr::only($data, [
            'kind',
            'order_id',
            'payment_id',
            'ticket_id',
            'refund_request_id',
            'bill_id',
            'shift_id',
            'purchase_order_id',
            'inventory_item_id',
            'table_id',
        ]);

        return array_merge([
            'title' => $title,
            'message' => $message,
            'dedupe_key' => sha1(json_encode([
                'title' => $title,
                'message' => $message,
                'identity' => $identity,
            ], JSON_UNESCAPED_UNICODE)),
        ], $data);
    }

    private function hasRecentUnreadDuplicate(int $userId, string $dedupeKey): bool
    {
        return DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $userId)
            ->whereNull('read_at')
            ->where('created_at', '>=', now()->subDay())
            ->where('data', 'like', '%"dedupe_key":"' . addslashes($dedupeKey) . '"%')
            ->exists();
    }
}
