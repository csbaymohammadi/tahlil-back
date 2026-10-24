---
name: laravel-php-best-practices
description: "Write, review, or refactor Laravel backend PHP in Tahlil using the project's PHP baseline, Eloquent conventions, validation boundaries, transaction handling, and Pint. Use for controllers, requests, models, queries, jobs, migrations, and actions; skip documentation-only and frontend-only changes."
---

# Laravel and PHP Best Practices

## Establish the local contract

Read the relevant code and [project instructions](../../../AGENTS.md). Check [composer.json](../../../composer.json) and installed package versions before using version-sensitive APIs. The baseline is PHP 8.3 and Laravel 13; a newer local interpreter does not authorize syntax above the supported PHP baseline.

Boost's `laravel-best-practices` skill supplies detailed framework rules. Use the rules relevant to the change; this skill adds Tahlil's implementation defaults. Use Boost documentation search for unfamiliar APIs, with installed framework source or official versioned documentation as the fallback.

## Implementation defaults

- Match nearby PHP conventions. Type parameters, properties, and return values; use PHPDoc for generic collections and array shapes that native types cannot express. Avoid unexplained `mixed` and unnecessary type assertions.
- Use constructor injection for collaborators and property promotion when useful. Resolve services at the application boundary rather than hiding container lookups inside business methods.
- Follow Laravel's generators and naming. Check `php artisan make:<type> --help`; pass `--no-interaction` with the required options.
- Put substantial request validation and request-level authorization in Form Requests. Pass validated, explicitly selected fields to the operation; keep business rules out of validation closures when they belong to a reusable action.
- Use API Resources for deliberate response fields when implementing APIs. Do not accidentally serialize internal attributes or turn a web route into an API as part of an unrelated refactor.
- Define relationships, casts, and reusable query constraints on models. The current User model uses Laravel attributes for fillable and hidden fields; preserve that convention for similar models.
- Keep queries out of views. Eager-load relationships actually used by the response, select necessary columns without omitting relationship keys, and paginate lists that can grow. Use chunking or lazy iteration for large background workloads.
- Match indexes and uniqueness constraints to actual lookups and invariants. Validation alone cannot prevent concurrent duplicate writes; handle database conflicts according to the intended behavior.
- Use `DB::transaction` for an operation that must commit multiple related writes together. Keep network calls out of the transaction and dispatch dependent asynchronous work after commit. Consider locks or idempotency only where retries or competing writes make them necessary.
- Handle expected failures explicitly. Do not swallow exceptions, catch `Throwable` without a recovery purpose, or return a success response after a failed operation.
- Read environment variables through configuration files; use `config()` elsewhere so configuration caching works. Set timeouts on outbound requests and retry only when repeating the operation is safe.

## Verify and finish

Review changed behavior with the project's `laravel-testing-qa` skill. For PHP changes, format the changed files with Pint; use the commands and Git-free fallback in `AGENTS.md`. Inspect the resulting changes so formatting does not spread into unrelated files.

Finish when the behavior and error contract are clear, the relevant checks pass, and any remaining limitation is reported. Summarize the changed behavior and validation; do not claim a test or check was run when it was only recommended.
