---
name: laravel-security
description: "Implement or review Laravel application security in Tahlil when changes touch authentication, authorization, user-controlled input, sensitive data, uploads, outbound requests, or dependencies. Also use for an explicitly requested security review. Apply relevant controls to the affected flow; routine activation does not authorize a full audit or live-system probing."
---

# Laravel Security

## Establish the boundary

Read [AGENTS.md](../../../AGENTS.md), the relevant route and middleware, request validation, policy, operation, and tests. Identify who controls each input, which data is sensitive, and which authorization rule must hold. Use installed Laravel 13 APIs and official versioned documentation or Boost documentation search for uncertain behavior.

Apply the controls relevant to the task. Do not change authentication schemes, add a security package, or scan unrelated systems merely because this skill is active. For a requested review, follow the affected input through authorization to the query, output, or side effect before reporting a finding.

## Access control and input

- Enforce authentication and permissions on the server. Use Policies or Gates and ensure the entry point actually invokes them. A route-bound model, hidden UI button, or successful validation is not authorization.
- Scope resource access to the permitted user or organization where the application has that ownership model. Test access with a second user; do not invent a tenant architecture for this starter application.
- Accept only intended fields from validated input. Keep ownership, roles, verification state, and other privileged fields server-controlled. Preserve explicit fillable attributes; do not globally unguard models or pass `$request->all()` into persistence.
- Validate shape, size, enum values, nested fields, and database references according to the operation. An `exists` check alone does not establish permission to reference a record.
- Use bound query parameters. Allowlist user-controlled column names and sort directions, because identifiers cannot be made safe just by binding a value. Avoid interpolated raw SQL, shell commands, and dynamic includes.

## Browser and authentication boundaries

- Preserve Laravel's CSRF and request-forgery protections for browser state changes. Do not disable protection or move a mutating operation to GET to make a request or test pass. Validate webhook signatures at a deliberate webhook boundary when needed.
- Rely on Blade's escaped output for untrusted strings. Use raw HTML only for trusted or properly sanitized content, and use Laravel's safe JSON rendering for data embedded in scripts. Validation does not replace output encoding.
- Use Laravel hashing or the existing hashed cast for passwords; avoid plaintext or double hashing. Use encryption only for values that must be recovered, and avoid returning secrets through model serialization or exception messages.
- Use the framework's session regeneration and logout invalidation flow when implementing session authentication. Match cookie and CORS settings to the actual deployment; do not broaden origins or credential access to fix a local integration problem.
- Rate-limit authentication and other abuse-sensitive operations using identifiers appropriate to the flow. Consider both user/account and network identity where relevant; do not trust spoofable forwarding headers without a defined trusted-proxy configuration.

## Files, external systems, and secrets

- For uploads, validate content type and size, generate storage names, and avoid trusting client filenames or paths. Keep sensitive or executable content outside publicly served locations and authorize downloads. Reject traversal and do not join arbitrary user paths to filesystem roots.
- For user-influenced outbound URLs, constrain destinations and schemes and account for redirects and resolved internal addresses. Set timeouts and response-size limits appropriate to the integration. Never disable TLS verification as a fix.
- Do not read, print, commit, or put secret values into prompts or logs. Use configuration and environment references. Do not run `key:generate` or rotate `APP_KEY` during routine development; encrypted data and sessions can depend on it.
- Keep production debug output disabled, and make sensitive file visibility and logging deliberate. Report required deployment configuration rather than silently editing a live environment.
- For dependency changes or a requested dependency review, run `composer audit --locked` and, when JS dependencies are in scope, `npm audit`. Report advisory scope and remediation options; do not blindly run an automatic fix or unrelated upgrade.

## Verify and finish

Use the project's `laravel-testing-qa` skill for regression coverage. Exercise the affected security boundary with an unauthorized actor or malicious input and assert both the failure response and absence of an unauthorized side effect. Do not treat default feature tests as proof of CSRF middleware behavior.

For review findings, include a verified file and line, attack prerequisites, the reachable failure, impact, and a focused remedy. Distinguish demonstrated defects from unverified concerns. State what was inspected and tested; neither a successful dependency audit nor a clean scoped review proves the whole application secure.
