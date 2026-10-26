<?php

namespace Tests\Feature;

use App\Exceptions\PanelAuthException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RequestDiagnosticsTest extends TestCase
{
    public function test_runtime_errors_record_safe_symbols_and_locations_without_request_payload_or_route_values(): void
    {
        Log::spy();
        Route::post('/api/v1/blog/diagnostic/{id}', function (): never {
            throw new \Error('Call to undefined function MissingRuntime\\decode_image() private@example.org secret-value');
        })->name('diagnostic.runtime');
        $response = $this->postJson('/api/v1/blog/diagnostic/private-id?token=query-secret', [
            'email' => 'private@example.org', 'password' => 'secret-value', 'text' => 'Private body content',
        ])->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL_ERROR');
        $requestId = $response->headers->get('X-Request-ID');
        Log::shouldHaveReceived('log')->once()->withArgs(function (string $level, string $message, array $context) use ($requestId): bool {
            $this->assertSame('error', $level);
            $this->assertSame('Application request failed.', $message);
            $this->assertSame($requestId, $context['request_id']);
            $this->assertSame('diagnostic.runtime', $context['route']);
            $this->assertSame('api/v1/blog/diagnostic/{id}', $context['route_template']);
            $this->assertSame('POST', $context['method']);
            $this->assertSame('UNDEFINED_FUNCTION', $context['error_code']);
            $this->assertSame('MissingRuntime\\decode_image', $context['symbol']);
            $this->assertSame('tests/Feature/RequestDiagnosticsTest.php', $context['file']);
            $this->assertIsInt($context['line']);
            foreach (['private@example.org', 'secret-value', 'query-secret', 'Private body content', 'private-id', 'password'] as $secret) {
                $this->assertStringNotContainsString($secret, json_encode($context));
            }

            return true;
        });
    }

    public function test_expected_client_rejections_have_their_code_and_status_without_becoming_server_errors(): void
    {
        Log::spy();
        Route::get('/api/v1/blog/rejected', function (): never {
            throw new PanelAuthException(403, 'CSRF_INVALID', 'Private diagnostic message');
        })->name('diagnostic.rejected');
        $this->getJson('/api/v1/blog/rejected')->assertForbidden()->assertJsonPath('error.code', 'CSRF_INVALID');
        Log::shouldHaveReceived('log')->once()->withArgs(function (string $level, string $message, array $context): bool {
            $this->assertSame('notice', $level);
            $this->assertSame('Application request rejected.', $message);
            $this->assertSame(403, $context['status']);
            $this->assertSame('CSRF_INVALID', $context['error_code']);
            $this->assertStringNotContainsString('Private diagnostic message', json_encode($context));

            return true;
        });
    }

    public function test_database_diagnostics_preserve_error_codes_without_sql_bindings_or_database_credentials(): void
    {
        Log::spy();
        Route::get('/api/v1/blog/database-failure', function (): never {
            $previous = new \PDOException('Duplicate secret@example.org with password private-value');
            $previous->errorInfo = ['23000', 1062, 'Duplicate secret@example.org'];
            throw new QueryException('mariadb', 'insert into users (email,password) values (?,?)', ['secret@example.org', 'private-value'], $previous);
        })->name('diagnostic.database');
        $this->getJson('/api/v1/blog/database-failure')->assertStatus(503)->assertJsonPath('error.code', 'BLOG_UNAVAILABLE')->assertDontSee('secret@example.org');
        Log::shouldHaveReceived('log')->once()->withArgs(function (string $level, string $message, array $context): bool {
            $this->assertSame('error', $level);
            $this->assertSame('DATABASE_QUERY_FAILED', $context['error_code']);
            $this->assertSame('23000', $context['sqlstate']);
            $this->assertSame(1062, $context['driver_code']);
            $this->assertSame(\PDOException::class, $context['causes'][0]['exception_type']);
            foreach (['secret@example.org', 'private-value', 'insert into', 'bindings', 'password'] as $secret) {
                $this->assertStringNotContainsString($secret, json_encode($context));
            }

            return true;
        });
    }

    public function test_missing_application_key_is_reported_as_configuration_unavailability_without_generating_a_key(): void
    {
        Log::spy();
        $before = config('app.key');
        Route::get('/api/v1/blog/missing-key', function (): never {
            throw new MissingAppKeyException;
        })->name('diagnostic.key');
        $this->getJson('/api/v1/blog/missing-key')->assertStatus(503)->assertJsonPath('error.code', 'BLOG_UNAVAILABLE');
        $this->assertSame($before, config('app.key'));
        Log::shouldHaveReceived('log')->once()->withArgs(fn (string $level, string $message, array $context): bool => $level === 'error' && $context['error_code'] === 'APPLICATION_KEY_MISSING' && $context['status'] === 503);
    }

    public function test_database_conflicts_and_connection_failures_log_the_status_returned_to_the_client(): void
    {
        Log::spy();
        Route::get('/api/v1/blog/conflict', function (): never {
            throw new UniqueConstraintViolationException('sqlite', 'private query', [], new \PDOException('private failure'));
        });
        Route::get('/api/v1/blog/connection-failure', function (): never {
            throw new \PDOException('private connection details');
        });
        $this->getJson('/api/v1/blog/conflict')->assertConflict()->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->getJson('/api/v1/blog/connection-failure')->assertServiceUnavailable()->assertJsonPath('error.code', 'BLOG_UNAVAILABLE');
        Log::shouldHaveReceived('log')->withArgs(fn (string $level, string $message, array $context): bool => $level === 'notice' && $context['status'] === 409)->once();
        Log::shouldHaveReceived('log')->withArgs(fn (string $level, string $message, array $context): bool => $level === 'error' && $context['status'] === 503)->once();
    }
}
