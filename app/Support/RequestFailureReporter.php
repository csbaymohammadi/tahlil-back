<?php

namespace App\Support;

use App\Exceptions\PanelAuthException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PDOException;
use Predis\Connection\ConnectionException;
use RedisException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class RequestFailureReporter
{
    public function report(Throwable $exception, Request $request): void
    {
        $route = $request->route();
        $status = match (true) {
            $exception instanceof PanelAuthException => $exception->status,
            $exception instanceof ValidationException => 422,
            $exception instanceof UniqueConstraintViolationException => 409,
            $exception instanceof MissingAppKeyException, $exception instanceof QueryException,
            $exception instanceof PDOException, $exception instanceof LockTimeoutException,
            $exception instanceof DecryptException, $exception instanceof RedisException,
            $exception instanceof ConnectionException => 503,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => 500,
        };
        $context = [
            'request_id' => $request->attributes->get('request_id'),
            'method' => $request->method(),
            'route' => $route instanceof Route ? $route->getName() : null,
            'route_template' => $route instanceof Route ? $route->uri() : null,
            'status' => $status,
            ...$this->diagnostic($exception),
        ];
        $previous = $exception->getPrevious();
        $causes = [];
        while ($previous !== null && count($causes) < 3) {
            $causes[] = $this->diagnostic($previous);
            $previous = $previous->getPrevious();
        }
        if ($causes !== []) {
            $context['causes'] = $causes;
        }
        $context['trace'] = [];
        foreach ($exception->getTrace() as $frame) {
            if (! isset($frame['file']) || ! str_starts_with($frame['file'], app_path().DIRECTORY_SEPARATOR)) {
                continue;
            }
            $function = $frame['function'] ?? '';
            $context['trace'][] = [
                'file' => $this->relativeFile($frame['file']), 'line' => $frame['line'] ?? null,
                'call' => ($frame['class'] ?? '').($frame['type'] ?? '').(str_contains($function, '{closure') ? '{closure}' : $function),
            ];
            if (count($context['trace']) === 8) {
                break;
            }
        }
        Log::log($status >= 500 ? 'error' : 'notice', $status >= 500 ? 'Application request failed.' : 'Application request rejected.', $context);
    }

    /** @return array<string, mixed> */
    private function diagnostic(Throwable $exception): array
    {
        $data = [
            'exception_type' => $exception::class,
            'file' => $this->relativeFile($exception->getFile()), 'line' => $exception->getLine(),
        ];
        if ($exception instanceof PanelAuthException) {
            $data['error_code'] = preg_match('/^[A-Z0-9_]{1,80}$/D', $exception->errorCode) ? $exception->errorCode : 'REQUEST_REJECTED';
        } elseif ($exception instanceof MissingAppKeyException) {
            $data['error_code'] = 'APPLICATION_KEY_MISSING';
        } elseif ($exception instanceof QueryException) {
            $data['error_code'] = 'DATABASE_QUERY_FAILED';
            $state = $exception->errorInfo[0] ?? null;
            $driverCode = $exception->errorInfo[1] ?? null;
            if (is_string($state) && preg_match('/^[A-Z0-9]{5}$/D', $state)) {
                $data['sqlstate'] = $state;
            }
            if (is_int($driverCode) || (is_string($driverCode) && ctype_digit($driverCode))) {
                $data['driver_code'] = (int) $driverCode;
            }
        } else {
            $patterns = [
                'UNDEFINED_FUNCTION' => '/Call to undefined function ([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\(\)/',
                'UNDEFINED_METHOD' => '/Call to undefined method ([A-Za-z_\\\\][A-Za-z0-9_\\\\]*::[A-Za-z_][A-Za-z0-9_]*)\(\)/',
                'CLASS_NOT_FOUND' => '/Class ["\']([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)["\'] not found/',
                'NULL_MEMBER_CALL' => '/Call to a member function ([A-Za-z_][A-Za-z0-9_]*)\(\) on null/',
            ];
            foreach ($patterns as $reason => $pattern) {
                if (preg_match($pattern, $exception->getMessage(), $match)) {
                    $data['error_code'] = $reason;
                    $data['symbol'] = substr($match[1], 0, 200);
                    break;
                }
            }
        }

        return $data;
    }

    private function relativeFile(string $file): string
    {
        $prefix = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : basename($file);
    }
}
