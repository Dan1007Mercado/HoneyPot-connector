<?php

namespace App\Http\Controllers;

use App\Services\IntsecClient;
use Illuminate\Http\Request;

class MonitoredLoginController extends Controller
{
    public function __invoke(Request $request, IntsecClient $intsec)
    {
        $intsec->sendSecurityEvent([
            'event_type' => 'monitored_login_attempt', 'ip' => $request->ip(), 'route' => '/security/monitored-login',
            'method' => $request->method(), 'user_agent' => $request->userAgent(),
            'message' => 'Attempt to access the isolated monitored login endpoint.', 'metadata' => ['controlled_endpoint' => true],
        ]);
        return response()->json(['message' => 'This endpoint is monitored.'], 404);
    }
}
