<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        // Required when Laravel is behind Cloudflare Tunnel
        $middleware->trustProxies(at: '*');

        $middleware->appendToGroup(
            'web',
            \App\Http\Middleware\ReportRequestActivityToIntsec::class
        );
        $middleware->appendToGroup(
            'web',
            \App\Http\Middleware\EnforceIntsecBlockedIps::class
        );

        $middleware->alias([
            'receptionist' => \App\Http\Middleware\ReceptionistMiddleware::class,
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
