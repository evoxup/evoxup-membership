# Pull Request

Thank you for contributing to **Evoxup Membership**.

Please complete the relevant sections below before submitting this Pull Request.

Evoxup Membership follows a modular, WordPress-native, provider-neutral architecture. Contributions should preserve existing functionality, integrations, security boundaries, and compatibility unless a breaking change is explicitly justified.

---

## Summary

Describe the change clearly and briefly.

What does this Pull Request change?

<!--
Example:
Fixes entitlement validation when a customer has multiple active license keys.
-->

---

## Problem

Describe the problem this Pull Request solves.

<!--
Explain:
- What was wrong?
- When did it happen?
- Who was affected?
- Was there a workaround?
-->

---

## Solution

Explain how the change solves the problem.

<!--
Include important implementation details, but avoid unnecessary internal detail.
-->

---

## Type of Change

Select all that apply:

- [ ] Bug fix
- [ ] Security fix
- [ ] New feature
- [ ] Performance improvement
- [ ] Compatibility improvement
- [ ] Refactoring
- [ ] Documentation update
- [ ] REST API change
- [ ] SDK change
- [ ] WooCommerce integration change
- [ ] External provider integration change
- [ ] Licensing change
- [ ] Membership change
- [ ] Entitlement change
- [ ] Extension / module change
- [ ] Database change
- [ ] Developer tooling / CI change
- [ ] Other

---

## Affected Components

Select all affected areas:

- [ ] Core
- [ ] Admin
- [ ] Frontend
- [ ] Memberships
- [ ] Products
- [ ] Membership Plans
- [ ] Customers / Members
- [ ] Licensing
- [ ] Entitlements
- [ ] Orders
- [ ] Fulfillment
- [ ] WooCommerce
- [ ] External Providers
- [ ] Webhooks
- [ ] REST API
- [ ] SDK
- [ ] Extensions
- [ ] Modules
- [ ] Notifications / Email
- [ ] Events / Audit
- [ ] Database
- [ ] Documentation
- [ ] GitHub Actions / CI
- [ ] Other

---

## Related Issue

Link the related Issue if available.

```text
Closes #
```

or:

```text
Related to #
```

---

## Architecture Impact

Does this Pull Request change or affect the architecture?

- [ ] No architectural impact
- [ ] Minor internal architectural change
- [ ] New extension point
- [ ] New provider / adapter
- [ ] New module
- [ ] New public API
- [ ] Breaking architectural change

If there is architectural impact, explain it below.

<!--
Describe:
- New interfaces
- New services
- New providers
- New hooks
- New events
- New extension boundaries
- Changed responsibilities
-->

---

## Provider Neutrality

Evoxup Membership must remain provider-neutral.

Confirm:

- [ ] This change does not hard-code the platform around a single commerce provider.
- [ ] This change does not remove or unnecessarily restrict other provider integrations.
- [ ] Provider-specific logic remains isolated where appropriate.
- [ ] Existing external provider support remains functional unless explicitly documented otherwise.
- [ ] No provider is treated as the only possible licensing or commerce authority without architectural justification.

If this Pull Request is provider-specific, explain why the implementation remains isolated:

<!-- Add explanation here -->

---

## Third-Party Dependencies

Does this change introduce a new third-party dependency?

- [ ] No
- [ ] Yes

If yes, provide:

- Dependency name:
- Purpose:
- License:
- Runtime requirement:
- Why an existing WordPress or Evoxup capability cannot be used instead:

Evoxup core functionality should not become dependent on a third-party component that Evoxup does not control unless the dependency is explicitly approved.

---

## WordPress Compatibility

Confirm:

- [ ] Uses WordPress public APIs where appropriate.
- [ ] Does not modify WordPress core files.
- [ ] Does not modify files belonging to third-party plugins.
- [ ] Uses WordPress hooks, APIs, adapters, or documented integration points.
- [ ] Handles optional plugins safely when they are inactive.
- [ ] Does not introduce unnecessary global state.
- [ ] Does not enqueue assets globally when they are only needed on Evoxup pages.

---

## WooCommerce Compatibility

If this Pull Request affects WooCommerce:

- [ ] WooCommerce remains optional.
- [ ] Evoxup Membership still works when WooCommerce is inactive.
- [ ] WooCommerce-specific logic remains inside the integration boundary.
- [ ] Evoxup products, plans, memberships, licenses, and entitlements remain independent platform concepts.
- [ ] Existing WooCommerce product mappings remain compatible.
- [ ] Refund and fulfillment behavior was considered.

If WooCommerce is not affected:

- [ ] Not applicable

---

## Membership & Entitlement Impact

Does this change affect membership or entitlement decisions?

- [ ] No
- [ ] Yes

If yes, confirm:

- [ ] Access cannot be granted without the required authorization.
- [ ] Membership levels remain isolated correctly.
- [ ] Lower-tier users cannot receive higher-tier entitlements unintentionally.
- [ ] Product-specific entitlements are preserved.
- [ ] Existing memberships are not unintentionally changed.
- [ ] Revoked, stopped, expired, or blocked states are respected where applicable.

Describe the access-control impact:

<!-- Add details here -->

---

## Licensing Impact

Does this change affect licensing?

- [ ] No
- [ ] Yes

If yes, confirm:

- [ ] License ownership validation remains intact.
- [ ] Product validation remains intact.
- [ ] License status is respected.
- [ ] Activation rules are respected.
- [ ] Membership and entitlement relationships are preserved.
- [ ] External license providers remain supported where applicable.
- [ ] Full license keys are not exposed in public logs.
- [ ] Blocked, suspended, stopped, expired, or revoked licenses are handled correctly.

Describe any licensing changes:

<!-- Add details here -->

---

## Webhook Impact

Does this change affect webhooks?

- [ ] No
- [ ] Yes

If yes, confirm:

- [ ] Provider identification is validated.
- [ ] Signature verification is preserved where required.
- [ ] Duplicate event protection remains active.
- [ ] Transaction identity is preserved.
- [ ] Product mapping is validated.
- [ ] Customer mapping is validated.
- [ ] Test transactions follow the intended validated fulfillment path.
- [ ] Refund handling was considered.
- [ ] Revocation handling was considered.
- [ ] Unauthorized fulfillment is prevented.

---

## REST API Impact

Does this change add or modify REST API routes?

- [ ] No
- [ ] Yes

If yes, confirm:

- [ ] Appropriate `permission_callback` is present.
- [ ] Authentication requirements are correct.
- [ ] Authorization checks are correct.
- [ ] Input is validated.
- [ ] Input is sanitized.
- [ ] Sensitive data is not exposed.
- [ ] HTTP status codes are appropriate.
- [ ] Existing API consumers are not unintentionally broken.
- [ ] API changes are documented.

List affected routes:

<!-- Add routes here -->

---

## Database Impact

Does this change affect database schema, tables, options, metadata, or stored data?

- [ ] No
- [ ] Yes

If yes, confirm:

- [ ] Existing data is preserved.
- [ ] Migration logic is included where required.
- [ ] Queries use safe WordPress database practices.
- [ ] Prepared queries are used where variables are involved.
- [ ] Upgrade behavior was tested.
- [ ] Uninstall behavior was considered.
- [ ] No customer, license, membership, order, or entitlement data is deleted unintentionally.

Describe database changes:

<!-- Add details here -->

---

## Security Review

Confirm:

- [ ] Capability checks are present where required.
- [ ] Authentication and authorization are handled separately.
- [ ] Nonces are used where appropriate.
- [ ] Input is validated.
- [ ] Input is sanitized.
- [ ] Output is escaped.
- [ ] SQL queries are safe.
- [ ] REST permissions are enforced.
- [ ] No sensitive credentials are exposed.
- [ ] No secrets are committed.
- [ ] File operations are validated.
- [ ] External input is not trusted automatically.
- [ ] Error handling does not expose sensitive internals.

---

## Sensitive Data Check

Confirm that this Pull Request does **not** contain:

- [ ] Passwords
- [ ] API keys
- [ ] REST secrets
- [ ] Webhook secrets
- [ ] Private keys
- [ ] Production license keys
- [ ] Database credentials
- [ ] Access tokens
- [ ] Session tokens
- [ ] Authentication cookies
- [ ] Private customer information
- [ ] Private server credentials

If sensitive information was accidentally committed, it must be considered compromised and rotated immediately.

---

## Backward Compatibility

Does this change affect existing users or integrations?

- [ ] No backward-compatibility impact
- [ ] Backward-compatible change
- [ ] Deprecation introduced
- [ ] Breaking change

If this is a breaking change, explain:

- What breaks?
- Why is it necessary?
- Is migration available?
- Is a deprecation period possible?

<!-- Add explanation here -->

---

## PHP Compatibility

Confirm:

- [ ] The code is compatible with the project's declared minimum PHP version.
- [ ] No unsupported syntax was introduced.
- [ ] PHP syntax checks pass.
- [ ] New PHP features are compatible with the declared requirement.

Current project requirement:

```text
PHP 8.3+
```

---

## Testing Performed

Describe all testing performed.

Examples:

- [ ] Clean installation
- [ ] Plugin activation
- [ ] Plugin deactivation
- [ ] Upgrade from previous version
- [ ] WordPress admin
- [ ] Frontend
- [ ] Membership creation
- [ ] Product creation
- [ ] Plan assignment
- [ ] License generation
- [ ] License verification
- [ ] Multiple license keys
- [ ] Entitlement validation
- [ ] REST API
- [ ] Webhooks
- [ ] WooCommerce
- [ ] External providers
- [ ] Extensions
- [ ] Modules
- [ ] Email notifications
- [ ] Error handling
- [ ] Uninstall behavior
- [ ] Other

Describe the test environment:

```text
Evoxup Membership:
WordPress:
PHP:
Database:
WooCommerce:
Browser:
Other integrations:
```

---

## Automated Checks

Confirm:

- [ ] PHP Syntax Check passes.
- [ ] WordPress Plugin Check passes.
- [ ] No new blocking CI errors were introduced.

---

## Screenshots

If this Pull Request changes the user interface, add screenshots or recordings.

### Before

<!-- Add screenshot if applicable -->

### After

<!-- Add screenshot if applicable -->

If there is no visual change:

- [ ] Not applicable

---

## Performance Impact

Does this change affect performance?

- [ ] No meaningful performance impact
- [ ] Improves performance
- [ ] May increase resource usage
- [ ] Requires further measurement

Consider:

- Database queries
- Remote API calls
- Filesystem operations
- Admin page loading
- Scheduled tasks
- Repeated license verification
- Repeated entitlement checks
- Memory usage

Describe any important impact:

<!-- Add details here -->

---

## Logging

Does this change add or modify logging?

- [ ] No
- [ ] Yes

If yes, confirm:

- [ ] Logs are useful for diagnostics.
- [ ] Logs do not expose passwords.
- [ ] Logs do not expose full API secrets.
- [ ] Logs do not expose private keys.
- [ ] Logs do not expose authentication tokens.
- [ ] Logs do not expose unnecessary customer data.
- [ ] License keys are masked where possible.

---

## Documentation

Select all updated documentation:

- [ ] `README.md`
- [ ] `readme.txt`
- [ ] `CHANGELOG.md`
- [ ] `/docs`
- [ ] PHPDoc
- [ ] REST API documentation
- [ ] SDK documentation
- [ ] Integration documentation
- [ ] No documentation change required

---

## Versioning

Do not change the plugin version number unless specifically requested by a maintainer.

Confirm:

- [ ] I did not change the plugin version without authorization.
- [ ] I did not create or modify release tags.
- [ ] I did not modify release assets unexpectedly.

---

## Files Changed

Briefly list the most important files changed and why.

```text
src/...
api/...
modules/...
docs/...
```

---

## Migration Notes

If administrators or developers must perform any action after updating, explain it here.

If no action is required:

```text
No manual migration required.
```

---

## Rollback Considerations

If this change causes an issue, can it be safely rolled back?

- [ ] Yes
- [ ] No
- [ ] Not applicable

Explain any important rollback considerations:

<!-- Add details here -->

---

## Final Checklist

Before requesting review, confirm:

- [ ] The Pull Request has a clear purpose.
- [ ] The implementation solves the stated problem.
- [ ] Unrelated files were not modified unnecessarily.
- [ ] Existing functionality was preserved.
- [ ] Existing integrations were preserved.
- [ ] Provider neutrality was preserved.
- [ ] WooCommerce remains optional.
- [ ] No unnecessary mandatory dependency was added.
- [ ] WordPress APIs are used appropriately.
- [ ] Security checks were considered.
- [ ] Sensitive information is not included.
- [ ] PHP compatibility was verified.
- [ ] PHP Syntax Check passes.
- [ ] WordPress Plugin Check passes.
- [ ] Relevant manual testing was completed.
- [ ] Backward compatibility was considered.
- [ ] Documentation was updated when necessary.
- [ ] The change is ready for review.

---

## Additional Notes

Add anything else reviewers should know.

<!-- Add additional notes here -->

---

Thank you for helping improve **Evoxup Membership**.

Official project resources:

- Evoxup: https://evoxup.com/
- Evoxup Membership: https://evoxup.com/evo-membership/
- Repository: https://github.com/evoxup/evoxup-membership
- Releases: https://github.com/evoxup/evoxup-membership/releases
- Documentation: https://github.com/evoxup/evoxup-membership/tree/main/docs
- Security Policy: https://github.com/evoxup/evoxup-membership/blob/main/.github/SECURITY.md
- Contribution Guidelines: https://github.com/evoxup/evoxup-membership/blob/main/.github/CONTRIBUTING.md
