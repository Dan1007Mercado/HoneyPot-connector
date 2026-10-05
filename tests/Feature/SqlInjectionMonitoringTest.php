<?php

use App\Http\Middleware\DetectSqlInjectionAttempts;
use App\Services\IntsecClient;
use App\Services\SqlInjectionDetector;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

it('reports rule metadata without transmitting the attacker supplied value', function (): void {
    $client = Mockery::mock(IntsecClient::class);
    $client->shouldReceive('sendSecurityEvent')->once()->with(Mockery::on(function (array $event): bool {
        $encoded = json_encode($event);

        return $event['event_type'] === 'sql_injection_attempt'
            && $event['metadata']['rule'] === 'SQLI_UNION_SELECT'
            && $event['metadata']['parameter'] === 'search'
            && $event['metadata']['request_id'] === 'request-123'
            && ! str_contains($encoded, 'UNION SELECT');
    }))->andReturnTrue();

    $request = Request::create('/rooms?search=1%20UNION%20SELECT%20password%20FROM%20users', 'GET');
    $request->attributes->set('intsec_request_id', 'request-123');
    $middleware = new DetectSqlInjectionAttempts(app(SqlInjectionDetector::class), $client);

    $response = $middleware->handle($request, fn (): Response => new Response('ok'));

    expect($response->getContent())->toBe('ok');
});

it('never inspects or transmits sensitive parameter values', function (): void {
    $client = Mockery::mock(IntsecClient::class);
    $client->shouldNotReceive('sendSecurityEvent');
    $request = Request::create('/login', 'POST', ['password' => "' OR 1=1 --", 'email' => 'guest@example.com']);

    $response = (new DetectSqlInjectionAttempts(app(SqlInjectionDetector::class), $client))
        ->handle($request, fn (): Response => new Response('ok'));

    expect($response->getContent())->toBe('ok');
});

it('fails open when intsec delivery fails', function (): void {
    $client = Mockery::mock(IntsecClient::class);
    $client->shouldReceive('sendSecurityEvent')->once()->andThrow(new RuntimeException('offline'));
    $request = Request::create('/rooms', 'GET', ['search' => '1; DROP TABLE rooms']);

    $response = (new DetectSqlInjectionAttempts(app(SqlInjectionDetector::class), $client))
        ->handle($request, fn (): Response => new Response('business response'));

    expect($response->getContent())->toBe('business response');
});
