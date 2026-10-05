<?php

use App\Services\IntsecClient;
use App\Http\Middleware\EnforceIntsecBlockedIps;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
    expect($client->activeBlockedIps())->toBe(['203.0.113.9']);
});

it('refreshes the authoritative blocklist on every request so block and unblock are immediate', function (): void {
    Http::fakeSequence()
        ->push(['data' => []], 200)
        ->push(['data' => [['ip_address' => '203.0.113.9']]], 200)
        ->push(['data' => []], 200);

    $client = app(IntsecClient::class);

    expect($client->activeBlockedIps())->toBe([])
        ->and($client->activeBlockedIps())->toBe(['203.0.113.9'])
        ->and($client->activeBlockedIps())->toBe([]);

    Http::assertSentCount(3);
});

it('sends sanitized request activity to the shared INTSEC endpoint', function (): void {
    Http::fake(['https://intsec.test/api/security/request-activities' => Http::response([], 201)]);

    expect(app(IntsecClient::class)->sendRequestActivity([
        'request_id' => '00000000-0000-4000-8000-000000000010',
        'ip' => '8.8.4.4',
        'method' => 'GET',
        'path' => '/hotel',
        'status_code' => 200,
        'is_authenticated' => false,
        'metadata' => ['password' => 'must-not-leak'],
    ]))->toBeTrue();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://intsec.test/api/security/request-activities'
        && $request['source'] === 'hotel-booking'
        && ! isset($request['metadata']['password']));
});

it('rejects an already authenticated actor before a protected post executes', function (): void {
    $client = Mockery::mock(IntsecClient::class);
    $client->shouldReceive('activeBlockedIps')->once()->andReturn(['203.0.113.9']);
    $middleware = new EnforceIntsecBlockedIps($client);
    $request = Request::create('/reservations', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9']);
    $request->setUserResolver(fn () => (object) ['id' => 10]);
    $executed = false;

    expect(fn () => $middleware->handle($request, function () use (&$executed): Response {
        $executed = true;

        return new Response('processed');
    }))->toThrow(HttpException::class, 'Access denied by central security policy.');

    expect($executed)->toBeFalse();
});

it('allows the next request immediately after the authoritative policy unblocks the ip', function (): void {
    $client = Mockery::mock(IntsecClient::class);
    $client->shouldReceive('activeBlockedIps')->once()->andReturn([]);
    $middleware = new EnforceIntsecBlockedIps($client);
    $request = Request::create('/dashboard', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']);

    $response = $middleware->handle($request, fn (): Response => new Response('allowed'));

    expect($response->getContent())->toBe('allowed');
});
