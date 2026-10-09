# Evoxup Membership Changelog

## 1.8.6
- Moved Evoxup Member Administration 1.1.0 from the always-on Core Customers workspace into the bundled internal `evomembers-member-administration-lite` module.
- Added local Enable / Disable control through the existing Extensions & Add-ons module system; no separate plugin, remote executable download, or license gate is required.
- Added a one-time upgrade migration that auto-enables the internal Member Administration module for sites upgrading from the prior 1.8.5 integrated Customers workspace.
- Preserved customer/member, membership, license, activation, order, entitlement, and event data independently of the module enabled state.
- Fixed the `block_categories_all` callback registration so the accepted argument count matches the callback signature.
- Simplified legacy table-prefix migration error initialization without changing migration behavior.
- Updated bundled module compatibility metadata for Evoxup Membership 1.8.6.
- Kept database schema 3.1.1 and Extension API 2.1.0 unchanged.

## 1.8.5
- Fixed WordPress.org output escaping in the integrated Customers workspace status badges without changing member-management behavior.
- Fixed Customers so it is a unified local directory of WordPress users and EVO customer records; native WordPress accounts, including administrators, are visible before an EVO record exists.
- Added an explicit local “Add as EVO member” action that links an existing WordPress account without granting a membership, license, or entitlement automatically.
- Added email-based identity matching so an existing EVO customer and WordPress user are shown as one directory entry before they are formally linked.
- Merged Evoxup Member Administration 1.1.0 directly into the WordPress.org Core package.
- Replaced the separate extension dependency with an integrated Customers workspace using Core services, existing Evoxup capabilities, and WordPress nonce protection.
- Added profile editing, membership operations, license and activation operations, entitlement visibility, order context, member search/filtering, and private administration notes.
- Preserved the WordPress.org public-build boundary: no remote WordPress-account creation, no third-party executable package installation, and no license gate for included functionality.
- Kept database schema 3.1.1 and Extension API 2.1.0 unchanged.

## 1.8.4
- Reorganized Extensions & Add-ons into Installed, Available / FREE, Premium / PRO, and Premium / SUPER STAR tabs.
- Premium Learn more links now open https://evoxup.com/evo-membership.
- Upgraded Dashboard into a unified administration hub linking every primary Evoxup page, status surface, quick action, and local Lite module.
- Added manifest-driven Lite module discovery so future bundled modules can surface automatically in Dashboard and Extensions without hard-coded page wiring.
- Added Details links for every Available Free module and an Open action when a module exposes its own administration page.
- Added five optional bundled Lite modules: Account, Content Access, Analytics, Social Identity, and System Health.
- Rebuilt Extensions & Add-ons as a tiered Free / PRO / SUPER STAR catalog with local-only premium promotion.
- Premium cards are informational only; no commercial executable code is bundled, downloaded, or unlocked.
- Shortened the WordPress.org readme short description to stay within the 150-character limit.
- Kept WooCommerce as an optional local integration and preserved WordPress.org security boundaries.

# Evoxup Membership Changelog

## 1.8.3
- Restored the optional Support / Donate link in Evoxup admin pages and public readme.
- Donations do not unlock or restrict features.


This packaged changelog intentionally keeps the current release and the five immediately preceding releases. Older engineering history is maintained outside the WordPress.org production package.

## 1.8.2
- Restored the bundled Marketplace visual interface using WordPress enqueue APIs after the WordPress.org compliance refactor.
- Kept Marketplace as a catalog/entitlement interface for separately distributed packages without restoring runtime executable-package installation.
- Refreshed the WordPress.org-facing product identity and documentation.

## 1.8.1
- Completed the main WordPress.org compliance pass for remote fulfillment, executable-package handling, script loading, audit sanitization, and external-service disclosure.
- Verified webhooks continue EVO fulfillment but no longer create WordPress users remotely.
- Removed Core-managed runtime install/update/rollback/uninstall of executable third-party add-ons and legacy custom executable storage paths.

## 1.8.0
- Clarified that every built-in Evoxup Membership function is free and that Core updates use the normal WordPress.org updater.
- Defined the boundary between built-in functionality and separately distributed commercial packages/services.
- Clarified WooCommerce fulfillment, verified webhook integration, and external-package access policy.

## 1.7.9
- Fixed WordPress filesystem initialization for managed update operations and moved staging into WordPress-managed upgrade storage.
- Preserved verification and rollback safeguards used by that update architecture.

## 1.7.8
- Resolved the remaining Plugin Check input-sanitization findings in local extensions administration requests.
- No membership, entitlement, Repository, or product-model behavior changed.

## 1.7.7
- Completed a broad WordPress.org remediation pass covering escaping, sanitization, nonce handling, translator comments, filesystem helpers, and rollback-storage location.
- Removed obsolete Remote Runtime/generated Cloud Proxy customer execution paths while preserving the membership/licensing model and Repository entitlement behavior.
