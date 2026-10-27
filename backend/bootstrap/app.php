<?php

use App\Http\Middleware\SecurityHeadersMiddleware;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeadersMiddleware::class);
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $exception): void {
            // Exception messages and traces may contain invoice data, SQL bindings or credentials.
            Log::error('Application exception', [
                'exception_type' => $exception::class,
            ]);
        })->stop();

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => 'VALIDATION_ERROR',
                    'errors' => $e->errors(),
                ], 422);
            }

            if ($e instanceof AuthenticationException) {
                return response()->json([
                    'message' => 'Não autenticado.',
                    'code' => 'UNAUTHENTICATED',
                    'errors' => null,
                ], 401);
            }

            if ($e instanceof AuthorizationException || $e instanceof AccessDeniedHttpException) {
                return response()->json([
                    'message' => 'Acesso não autorizado.',
                    'code' => 'FORBIDDEN',
                    'errors' => null,
                ], 403);
            }

            if ($e instanceof NotFoundHttpException || $e instanceof ModelNotFoundException) {
                return response()->json([
                    'message' => 'Recurso não encontrado.',
                    'code' => 'NOT_FOUND',
                    'errors' => null,
                ], 404);
            }

            if ($e instanceof MethodNotAllowedHttpException) {
                return response()->json([
                    'message' => 'Método não permitido.',
                    'code' => 'METHOD_NOT_ALLOWED',
                    'errors' => null,
                ], 405);
            }

            if ($e instanceof ThrottleRequestsException) {
                return response()->json([
                    'message' => 'Muitas requisições. Tente novamente mais tarde.',
                    'code' => 'TOO_MANY_REQUESTS',
                    'errors' => null,
                ], 429);
            }

            if ($e instanceof HttpExceptionInterface) {
                $code = method_exists($e, 'getErrorCode')
                    ? $e->getErrorCode()
                    : match ($e->getStatusCode()) {
                        400 => 'BAD_REQUEST',
                        401 => 'UNAUTHENTICATED',
                        403 => 'FORBIDDEN',
                        404 => 'NOT_FOUND',
                        413 => 'PAYLOAD_TOO_LARGE',
                        415 => 'UNSUPPORTED_MEDIA_TYPE',
                        422 => 'VALIDATION_ERROR',
                        429 => 'TOO_MANY_REQUESTS',
                        default => 'HTTP_ERROR',
                    };

                return response()->json([
                    'message' => $e->getMessage() ?: 'Erro na requisição.',
                    'code' => $code,
                    'errors' => method_exists($e, 'getErrors') ? $e->getErrors() : null,
                ], $e->getStatusCode());
            }

            return response()->json([
                'message' => config('app.debug') ? $e->getMessage() : 'Erro interno do servidor.',
                'code' => 'INTERNAL_SERVER_ERROR',
                'errors' => null,
            ], 500);
        });
    })->create();
