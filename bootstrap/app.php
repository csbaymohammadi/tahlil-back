<?php

use App\Auth\PanelSessions;
use App\Blog\BlogApiErrorPresenter;
use App\Exceptions\PanelAuthException;
use App\Http\Middleware\PanelEnvelope;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Predis\Connection\ConnectionException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: fn () => Route::group([], base_path('routes/media.php')),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(PanelEnvelope::class);
        $middleware->trimStrings(except: [fn (Request $request) => $request->is('api/v1/admin/*')]);
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request) => $request->is('api/v1/admin/*')]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $exception) {
            if (app()->bound('request') && request()->is('api/v1/admin/*', 'media/*')) {
                Log::error('Panel request failed.', [
                    'request_id' => request()->attributes->get('request_id'),
                    'exception_type' => $exception::class,
                ]);

                return false;
            }
        });
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'new_password']);
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/v1/admin/*', 'media/*')) {
                return null;
            }
            $fields = [];
            $retryAfter = null;
            if ($exception instanceof PanelAuthException) {
                [$status, $code, $message, $fields, $retryAfter] = [$exception->status, $exception->errorCode, $exception->getMessage(), $exception->fields, $exception->retryAfter];
            } elseif ($exception instanceof ValidationException) {
                [$status, $code, $message, $fields] = [422, 'VALIDATION_FAILED', 'Check the submitted information.', $exception->errors()];
            } elseif ($exception instanceof UniqueConstraintViolationException) {
                $detail = $exception->getMessage();
                if (str_contains($detail, 'username_normalized')) {
                    [$code, $field] = ['USERNAME_TAKEN', 'username'];
                } elseif (str_contains($detail, 'users.email') || str_contains($detail, 'users_email_unique')) {
                    [$code, $field] = ['EMAIL_TAKEN', 'email'];
                } elseif (str_contains($detail, 'name_normalized')) {
                    [$code, $field] = ['ROLE_NAME_TAKEN', 'name'];
                } elseif (str_contains($detail, 'blog_slug_ownership.slug')) {
                    [$code, $field] = ['SLUG_TAKEN', 'slug'];
                } elseif (str_contains($detail, 'blog_categories.name_normalized')) {
                    [$code, $field] = ['CATEGORY_NAME_TAKEN', 'name'];
                } elseif (str_contains($detail, 'news_slug_ownership.slug')) {
                    [$code, $field] = ['SLUG_TAKEN', 'slug'];
                } elseif (str_contains($detail, 'news_categories.name_normalized')) {
                    [$code, $field] = ['CATEGORY_NAME_TAKEN', 'name'];
                } elseif (str_contains($detail, 'exchange_slug_ownership.slug') || str_contains($detail, 'exchanges.slug')) {
                    [$code, $field] = ['SLUG_TAKEN', 'slug'];
                } elseif (str_contains($detail, 'exchange_categories.name_normalized') || str_contains($detail, 'exchange_categories.key')) {
                    [$code, $field] = ['CATEGORY_NAME_TAKEN', 'name'];
                } else {
                    [$code, $field] = ['VERSION_CONFLICT', null];
                }
                [$status, $message] = [409, 'A record with this value already exists.'];
                $fields = $field ? [$field => [$message]] : [];
            } elseif ($exception instanceof QueryException || $exception instanceof PDOException || $exception instanceof LockTimeoutException || $exception instanceof DecryptException || $exception instanceof RedisException || $exception instanceof ConnectionException) {
                [$status, $code, $message] = [503, 'SERVICE_UNAVAILABLE', 'Authentication is temporarily unavailable.'];
            } elseif ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 503) {
                [$status, $code, $message] = [503, 'SERVICE_UNAVAILABLE', 'Authentication is temporarily unavailable.'];
            } elseif ($exception instanceof PostTooLargeException) {
                [$status, $code, $message] = [413, 'PAYLOAD_TOO_LARGE', 'The request body is too large.'];
            } elseif ($exception instanceof HttpExceptionInterface && in_array($exception->getStatusCode(), [404, 405], true)) {
                [$status, $code, $message] = [$exception->getStatusCode(), 'NOT_FOUND', 'The endpoint was not found.'];
            } else {
                [$status, $code, $message] = [500, 'INTERNAL_ERROR', 'An unexpected error occurred.'];
            }
            if ($request->routeIs('panel.blog.articles.*', 'panel.news.items.*', 'panel.content-imports.store', 'panel.content-imports.report')) {
                $contentType = $request->routeIs('panel.news.items.*') || ($request->routeIs('panel.content-imports.store') && $request->input('content_type') === 'news') ? 'news' : 'blog';
                $presented = app(BlogApiErrorPresenter::class)->present($code, $message, $fields, $contentType);
                $message = $presented['message'];
                $fields = $presented['fields'];
            }
            $response = response()->json(['error' => ['code' => $code, 'message' => $message, 'fields' => (object) $fields],
                'request_id' => $request->attributes->get('request_id')], $status);
            if ($exception instanceof HttpExceptionInterface) {
                $response->headers->add($exception->getHeaders());
            }
            if ($retryAfter !== null) {
                $response->headers->set('Retry-After', (string) $retryAfter);
            }
            if ($status === 401 && $code === 'UNAUTHENTICATED') {
                $response->headers->clearCookie(PanelSessions::COOKIE, '/', null, config('panel.secure_cookie'), true, 'lax');
            }

            return $response;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
