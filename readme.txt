=== Evoxup Membership — Membership, Licensing, & Universal Integrations ===
Contributors: evoxupteam
Tags: membership, licensing, woocommerce, webhooks, integrations
Requires at least: 6.5
Requires PHP: 8.3
Tested up to: 7.1
Stable tag: 1.8.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Donate link: https://evoxup.com/donate/

Free membership and licensing for WordPress with products, plans, WooCommerce, secure webhooks, entitlements, and integrations.

== Description ==

Evoxup Membership is a WordPress platform for managing memberships, licensing, customers, products, entitlements, commerce fulfillment, and integrations from one administration area.

Every function included in the WordPress.org plugin is free to use. Evoxup Membership does not require a paid license, paid membership, trial, quota, or activation key to unlock functionality that is already included in the plugin.

A license or membership key may be used only to verify eligibility for separately distributed commercial Evoxup packages or external services that are not included in this WordPress.org plugin.

= What Evoxup Membership manages =

* EVO Products as the canonical product identity used by Evoxup.
* Membership Plans and Product-to-Plan relationships.
* Customers and members, while preserving the native WordPress user ID when a WordPress account is linked.
* Bundled Member Administration module that can be enabled or disabled from Extensions & Add-ons. When enabled, it lists native WordPress users and EVO members together and provides profile, membership, license, activation, order-context, entitlement, and activity management after an EVO member record exists.
* Membership lifecycle, status, start and expiration dates.
* EVO licenses, activations, verification rules, domains and entitlement state.
* Product and membership entitlements.
* Normalized order and transaction records.
* Purchase fulfillment from WooCommerce or verified external providers.
* Versioned REST/API integration surfaces for supported integrations.
* Optional bundled Lite modules that run locally and can be enabled or disabled by an administrator.

= Products, plans, members, and entitlements =

An EVO Product is the central commercial object. A Product can be linked to one or more Membership Plans, and a Membership Plan can include one or more Products.

A successful verified purchase can resolve the mapped EVO Product, create or update the Evoxup customer/member record, grant the configured Membership Plan, issue an EVO license when EVO licensing is selected, create entitlements, and record the normalized transaction. It does not create WordPress user accounts.

A WordPress account and an EVO member record remain separate identities. When a matching WordPress account already exists, Evoxup can link it to the EVO member record without replacing the native WordPress user ID.

= Licensing =

Evoxup can issue and manage EVO licenses for Products configured to use EVO licensing. License verification can use the Product, license status, activation/site binding, membership entitlement, and configured tier requirements.

If a Product is configured to use another supported licensing provider, Evoxup does not force EVO license issuance and can preserve that provider's ownership of the licensing flow.

Built-in Evoxup Membership functionality is never unlocked by these license checks.

= WooCommerce integration =

WooCommerce is optional.

When WooCommerce is installed, an administrator can map WooCommerce products to EVO Products and define how the purchase should be fulfilled. A successful WooCommerce order can trigger the configured Evoxup membership, entitlement, customer/member, and licensing workflow.

When EVO licensing is selected, Evoxup can issue the corresponding EVO license. When another provider is selected, Evoxup respects that provider instead of generating a duplicate EVO license.

Evoxup uses supported WooCommerce APIs and declares compatibility with WooCommerce HPOS and Cart/Checkout Blocks for the integration paths used by the plugin.

= Verified webhooks and universal integrations =

Evoxup can receive verified commerce events from configured external providers. Webhooks are fulfillment inputs, not a remote administration channel.

A verified provider event can:

* Resolve a provider product to an EVO Product.
* Create or update the Evoxup customer/member record.
* Create or update the normalized order record.
* Grant, update, cancel, or revoke the configured membership or entitlement according to the verified event.
* Issue an EVO license when EVO is the selected licensing provider.
* Process supported refund, cancellation, and revocation events.

Webhook payloads cannot choose privileged WordPress roles, install or remove plugins/themes, change arbitrary WordPress settings, execute PHP or SQL, or perform arbitrary filesystem operations.

Remote webhook fulfillment does not create WordPress users. If a matching WordPress account already exists, Evoxup may link it to the EVO member record by the supported identity-matching workflow.

= Free modules and optional add-ons =

Evoxup Membership 1.8.6 includes optional local Lite modules that can be enabled or disabled by an administrator from Extensions & Add-ons. These modules are bundled in the plugin ZIP and are fully functional without a paid key.

PRO and SUPER STAR add-ons shown on the Extensions & Add-ons screen are informational references to separately distributed products. Their executable code is not included in this plugin, is not downloaded by this plugin, and no built-in feature is locked behind an upgrade.

= Updates =

Evoxup Membership Core and the infrastructure bundled inside it update with the normal WordPress.org plugin update process. Core updates do not require an Evoxup license or membership key.

Separately distributed packages follow their own documented distribution/update channel. Entitlement for a commercial external package does not affect the free built-in functionality of Evoxup Membership.

== External Services ==

Evoxup Membership works locally without connecting to Evoxup or any paid service. It does not contact external servers automatically. Network requests occur only after a site administrator explicitly configures an external API/provider or follows a link to Evoxup.com.

= Administrator-configured external API =

An administrator may optionally enter an HTTPS external API base URL and credentials in Settings. When External or Hybrid API mode is selected, Evoxup Membership can send server-to-server HTTPS requests to that administrator-supplied endpoint. Plain HTTP endpoints are rejected.

Data sent can include the configured client ID, encrypted-at-rest service credential after decryption for transport, request correlation ID, node identifier, license key, product identifier, site URL/domain, client version, and other fields required by the requested licensing operation. These requests occur only after the administrator saves and enables that external configuration.

Because the destination is supplied by the site administrator, its operator, Terms of Use, and Privacy Policy depend on the selected service. The administrator is responsible for reviewing those policies before enabling the connection.

= Configured external commerce providers =

Administrators may configure authenticated webhook integrations for purchase fulfillment. Inbound events can contain provider order/product identifiers, customer/order information required for fulfillment, event status, timestamps, and authentication metadata. Unsigned webhook delivery is rejected unless a provider-specific verifier explicitly authenticates it.

If a configured provider requires outbound API requests, only the data required by that integration is sent after the administrator enables it. The site owner should review the provider's Terms of Use and Privacy Policy before configuration.

= Evoxup.com and GitHub links =

The plugin administration screens and documentation can contain optional links to https://evoxup.com/evo-membership, https://evoxup.com/donate/, and the official Evoxup Membership GitHub repository at https://github.com/evoxup/evoxup-membership.

Following these links is normal browser navigation initiated by the administrator. The plugin does not send site, customer, membership, license, or order data to Evoxup.com or GitHub in the background merely because these links are present.

= WooCommerce =

WooCommerce integration is local to the WordPress installation unless the site owner separately configures WooCommerce or another service to communicate externally. Evoxup Membership does not require an external Evoxup service merely to process a local WooCommerce order.

== Installation ==

1. Upload the `evoxup-membership` folder to `/wp-content/plugins/`, or install the plugin through the WordPress Plugins screen.
2. Activate **Evoxup Membership — Membership, Licensing, & Universal Integrations**.
3. Open the Evoxup administration menu.
4. Enable Member Administration from Extensions & Add-ons, then open Customers to manage WordPress users and EVO members.
5. Configure licensing rules for Products that use EVO licensing.
6. Optionally map WooCommerce products to EVO Products.
7. Optionally configure verified external providers/webhooks under Integrations.
8. Open Extensions & Add-ons to enable bundled Lite modules or view informational PRO / SUPER STAR add-on cards.

No paid key is required to use the functionality included in this plugin.

== Frequently Asked Questions ==

= Is Evoxup Membership free? =

Yes. Every feature included in the WordPress.org plugin is available without a paid license, paid membership, trial, quota, or feature-unlock key.

= Do I need WooCommerce? =

No. WooCommerce is optional. Evoxup Products, Membership Plans, customers/members, memberships, licensing, entitlements, APIs, and supported webhook integrations can be used independently of WooCommerce where applicable.

= What is an EVO Product? =

An EVO Product is Evoxup's canonical product identity. It can be mapped to WooCommerce or another configured provider without creating a separate duplicate product model for every integration.

= Can one Product belong to more than one Membership Plan? =

Yes. Evoxup supports Product-to-Plan relationships that allow a Product to participate in one or more plans, and plans can include multiple Products.

= Does a webhook create WordPress administrator accounts? =

No. Verified webhook fulfillment cannot choose privileged WordPress roles and does not create WordPress users. It creates or updates Evoxup customer/member data and may link an already-existing WordPress account through the supported identity flow.

= Are PRO and SUPER STAR features included but locked? =

No. Premium cards describe separate products whose executable code is not included in the WordPress.org ZIP. Nothing included in this plugin is locked behind payment.

= Does the WordPress.org plugin install external executable packages? =

No. The WordPress.org build does not install, update, roll back, or remove executable third-party add-on code from the Add-ons screen.

= Does Core require an Evoxup key to update? =

No. Evoxup Membership Core updates through WordPress.org and does not require an Evoxup license or membership key.

== Screenshots ==

1. Evoxup Membership installed and active in the WordPress Plugins screen.
2. Evoxup Membership dashboard — an illustrative overview of the unified membership, product, licensing, and integration workspace.
3. Memberships & Plans — manage membership levels, access rules, members, and linked products.
4. Create or review your EVO Products and Membership Plans.
5. Optionally map WooCommerce products to EVO Products.
6. EVO Products — configure products, licensing rules, membership relationships, and optional WooCommerce mappings.
7. Licenses — review license keys, activation status, customers, and verification activity.
8. Integrations — connect WooCommerce and supported providers, manage mappings, and configure verified webhooks.
9. Extensions & Add-ons — manage bundled Lite modules and discover optional PRO and SUPER STAR extensions.


== Support Evoxup ==

Evoxup Membership is free software. If the plugin is useful to you and you would like to support continued development, you can make an optional one-time contribution.

Donate: https://evoxup.com/donate/

Donations are optional and do not unlock features, licenses, memberships, updates, support tiers, or any functionality included in the WordPress.org plugin.

== Development and Source Code ==

Evoxup Membership is developed publicly on GitHub.

* Source code: https://github.com/evoxup/evoxup-membership
* Latest releases: https://github.com/evoxup/evoxup-membership/releases
* Issue tracker: https://github.com/evoxup/evoxup-membership/issues
* Developer documentation: https://github.com/evoxup/evoxup-membership/tree/main/docs
* Security policy: https://github.com/evoxup/evoxup-membership/security/policy
* Contributing guidelines: https://github.com/evoxup/evoxup-membership/blob/main/.github/CONTRIBUTING.md

Bug reports, compatibility reports, and contributions are welcome through the public GitHub repository.

Security vulnerabilities should not be reported through public issues. Please use the private vulnerability reporting process described in the Security Policy.

== Changelog ==

= 1.8.6 =
* Moved Evoxup Member Administration 1.1.0 from the always-on Core Customers workspace into the bundled internal `evomembers-member-administration-lite` module with local Enable / Disable control.
* Added a one-time migration that keeps the existing Customers workspace enabled when upgrading from 1.8.5, while preserving member, membership, license, order, entitlement, and event data when the module is disabled.
* Fixed the block category callback argument registration so it matches the callback signature and passes static analysis cleanly.
* Simplified legacy table-prefix migration error initialization without changing database migration behavior.
* Updated bundled module compatibility metadata for Evoxup Membership 1.8.6 while keeping database schema 3.1.1 and Extension API 2.1.0 unchanged.

= 1.8.5 =
* Fixed WordPress.org output escaping in the integrated Customers workspace status badges without changing member-management behavior.
* Merged Evoxup Member Administration 1.1.0 into the WordPress.org Core package as an integrated Customers workspace; no separate extension or executable add-on is required.
* Added member profile editing, membership operations, license/activation operations, entitlement view, order context, search/filtering, and private activity notes behind existing Evoxup capabilities and nonces.
* Preserved the public-build account boundary: remote fulfillment does not create WordPress users; the integrated admin workspace links only existing local WordPress accounts.
* Kept WordPress.org-safe distribution: no third-party executable package installer, no premium code bundle, and no license gate for included functionality.

= 1.8.4 =
* Added bundled Account Lite, Content Access Lite, Analytics Lite, Social Identity Lite, and System Health Lite modules with local Enable / Disable controls.
* Rebuilt Extensions & Add-ons with a dedicated FREE / PRO / SUPER STAR presentation while keeping premium executable code outside the WordPress.org ZIP.
* Shortened the readme short description to remain within the WordPress.org 150-character limit.
* Standardized current WordPress capability identifiers on the `evomembers_` prefix.

= 1.8.3 =
* Restored a visible optional Support / Donate link inside Evoxup administration pages and in the WordPress.org readme.
* Donations remain completely optional and do not unlock or restrict any plugin functionality.

= 1.8.2 =
* Restored the bundled Marketplace visual interface using WordPress enqueue APIs after the WordPress.org compliance refactor.
* Kept Marketplace as a catalog/entitlement interface for separately distributed packages without restoring runtime executable-package installation.
* Updated the public product identity to **Evoxup Membership — Membership, Licensing, & Universal Integrations** and refreshed WordPress.org-facing documentation.

= 1.8.1 =
* Completed the main WordPress.org compliance pass for remote fulfillment, executable-package handling, script loading, audit sanitization, and external-service disclosure.
* Verified webhooks continue to create/update EVO member, membership, entitlement, order, and license records but no longer create WordPress users remotely.
* Removed Core-managed runtime install/update/rollback/uninstall of executable third-party add-ons and legacy custom executable storage paths.

= 1.8.0 =
* Clarified that every built-in Evoxup Membership function is free and that Core updates use the normal WordPress.org updater.
* Defined the boundary between free built-in functionality and separately distributed commercial packages/services.
* Clarified WooCommerce fulfillment, verified webhook integration, and free/external package access policy.

= 1.7.9 =
* Fixed managed update filesystem initialization and moved temporary update/rollback staging into WordPress-managed upgrade storage.
* Preserved signed download verification, post-update identity/version checks, restore points, and automatic rollback behavior for the pre-compliance update architecture.

= 1.7.8 =
* Resolved the remaining Plugin Check input-sanitization findings in local extensions administration requests.
* No membership, entitlement, Repository, or product-model behavior changed in this maintenance release.

= 1.7.7 =
* Completed a broad WordPress.org remediation pass covering escaping, sanitization, nonce handling, translator comments, filesystem helpers, and rollback-storage location.
* Removed obsolete Remote Runtime/generated Cloud Proxy customer execution paths while preserving the membership/licensing model and Repository entitlement behavior.

== Upgrade Notice ==

= 1.8.6 =
Moves Member Administration 1.1.0 from the always-on Core workspace into a bundled internal module, preserving the existing Customers workspace through a one-time migration and including static-analysis compatibility fixes.

= 1.8.5 =
Integrates Member Administration directly into Evoxup Membership and removes the need for a separate member-administration extension in the public build.

= 1.8.4 =
Adds bundled Lite modules and the redesigned WordPress.org-safe Extensions & Add-ons catalog.

= 1.8.3 =
Adds the optional Evoxup support/donation link while preserving WordPress.org compliance fixes.