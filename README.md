# Evoxup Membership

<p align="center">
  <strong>Membership • Licensing • WooCommerce • Entitlements • Universal Integrations</strong>
</p>

<p align="center">
  A modular WordPress membership and licensing platform for products, plans, customers, entitlements, commerce fulfillment, WooCommerce, verified webhooks, and integrations.
</p>

<p align="center">
  <a href="https://github.com/evoxup/evoxup-membership/releases/latest">
    <img src="https://img.shields.io/github/v/release/evoxup/evoxup-membership?display_name=tag&label=Release" alt="Latest Release">
  </a>
  <a href="https://github.com/evoxup/evoxup-membership/blob/main/LICENSE">
    <img src="https://img.shields.io/github/license/evoxup/evoxup-membership?label=License" alt="License">
  </a>
  <img src="https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white" alt="PHP 8.3+">
  <img src="https://img.shields.io/badge/WordPress-6.5%2B-21759B?logo=wordpress&logoColor=white" alt="WordPress 6.5+">
  <img src="https://img.shields.io/badge/Tested%20up%20to-7.1-21759B?logo=wordpress&logoColor=white" alt="WordPress Tested up to 7.1">
</p>

<p align="center">
  <a href="https://github.com/evoxup/evoxup-membership/actions/workflows/php-syntax.yml">
    <img src="https://github.com/evoxup/evoxup-membership/actions/workflows/php-syntax.yml/badge.svg?branch=main" alt="PHP Syntax Check">
  </a>
  <a href="https://github.com/evoxup/evoxup-membership/actions/workflows/phpstan.yml">
    <img src="https://github.com/evoxup/evoxup-membership/actions/workflows/phpstan.yml/badge.svg?branch=main" alt="PHPStan Static Analysis">
  </a>
  <a href="https://github.com/evoxup/evoxup-membership/actions/workflows/wordpress-plugin-check.yml">
    <img src="https://github.com/evoxup/evoxup-membership/actions/workflows/wordpress-plugin-check.yml/badge.svg?branch=main" alt="WordPress Plugin Check">
  </a>
</p>

<p align="center">
  <a href="https://evoxup.com/evo-membership/"><strong>Website</strong></a>
  ·
  <a href="https://github.com/evoxup/evoxup-membership/releases/latest"><strong>Download</strong></a>
  ·
  <a href="https://github.com/evoxup/evoxup-membership/tree/main/docs"><strong>Documentation</strong></a>
  ·
  <a href="https://github.com/evoxup/evoxup-membership/issues"><strong>Issues</strong></a>
  ·
  <a href="https://github.com/evoxup/evoxup-membership/security/policy"><strong>Security</strong></a>
</p>

---

## Overview

**Evoxup Membership** is a WordPress platform for managing memberships, licensing, customers, products, membership plans, entitlements, commerce fulfillment, WooCommerce integrations, verified webhooks, and external service integrations from one administration environment.

The public WordPress build is designed around a clear principle:

> **Every feature included in the plugin is available without a paid license, paid membership, trial, quota, or activation key.**

License or membership keys may be used to verify eligibility for separately distributed commercial Evoxup packages or external services, but they do not unlock functionality that is already included in the public plugin.

Current release:

**Evoxup Membership 1.8.6**

---

## Key Features

### Membership Management

- Membership plans and lifecycle management
- Product-to-plan relationships
- Membership status, start date, and expiration handling
- Member entitlement management
- Membership assignment through verified fulfillment flows

### EVO Products

EVO Products act as the canonical commercial identity inside Evoxup.

A product can be linked to:

- One or more Membership Plans
- WooCommerce products
- External commerce providers
- EVO licensing
- Supported external licensing providers
- Entitlements
- Purchase and fulfillment workflows

This avoids creating a separate duplicate product model for every integration.

### Licensing

Evoxup Membership can issue and manage EVO licenses for products configured to use EVO licensing.

License verification can evaluate:

- Product identity
- License status
- Activation/site binding
- Membership entitlement
- Domain/site information
- Configured access tier
- Client version
- Other configured verification requirements

When another licensing provider is selected, Evoxup can preserve that provider's ownership of the licensing flow instead of generating a duplicate EVO license.

### Entitlements

Entitlements connect products, plans, memberships, licenses, and access rights.

A verified purchase can:

1. Resolve the provider product.
2. Map it to an EVO Product.
3. Create or update the Evoxup customer/member record.
4. Grant the configured Membership Plan.
5. Issue an EVO license when EVO licensing is selected.
6. Create the required entitlements.
7. Record the normalized order or transaction.

---

## Member Administration

Starting with **1.8.6**, Evoxup Member Administration `1.1.0` is provided as a bundled internal module instead of being permanently embedded in Core.

Module:

```text
modules/evomembers-member-administration-lite/
```

The module can be enabled or disabled from:

```text
Evoxup → Extensions & Add-ons
```

When enabled, it provides the Customers administration workspace for managing WordPress users and EVO member records.

Capabilities include:

- Customer/member directory
- Existing WordPress user linking
- Member profile management
- Membership operations
- License and activation context
- Entitlement visibility
- Order context
- Search and filtering
- Activity information

Disabling the module does not remove member, membership, license, order, entitlement, or event data.

Sites upgrading from 1.8.5 receive a one-time migration that preserves the existing Customers workspace behavior.

---

## Bundled Modules

Evoxup Membership includes optional local Lite modules.

Current bundled modules include:

```text
evomembers-account-lite
evomembers-analytics-lite
evomembers-content-access-lite
evomembers-member-administration-lite
evomembers-social-identity-lite
evomembers-system-health-lite
```

Bundled modules:

- Run locally
- Are included with the public plugin
- Can be enabled or disabled by an administrator
- Do not require a paid key
- Use the Evoxup extension/module architecture

Commercial PRO and SUPER STAR add-ons displayed by the administration interface are separate products. Their executable code is not bundled into this repository's public WordPress package.

---

## WooCommerce Integration

WooCommerce is optional.

When WooCommerce is installed, administrators can map WooCommerce products to EVO Products and configure how purchases should be fulfilled.

A successful WooCommerce order can trigger:

```text
WooCommerce Order
       ↓
EVO Product
       ↓
Customer / Member
       ↓
Membership Plan
       ↓
License
       ↓
Entitlements
```

When EVO licensing is selected, Evoxup can issue the corresponding EVO license.

When another licensing provider is configured, Evoxup respects that provider instead of issuing a duplicate license.

Evoxup Membership uses supported WooCommerce APIs and supports the integration paths used with:

- WooCommerce HPOS
- Cart/Checkout Blocks

---

## Verified Webhooks

Evoxup Membership can receive authenticated commerce events from configured providers.

Verified events can:

- Resolve an external product to an EVO Product
- Create or update an Evoxup customer/member
- Create or update a normalized order
- Grant memberships
- Update memberships
- Cancel or revoke memberships
- Create entitlements
- Revoke entitlements
- Issue EVO licenses when configured
- Process supported refund and cancellation events

Webhook payloads are fulfillment inputs.

They cannot arbitrarily:

- Create privileged WordPress administrator accounts
- Choose privileged WordPress roles
- Install plugins
- Remove plugins
- Install themes
- Change arbitrary WordPress settings
- Execute arbitrary PHP
- Execute arbitrary SQL
- Perform arbitrary filesystem operations

Remote fulfillment does not create WordPress users.

If a matching WordPress account already exists, Evoxup can link it through the supported identity workflow.

---

## Universal Integrations

Evoxup uses a provider-neutral integration model.

External providers can participate in commerce or licensing flows without becoming hard dependencies of Core.

The architecture is designed to support:

- WooCommerce
- External commerce providers
- External licensing providers
- Verified webhook providers
- External API services
- Future adapters using supported APIs and hooks

Core functionality does not depend on one specific external commerce provider.

---

## External API

Administrators can optionally configure an external HTTPS API endpoint.

When External or Hybrid API mode is enabled, Evoxup can perform server-to-server requests to the administrator-configured endpoint.

Depending on the requested operation, transmitted data can include:

- Client identifier
- Configured service credential
- Correlation/request identifier
- Node identifier
- License key
- Product identifier
- Site URL/domain
- Client version
- Licensing-operation data

Plain HTTP endpoints are rejected for supported external API configuration.

Evoxup Membership does not require an Evoxup external service for normal local operation.

---

## Identity Model

A WordPress user and an EVO member record are separate identities.

When an existing WordPress account matches an EVO member, Evoxup can link them while preserving the native WordPress user ID.

This design prevents remote commerce fulfillment from becoming a WordPress account-provisioning mechanism.

---

## Architecture

The project is organized into several distinct layers.

```text
evoxup-membership/
│
├── api/
│   └── V1/
│
├── assets/
│
├── docs/
│
├── Extensions/
│
├── modules/
│   ├── evomembers-account-lite/
│   ├── evomembers-analytics-lite/
│   ├── evomembers-content-access-lite/
│   ├── evomembers-member-administration-lite/
│   ├── evomembers-social-identity-lite/
│   └── evomembers-system-health-lite/
│
├── src/
│   ├── Admin/
│   ├── API/
│   ├── Contracts/
│   ├── Core/
│   ├── Editor/
│   ├── Frontend/
│   ├── Infrastructure/
│   ├── Integrations/
│   ├── Providers/
│   ├── SDK/
│   └── Services/
│
├── CHANGELOG.md
├── LICENSE
├── README.md
├── evoxup-membership.php
├── readme.txt
└── uninstall.php
```

### Core

Core provides shared infrastructure such as:

- Database management
- Capability definitions
- Module loading
- Extension context
- Version compatibility
- Installation and upgrade handling
- Security/request guards
- Core hooks

### Services

The Services layer contains business operations including:

- Customers
- Memberships
- Products
- Plans
- Orders
- Licenses
- Entitlements
- Integrations
- Notifications
- Mapping
- Purchase links
- Fulfillment routing

### SDK

The SDK exposes structured APIs for supported Evoxup operations.

Examples include:

- Product API
- Customer API
- Membership API
- License API
- Entitlement API
- Order API
- Plan API
- Notification API
- Capability API
- Purchase Link API
- Platform APIs

### Contracts

Interfaces define stable boundaries between Core, services, integrations, extensions, and SDK implementations.

---

## Fulfillment Architecture

Evoxup separates commerce detection from fulfillment.

```text
Commerce Provider
       ↓
Verified Event / Order
       ↓
Mapping
       ↓
EVO Product
       ↓
Fulfillment Router
       ↓
┌────────────────────────────┐
│ Membership                 │
│ License                    │
│ Entitlements               │
│ Customer / Member          │
│ Order / Transaction        │
│ Module fulfillment         │
│ External-license handling  │
└────────────────────────────┘
```

This allows supported commerce and licensing providers to participate without requiring Core to be rewritten around a single provider.

---

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 6.5 or later |
| PHP | 8.3 or later |
| Tested up to | WordPress 7.1 |
| WooCommerce | Optional |
| License | GPL-2.0-or-later |

---

## Installation

### Recommended: GitHub Release Package

Go to:

**Releases → Latest Release**

Download:

```text
evoxup-membership-<version>.zip
```

For example:

```text
evoxup-membership-1.8.6.zip
```

Then:

1. Open WordPress Admin.
2. Go to **Plugins → Add New Plugin**.
3. Select **Upload Plugin**.
4. Upload the Evoxup Membership ZIP.
5. Install.
6. Activate the plugin.
7. Open the Evoxup administration area.

> Do not use GitHub's automatically generated **Source code (zip)** as the WordPress installation package. Use the versioned Evoxup Membership ZIP attached to the GitHub Release.

---

## Release Integrity

Official GitHub releases include a SHA-256 checksum file:

```text
evoxup-membership-<version>.zip.sha256
```

Example:

```text
evoxup-membership-1.8.6.zip.sha256
```

This can be used to verify that the downloaded package matches the artifact created by the release workflow.

---

## Automated Release Builds

Release packages are built automatically by GitHub Actions.

The release workflow:

1. Validates the release tag.
2. Verifies the plugin version.
3. Verifies the `readme.txt` stable tag.
4. Runs PHP syntax validation.
5. Prepares a WordPress distribution package.
6. Excludes development-only files.
7. Creates the release ZIP.
8. Generates a SHA-256 checksum.
9. Validates the ZIP structure.
10. Uploads the final package to the GitHub Release.

Development-only files such as the following are not intended to be included in the WordPress release package:

```text
.git/
.github/
phpstan.neon.dist
phpstan-bootstrap.php
vendor/
node_modules/
```

---

## Quality Assurance

The `main` branch is checked automatically.

### PHP Syntax Check

Every PHP file is validated for syntax errors.

[View workflow](https://github.com/evoxup/evoxup-membership/actions/workflows/php-syntax.yml)

### PHPStan Static Analysis

Static analysis is performed using PHPStan.

[View workflow](https://github.com/evoxup/evoxup-membership/actions/workflows/phpstan.yml)

### WordPress Plugin Check

The project is checked using the WordPress Plugin Check workflow.

[View workflow](https://github.com/evoxup/evoxup-membership/actions/workflows/wordpress-plugin-check.yml)

### Release Build

Official GitHub release packages are created by:

[Build Release Package](https://github.com/evoxup/evoxup-membership/actions/workflows/release-build.yml)

---

## Development Files

The repository includes files used for development and continuous integration.

Examples:

```text
.github/
phpstan.neon.dist
phpstan-bootstrap.php
```

These files are useful for development but are excluded from the official WordPress release package where appropriate.

---

## Repository Protection

The `main` branch uses repository protection rules.

The project is configured to protect the stable branch from accidental destructive changes such as:

- Branch deletion
- Force pushes
- Invalid updates

Project maintainers should preserve these protections except during carefully controlled repository maintenance.

---

## Documentation

Developer and architecture documentation is available in:

```text
docs/
```

Online:

https://github.com/evoxup/evoxup-membership/tree/main/docs

Additional extension documentation is available under:

```text
Extensions/
```

---

## Releases

Latest release:

https://github.com/evoxup/evoxup-membership/releases/latest

All releases:

https://github.com/evoxup/evoxup-membership/releases

Current stable release:

```text
1.8.6
```

---

## Version 1.8.6 Highlights

Evoxup Membership 1.8.6 includes:

- Member Administration `1.1.0` moved from the always-on Core workspace to a bundled internal module.
- Local Enable / Disable control for Member Administration.
- Upgrade migration preserving the Customers workspace for installations upgrading from 1.8.5.
- Member, membership, license, order, entitlement, and event data preserved independently from the module enabled state.
- Corrected `block_categories_all` callback registration.
- Simplified legacy table-prefix migration error initialization.
- Updated bundled module compatibility metadata.
- Database schema remains `3.1.1`.
- Extension API remains `2.1.0`.
- PHP Syntax Check passing.
- PHPStan Static Analysis passing.
- WordPress Plugin Check passing.

See:

[CHANGELOG.md](https://github.com/evoxup/evoxup-membership/blob/main/CHANGELOG.md)

---

## Updating

For the public WordPress distribution, Core updates are intended to follow the normal WordPress plugin update path.

Separately distributed Evoxup packages can use their own documented distribution and entitlement mechanisms.

Commercial package eligibility does not restrict features already included in Evoxup Membership Core.

---

## Security

Security issues should **not** be submitted as public GitHub Issues when they contain vulnerability details.

Please review the security policy:

https://github.com/evoxup/evoxup-membership/security/policy

Security documentation:

[SECURITY.md](https://github.com/evoxup/evoxup-membership/blob/main/.github/SECURITY.md)

---

## Reporting Bugs

Before opening an issue:

1. Confirm you are using the latest stable release.
2. Reproduce the issue with the relevant integration configuration.
3. Collect non-sensitive error details.
4. Do not include passwords, API secrets, private license keys, or customer-sensitive data.

Open a bug report:

https://github.com/evoxup/evoxup-membership/issues/new/choose

---

## Feature Requests

Feature ideas are welcome through the GitHub issue templates.

Submit a request:

https://github.com/evoxup/evoxup-membership/issues/new/choose

Feature proposals should explain:

- The use case
- The problem being solved
- Expected behavior
- Compatibility considerations
- Whether the feature belongs in Core, a bundled module, or a separate integration/add-on

---

## Contributing

Contributions are welcome.

Please read:

[CONTRIBUTING.md](https://github.com/evoxup/evoxup-membership/blob/main/.github/CONTRIBUTING.md)

Typical contribution workflow:

```text
Fork repository
      ↓
Create feature/fix branch
      ↓
Make focused changes
      ↓
Run checks
      ↓
Open Pull Request
      ↓
Review
      ↓
Merge
```

Please avoid mixing unrelated architectural changes into a single Pull Request.

---

## Development Principles

Evoxup Membership follows several architectural principles.

### WordPress Native

Core functionality should use supported WordPress APIs, hooks, permissions, database APIs, HTTP APIs, filesystem APIs, and extension mechanisms.

### Provider Neutral

Core commerce and licensing behavior should not be permanently tied to one external provider.

### Modular

Optional capabilities should be isolated into modules or integrations where appropriate.

### Secure by Default

Sensitive actions should use:

- Capability checks
- Nonces where applicable
- Input validation
- Sanitization
- Escaping
- Authenticated integrations
- Verified webhooks
- HTTPS for supported external API configuration

### Stable Core Boundaries

Extensions and integrations should use public contracts, APIs, hooks, and supported interfaces rather than directly modifying unrelated Core internals.

### Local First

Features included in the public plugin should remain usable without requiring a paid remote Evoxup service.

---

## Free and Commercial Boundary

The public Evoxup Membership plugin does not use a paid key to unlock functionality already shipped in the plugin.

Bundled Lite modules are included functionality.

Separate PRO or SUPER STAR products can be distributed independently.

In short:

```text
Included in public plugin
        =
Available without paid unlock
```

while:

```text
Separately distributed commercial package
        =
Can use separate licensing / entitlement rules
```

---

## Support

Project website:

https://evoxup.com/evo-membership/

GitHub Issues:

https://github.com/evoxup/evoxup-membership/issues

Documentation:

https://github.com/evoxup/evoxup-membership/tree/main/docs

---

## Support Evoxup

Evoxup Membership is free software.

If the project is useful to you and you would like to support continued development, an optional contribution can be made at:

https://evoxup.com/donate/

Donations are optional and do not unlock features, memberships, licenses, updates, or functionality already included in the public plugin.

---

## License

Evoxup Membership is licensed under the **GNU General Public License v2.0 or later**.

See:

[LICENSE](https://github.com/evoxup/evoxup-membership/blob/main/LICENSE)

```text
GPL-2.0-or-later
```

---

## Project Links

| Resource | Link |
|---|---|
| Website | https://evoxup.com/evo-membership/ |
| Repository | https://github.com/evoxup/evoxup-membership |
| Latest Release | https://github.com/evoxup/evoxup-membership/releases/latest |
| Releases | https://github.com/evoxup/evoxup-membership/releases |
| Documentation | https://github.com/evoxup/evoxup-membership/tree/main/docs |
| Issues | https://github.com/evoxup/evoxup-membership/issues |
| Security | https://github.com/evoxup/evoxup-membership/security/policy |
| Contributing | https://github.com/evoxup/evoxup-membership/blob/main/.github/CONTRIBUTING.md |
| Changelog | https://github.com/evoxup/evoxup-membership/blob/main/CHANGELOG.md |
| Donate | https://evoxup.com/donate/ |

---

<p align="center">
  <strong>Evoxup Membership</strong><br>
  Membership • Licensing • Products • Entitlements • WooCommerce • Integrations
</p>

<p align="center">
  <a href="https://evoxup.com/evo-membership/">evoxup.com</a>
  ·
  <a href="https://github.com/evoxup/evoxup-membership/releases/latest">Latest Release</a>
  ·
  <a href="https://github.com/evoxup/evoxup-membership/issues">Report an Issue</a>
</p>
