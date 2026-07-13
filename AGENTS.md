# POM Framework — Agent Rules

This is the source repository for `pom-labs/pom-framework`. Consumer plugins and the POM theme bundle it as a vendor copy (`vendor/pom-labs/pom-framework/`); never edit those copies — develop here, then roll out with `composer update pom-labs/pom-framework` in every bundling repository.

## Documentation convention

ALL documentation for this repository lives in the single `README.md`. This is intentional and differs from other POM repos: an AI agent must be able to understand the framework's entire scope and behavior — settings pages, fields, helpers, and the product licensing client — from one document.

- Never create a `docs/` directory or separate documentation files here.
- Every change to framework functionality must update the relevant `README.md` section in the same change.
- The licensing client (`src/Licensing/`) is documented in the "Product licensing client" section of `README.md`; keep it synchronized with the storage contract mirrored by `plugins/pom-pomstandard/includes/license-registry-reader.php` in the multisite project.

## Rollout coordination

The first registered Composer autoloader wins for the whole request, so a stale vendor copy in any active plugin can shadow new framework classes. Changes to `src/Licensing/` or `POM_Framework_Settings` require updating the vendor copy in every bundling repository at the same time, not only in the repos that motivated the change.
