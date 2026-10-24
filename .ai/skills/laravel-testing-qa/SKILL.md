---
name: laravel-testing-qa
description: "Write, review, run, or diagnose Tahlil's PHPUnit tests and perform QA for changed Laravel behavior. Use for regressions, feature verification, test isolation, factories, fakes, and test failures. Preserve PHPUnit; documentation-only work needs its own relevant validation rather than a new application test."
---

# Laravel Testing and QA

## Use the existing test stack

Read [phpunit.xml](../../../phpunit.xml), [tests/TestCase.php](../../../tests/TestCase.php), and nearby tests. Tahlil uses PHPUnit 12, not Pest. Do not introduce Pest, Dusk, Larastan, coverage tooling, or a CI service as a side effect of writing tests.

Use Boost's `testing-best-practices` skill for detailed test design rules. This skill defines the project's tooling, isolation, and verification workflow.

## Select useful coverage

- Write a regression test that fails for the reported defect when practical, then verify that the fix makes it pass.
- Prefer a Feature Test extending `Tests\TestCase` for routes, policies, actions using Eloquent, and other Laravel integrations. Use a Unit Test extending `PHPUnit\Framework\TestCase` for logic independent of Laravel.
- Generate tests with `php artisan make:test OperationTest --phpunit --no-interaction`; add `--unit` for independent logic. Do not prefix the name with `Feature/` or `Unit/` because the generator chooses the suite directory.
- Assert observable behavior: returned fields or redirects, persisted state, an expected failure, and relevant dispatched work. A status code alone is insufficient when the behavior includes a state change.
- Cover the meaningful success path, invalid input, unauthorized or unauthenticated callers, missing resources, and other branches affected by the change. Use data providers for comparable inputs rather than duplicating setup.
- Where relevant, verify another user's resource cannot be read or changed, rejected input causes no mutation, failed multi-write operations roll back, and retried work does not repeat a forbidden side effect.
- Avoid tests that restate an implementation, assert private method calls, or test an untouched framework feature. Do not add application tests for formatting or prose edits.

## Keep tests isolated

- The committed PHPUnit configuration uses SQLite `:memory:`, array-backed services, and the synchronous queue. Confirm the effective test configuration still selects an isolated database before database tests, especially after config caching or environment changes. Never use the development SQLite file or a production database as a test fixture.
- Use `RefreshDatabase` on database-backed tests and factories with explicit states for important data. Tests must not depend on execution order, previously seeded development data, or an existing user ID.
- Fake HTTP, mail, notifications, jobs, or events only at boundaries not under test. Prevent stray outbound HTTP requests in tests that exercise external clients. When testing a job's internals, execute the job rather than faking away the behavior being verified.
- Freeze time when assertions depend on it, restore time and other global state, and avoid live network calls and real sleeps.
- Do not claim SQLite verifies database-specific SQL, locking, or production concurrency. Use a disposable database of the relevant engine when the feature needs that evidence, and report when it is unavailable.
- Laravel's normal feature-test environment bypasses CSRF protection. A passing ordinary HTTP test alone does not prove CSRF protection; use an appropriate integration setup when changing that middleware.

## Run and report

Run commands from the project root:

```sh
php artisan test --compact tests/Feature/OperationTest.php
php artisan test --compact --filter=OperationTest
composer test
composer validate --no-check-publish
```

The first two are alternative focused runs; replace `OperationTest` with the actual test. Run the smallest relevant set first. Use `composer test` for the full suite when shared behavior, dependencies, configuration, or the change's risk warrants it; the script clears cached configuration first. Do not run `composer setup` as a test command because it generates keys and migrates the development database.

Format changed PHP according to `AGENTS.md`. Run `npm run build` when frontend or asset configuration changes; it is not a substitute for behavioral QA. Run static analysis or coverage only when the project has the relevant tool and the task calls for it; do not invent a coverage percentage.

Finish with the actual commands and results. Separate failures introduced by the change from pre-existing failures and environment blockers. A skipped or unavailable check remains unverified, even if another check passes.
