<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.3. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>

# Tahlil Development Instructions

These project-specific rules live outside the Boost-managed block. Maintain them here; Boost updates replace only the block above.

## Project context

- This is a Laravel 13 application with PHP `^8.3`. Confirm current installed versions with Composer before using package APIs; keep new code compatible with the declared PHP baseline.
- Tests use PHPUnit 12 and formatting uses Laravel Pint. Frontend assets use Blade, Vite, and Tailwind CSS 4. Preserve the existing tools unless the requested task includes changing them.
- The application starts with Laravel's skeleton and a welcome route. Do not infer business entities, user roles, an API, or a tenancy model from the project name.
- Keep technical files, identifiers, and instructions in English. Explain progress and results to the user in Persian unless they request another language.

## Select the relevant skills

Use the project skills below for their stated concerns. Read more than one when a change crosses boundaries; do not load every skill for an unrelated edit. They complement Boost's framework guidance and share the same PHPUnit and Laravel conventions.

| When working on | Project skill |
| --- | --- |
| Writing, reviewing, or refactoring backend PHP | [laravel-php-best-practices](.agents/skills/laravel-php-best-practices/SKILL.md) |
| Business operations, controller responsibilities, or dependency boundaries | [laravel-clean-architecture](.agents/skills/laravel-clean-architecture/SKILL.md) |
| Tests, regressions, test failures, or verification of changed behavior | [laravel-testing-qa](.agents/skills/laravel-testing-qa/SKILL.md) |
| Authentication, permissions, untrusted input, sensitive data, or security review | [laravel-security](.agents/skills/laravel-security/SKILL.md) |

Each skill can also be requested explicitly using its `$skill-name`. For example, use `$laravel-testing-qa` when asking for test coverage.

## Architecture and code placement

- Preserve Laravel's normal directories. HTTP validation and request authorization belong in Form Requests and Policies; controllers adapt inputs, invoke operations, and return responses.
- Put meaningful business operations in `app/Actions`, with a typed `handle` method and feature grouping when useful. Actions may use Eloquent, but receive explicit data and actor context rather than reading the global HTTP request or session.
- Use Eloquent models for persistence, relationships, casts, and query scopes. Add a DTO in `app/Data` or an interface in `app/Contracts` only when it clarifies a real boundary; bind interfaces in an existing provider.
- Creating these conventional directories is authorized when a feature actually needs them. Do not scaffold empty layers, generic repositories, or a separate Domain/Application/Infrastructure tree as routine cleanup.
- Framework bootstrapping and middleware configuration live in `bootstrap/app.php`; providers are registered in `bootstrap/providers.php`. Follow the installed Laravel layout rather than introducing legacy kernel files.
- Preserve established route behavior and public response contracts during internal refactoring. Add API routes only for an API requirement.

## Working commands

Run commands from the project root and use the narrowest checks that provide evidence for the change.

```sh
composer install
php artisan test --compact tests/Feature/ExampleTest.php
php artisan test --compact --filter=ExampleTest
composer test
composer validate --no-check-publish
vendor/bin/pint --dirty --format agent
npm run build
```

- Test commands naming `ExampleTest` are examples; select the actual affected test file or filter. `composer test` clears cached configuration and runs the full suite.
- Run Pint after PHP edits. If Git metadata is unavailable, pass the changed PHP file paths to `vendor/bin/pint --format agent` instead of `--dirty`; avoid formatting the entire project for a narrow change.
- Run the asset build for frontend or Vite changes. Do not require application tests or an asset build for prose-only changes; validate the affected instructions, YAML, or links instead.
- Do not run `composer setup` for routine checks: it generates an application key and migrates the development database. Do not use destructive migration commands on existing development data to make tests pass.
- Database-backed tests use the isolated SQLite `:memory:` configuration in `phpunit.xml`, with factories and `RefreshDatabase`. Confirm the test connection before database tests; never substitute a live or development database.

## Data and security

- Enforce permissions on the server and test that another user cannot access or mutate a protected resource. Validation and route binding do not replace authorization.
- Persist only intended validated fields, and keep privileged attributes server-controlled. Preserve CSRF protections, escaped output, and parameterized queries.
- Keep secrets out of source, logs, tool output, and user-facing reports. Do not display `.env` values or rotate `APP_KEY` as a routine fix.
- For changes involving uploads, external requests, authentication, or sensitive data, read the security skill and test the affected failure path. A routine feature does not require an unrelated full security audit.

## Maintaining the agent configuration

- Edit the four custom skills in `.ai/skills/`; `.agents/skills/` is the Codex-facing publication directory managed by Boost. Keep names unique from Boost's built-in skills.
- Each custom skill has a `SKILL.md` entry point and `agents/openai.yaml` metadata. Keep automatic invocation enabled and descriptions precise. Add supporting resources only for an actual workflow need.
- After editing custom skills, run `php artisan boost:update --no-discover --no-interaction`. Check that all four appear in `boost.json`, their published content matches the source, and these project rules remain outside the Boost block.
- Codex MCP configuration is local to this project in `.codex/config.toml`; this directory is currently ignored by `.gitignore`. On a fresh checkout, run `php artisan boost:install` and select Codex with guidelines, skills, and MCP to recreate it. No global Codex configuration is required.
- Consult official documentation or installed source when changing Boost configuration. Do not manually edit generated framework rules to make a project convention persistent.

## Completion and reporting

Keep changes focused on the requested behavior. Add meaningful regression coverage for behavior changes and report the checks actually run, their results, and any unresolved limitation. Documentation changes need relevant validation rather than synthetic application tests. Do not claim successful verification for checks that were skipped, unavailable, or failed.
