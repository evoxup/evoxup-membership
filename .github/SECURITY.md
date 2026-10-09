# Security Policy

## Supported Versions

Security updates are provided for the latest supported public release of **Evoxup Membership**.

| Version | Supported |
|---|---|
| 1.8.5 | ✅ Yes |
| Older versions | ❌ No |

Users are strongly encouraged to update to the latest available version before reporting a security issue.

---

## Reporting a Vulnerability

If you discover a security vulnerability in **Evoxup Membership**, please do **not** disclose it publicly through GitHub Issues, Discussions, pull requests, social media, or public forums.

Security reports should be submitted privately.

Please include as much relevant information as possible:

- A clear description of the vulnerability
- The affected Evoxup Membership version
- WordPress version
- PHP version
- Affected component or module
- Steps required to reproduce the issue
- Proof of concept, if available
- Expected behavior
- Actual behavior
- Potential security impact
- Relevant logs or error messages
- Whether authentication is required
- Whether administrator privileges are required
- Any known mitigation or workaround

Do not include live credentials, private license keys, API secrets, passwords, access tokens, customer information, or other sensitive production data.

---

## Private Security Contact

Security vulnerabilities should be reported through the official Evoxup communication channels.

**Website:**  
https://evoxup.com/

**Evoxup Membership:**  
https://evoxup.com/evo-membership/

If Evoxup publishes a dedicated security contact channel, please prefer that channel for vulnerability reports.

---

## Scope

Security reports may include vulnerabilities affecting Evoxup Membership components such as:

- Authentication
- Authorization
- WordPress capabilities
- Membership access control
- Entitlement validation
- License generation
- License verification
- License activation
- REST API authentication
- REST API authorization
- Webhooks
- External provider integrations
- WooCommerce integration
- Product and membership plan access
- Customer and member administration
- File handling
- Input validation
- Output escaping
- Nonce validation
- CSRF protection
- SQL queries
- Privilege escalation
- Sensitive data exposure
- Cryptographic operations
- Extension loading
- Module loading
- Fulfillment workflows
- Notification workflows
- API keys and credentials
- Audit and event infrastructure

---

## Out of Scope

The following are generally not considered security vulnerabilities unless they create a demonstrable security impact:

- Missing non-security UI improvements
- Feature requests
- General performance issues
- Browser compatibility issues
- Unsupported WordPress or PHP versions
- Issues caused exclusively by modified third-party code
- Vulnerabilities in third-party plugins or services that do not originate from Evoxup Membership
- Social engineering
- Denial-of-service testing that may disrupt production systems
- Reports based only on automated scanner output without validation
- Issues requiring intentionally weakened or disabled security controls without realistic exploitation

---

## Third-Party Integrations

Evoxup Membership supports optional integrations with WordPress components, commerce systems, license providers, webhook providers, and external services.

A vulnerability in a third-party service or plugin should normally be reported to that project's security team.

However, if Evoxup Membership incorrectly handles third-party data, permissions, authentication, webhooks, licenses, API responses, or integration boundaries in a way that creates a security vulnerability, it may be reported to Evoxup.

---

## Responsible Disclosure

We ask security researchers to:

- Report vulnerabilities privately before public disclosure
- Allow reasonable time for investigation and remediation
- Avoid accessing, modifying, or deleting data that does not belong to you
- Avoid disrupting production systems
- Avoid testing against systems without authorization
- Minimize collection of sensitive information
- Provide sufficient technical detail to reproduce the issue
- Keep vulnerability details confidential until a fix is available

---

## Security Fix Process

When a valid security issue is received, the project may:

1. Review and validate the report
2. Determine affected versions and components
3. Assess severity and potential impact
4. Develop and test a fix
5. Prepare a security release when necessary
6. Publish updated packages
7. Provide remediation or upgrade guidance
8. Disclose relevant details after affected users have had a reasonable opportunity to update

The exact response time depends on the severity and complexity of the issue.

---

## Security Releases

Security fixes may be distributed through official Evoxup Membership releases:

https://github.com/evoxup/evoxup-membership/releases

Users should install releases only from trusted Evoxup distribution channels.

The official WordPress installation package is normally provided as a versioned release asset, for example:

```text
evoxup-membership-x.x.x.zip
```

GitHub may also provide automatically generated source-code archives.

For normal WordPress installation, use the official Evoxup Membership release package when one is provided.

---

## Package Verification

When downloading Evoxup Membership from GitHub Releases, users should verify available release information and checksums where provided.

Do not install packages received from unknown or untrusted sources.

Do not trust a package solely because its filename resembles an official Evoxup package.

Official releases should be obtained from:

https://github.com/evoxup/evoxup-membership/releases

---

## WordPress Security Practices

Evoxup Membership aims to follow appropriate WordPress security practices, including the use of:

- Capability checks
- Nonces
- Input validation
- Input sanitization
- Output escaping
- Prepared database queries
- REST API permission callbacks
- Authentication checks
- Authorization checks
- Secure handling of credentials
- WordPress APIs
- Controlled extension boundaries

Security is an ongoing process, and responsible reports that help improve these protections are appreciated.

---

## REST API Security

Security reports related to the Evoxup Membership REST API may include:

- Missing permission callbacks
- Authentication bypass
- Authorization bypass
- Privilege escalation
- Exposure of sensitive information
- Improper input validation
- Unsafe update or delete operations
- License verification bypass
- Entitlement verification bypass
- Improper handling of API credentials

Public reports should never include active API keys, authentication tokens, or production credentials.

---

## Webhook Security

Webhook-related reports may include:

- Missing signature verification
- Incorrect provider verification
- Replay vulnerabilities
- Unauthorized fulfillment
- Duplicate transaction processing
- Improper event validation
- Incorrect product mapping
- Privilege escalation through webhook data
- Sensitive information disclosure

Never publish active webhook secrets in a public report.

---

## Licensing Security

Security reports related to licensing may include:

- License verification bypass
- Unauthorized license activation
- License ownership bypass
- Product validation bypass
- Entitlement escalation
- Unauthorized access to protected functionality
- Improper handling of revoked, suspended, expired, or blocked licenses
- Disclosure of license secrets
- Incorrect external license-provider validation

License keys used for testing should not belong to real customers or production systems.

---

## Membership and Entitlement Security

Security reports may include issues where a user can obtain membership access or entitlements they are not authorized to receive.

Examples include:

- Accessing a higher membership level without authorization
- Obtaining PRO or higher entitlements using a lower-level membership
- Bypassing product-specific access rules
- Accessing protected modules without valid entitlement
- Incorrect entitlement inheritance
- Unauthorized plan assignment
- Membership status bypass

---

## WooCommerce Integration Security

WooCommerce-related reports may include:

- Unauthorized fulfillment
- Incorrect order validation
- Incorrect customer assignment
- Product mapping bypass
- License issuance without a valid purchase
- Membership activation without valid order state
- Incorrect refund handling
- Entitlement retention after revocation where revocation is required

WooCommerce itself remains a separate third-party project.

Issues originating solely from WooCommerce should be reported to the WooCommerce project.

---

## External Provider Security

Evoxup Membership may integrate with external commerce, licensing, or service providers.

Reports may include:

- Incorrect provider authentication
- Improper signature verification
- Provider identity confusion
- Cross-provider mapping errors
- Unauthorized fulfillment
- Incorrect handling of test transactions
- Incorrect handling of refunds or revocations
- Leakage of provider credentials

The Evoxup core should not rely on unverified external data for privileged operations.

---

## Extension and Module Security

Reports involving Evoxup extensions or modules may include:

- Unauthorized module loading
- Privilege escalation
- Unsafe extension registration
- Arbitrary file inclusion
- Incorrect capability boundaries
- Improper access to platform services
- Untrusted extension metadata handling
- Unsafe activation or deactivation behavior

---

## Sensitive Information

Never submit any of the following through public GitHub Issues, Discussions, pull requests, screenshots, or logs:

- Passwords
- License keys
- REST API secrets
- Webhook secrets
- Access tokens
- Private keys
- Customer personal information
- Private server information
- Production credentials
- Database credentials
- Authentication cookies
- Session tokens
- Private API responses containing secrets

If sensitive information is accidentally posted publicly, revoke or rotate the affected credential immediately.

Deleting a public message does not guarantee that the exposed secret has not already been copied.

---

## Testing Guidelines

Security testing should be performed only on systems you own or systems where you have explicit authorization to conduct security testing.

Do not:

- Test against unrelated customer websites
- Access customer data without authorization
- Modify or delete third-party data
- Perform destructive tests against production systems
- Conduct denial-of-service attacks
- Attempt social engineering
- Publish exploit details before coordinated disclosure

Whenever possible, reproduce vulnerabilities in a local or dedicated testing environment.

---

## Automated Security Reports

Automated scanner reports are welcome when they identify a real and reproducible security issue.

Reports that contain only generic scanner output without demonstrating an actual vulnerability may not be actionable.

A useful automated report should include:

- The affected file or endpoint
- The specific security condition
- Reproduction steps
- Why the finding is exploitable
- Expected security behavior
- Actual behavior

---

## Coordinated Disclosure

If a vulnerability affects multiple Evoxup components or third-party integrations, coordinated disclosure may be necessary.

Please mention this in the initial private report so appropriate coordination can take place before public disclosure.

---

## Public Disclosure

After a vulnerability has been fixed and users have had a reasonable opportunity to update, Evoxup may publish information about the issue.

A public security notice may include:

- Affected versions
- Fixed version
- Severity
- Affected component
- General impact
- Upgrade instructions
- Mitigation guidance

Sensitive exploit details may be withheld when disclosure could unnecessarily place users at risk.

---

## Security Updates

Users should keep Evoxup Membership updated to the latest supported release.

Security fixes may be included in:

- Patch releases
- Maintenance releases
- Security releases
- Major or minor updates when architectural changes are required

The latest releases are available at:

https://github.com/evoxup/evoxup-membership/releases

---

## No Warranty

Evoxup Membership is distributed under the license included with the project.

Security reports, security fixes, documentation, or release notices do not create any additional warranty beyond the terms of the applicable software license.

---

## Official Resources

- **Evoxup:** https://evoxup.com/
- **Evoxup Membership:** https://evoxup.com/evo-membership/
- **GitHub Repository:** https://github.com/evoxup/evoxup-membership
- **Releases:** https://github.com/evoxup/evoxup-membership/releases
- **Issues:** https://github.com/evoxup/evoxup-membership/issues

---

## Thank You

We appreciate responsible security research and reports that help improve **Evoxup Membership** and protect its users.

Please report security vulnerabilities privately and provide enough technical information for the issue to be reproduced and investigated.
