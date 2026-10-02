<?php

namespace App\Http\Middleware;

use App\Services\IntsecClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ReportRequestActivityToIntsec
{
    public function __construct(private IntsecClient $intsec) {}

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);
        $occurredAt = now();

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->report($request, $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500, $startedAt, $occurredAt, null);
            throw $exception;
        }

        $this->report($request, $response->getStatusCode(), $startedAt, $occurredAt, $response);

        return $response;
    }

    private function report(Request $request, int $statusCode, int $startedAt, mixed $occurredAt, ?Response $response): void
    {
        try {
            $this->intsec->sendRequestActivity([
                'request_id' => (string) Str::uuid(),
                'ip' => (string) $request->ip(),
                'method' => strtoupper($request->method()),
                'path' => '/'.ltrim($request->path(), '/'),
                'route_name' => $request->route()?->getName(),
                'status_code' => $statusCode,
                'user_agent' => $request->userAgent(),
                'is_authenticated' => $request->user() !== null,
                'duration_ms' => max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000)),
                'request_size' => $this->nonNegativeInteger($request->server('CONTENT_LENGTH')),
                'response_size' => $response ? $this->responseSize($response) : null,
                'occurred_at' => $occurredAt->toIso8601String(),
                'metadata' => [],
            ]);
        } catch (Throwable) {
            // Monitoring is fail-open and must not interrupt Hotel workflows.
        }
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
    }

    private function responseSize(Response $response): ?int
    {
        $length = $this->nonNegativeInteger($response->headers->get('Content-Length'));
        if ($length !== null) {
            return $length;
        }

        $content = $response->getContent();

        return is_string($content) ? strlen($content) : null;
    }
}
