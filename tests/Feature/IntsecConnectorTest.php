<?php

use App\Services\IntsecClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'intsec.api_url' => 'https://intsec.test',
        'intsec.api_token' => 'test-token',
        'intsec.source' => 'hotel-booking',
        'intsec.blocklist_cache_seconds' => 60,
        'logging.default' => 'null',
    ]);
    Cache::flush();
});

it('sends authenticated sanitized security events', function (): void {
    Http::fake(['https://intsec.test/api/security/events' => Http::response([], 202)]);

    expect(app(IntsecClient::class)->sendSecurityEvent([
        'event_type' => 'login_failed', 'ip' => '8.8.8.8', 'route' => '/login',
        'method' => 'POST', 'message' => 'Failed login', 'password' => 'must-not-leak',
    ]))->toBeTrue();

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer test-token')
        && $request['source'] === 'hotel-booking' && ! isset($request['password']));
});

it('retains the last known blocklist when central service is unavailable', function (): void {
    Http::fakeSequence()->push(['data' => [['ip_address' => '203.0.113.9']]], 200)->pushStatus(503);
    $client = app(IntsecClient::class);

    expect($client->activeBlockedIps())->toBe(['203.0.113.9']);
    Cache::forget('intsec.active-blocked-ips');
    expect($client->activeBlockedIps())->toBe(['203.0.113.9']);
});
