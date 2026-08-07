<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NormalizeApiResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse) {
            return $response;
        }

        $payload = $response->getData(true);
        if (! is_array($payload)) {
            $payload = ['data' => $payload];
        }

        $meta = $payload['meta'] ?? null;
        $data = $payload['data'] ?? null;
        if ($meta === null && is_array($data) && isset($data['current_page'], $data['per_page'], $data['total'])) {
            $meta = [
                'current_page' => (int) $data['current_page'],
                'last_page' => (int) ($data['last_page'] ?? 1),
                'per_page' => (int) $data['per_page'],
                'total' => (int) $data['total'],
            ];
        }

        $successful = $response->isSuccessful();
        $payload = [
            'success' => array_key_exists('success', $payload) ? (bool) $payload['success'] : $successful,
            'message' => $payload['message'] ?? ($successful ? 'Request completed successfully.' : 'Request failed.'),
            'data' => array_key_exists('data', $payload) ? $payload['data'] : null,
            'meta' => $meta,
        ] + collect($payload)->except(['success', 'message', 'data', 'meta'])->all();

        $response->setData($payload);

        return $response;
    }
}
