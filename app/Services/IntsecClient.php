<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class IntsecClient
{
    public function sendSecurityEvent(array $event): bool
    {
        $token = (string) config('intsec.api_token');
        if ($token === '') {
            Log::warning('INTSEC event was not sent because the API token is not configured.');
            return false;
        }

        $event = $this->sanitize($event);
        $event = array_merge([
            'event_id' => (string) Str::uuid(), 'source' => config('intsec.source'), 'severity' => 'normal', 'metadata' => [],
        ], $event);

        try {
            $response = Http::acceptJson()->asJson()->withToken($token)->connectTimeout(1)->timeout(2)
                ->post(config('intsec.api_url').'/api/security/events', $event);
            if ($response->successful()) return true;
            Log::warning('INTSEC event delivery was rejected.', ['status' => $response->status(), 'event_type' => $event['event_type'] ?? null]);
        } catch (Throwable $exception) {
            Log::warning('INTSEC event delivery failed; the Hotel application will continue.', ['exception' => $exception->getMessage(), 'event_type' => $event['event_type'] ?? null]);
        }
        return false;
    }

    public function sendRequestActivity(array $activity): bool
    {
        $token = (string) config('intsec.api_token');
        if ($token === '') {
            return false;
        }

        $activity = $this->sanitize(array_merge([
            'source' => config('intsec.source'),
            'metadata' => [],
        ], $activity));

        try {
            $response = Http::acceptJson()->asJson()->withToken($token)->connectTimeout(1)->timeout(2)
                ->post(config('intsec.api_url').'/api/security/request-activities', $activity);

            if ($response->successful()) {
                return true;
            }

            Log::warning('INTSEC request telemetry was rejected.', ['status' => $response->status()]);
        } catch (Throwable $exception) {
            Log::warning('INTSEC request telemetry delivery failed; the Hotel application will continue.', [
                'exception' => $exception::class,
            ]);
        }

        return false;
    }

    private function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (preg_match('/(?:password|passwd|passphrase|secret|token|authorization|cookie|csrf|session|api[_-]?key|credential)/i', (string) $key)) {
                unset($data[$key]);
                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            }
        }

        return $data;
    }

    /** @return array<int, string> */
    public function activeBlockedIps(): array
    {
        $staleKey = 'intsec.last-known-blocked-ips';
        $blockedIps = null;
        $token = (string) config('intsec.api_token');
        if ($token === '') {
            return (array) Cache::get($staleKey, []);
        }

        try {
            // Security policy is checked authoritatively on every Hotel request.
            // The last-known policy is used only when INTSEC is unavailable.
            $response = Http::acceptJson()->withToken($token)->withHeaders(['Cache-Control' => 'no-cache'])
                ->connectTimeout(1)->timeout(2)
                ->get(config('intsec.api_url').'/api/security/blocked-ips');
            if ($response->successful()) {
                $blockedIps = collect($response->json('data', []))->pluck('ip_address')->filter()->values()->all();
            } else {
                Log::warning('INTSEC blocklist retrieval was rejected.', ['status' => $response->status()]);
            }
        } catch (Throwable $exception) {
            Log::warning('INTSEC blocklist retrieval failed; retaining the last known policy.', ['exception' => $exception->getMessage()]);
        }

        if ($blockedIps === null) {
            return (array) Cache::get($staleKey, []);
        }

        Cache::forever($staleKey, $blockedIps);

        return $blockedIps;
    }
}
