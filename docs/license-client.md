# POM Framework License Client

Shared client-side licensing for POM products (`src/Licensing/`, namespace
`POMFramework\Licensing`). Product plugins/themes consume it through thin
adapters and must not duplicate storage, HTTP, caching, or UI code.

## Components

### `License_Registry`

One registry option `pom_product_licenses` keyed by canonical product slug:

- Single site: non-autoloaded option. Multisite: network option, so the
  common POM Standard updater can read every product key while target
  plugins are inactive. One product license applies to one WordPress network.
- Each entry stores the encrypted key (`sodium:`/`openssl:` envelope,
  key derived from WordPress salts via versioned KDF `v1`), masked display
  value, network UUID, last validation result, `next_check_at`, and
  `grace_until`.
- If salts change and decryption fails, `needs_reconnect()` is true and the
  administrator must paste the key again; ciphertext is never logged or
  exposed.
- `ensure_network_uuid()` creates the stable random network UUID sent with
  multisite payloads (`pom_license_network_uuid` option).

Storage contract consumers: `POM_Private_Updates` in POM Standard reads this
registry through its own minimal reader
(`plugins/pom-pomstandard/includes/license-registry-reader.php`). Any change
to the option name, entry schema, KDF, or envelope must update both sides and
bump the version markers.

### `License_Client`

HTTP wrapper for `pom-lic/v2`: `validate()`, `status()`,
`deactivate_domain()`, `validate_and_cache()`, `cached_or_revalidate()`.
The API base is filterable via `pom_license_api_base` for local/staging
account servers. Grace behavior: a `network_error` (account outage) keeps the
previously cached valid state while `grace_until` (7 days after the last
valid check) has not passed; deterministic failures (invalid key, revoked,
expired, mismatch) clear grace immediately.

### `License_Field`

Reusable settings UI: masked key after save, status line, Save/Replace,
Revalidate, and Unlink actions through `admin-post.php`
(`pom_license_field_action`, per-product nonce). Multisite keys can only be
changed by network administrators; site administrators see masked status.
Call `License_Field::boot()` early on admin requests (the form posts before
settings pages render) and `License_Field::render( '<slug>', $args )` where
the product wants its panel.

After a successful save-and-validate the `pom_license_saved` action fires
with `( $product_slug, $result )` — POM AI uses it to delete its legacy
key-pair option.

## Writing a product adapter

See `includes/license-adapter.php` in POM Translate/Tweaks/Cache/LMS/Events
or `themes/pom/includes/license-adapter.php`. An adapter only declares the
canonical slug, page placement, and product-specific constraints (e.g. POM
Cache never touches cache hot paths; POM Events loads before its POM-theme
guard). Adapters never invent key options and never bypass the registry.
