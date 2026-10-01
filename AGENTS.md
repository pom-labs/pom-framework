# POM Framework — Agent Rules

This is the source repository for `pom-labs/pom-framework`. Consumer plugins and the POM theme bundle it as a vendor copy (`vendor/pom-labs/pom-framework/`); never edit those copies — develop here, then roll out with `composer update pom-labs/pom-framework` in every bundling repository.

# Docs

ALL documentation for this repository lives in the single `README.md`. This is intentional and differs from other POM repos: an AI agent must be able to understand the framework's entire scope and behavior — settings pages, fields, helpers, and the product licensing client — from one document.

- Existing documentation filenames and paths are stable identifiers that may determine published URLs. Preserve them to avoid broken links and production 404s.
- You may edit the existing `README.md` in place. The single-file documentation rule below remains in force; this policy does not authorize additional documentation files or directories here.
- Never delete, rename, or move an existing documentation file or directory without the user's prior explicit instruction and consent for that specific operation. If that authorization is not already present, explain the affected paths and proposed destinations, request permission, and wait before acting. A general request to update, clean up, reorganize, or improve documentation is not authorization.
- This protection includes renumbering topic directories or Markdown basenames and moving a parent directory. Naming, hierarchy, and code-mirroring conventions apply to new paths; they never authorize changing existing paths without prior consent.
- After an explicitly authorized deletion, rename, or move, update affected links and documentation manifests in the same change. Those maintenance requirements do not themselves authorize the operation.

- Never create a `docs/` directory or separate documentation files here.
- Every change to framework functionality must update the relevant `README.md` section in the same change.
- The licensing client (`src/Licensing/`) is documented in the "Product licensing client" section of `README.md`; keep it synchronized with the storage contract mirrored by `plugins/pomatio-platform/private-updates/includes/license-registry-reader.php` in the multisite project.
- Treat `README.md` as production-facing documentation: write clear English, keep it complete and durable, and never include known bugs, temporary hacks, internal TODOs, technical debt, security-sensitive details, debugging traces, speculative notes, or unfinished thoughts.
- For every feature, behavior, API, configuration, integration, dependency, or release change, inspect the matching `README.md` section and update it in the same change whenever public or developer-facing usage is affected.
- Document relevant usage, configuration, inputs, outputs, hooks, helpers, limitations, integration behavior, and rollout requirements without creating placeholders.
- Skip documentation only when a change is purely internal and has no meaningful public or developer-facing impact.
- Internal engineering notes belong in code comments, docblocks, or another non-public developer location, never in `README.md`.

The standard POM rule requiring `/docs/.htaccess` does not apply here because this repository must not contain `/docs`. If that repository-wide exception ever changes explicitly, the new documentation directory must include the canonical access-denying `.htaccess` in the same change.

## Rollout coordination

The first registered Composer autoloader wins for the whole request, so a stale vendor copy in any active plugin can shadow new framework classes. Changes to `src/Licensing/` or `POM_Framework_Settings` require updating the vendor copy in every bundling repository at the same time, not only in the repos that motivated the change.
