
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

use App\Http\Middleware\AdminMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )

    ->withMiddleware(function (Middleware $middleware): void {

        $middleware->alias([
            'admin' => AdminMiddleware::class,
        ]);

        $middleware->redirectGuestsTo(
            fn ($request) => $request->expectsJson() ? null : '/login'
        );

    })

    ->withExceptions(function (Exceptions $exceptions): void {

        // Return JSON responses for API requests.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, \Throwable $e): bool =>
                $request->is('api/*') || $request->expectsJson()
        );

        // Handle API exceptions.
        $exceptions->render(function (\Throwable $e, Request $request) {
            // Do not change normal web exception handling.
            if (! $request->is('api/*')) {
                return null;
            }

            // 422 - Validation errors.
            if ($e instanceof ValidationException) {
                return response()->json([
                    'success' => false,
                    'message' => 'The given data was invalid.',
                    'errors' => $e->errors(),
                ], 422);
            }

            // 401 - Unauthenticated.
            if ($e instanceof AuthenticationException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                    'errors' => null,
                ], 401);
            }

            // 403 - Unauthorized action.
            if ($e instanceof AuthorizationException) {
                return response()->json([
                    'success' => false,
                    'message' => 'This action is unauthorized.',
                    'errors' => null,
                ], 403);
            }

            // 404 - Model not found.
            if ($e instanceof ModelNotFoundException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Resource not found.',
                    'errors' => null,
                ], 404);
            }

            // HTTP exceptions: 404, 405, 429, etc.
            if ($e instanceof HttpExceptionInterface) {

                $status = $e->getStatusCode();

                $message = match ($status) {
                    400 => 'Bad request.',
                    401 => 'Unauthenticated.',
                    403 => 'Forbidden.',
                    404 => 'Endpoint or resource not found.',
                    405 => 'Method not allowed.',
                    419 => 'Page expired.',
                    422 => 'The given data was invalid.',
                    429 => 'Too many requests. Please try again later.',
                    default => $status >= 500
                        ? 'An unexpected server error occurred.'
                        : 'The request could not be processed.',
                };

                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'errors' => null,
                ], $status, $e->getHeaders());
            }

            // 500 - Unexpected errors.
            return response()->json([
                'success' => false,
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'An unexpected server error occurred.',
                'errors' => null,
            ], 500);

        });

    })

    ->create();
