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

        unset($event['password'], $event['token'], $event['authorization']);
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

    /** @return array<int, string> */
    public function activeBlockedIps(): array
    {
        return Cache::remember('intsec.active-blocked-ips', max(1, (int) config('intsec.blocklist_cache_seconds')), function (): array {
            $token = (string) config('intsec.api_token');
            if ($token === '') return [];
            try {
                $response = Http::acceptJson()->withToken($token)->connectTimeout(1)->timeout(2)
                    ->get(config('intsec.api_url').'/api/security/blocked-ips');
                if ($response->successful()) return collect($response->json('data', []))->pluck('ip_address')->filter()->values()->all();
                Log::warning('INTSEC blocklist retrieval was rejected.', ['status' => $response->status()]);
            } catch (Throwable $exception) {
                Log::warning('INTSEC blocklist retrieval failed; allowing request.', ['exception' => $exception->getMessage()]);
            }
            return [];
        });
    }
}
