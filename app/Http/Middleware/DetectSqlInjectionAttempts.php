<?php

namespace App\Http\Middleware;

use App\Services\IntsecClient;
use App\Services\SqlInjectionDetector;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class DetectSqlInjectionAttempts
{
    public function __construct(private SqlInjectionDetector $detector, private IntsecClient $intsec) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $match = $this->firstMatch(array_merge($request->query->all(), $request->request->all(), $request->route()?->parameters() ?? []));
            if ($match !== null) {
                $this->intsec->sendSecurityEvent([
                    'event_type' => 'sql_injection_attempt',
                    'severity' => $match['detection']['severity'],
                    'ip' => (string) $request->ip(),
                    'route' => '/'.ltrim($request->path(), '/'),
                    'method' => strtoupper($request->method()),
                    'user_agent' => $request->userAgent(),
                    'message' => 'A request matched a contextual SQL injection detection rule.',
                    'occurred_at' => now()->toIso8601String(),
                    'metadata' => [
                        'request_id' => $request->attributes->get('intsec_request_id'),
                        'parameter' => $match['parameter'],
                        'rule' => $match['detection']['rule'],
                        'confidence' => $match['detection']['confidence'],
                    ],
                ]);
            }
        } catch (Throwable) {
            // Monitoring remains fail-open and never interrupts Hotel workflows.
        }

        return $next($request);
    }

    /** @return array{parameter:string,detection:array{rule:string,severity:string,confidence:float}}|null */
    private function firstMatch(array $input, string $prefix = ''): ?array
    {
        foreach ($input as $key => $value) {
            $parameter = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if ($this->isSensitive($parameter)) {
                continue;
            }
            if (is_array($value)) {
                if ($match = $this->firstMatch($value, $parameter)) {
                    return $match;
                }
                continue;
            }
            if (! is_scalar($value) || is_bool($value)) {
                continue;
            }
            if ($detection = $this->detector->detect((string) $value)) {
                return ['parameter' => mb_substr($parameter, 0, 120), 'detection' => $detection];
            }
        }

        return null;
    }

    private function isSensitive(string $key): bool
    {
        return preg_match('/(?:password|passwd|passphrase|secret|token|authorization|cookie|csrf|session|api[_-]?key|credential|card|cvv)/i', $key) === 1;
    }
}
