<?php

use App\Http\Middleware\EnsureAccountIsSecure;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias([
            'account.secure' => EnsureAccountIsSecure::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());

        // Accounts without an employee record (e.g. the platform super admin) can open admin pages that also
        // load "my …" data. Treat those self-service reads as empty instead of failing the whole page.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e, $request) {
            if ($request->isMethod('GET') && $request->is('api/*')
                && in_array($e->getStatusCode(), [403, 404, 422], true)
                && preg_match('/not linked to an employee|no employee record/i', $e->getMessage())) {
                return response()->json(['data' => [], 'linked' => false, 'message' => $e->getMessage()]);
            }

            return null;
        });
    })->create();
