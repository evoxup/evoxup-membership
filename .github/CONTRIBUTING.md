# Contributing to Evoxup Membership

Thank you for your interest in contributing to **Evoxup Membership**.

Evoxup Membership is a WordPress-native membership, licensing, entitlement, commerce integration, and extension platform.

We welcome bug reports, compatibility improvements, documentation updates, security improvements, carefully designed features, and code contributions that respect the architecture and long-term direction of the project.

This document explains how to contribute while preserving the stability, security, compatibility, and modular architecture of Evoxup Membership.

---

## Table of Contents

- [Code of Contribution](#code-of-contribution)
- [Before You Contribute](#before-you-contribute)
- [Ways to Contribute](#ways-to-contribute)
- [Reporting Bugs](#reporting-bugs)
- [Requesting Features](#requesting-features)
- [Security Vulnerabilities](#security-vulnerabilities)
- [Development Principles](#development-principles)
- [Core Architecture Rules](#core-architecture-rules)
- [Third-Party Integrations](#third-party-integrations)
- [Provider-Neutral Architecture](#provider-neutral-architecture)
- [WordPress Compatibility](#wordpress-compatibility)
- [WooCommerce Integration](#woocommerce-integration)
- [Membership and Entitlement Logic](#membership-and-entitlement-logic)
- [Licensing](#licensing)
- [Webhook and Commerce Providers](#webhook-and-commerce-providers)
- [Extensions and Modules](#extensions-and-modules)
- [REST API](#rest-api)
- [Database Changes](#database-changes)
- [Security Requirements](#security-requirements)
- [Coding Standards](#coding-standards)
- [PHP Compatibility](#php-compatibility)
- [JavaScript and CSS](#javascript-and-css)
- [Internationalization](#internationalization)
- [Testing](#testing)
- [Backward Compatibility](#backward-compatibility)
- [Performance](#performance)
- [Logging and Debugging](#logging-and-debugging)
- [Documentation](#documentation)
- [Commit Guidelines](#commit-guidelines)
- [Pull Requests](#pull-requests)
- [Review Process](#review-process)
- [Release Safety](#release-safety)
- [Sensitive Information](#sensitive-information)
- [Licensing of Contributions](#licensing-of-contributions)
- [Official Resources](#official-resources)

---

# Code of Contribution

Contributors are expected to communicate respectfully and professionally.

Technical disagreement is welcome when it helps improve the project.

Please:

- Focus discussion on the code, architecture, behavior, security, or documentation.
- Provide technical evidence when reporting problems.
- Avoid personal attacks.
- Avoid spam.
- Avoid unrelated promotional material.
- Respect maintainers, users, contributors, and external projects.
- Keep discussions relevant to Evoxup Membership.

---

# Before You Contribute

Before making a significant change:

1. Review the existing implementation.
2. Review open Issues and Pull Requests.
3. Understand the affected architecture.
4. Determine whether the functionality belongs in the core, an extension, an integration, or a provider adapter.
5. Avoid duplicating an existing service or abstraction.
6. Preserve existing functionality unless the change intentionally replaces deprecated behavior.
7. Consider backward compatibility.
8. Consider WordPress compatibility.
9. Consider security implications.
10. Consider interactions with existing extensions and integrations.

For major architectural changes, opening an Issue before writing the implementation is strongly recommended.

---

# Ways to Contribute

You can contribute through:

- Bug reports
- Compatibility reports
- Documentation improvements
- Translation improvements
- Performance improvements
- Security improvements
- Tests
- Accessibility improvements
- WordPress compatibility fixes
- WooCommerce compatibility fixes
- Integration adapters
- Provider implementations
- REST API improvements
- Developer SDK improvements
- Extension improvements
- Carefully reviewed new features
- Pull Requests

---

# Reporting Bugs

Before opening a bug report:

- Confirm that you are using a supported Evoxup Membership release.
- Confirm that the issue can be reproduced.
- Check whether the issue has already been reported.
- Test with debugging enabled when appropriate.
- Avoid including private credentials or sensitive data.

A useful bug report should include:

- Evoxup Membership version
- WordPress version
- PHP version
- Database version when relevant
- WooCommerce version when relevant
- Browser when relevant
- Active integration or provider
- Relevant extension or module
- Expected behavior
- Actual behavior
- Reproduction steps
- Error message
- Relevant log output
- Screenshots when useful

Please provide the smallest reproducible example possible.

---

# Requesting Features

Feature requests are welcome.

A useful feature request should explain:

- The problem being solved
- The expected behavior
- Why the feature belongs in Evoxup Membership
- Whether it belongs in the core or an extension
- Whether it requires a third-party integration
- Whether it changes public APIs
- Whether it affects existing users
- Whether a backward-compatible implementation is possible

Feature requests should avoid introducing unnecessary complexity or mandatory dependencies.

---

# Security Vulnerabilities

Do **not** report security vulnerabilities publicly.

Do not publish:

- Exploit code
- Authentication bypasses
- Active credentials
- License keys
- API secrets
- Webhook secrets
- Private keys
- Customer information
- Production database information

Follow the project's security policy:

```text
.github/SECURITY.md
```

Security issues should be disclosed privately through the official Evoxup communication channels.

---

# Development Principles

Evoxup Membership follows several important architectural principles.

Contributions should preserve these principles whenever possible.

---

## 1. Stable Core

Core membership, product, plan, entitlement, licensing, customer, and platform capabilities should remain stable and maintainable.

Do not introduce unnecessary complexity into the core.

Functionality that is optional should normally remain outside the core when practical.

---

## 2. Evoxup-Controlled Core Components

Essential platform functionality should not depend on a third-party component that Evoxup does not control and cannot modify.

Third-party systems may be integrated, but should not become mandatory foundations for core platform functionality unless explicitly approved.

---

## 3. Optional Integrations

External systems should normally be connected through:

- Adapters
- Providers
- Interfaces
- Hooks
- REST APIs
- Webhooks
- Extensions
- Modules

The absence of an optional third-party integration should not prevent the core platform from functioning.

---

## 4. Provider Neutrality

Do not hard-code the platform around one commerce provider, licensing provider, payment provider, or external service.

A fix for one provider must not unnecessarily remove or break support for other providers.

Provider-specific behavior should remain isolated whenever possible.

---

## 5. Separation of Responsibilities

Keep distinct concepts separated.

Examples include:

```text
Product
Membership Plan
Membership
Customer
Order
License
Entitlement
Provider
Integration
Webhook
Fulfillment
Notification
```

These components may cooperate, but they should not be merged into one concept simply for implementation convenience.

---

# Core Architecture Rules

Contributions should avoid architectural shortcuts that create long-term coupling.

Do not:

- Put provider-specific business logic directly into unrelated core services.
- Duplicate an existing service without a clear reason.
- Bypass public service APIs when an appropriate abstraction already exists.
- Modify unrelated functionality as part of a small fix.
- Introduce global state unnecessarily.
- Add mandatory third-party libraries without strong justification.
- Replace an extensible provider mechanism with hard-coded provider logic.
- Remove existing integration support while fixing another integration.
- Silence errors instead of handling them properly.
- Store secrets in source files.

Prefer:

- Interfaces
- Contracts
- Services
- Dependency boundaries
- Registries
- Providers
- Adapters
- Hooks
- Events
- Clearly scoped modules

---

# Third-Party Integrations

Third-party integrations should remain optional.

Examples may include:

- WooCommerce
- External commerce providers
- External licensing systems
- Email services
- Analytics services
- SEO plugins
- External APIs

Integrations should use documented and public APIs whenever possible.

Do not modify files belonging to another WordPress plugin.

Do not assume that a third-party plugin is always installed.

Always check availability before invoking third-party functionality.

Example:

```php
if ( class_exists( 'WooCommerce' ) ) {
    // WooCommerce-specific integration.
}
```

Use appropriate hooks, APIs, or adapters instead of directly changing third-party code.

---

# Provider-Neutral Architecture

Provider integrations should follow a provider-neutral architecture.

Provider-specific behavior should be isolated behind appropriate abstractions.

A provider implementation should not:

- Change global platform behavior unnecessarily.
- Remove other providers.
- Assume it is the only commerce source.
- Assume it is the only licensing authority.
- Hard-code product behavior outside its integration boundary.

The platform should be able to support multiple providers simultaneously where the architecture allows it.

---

# WordPress Compatibility

Contributions should use WordPress public APIs whenever appropriate.

Prefer WordPress functionality for:

- Users
- Roles
- Capabilities
- Nonces
- REST API
- HTTP requests
- Scheduling
- Email
- Database access
- Options
- Metadata
- Filesystem access
- Internationalization
- Sanitization
- Escaping
- Plugin lifecycle
- Administration pages

Avoid recreating WordPress functionality without a clear technical reason.

---

# WooCommerce Integration

WooCommerce support is an integration, not a mandatory core dependency.

Contributions involving WooCommerce should preserve the separation between:

```text
Commerce
      ↓
Product / Order
      ↓
Evoxup Membership
      ↓
Membership / License / Entitlements
```

WooCommerce should not become the owner of Evoxup Membership's internal product, membership, licensing, or entitlement architecture.

Code must safely handle environments where WooCommerce is not installed or is inactive.

---

# Membership and Entitlement Logic

Membership and entitlement logic must remain explicit and predictable.

A typical conceptual workflow is:

```text
Sale
  ↓
Product / Plan
  ↓
Membership
  ↓
License
  ↓
Entitlements
  ↓
Access / Fulfillment
```

Do not grant access merely because a transaction payload exists.

Access should be granted only after the appropriate transaction, product, plan, membership, license, or entitlement rules have been satisfied.

Changes affecting access rights require careful testing.

---

# Licensing

Licensing contributions must preserve product and entitlement boundaries.

License-related code should consider:

- Product identity
- License ownership
- License status
- Activation state
- Membership association
- Entitlement association
- Provider identity
- Verification response
- Expiration when applicable
- Revocation when applicable
- Blocked or suspended state
- Site or domain restrictions when applicable

Do not expose complete license keys in logs unless specifically required for secure administrative diagnostics.

Secrets should be masked whenever possible.

---

# Webhook and Commerce Providers

Webhook processing should be secure, idempotent, and provider-aware.

Contributions involving webhooks should consider:

- Provider identification
- Signature verification
- Payload validation
- Duplicate-event prevention
- Transaction identity
- Product mapping
- Customer mapping
- Test transaction handling
- Refund handling
- Revocation handling
- Fulfillment
- Logging
- Error recovery

Test transactions should exercise the same validated fulfillment pipeline as real transactions whenever appropriate, while remaining clearly marked as test activity.

Do not silently skip core fulfillment logic merely because a transaction is marked as a test unless the provider contract explicitly requires different behavior.

---

# Extensions and Modules

Optional functionality should normally remain modular.

Extensions and modules should:

- Define clear responsibilities
- Avoid duplicating core logic
- Respect capability boundaries
- Use public platform services where available
- Avoid modifying unrelated modules
- Fail gracefully when unavailable
- Validate compatibility before activation
- Avoid exposing internal implementation unnecessarily

An extension should not assume that another optional extension is installed unless that dependency is explicitly declared and handled.

---

# REST API

REST API contributions should follow WordPress REST API practices.

Every privileged endpoint should include an appropriate:

```php
permission_callback
```

REST routes should validate and sanitize input.

Responses should avoid exposing:

- Passwords
- API secrets
- Private keys
- Internal tokens
- Full license secrets
- Sensitive customer information

Use meaningful HTTP status codes.

Avoid returning internal stack traces to public clients.

---

# Database Changes

Database changes require careful review.

When introducing or modifying database structures:

- Preserve existing data.
- Use appropriate WordPress database APIs.
- Use prepared SQL queries.
- Avoid destructive migrations whenever possible.
- Provide upgrade handling where necessary.
- Consider rollback behavior.
- Consider multisite environments when relevant.
- Avoid creating unnecessary tables.
- Document schema changes.

Never delete customer, membership, license, order, or entitlement data during a normal update unless the behavior is explicitly required and safely implemented.

---

# Security Requirements

All contributions should consider security.

Use appropriate WordPress mechanisms for:

### Authentication

Confirm the identity of the requester when required.

### Authorization

Check capabilities and ownership.

Authentication alone is not authorization.

### Nonces

Use WordPress nonces for appropriate administrative and state-changing browser requests.

### Sanitization

Sanitize input according to its expected type.

Examples include:

```php
sanitize_text_field()
sanitize_email()
sanitize_key()
absint()
esc_url_raw()
```

### Escaping

Escape output according to context.

Examples include:

```php
esc_html()
esc_attr()
esc_url()
wp_kses_post()
```

### Database Queries

Use prepared queries when variables are involved.

Example:

```php
$wpdb->prepare()
```

### REST APIs

Use appropriate permission callbacks and input validation.

### Secrets

Never commit:

- Passwords
- API keys
- Access tokens
- Private keys
- Real license keys
- Production webhook secrets
- Database passwords
- Authentication cookies

---

# Coding Standards

Code should remain readable, maintainable, and consistent with the existing project.

Prefer:

- Clear naming
- Small focused methods
- Explicit responsibilities
- Defensive validation
- Predictable return values
- Appropriate PHPDoc
- Meaningful exceptions or error objects
- Reusable abstractions where justified

Avoid:

- Unnecessarily large classes
- Deeply nested conditional logic
- Duplicate code
- Unexplained magic values
- Dead code
- Hidden side effects
- Suppressed errors without handling
- Unnecessary global variables

---

# PHP Compatibility

Code must respect the PHP versions officially supported by the current Evoxup Membership release.

Do not introduce syntax requiring a newer PHP version unless the project's minimum PHP requirement has intentionally been updated.

Before using newer PHP syntax or APIs, verify the supported PHP requirement declared by the project.

---

# JavaScript and CSS

JavaScript and CSS contributions should:

- Avoid unnecessary global variables
- Avoid polluting the global namespace
- Load only where required
- Respect WordPress administration styles where appropriate
- Avoid unnecessary dependencies
- Remain accessible
- Avoid breaking other plugins or themes
- Use unique Evoxup selectors where appropriate

Do not enqueue administration assets globally if they are only required on Evoxup pages.

---

# Internationalization

User-facing strings should be translatable where appropriate.

Use WordPress internationalization functions such as:

```php
__()
_e()
esc_html__()
esc_html_e()
esc_attr__()
esc_attr_e()
```

Do not concatenate translatable fragments in a way that prevents proper translation.

Use the project's correct text domain.

---

# Testing

All significant changes should be tested before submission.

Test the functionality directly affected by the change.

Depending on the contribution, testing may include:

- Plugin activation
- Plugin deactivation
- Clean installation
- Upgrade from an older version
- WordPress administration
- Frontend behavior
- Membership creation
- Product creation
- Plan assignment
- License generation
- License verification
- Entitlement checks
- REST API
- Webhooks
- WooCommerce
- External providers
- Extensions
- Modules
- Email notifications
- Error handling
- Uninstallation behavior

When fixing a bug, reproduce the bug before the change and confirm that the fix resolves it afterward.

Also confirm that related functionality was not broken.

---

# Backward Compatibility

Backward compatibility is important.

Avoid changing public behavior unexpectedly.

Before modifying:

- Public methods
- REST endpoints
- Hooks
- Filters
- Database structures
- Option names
- Metadata
- Extension APIs
- Provider interfaces
- SDK methods

consider whether existing integrations may depend on them.

When a breaking change is necessary:

- Document it clearly.
- Provide migration guidance when possible.
- Prefer a deprecation period when practical.

---

# Performance

Contributions should avoid unnecessary resource usage.

Consider:

- Database query count
- Repeated remote requests
- Large option values
- Expensive operations on every request
- Excessive filesystem scanning
- Unnecessary admin asset loading
- Repeated API verification
- Cache opportunities
- Scheduled task frequency

Do not optimize prematurely, but avoid obvious performance regressions.

---

# Logging and Debugging

Logs should help administrators diagnose problems without exposing sensitive data.

Good logs may include:

- Event type
- Provider
- Product ID
- Membership ID
- Order ID
- HTTP status
- Error code
- Request correlation ID
- Processing result

Avoid logging:

- Complete passwords
- Full API secrets
- Private keys
- Authentication cookies
- Complete access tokens
- Sensitive customer information
- Complete license keys unless absolutely necessary

Sensitive values should be masked or redacted.

---

# Documentation

Changes affecting public behavior should update relevant documentation.

Documentation may include:

- `README.md`
- `readme.txt`
- `CHANGELOG.md`
- `/docs`
- PHPDoc
- REST API documentation
- Extension documentation
- Integration documentation

Documentation should describe actual functionality.

Do not document functionality that does not exist.

---

# Commit Guidelines

Use clear and descriptive commit messages.

Good examples:

```text
Fix entitlement validation for multiple licenses
```

```text
Improve WooCommerce product mapping
```

```text
Add provider-neutral webhook validation
```

```text
Update membership REST API documentation
```

```text
Prevent duplicate fulfillment processing
```

Avoid vague messages such as:

```text
fix
```

```text
changes
```

```text
update files
```

```text
test
```

A commit should ideally represent one logical change.

---

# Pull Requests

Pull Requests should be focused and reviewable.

A Pull Request should include:

- A clear title
- Description of the problem
- Description of the solution
- Affected components
- Testing performed
- Compatibility impact
- Security impact when relevant
- Screenshots for UI changes when useful
- Related Issue when applicable

Avoid combining unrelated changes into one Pull Request.

---

## Pull Request Checklist

Before submitting a Pull Request, confirm:

- [ ] The change solves a defined problem.
- [ ] Existing functionality has been preserved.
- [ ] The change follows Evoxup architecture.
- [ ] No unnecessary mandatory dependency was added.
- [ ] Provider-neutral behavior has been preserved.
- [ ] WordPress compatibility has been considered.
- [ ] WooCommerce remains optional unless explicitly required by the feature.
- [ ] Inputs are validated and sanitized.
- [ ] Outputs are escaped appropriately.
- [ ] Capability checks are present where required.
- [ ] REST endpoints have appropriate permission callbacks.
- [ ] Database queries are safe.
- [ ] Sensitive information is not logged.
- [ ] No credentials are committed.
- [ ] Relevant functionality has been tested.
- [ ] Documentation has been updated when necessary.
- [ ] Public APIs were not broken unintentionally.
- [ ] The change does not unnecessarily modify unrelated files.

---

# Review Process

Maintainers may:

- Request changes
- Ask for additional tests
- Request architectural adjustments
- Ask for documentation
- Reject unnecessary dependencies
- Reject changes that weaken security
- Reject changes that unnecessarily break compatibility
- Request provider-neutral implementations
- Move functionality from the core to an extension
- Request separation of unrelated changes

Submission of a Pull Request does not guarantee acceptance.

Changes are evaluated based on:

- Quality
- Security
- Maintainability
- Architecture
- Compatibility
- Scope
- Long-term project direction

---

# Release Safety

Do not change the plugin version number as part of an ordinary Pull Request unless specifically requested.

Release versioning is managed by the maintainers.

Do not create release tags without authorization.

Do not modify release assets or release processes unexpectedly.

Changes intended for a release should be reflected in appropriate release documentation when requested.

---

# Sensitive Information

Never commit real sensitive information.

This includes:

```text
Passwords
API keys
REST secrets
Webhook secrets
Private keys
Production license keys
Database credentials
Access tokens
Session tokens
Authentication cookies
Customer private information
Server credentials
```

Use placeholders in examples.

Example:

```text
YOUR_API_KEY
```

instead of a real key.

If a secret is accidentally committed:

1. Treat it as compromised.
2. Revoke or rotate it immediately.
3. Remove it from the repository where appropriate.
4. Notify maintainers if necessary.

Removing the latest commit alone may not remove the secret from Git history.

---

# Licensing of Contributions

Evoxup Membership is distributed under the license declared by the project.

By submitting a contribution, you represent that you have the right to submit the contributed code or documentation.

Unless explicitly agreed otherwise, contributions submitted to this repository are intended to be distributed under the same applicable open-source license as the project.

Do not contribute code that you do not have the legal right to distribute.

Do not copy proprietary code from another plugin, application, service, repository, or commercial product.

Third-party open-source code must comply with compatible licensing requirements and should include appropriate attribution when required.

---

# Trademark and Branding

The source-code license does not automatically grant rights to use Evoxup trademarks, logos, branding, domain names, or other brand assets in a way that implies official endorsement.

Contributors and forks should avoid representing modified versions as official Evoxup releases unless authorized.

---

# Forks

Forking is permitted according to the project's applicable open-source license.

Forks should clearly distinguish themselves from official Evoxup releases when substantial modifications are made.

Official Evoxup releases are distributed through Evoxup-controlled channels.

---

# Official Releases

Official public releases are published at:

https://github.com/evoxup/evoxup-membership/releases

When a WordPress-ready release asset is provided, it normally follows a versioned naming format such as:

```text
evoxup-membership-x.x.x.zip
```

GitHub-generated source archives are separate from official installation packages.

---

# Official Resources

- **Evoxup:** https://evoxup.com/
- **Evoxup Membership:** https://evoxup.com/evo-membership/
- **GitHub Repository:** https://github.com/evoxup/evoxup-membership
- **Releases:** https://github.com/evoxup/evoxup-membership/releases
- **Issues:** https://github.com/evoxup/evoxup-membership/issues
- **Documentation:** https://github.com/evoxup/evoxup-membership/tree/main/docs
- **Security Policy:** https://github.com/evoxup/evoxup-membership/blob/main/.github/SECURITY.md

---

# Thank You

Thank you for helping improve **Evoxup Membership**.

High-quality contributions that preserve security, compatibility, modularity, provider neutrality, and WordPress-native architecture help make the platform stronger for everyone.
