# Modules

Each RightAlly module lives in its own folder:

```
modules/<Name>/
  module.json      id, version, dependencies, entitlement key
  src/             PSR-4 namespace Modules\<Name>\
  database/        the module's own migrations (tables prefixed per module)
  routes/          module routes
  resources/js/    module React pages and components
  tests/           module tests
```

Rules (enforced by tests/Architecture):

- A module owns its tables; other modules never query them directly.
- Modules communicate through public interfaces and domain events.
- Every tenant-owned model is scoped by tenant (introduced in Sprint 1).
