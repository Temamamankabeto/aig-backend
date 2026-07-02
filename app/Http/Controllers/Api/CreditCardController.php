<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreditAccount;
use App\Models\CreditAccountUser;
use App\Models\CreditAgreement;
use Illuminate\Http\Request;

class CreditCardController extends Controller
{
    public function scan(Request $request)
    {
        return $this->validate($request);
    }

    public function validate(Request $request)
    {
        abort_unless($request->user()?->can('credit.accounts.read') || $request->user()?->can('credit.orders.create'), 403, 'You are not authorized to validate credit cards.');

        $data = $request->validate([
            'card_number' => ['required', 'string', 'max:255'],
        ]);

        $parsed = $this->parseCard((string) $data['card_number']);

        if (!$parsed['credit_account_id']) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid card number. Scan or enter a valid credit smart card.',
            ], 422);
        }

        $account = CreditAccount::with(['authorizedUsers', 'activeAgreements'])->find($parsed['credit_account_id']);

        if (!$account) {
            return response()->json([
                'success' => false,
                'message' => 'Credit account card was not found.',
            ], 404);
        }

        $activeAgreement = CreditAgreement::where('credit_account_id', $account->id)
            ->where('status', 'active')
            ->whereDate('start_date', '<=', now()->toDateString())
            ->whereDate('end_date', '>=', now()->toDateString())
            ->orderByDesc('end_date')
            ->first();
        $accountActive = (bool) $account->is_credit_enabled && $account->status === 'active';
        $isOrganization = strtolower((string) $account->account_type) === 'bulky';
        $authorizedUser = null;

        if ($isOrganization) {
            if (!$parsed['authorized_user_id']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bulky account card must include an authorized user scan value.',
                    'data' => [
                        'account' => $account,
                        'active_agreement' => $activeAgreement,
                        'requires_authorized_user' => true,
                    ],
                ], 422);
            }

            $authorizedUser = CreditAccountUser::where('credit_account_id', $account->id)
                ->where('id', $parsed['authorized_user_id'])
                ->first();

            if (!$authorizedUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authorized user card was not found for this credit account.',
                ], 404);
            }

            if (!$authorizedUser->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authorized user is disabled for this credit account.',
                    'data' => [
                        'account' => $account,
                        'authorized_user' => $authorizedUser,
                        'active_agreement' => $activeAgreement,
                    ],
                ], 422);
            }
        }

        if (!$accountActive) {
            return response()->json([
                'success' => false,
                'message' => 'Credit account is blocked or credit is disabled.',
                'data' => [
                    'account' => $account,
                    'authorized_user' => $authorizedUser,
                    'active_agreement' => $activeAgreement,
                ],
            ], 422);
        }

        if (!$activeAgreement) {
            return response()->json([
                'success' => false,
                'message' => 'No active agreement is available for this credit account. Credit order is not allowed.',
                'data' => [
                    'account' => $account,
                    'authorized_user' => $authorizedUser,
                    'active_agreement' => null,
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Credit card validated successfully.',
            'data' => [
                'account' => $account,
                'authorized_user' => $authorizedUser,
                'credit_account_id' => $account->id,
                'credit_account_user_id' => $authorizedUser?->id,
                'active_agreement' => $activeAgreement,
                'is_organization' => $isOrganization,
                'is_active' => true,
            ],
        ]);
    }

    private function parseCard(string $value): array
    {
        $text = trim($value);
        $accountId = null;
        $authorizedUserId = null;

        $json = json_decode($text, true);
        if (is_array($json)) {
            $accountId = $json['credit_account_id']
                ?? $json['account_id']
                ?? $json['accountId']
                ?? null;

            $authorizedUserId = $json['credit_account_user_id']
                ?? $json['authorized_user_id']
                ?? $json['user_id']
                ?? $json['userId']
                ?? null;
        }

        if (!$accountId && preg_match('/credit-account\s*:\s*([^;\s]+)/i', $text, $match)) {
            $accountId = $match[1];
        }

        if (!$authorizedUserId && preg_match('/authorized-user\s*:\s*([^;\s]+)/i', $text, $match)) {
            $authorizedUserId = $match[1];
        }

        if (!$accountId && preg_match('/account_id\s*[:=]\s*([^;\s]+)/i', $text, $match)) {
            $accountId = $match[1];
        }

        if (!$authorizedUserId && preg_match('/authorized_user_id\s*[:=]\s*([^;\s]+)/i', $text, $match)) {
            $authorizedUserId = $match[1];
        }

        if (!$accountId && preg_match('/owner\s*:\s*([^;\s]+)/i', $text, $match)) {
            $accountId = $match[1];
        }

        if (!$accountId && str_starts_with(strtoupper($text), 'CR-')) {
            $digits = preg_replace('/\D+/', '', $text);
            $accountId = $digits ? (int) ltrim($digits, '0') : null;
        }

        return [
            'credit_account_id' => $accountId ? (int) $accountId : null,
            'authorized_user_id' => $authorizedUserId ? (int) $authorizedUserId : null,
        ];
    }
}
