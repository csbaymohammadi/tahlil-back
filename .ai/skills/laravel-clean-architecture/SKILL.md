---
name: laravel-clean-architecture
description: "Design or refactor Tahlil business operations and dependency boundaries using pragmatic Clean Architecture within Laravel. Use when adding actions, moving logic out of controllers, reusing operations across HTTP and jobs, or deciding on DTOs, services, or repository boundaries. Skip mechanical edits and requests limited to styling, documentation, or test syntax."
---

# Laravel Clean Architecture

## Project decision

Tahlil uses Laravel's normal application structure, with actions for business operations. Eloquent may be used by an action. A framework-independent domain layer, a parallel `Application` tree, or a repository around every model is not the default architecture.

Read [AGENTS.md](../../../AGENTS.md) and the affected caller, model, and tests first. Preserve established boundaries unless the task requires changing them. Do not create empty architectural directories or migrate unrelated features to a new pattern.

## Place each responsibility

| Responsibility | Default home |
| --- | --- |
| HTTP validation and request authorization | Form Requests and Policies |
| HTTP orchestration and response mapping | Controllers and API Resources |
| A named business operation | `app/Actions`, grouped by feature when needed |
| Persistence, relationships, casts, query scopes | Eloquent models and focused query objects when justified |
| Structured input shared across boundaries | `app/Data` only when a DTO improves the contract |
| An external integration or meaningful interchangeable boundary | A focused service; an interface in `app/Contracts` when needed |
| Interface bindings | Existing service providers |

These are conventions for classes needed by a feature, not instructions to scaffold all the directories.

## Shape the operation

1. Identify the use case, its callers, required input, observable result, and failure behavior. Preserve the existing public contract during internal refactoring unless the user requests a behavior change.
2. Give an action a business-oriented name and one typed `handle` method. Extract a meaningful operation, not every line of a controller. Trivial reads and response mapping need no extra layer.
3. Pass explicit input and actor context when required. Do not pass an HTTP Request into an action or reach for the global request, session, response, or authenticated user inside it. Controllers and jobs adapt their inputs before calling it.
4. Keep the operation's invariants inside the action or a focused collaborator. Keep authorization at the relevant entry point; when an operation is also called from a job or command, make the actor and permission requirements explicit for that caller too.
5. Let the action own the transaction when several writes form one atomic operation. Decide the output and expected exceptions before arranging side effects; keep response codes and redirects in the HTTP layer.
6. Introduce a DTO for a meaningful structured contract, not a single primitive. Add an interface when it represents a real dependency boundary, alternate implementation, or external system; bind it in an existing provider.
7. Use Eloquent directly unless a repository isolates a concrete persistence concern. Avoid generic base repositories, pass-through services, and circular action/service dependencies.

## Verify the boundary

- HTTP callers validate and authorize before mutating state; background callers cannot silently bypass required permissions.
- A job or command can reuse an action without constructing a Request or depending on an HTTP response.
- Tests protect the operation's result, persistence, failure behavior, and atomicity where relevant. Use framework-backed tests for Eloquent actions and unit tests for framework-independent logic.
- The change adds only the abstractions it uses and preserves existing callers and response contracts.

Finish by explaining the responsibility moved, any new dependency boundary, and the tests that protect the change. Do not apply an architecture migration merely because this skill was activated.
