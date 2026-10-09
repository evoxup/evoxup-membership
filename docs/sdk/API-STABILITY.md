# Evoxup Extension API stability policy

1. Public contracts live under `EvoMembers\Contracts` and the scoped `ExtensionContext` methods documented for Extension API 2.0.
2. `EvoMembers\Services`, database table layouts, admin classes and internal loader implementation are not public contracts.
3. A minor Core release may add optional methods, events and manifest fields but must not remove or change an API 2.0 method signature.
4. A breaking public contract change requires an Extension API major bump.
5. Event schemas carry their own version and are not silently changed in a breaking way under the same schema version.
6. RBAC and Audit are cross-platform contracts; extensions should not create parallel authorization or audit systems.
7. Dependencies are explicit in `extension.json`; filesystem discovery order is never a valid dependency mechanism.
