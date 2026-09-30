<?php

namespace App\Http\Middleware;

use App\Services\IntsecClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class EnforceIntsecBlockedIps
{
    public function __construct(private IntsecClient $intsec) {}

    public function handle(Request $request, Closure $next): Response
    {
        $ip = (string) $request->ip();
        if ($ip !== '' && IpUtils::checkIp($ip, $this->intsec->activeBlockedIps())) {
            abort(403, 'Access denied by central security policy.');
        }
        return $next($request);
    }
}
