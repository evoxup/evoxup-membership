# Evoxup Membership

[![Latest Release](https://img.shields.io/github/v/release/evoxup/evoxup-membership?display_name=tag&sort=semver)](https://github.com/evoxup/evoxup-membership/releases/latest)
[![Downloads](https://img.shields.io/github/downloads/evoxup/evoxup-membership/total)](https://github.com/evoxup/evoxup-membership/releases)
[![WordPress](https://img.shields.io/badge/WordPress-Plugin-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-Compatible-96588A?logo=woocommerce&logoColor=white)](https://woocommerce.com/)
[![License](https://img.shields.io/badge/License-GPL--2.0%2B-blue)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Last Commit](https://img.shields.io/github/last-commit/evoxup/evoxup-membership)](https://github.com/evoxup/evoxup-membership/commits/main)

**Professional WordPress membership, licensing, WooCommerce integration, entitlement management, and extensible commerce platform.**

[Website](https://evoxup.com/) ·
[Evoxup Membership](https://evoxup.com/evo-membership/) ·
[Latest Release](https://github.com/evoxup/evoxup-membership/releases/latest) ·
[Documentation](https://github.com/evoxup/evoxup-membership/tree/main/docs) ·
[Issues](https://github.com/evoxup/evoxup-membership/issues)

---

## About Evoxup Membership

**Evoxup Membership** is a professional WordPress-native platform for managing memberships, products, membership plans, licenses, customers, entitlements, integrations, fulfillment workflows, and commerce-related access.

The platform is designed around a modular and extensible architecture that allows the core membership and licensing system to remain stable while additional capabilities can be introduced through extensions, integrations, providers, APIs, and WordPress hooks.

Evoxup Membership can support multiple types of commercial and membership workflows, including:

- Membership products
- Membership plans
- Licensed WordPress products
- WooCommerce products
- External commerce providers
- Internal Evoxup products
- Free and paid products
- Entitlement-based access
- Modular extensions
- External license providers

---

## Key Features

### Membership Management

- Create and manage memberships
- Associate customers with memberships
- Membership plan management
- Membership status handling
- Membership-aware entitlements
- Membership lifecycle infrastructure
- Customer and member administration

### Products & Plans

- Evoxup product management
- Membership plan management
- Product-to-plan relationships
- Product-specific licensing
- Purchase links
- External product mappings
- Commerce provider integration
- Flexible fulfillment routing

### Licensing

- License generation
- License verification
- Product-aware licenses
- Membership-aware licenses
- License provider architecture
- Internal and external licensing support
- Entitlement validation
- Extensible license verification workflows

### Entitlements

- Centralized entitlement management
- Product-based access
- Membership-based access
- Plan-based access
- License-aware permissions
- Extension-aware capabilities
- Flexible access rules

### WooCommerce Integration

- WooCommerce product integration
- Product licensing support
- WooCommerce fulfillment
- Customer synchronization workflows
- Commerce-independent membership architecture
- Optional WooCommerce integration without making WooCommerce a core dependency

### External Integrations

- External commerce providers
- Webhook provider interfaces
- License provider interfaces
- REST API integrations
- Provider adapters
- WordPress hooks and filters
- Modular integration architecture

### REST API

Evoxup Membership includes a REST API architecture for working with platform capabilities programmatically.

Supported API areas include:

- Products
- Plans
- Memberships
- Customers
- Orders
- Licenses
- Entitlements
- Purchase links
- Notifications
- Events
- Audit information
- Platform extensions

### Developer SDK

The project includes an SDK layer designed to provide structured access to Evoxup Membership capabilities.

SDK components include APIs for:

- Audit
- Capabilities
- Customers
- Entitlements
- Events
- Licenses
- Memberships
- Notifications
- Orders
- Plans
- Products
- Purchase links
- Extension registry
- Platform storage

---

## Architecture

Evoxup Membership follows a modular WordPress-native architecture.

```text
evoxup-membership/
│
├── Extensions/
├── api/
│   └── V1/
├── assets/
├── docs/
├── modules/
├── src/
│   ├── API/
│   ├── Admin/
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
├── evoxup-membership.php
├── readme.txt
├── CHANGELOG.md
└── uninstall.php
```

The architecture separates platform responsibilities into independent layers rather than placing all functionality into a single monolithic plugin class.

---

## Membership Flow

A typical Evoxup Membership commercial workflow can be represented as:

```text
Sale
  ↓
Product / Membership Plan
  ↓
Membership
  ↓
License
  ↓
Entitlements
  ↓
Access / Fulfillment
```

Products, plans, memberships, licenses, and entitlements are treated as distinct platform concepts.

This provides greater flexibility for commerce integrations and allows Evoxup Membership to work with multiple providers without making the core architecture dependent on a single commerce platform.

---

## Product & Plan Model

An Evoxup Product represents the commercial product inside the platform.

A product may be connected to:

- One or more membership plans
- WooCommerce products
- External commerce products
- Licensing providers
- Purchase links
- Entitlements
- Fulfillment workflows

Membership plans remain separate from products so the platform can support flexible relationships between commercial products and membership access.

---

## Licensing Architecture

Evoxup Membership includes a provider-neutral licensing architecture.

Licensing logic is separated from commerce logic, allowing different products to use different licensing strategies.

The platform can support:

```text
Product
  ↓
License Provider
  ↓
License
  ↓
Verification
  ↓
Entitlements
```

The licensing architecture is designed so external providers can be integrated without replacing or tightly coupling the Evoxup core licensing system.

---

## Fulfillment

Evoxup Membership includes a fulfillment layer capable of routing fulfillment according to the product and integration configuration.

The project includes fulfillment components for:

- Evoxup fulfillment
- Module fulfillment
- WooCommerce fulfillment
- External license fulfillment
- Combined fulfillment

This architecture allows multiple fulfillment mechanisms to coexist.

---

## Provider-Neutral Integrations

Evoxup Membership is designed to avoid hard dependency on a single external commerce or licensing provider.

Integrations can be implemented through:

- Provider adapters
- Webhooks
- REST APIs
- WordPress hooks
- Extension modules
- License provider interfaces
- Webhook provider interfaces

This keeps the core platform portable and extensible.

---

## WordPress-Native Integration

Evoxup Membership is built as a WordPress-native platform.

It uses WordPress APIs and integration mechanisms where appropriate, including:

- Actions
- Filters
- REST API
- WordPress users
- WordPress mail
- WordPress administration
- WordPress capabilities
- WordPress plugin lifecycle
- WordPress scheduling infrastructure

Optional integrations should remain optional and should not become required dependencies for the core platform.

---

## WooCommerce

WooCommerce integration is optional.

When WooCommerce is available, Evoxup Membership can connect commerce activity with membership, licensing, products, plans, customers, and fulfillment workflows.

WooCommerce remains the commerce layer while Evoxup Membership manages membership and licensing responsibilities.

This separation helps avoid coupling the entire membership platform to WooCommerce.

---

## Modular Extensions

Evoxup Membership supports modular functionality through its extension architecture.

Optional functionality can be isolated from the core platform and loaded only when required.

This architecture helps:

- Keep the core stable
- Reduce unnecessary dependencies
- Improve maintainability
- Support optional features
- Enable future integrations
- Provide clear extension boundaries

---

## Included Modules

The repository includes modular components for additional functionality.

Examples include:

- Account functionality
- Analytics
- Content access
- Social identity
- System health

Modules are designed to extend the platform without forcing unrelated functionality into the core.

---

## Security

Evoxup Membership includes infrastructure intended to support secure membership and licensing workflows.

Security-related architecture includes:

- Request validation
- Capability checks
- License verification
- REST authentication infrastructure
- Cryptographic utilities
- Audit trail support
- Controlled provider interfaces
- WordPress-native permission handling

If you discover a security vulnerability, please do **not** publish sensitive vulnerability information in a public GitHub issue.

Use the official Evoxup communication channels for responsible disclosure.

---

## Audit & Events

The platform includes services for audit and event handling.

These components allow important platform operations to be recorded and routed through structured workflows.

The architecture includes:

- Audit trail services
- Event bus
- Event catalog
- Event message routing
- Notifications
- Platform activity infrastructure

---

## Requirements

Recommended production environment:

- WordPress
- PHP 8.2/8.3+ or newer
- HTTPS for production websites
- MySQL/MariaDB supported by the installed WordPress version

WooCommerce is **optional** and is required only when WooCommerce-specific functionality is used.

---

## Installation

### Download the Official Release

Download the latest installation package from:

**[Download the Latest Evoxup Membership Release](https://github.com/evoxup/evoxup-membership/releases/latest)**

For version **1.8.5**, use the official release asset:

```text
evoxup-membership-1.8.5.zip
```

Do not confuse the official installation package with GitHub's automatically generated:

```text
Source code (zip)
Source code (tar.gz)
```

The versioned Evoxup Membership ZIP is the intended WordPress installation package.

### Install in WordPress

From the WordPress administration dashboard:

```text
Plugins
→ Add Plugin
→ Upload Plugin
→ Choose evoxup-membership ZIP
→ Install Now
→ Activate
```

After activation, configure Evoxup Membership from the WordPress administration interface.

---

## Current Release

### Evoxup Membership 1.8.5

Version `1.8.5` is available from GitHub Releases.

**[View Latest Release](https://github.com/evoxup/evoxup-membership/releases/latest)**

**[View All Releases](https://github.com/evoxup/evoxup-membership/releases)**

GitHub automatically provides source archives for each tag, while the official WordPress-ready package is distributed as a release asset.

---

## Documentation

Developer and architecture documentation is available in the repository:

**[Browse Documentation](https://github.com/evoxup/evoxup-membership/tree/main/docs)**

Product information is available at:

**[Evoxup Membership Website](https://evoxup.com/evo-membership/)**

---

## Repository Structure

The main repository contains the public Evoxup Membership codebase.

Important locations include:

| Directory | Purpose |
|---|---|
| `Extensions/` | Extension-related resources |
| `api/V1/` | REST API routes |
| `assets/` | Frontend and administration assets |
| `docs/` | Technical and developer documentation |
| `modules/` | Modular platform components |
| `src/API/` | API client and configuration |
| `src/Admin/` | WordPress administration components |
| `src/Contracts/` | Platform interfaces and contracts |
| `src/Core/` | Core platform infrastructure |
| `src/Integrations/` | WordPress and commerce integrations |
| `src/Providers/` | Provider registries |
| `src/SDK/` | Developer SDK |
| `src/Services/` | Membership platform services |

---

## Development Principles

Evoxup Membership follows several architectural principles.

### Stable Core

Core membership, licensing, entitlement, and product functionality should remain under Evoxup control.

### Optional Integrations

Third-party systems should be integrated through optional adapters, providers, hooks, and APIs rather than mandatory core dependencies.

### Provider Neutrality

The platform should support multiple commerce and licensing providers without hard-coding the entire system around a single provider.

### WordPress Compatibility

Integrations should use WordPress public APIs and established WordPress extension mechanisms.

### Extensibility

New functionality should be capable of being introduced through clearly defined interfaces and extensions.

### Separation of Responsibilities

Commerce, membership, licensing, entitlements, notifications, and fulfillment should remain conceptually separated even when working together.

---

## For Developers

Developers extending Evoxup Membership should prefer the public platform APIs, contracts, hooks, providers, and SDK interfaces rather than directly modifying internal implementation details.

Available architectural extension points include:

- WordPress actions
- WordPress filters
- REST APIs
- Platform contracts
- Provider interfaces
- Extension registry
- SDK APIs
- Event infrastructure

See the documentation directory for additional technical information.

---

## Issues & Feedback

Bug reports, compatibility reports, and constructive feedback are welcome.

**[Open an Issue](https://github.com/evoxup/evoxup-membership/issues)**

When reporting a problem, include useful diagnostic information when possible:

- Evoxup Membership version
- WordPress version
- PHP version
- Relevant integration
- Error message
- Reproduction steps

Please do not include passwords, license keys, API secrets, private customer information, or other sensitive data in public issues.

---

## Releases

Official releases are published through GitHub Releases:

**https://github.com/evoxup/evoxup-membership/releases**

Release packages may include:

```text
evoxup-membership-x.x.x.zip
```

GitHub also automatically generates source archives for every release tag.

---

## License

Evoxup Membership is distributed under the licensing terms declared by the project and plugin package.

The public WordPress-compatible code is intended to remain compatible with the applicable WordPress licensing requirements.

---

## Official Links

- **Evoxup:** https://evoxup.com/
- **Evoxup Membership:** https://evoxup.com/evo-membership/
- **GitHub Repository:** https://github.com/evoxup/evoxup-membership
- **Latest Release:** https://github.com/evoxup/evoxup-membership/releases/latest
- **All Releases:** https://github.com/evoxup/evoxup-membership/releases
- **Documentation:** https://github.com/evoxup/evoxup-membership/tree/main/docs
- **Issues:** https://github.com/evoxup/evoxup-membership/issues

---

## Evoxup

**Build. License. Manage. Extend.**

Evoxup Membership provides a flexible foundation for building professional WordPress membership, licensing, entitlement, and commerce-connected products.

© 2026 Evoxup
