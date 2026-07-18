# POM Framework

POM Framework lets you describe WordPress admin interfaces with plain PHP arrays and have the library render, validate, and persist every field for you. It bootstraps helper, disk, settings, AJAX, save, and translation services as soon as you instantiate the main class, so assets only load on registered screens and sanitizers always match your field definitions.

Key advantages at a glance:

- **Purpose-built admin UI builder** – Declare tabs, subsections, and fields, then call a single render method to output fully localised markup with nonce handling, repeaters, and dependency metadata baked in.
- **Deterministic configuration** – Settings live alongside your code in PHP files, yet any field can opt into WordPress options or theme mods when runtime overrides are required.
- **Performance-first storage** – OPcache caches the generated settings arrays, reducing database lookups and avoiding autoload bloat while still supporting multisite-aware directories.
- **Extensive field library** – Everything from text inputs to CodeMirror editors, Select2-powered dropdowns, signature pads, font managers, and nested repeaters ships out of the box.
- **Customisable tool cards** – Add wrapper elements, hero images, and onboarding copy to make complex toggle panels look polished without rewriting templates.

![Placeholder – default settings card](screenshots/default-settings-card-placeholder.png)
![Placeholder – custom div wrapper with image](screenshots/custom-wrapper-card-placeholder.png)

> Replace the placeholder screenshots above with real captures from your project once they are available.

## Table of contents

- [Quick start](#quick-start)
- [Benefits of PHP settings files](#benefits-of-php-settings-files)
  - [Performance profile](#performance-profile)
  - [Operational advantages](#operational-advantages)
  - [When to use the database instead](#when-to-use-the-database-instead)
  - [Common objections](#common-objections)
- [Requirements](#requirements)
- [Installation](#installation)
- [Example project structure](#example-project-structure)
  - [Runtime storage locations](#runtime-storage-locations)
- [Step-by-step integration tutorial](#step-by-step-integration-tutorial)
  - [1. Bootstrap your admin class](#1-bootstrap-your-admin-class)
  - [2. Register hooks and load enabled modules](#2-register-hooks-and-load-enabled-modules)
  - [3. Register the settings page with WordPress and the framework](#3-register-the-settings-page-with-wordpress-and-the-framework)
  - [4. Describe tabs and fields in a settings definition file](#4-describe-tabs-and-fields-in-a-settings-definition-file)
  - [Cooler tool cards with wrappers and images](#cooler-tool-cards-with-wrappers-and-images)
- [Field reference](#field-reference)
  - [Defining fields](#defining-fields)
  - [Common parameters](#common-parameters)
  - [External storage with `save_as`](#external-storage-with-save_as)
  - [Dependencies and conditional logic](#dependencies-and-conditional-logic)
  - [Working with repeaters](#working-with-repeaters)
  - [Field library](#field-library)
- [Helper classes](#helper-classes)
- [Settings pages in depth](#settings-pages-in-depth)
  - [Registering the admin page](#registering-the-admin-page)
  - [Rendering the settings form](#rendering-the-settings-form)
  - [Overriding tab content](#overriding-tab-content)
  - [Rendering the tab navigation](#rendering-the-tab-navigation)
  - [Handling callbacks after saving](#handling-callbacks-after-saving)
  - [Loading tweak definitions](#loading-tweak-definitions)
  - [Retrieving saved values](#retrieving-saved-values)
- [Product licensing client](#product-licensing-client)
  - [License_Registry](#license_registry)
  - [License_Client](#license_client)
  - [License_Field and the License settings tab](#license_field-and-the-license-settings-tab)
  - [License_Notices](#license_notices)
  - [Custom subsection rendering with render_callback](#custom-subsection-rendering-with-render_callback)
  - [Writing a product license adapter](#writing-a-product-license-adapter)
- [Additional resources](#additional-resources)

## Quick start

1. **Register your admin page** and keep the hook suffix so framework assets only load where needed.
2. **Return a settings array** from a PHP file describing tabs, subsections, and field definitions.
3. **Render the form** by calling `POM_Framework_Settings::render( $slug, $settings_array )` inside the admin page callback.
4. **React after saving** with the `pom_framework_after_save_settings` action.

```php
use POMFramework\POM_Framework;
use POMFramework\POM_Framework_Settings;

add_action('admin_menu', function () {
    $hook_suffix = add_submenu_page(
        'options-general.php',
        __('Demo settings', 'demo-slug'),
        __('Demo settings', 'demo-slug'),
        'manage_options',
        'demo-slug',
        'demo_settings_page'
    );

    POM_Framework::register_settings_page($hook_suffix);
});

function demo_settings_page() {
    $settings = require __DIR__ . '/settings.php';
    POM_Framework_Settings::render('demo-slug', $settings);
}
```

## Benefits of PHP settings files

### Performance profile

- **Zero database round-trips on cold start** – `require_once` loads a compiled PHP array, whereas `get_option()` must query the database unless caches are already warm.
- **OPcache acceleration** – Once deployed, PHP bytecode lives in memory; subsequent requests simply reference the cached array.
- **Smaller autoload payload** – Large option arrays no longer inflate `wp_options` autoload data, improving TTFB across the board.
- **Predictable caching** – Files benefit from both the OS page cache and OPcache, avoiding sudden cache evictions that affect options.

### Operational advantages

- **True version control** – Configuration travels with Git history, enabling diffs, reviews, and instant rollbacks.
- **Reproducible deployments** – Every environment receives the same configuration as part of the release artifact.
- **Immutable production friendly** – Read-only filesystems and GitOps workflows work seamlessly when configuration is code.
- **Decoupled from theme lifecycle** – Unlike `theme_mods`, PHP settings files survive theme switches.
- **Database hygiene** – Keep `wp_options` focused on stateful content while code governs behaviour.

### When to use the database instead

- Per-user preferences or rapidly changing state.
- Translatable editorial content maintained by non-technical users.
- Ephemeral values such as counters, temporary tokens, or session data.

### Common objections

- **“Marketing needs to change colours via UI.”** POM Framework produces the admin UI that writes straight to the file, so non-developers still use familiar forms.
- **“Where do API secrets live?”** Store sensitive values as WordPress options or theme mods using the `save_as` flag so they stay out of version control.

## Requirements

- PHP 7.4 or higher
- WordPress 5.0 or higher
- Composer (for installing the package and autoload file)

## Installation

Install the package in your plugin or theme directory with Composer:

```bash
composer require pom-labs/pom-framework
```

POM Framework is published on Packagist under the `pom-labs` vendor:
https://packagist.org/packages/pom-labs/pom-framework

If you bundle the framework in a distributed plugin or theme, include Composer’s autoload file before bootstrapping the framework:

```php
require_once __DIR__ . '/vendor/autoload.php';
```

## Example project structure

A minimal plugin that uses POM Framework to render settings might look like this:

```
wp-content/
└── plugins/
    └── your-plugin/
        ├── admin/
        │   ├── class-admin.php
        │   └── settings.php
        ├── assets/
        │   ├── css/
        │   └── js/
        ├── settings/
        │   ├── module-subdirectory
        │   │   ├── fields.php -> Settings rendered on the admin page for that module.
        │   │   └── bootstrap.php -> Code executed when the module is enabled.
        │   └── other-module-subdirectory
        ├── vendor/
        │   └── autoload.php
        └── your-plugin.php (plugin bootstrap file)
```

### Runtime storage locations

At runtime the framework stores configuration files under `wp-content/settings/pom-framework/sites/<site-id>/<slug>/`. In the structure above, the slug is `your-plugin`, so the file `enabled_settings.php` lives at `wp-content/settings/pom-framework/sites/<site-id>/your-plugin/enabled_settings.php`.

![Placeholder – storage diagram](screenshots/storage-diagram-placeholder.png)

## Step-by-step integration tutorial

The walkthrough below mirrors how a typical admin class wires POM Framework into a plugin. Copy the snippets into your own project and adjust namespaces, slugs, and asset paths as needed.

### 1. Bootstrap your admin class

Create `admin/class-admin.php` and instantiate it from your plugin’s main file:

```php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/admin/class-admin.php';
new Your_Plugin_Admin_Settings();
```

### 2. Register hooks and load enabled modules

Inside `Your_Plugin_Admin_Settings` add the WordPress and framework hooks you need. The snippet below registers scripts, AJAX handlers, and dashboard UI pieces. The key call is `POM_Framework_Settings::get_effective_enabled_settings()`, which reads stored module flags and loads their bootstrap files dynamically.

```php
use POMFramework\POM_Framework_Disk;
use POMFramework\POM_Framework_Settings;

class Your_Plugin_Admin_Settings {
    public function __construct() {
        add_action('admin_footer', [$this, 'render_dashboard_banner']);
        add_action('wp_ajax_your_plugin_get_credits', [$this, 'get_credits']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);

        add_action('admin_menu', [$this, 'register_options_menu']);
        add_action('in_admin_header', [$this, 'render_settings_tabs']);

        $settings_definition = require YOUR_PLUGIN_PATH . 'admin/settings.php';
        $enabled_settings = POM_Framework_Settings::get_effective_enabled_settings('your-plugin', $settings_definition);

        foreach ($enabled_settings as $module => $status) {
            if ($status === '1') {
                $module_path = YOUR_PLUGIN_PATH . "settings/{$module}/";

                if (file_exists("{$module_path}bootstrap.php")) {
                    include "{$module_path}bootstrap.php";
                }
            }
        }
    }

    // ... other methods (enqueue_scripts, get_credits, etc.) ...
}
```

`POM_Framework_Settings::get_effective_enabled_settings()` resolves the correct storage directory, merges stored flags with any modules marked `requires_initialization => false`, and returns an up-to-date array you can iterate to bootstrap functionality without touching PHP code.

### 3. Register the settings page with WordPress and the framework

Use `add_submenu_page()` to create a submenu under “Settings → Your Plugin”. Save the return value in `$hook_suffix`, then register that hook with the framework so its assets load only when the page is active.

```php
public function register_options_menu(): void {
    $hook_suffix = add_submenu_page(
        'options-general.php',
        __('Your Plugin settings', 'your-plugin'),
        __('Your Plugin settings', 'your-plugin'),
        'manage_options',
        'your-plugin',
        [$this, 'render_settings_page']
    );

    if (class_exists('POMFramework\\POM_Framework')) {
        POMFramework\POM_Framework::register_settings_page($hook_suffix);
    }
}
```

### 4. Describe tabs and fields in a settings definition file

The settings definition file returns an associative array grouped by tab.

```php
use POMFramework\POM_Framework_Settings;
use POMFramework\POM_Framework_Disk;

$your_plugin_settings['config'] = [
    'settings_dir' => YOUR_PLUGIN_PATH . 'settings',
];

$your_plugin_settings['general'] = [
    'title' => __('General setup', 'your-plugin'),
    'allowed_roles' => ['administrator', 'editor'],
    'tab' => [
        'general' => [
            'title' => __('Basic configuration', 'your-plugin'),
            'description' => __('Foundational toggles for Your Plugin.', 'your-plugin'),
            'settings' => [
                'enable-tools' => [
                    'title' => __('Enable tools', 'your-plugin'),
                    'description' => __('Check this to enable core features.', 'your-plugin'),
                    'heading_checkbox' => __('Globally enable features', 'your-plugin'),
                    'label_checkbox' => __('Check this to activate the feature set.', 'your-plugin'),
                    'requires_initialization' => true,
                ],
            ],
        ],
    ],
];

$enabled_settings = POM_Framework_Settings::get_effective_enabled_settings('your-plugin', $your_plugin_settings);

if (!empty($enabled_settings['enable-tools'])) {
    $your_plugin_settings['api'] = [
        'title' => __('API keys', 'your-plugin'),
        'allowed_roles' => ['administrator'],
        'tab' => [
            'api' => [
                'title' => __('Credentials', 'your-plugin'),
                'description' => __('Store API tokens required for integrations.', 'your-plugin'),
                'settings' => [
                    'api-key' => [
                        'type' => 'text',
                        'label' => __('Primary API key', 'your-plugin'),
                        'description' => __('Paste the token issued by your service provider.', 'your-plugin'),
                    ],
                    'api-secret' => [
                        'type' => 'password',
                        'label' => __('API secret', 'your-plugin'),
                        'description' => __('Displayed only once—store it securely.', 'your-plugin'),
                    ],
                ],
            ],
        ],
    ];
}

return $your_plugin_settings;
```

### Cooler tool cards with wrappers and images

Settings arrays support presentation hints to help you design eye-catching layouts. Set `'wrapper' => 'div'` to render a `<div class="pom-framework-setting">` container and provide an `'img'` URL for a hero graphic. Combined with the checkbox labels, you can build multi-step toggles that read like product cards.

```php
$pom_ai_settings['ai_tools']['tab']['ai_tools']['settings']['ai-write-long-posts'] = [
    'title' => __('Write ultra-long posts (2-step)', POM_AI_PLUGIN_SLUG),
    'description' => __('Create thousands-word articles with a plan-first workflow: Step 1 generates a detailed outline, Step 2 writes the full post optimized for SEO and LLM retrieval (clear structure, citations, entities, and schema hints). Ideal for cornerstone content and topic clusters.', POM_AI_PLUGIN_SLUG),
    'heading_checkbox' => __('AI long-form post builder', POM_AI_PLUGIN_SLUG),
    'label_checkbox' => __('Enable 2-step structure → content', POM_AI_PLUGIN_SLUG),
    'description_checkbox' => __('First build the outline, then generate thousands of words optimized for SEO & LLMs.', POM_AI_PLUGIN_SLUG),
    'wrapper' => 'div',
    'img' => POM_AI_PLUGIN_URL . '/assets/img/tool-write-long-posts.jpg',
    'requires_initialization' => true,
];
```

Use the two screenshots at the top of this README to show both the default table layout and the enhanced wrapper layout once your captures are ready.

## Field reference

This section consolidates the complete field documentation so you can configure interfaces without switching files.

### Defining fields

Every field is declared as a PHP array and rendered by `POM_Framework::add_field()`, which normalises arguments and locates the matching renderer under `src/Fields`. The framework enqueues scripts such as CodeMirror, Select2, colour pickers, and repeater helpers on demand based on the field types present in your definition.

```php
[
    'type'        => 'text',
    'name'        => 'cta-title',
    'label'       => __('CTA title', 'your-plugin'),
    'placeholder' => __('Launch offer…', 'your-plugin'),
    'description' => __('Shown on the homepage hero.', 'your-plugin'),
]
```

### Common parameters

| Key | Purpose |
|-----|---------|
| `label`, `description` | Human-friendly text rendered above or below the field; use `description_position` to switch between `below_label` and `under_field`. |
| `default`, `value` | Renderers prefer a saved `value`, then fall back to `default`, so you can pre-populate fields while letting users overwrite them. |
| `class`, `id` | Custom classes/IDs appended to the generated markup after being sanitised, handy for styling or JavaScript hooks. |
| `disabled` | Pass `true` to render a disabled input without losing the value on save—useful for templates or repeater defaults. |
| `dependency` | Define conditional logic that is serialised into a `data-dependencies` attribute so JavaScript can hide or show the field based on other values. |
| `persist` | Optional boolean that defaults to `true`. Set it to `false` for request-scoped UI state that should render but must never be read from or written to saved settings. |
| `save_as` | Optional string that re-routes persistence to a theme mod or WordPress option. See below for the supported values. |

Because each field posts back under its `name`, the save handler can detect the field type, run the corresponding sanitizer from `class-sanitize.php`, and persist a clean value to disk. When `persist` is set to `false`, the framework skips both the save step and the saved-value hydration step, making the field suitable for transient dependency markers such as “currently selected tab”.

### External storage with `save_as`

Setting `save_as` on a field instructs the saver to sanitise the payload and forward it to either the theme-mod API or the options table instead of appending it to the generated `<setting>.php` file. The saver records the routing in `fields_save_as.php` so the renderer, sanitizers, and translation helpers can look up the correct source later.

| `save_as` value | Storage target |
|-----------------|----------------|
| _Omitted / `default`_ | Keeps the standard behaviour and stores values in the PHP array file. |
| `theme_mod` | Uses `set_theme_mod()` / `get_theme_mod()` with the field name as the key. |
| `option_autoload_yes` | Calls `update_option()` with autoload forced to `'yes'`. |
| `option_autoload_no` | Calls `update_option()` with autoload forced to `'no'`. |
| `option_autoload_auto` | Calls `update_option()` without the autoload flag so WordPress chooses the appropriate behaviour. |

Examples:

```php
return [
    [
        'type'    => 'text',
        'name'    => 'footer_note',
        'label'   => __('Footer note', 'your-plugin'),
        // Stored in <setting>.php because save_as is omitted.
    ],
    [
        'type'    => 'Image_Picker',
        'name'    => 'header_logo',
        'label'   => __('Header logo', 'your-plugin'),
        'default' => '',
        'save_as' => 'theme_mod',
    ],
    [
        'type'    => 'Toggle',
        'name'    => 'feature_flag',
        'label'   => __('Enable feature', 'your-plugin'),
        'default' => false,
        'save_as' => 'option_autoload_yes',
    ],
    [
        'type'    => 'Textarea',
        'name'    => 'legal_blurb',
        'label'   => __('Legal blurb', 'your-plugin'),
        'default' => '',
        'save_as' => 'option_autoload_no',
    ],
    [
        'type'    => 'Text',
        'name'    => 'utm_source',
        'label'   => __('UTM source override', 'your-plugin'),
        'save_as' => 'option_autoload_auto',
    ],
];
```

When the administrator saves the page the framework writes the alternative targets, updates `fields_save_as.php`, and invalidates OPcache so subsequent requests pick up the change immediately. `POM_Framework_Settings::get_setting_value()` consults that metadata before it sanitises the value, meaning your front-end code can continue to call the helper without caring whether the value lives in a PHP array, a theme mod, or an option.

### Dependencies and conditional logic

Any field can react to other inputs by providing a `dependency` array. `POM_Framework_Helper::get_dependencies_data_attr()` JSON-encodes the structure and stores it as a data attribute without forcing you to write boilerplate, so repeaters, selects, and custom inputs can all participate in complex flows.

### Working with repeaters

The repeater field is a container that renders a list of child fields, supports defaults, cloning, sorting, nested repeaters, and per-item dependencies. It stores its configuration alongside the saved values in a hidden input (`data-type="repeater"`). AJAX-created rows update that value immediately, and every repeater in a framework settings form is rebuilt from its visible fields before submission, with nested repeaters processed before their parents. Row deletion reads only the hidden value owned by the current wrapper and safely rebuilds malformed or stale client-side payloads from the remaining visible rows. During sanitisation each nested field is processed with its own sanitizer so code editors are written to disk while plain inputs remain inline.

```php
return [
    [
        'type'        => 'repeater',
        'name'        => 'rules',
        'label'       => __('Rules', 'your-plugin'),
        'max_items'   => 5,
        'title_field' => 'rule_name',
        'fields'      => [
            [
                'type'           => 'Text',
                'name'           => 'rule_name',
                'label'          => __('Rule name', 'your-plugin'),
                'used_for_title' => true,
            ],
            [
                'type'   => 'repeater',
                'title'  => __('Rule', 'your-plugin'),
                'name'   => 'rule',
                'fields' => [
                    [
                        'type'           => 'Text',
                        'name'           => 'rule_name',
                        'label'          => __('Rule name', 'your-plugin'),
                        'used_for_title' => true,
                    ],
                    [
                        'type'    => 'select',
                        'name'    => 'rule_type',
                        'label'   => __('Rule type', 'your-plugin'),
                        'options' => $rule_types,
                        'default' => 'none'
                    ],
                    [
                        'type'       => 'select',
                        'name'       => 'roles',
                        'multiple'   => true,
                        'label'      => __('Roles', 'your-plugin'),
                        'options'    => pom_get_roles(true),
                        'dependency' => [[[ 'field' => 'rule_type', 'values' => ['role_is', 'role_is_not'] ]]],
                    ],
                    // … additional dependent selects and number fields …
                ],
            ],
            [
                'type'    => 'select',
                'name'    => 'condition_between_rules',
                'label'   => __('Rule type', 'your-plugin'),
                'options' => [
                    ''   => __('Set a condition', 'your-plugin'),
                    'and' => __('AND', 'your-plugin'),
                    'or'  => __('OR', 'your-plugin'),
                ],
                'default' => 'and',
            ],
        ],
    ],
];
```

When this structure is posted, `sanitize_pom_framework_repeater()` walks each layer, enforces the limit, resolves every nested field against the server-side `fields` definition, and delegates to the corresponding sanitizer. Client-provided field names and types that are not present in that definition are discarded, and closed controls such as selects, radios, and checkbox groups are restricted to their declared `options`.

AJAX-created repeater rows and restored defaults also carry an HMAC of the exact server-rendered schema. Requests with altered field definitions are rejected even when they have a valid authenticated admin nonce. File-backed code and TinyMCE values are read only after `realpath()` confirms that the file belongs to the current site's managed `wp-content/settings/pom-framework/` tree.

### Field library

The framework ships the following field types. Use this table to pick the right control and learn about its quirks.

| Field type | What it renders | Notable options & behaviour |
|------------|-----------------|-----------------------------|
| `background_image` | Composite background designer combining image picker, alignment radios, size selectors, repeat controls, and colour pickers. | Stores JSON describing every background property. Sanitized via `sanitize_pom_framework_background_image()` and enqueues its own scripts/styles. |
| `button` | `<input type="button">`, submit button, or `<a>` element. | Use `submit => true` for submit buttons, `link => true` with `href` for anchor tags, and `text` for the label. Optional `description` renders helper text. |
| `checkbox` | Single yes/no toggle or multiple checkboxes. | Hidden input posts `no` when unchecked. Accepts `options` array for multi-select mode and honours dependencies and disabled state. |
| `code_css` | CodeMirror-powered CSS editor. | Loads existing file contents when `value` is a path, supports `custom_attrs` like `placeholder`, and saves through the CSS sanitizer with optional compression. |
| `code_html` | CodeMirror HTML editor. | Retrieves file contents when given a path, enqueues CodeMirror assets, and sanitises output with WordPress KSES-compatible rules. |
| `code_js` | CodeMirror JavaScript editor. | Enqueues JS mode, writes files to disk when necessary, and sanitises script input to prevent malformed payloads. |
| `code_json` | CodeMirror JSON editor. | Validates JSON structure during sanitisation and is ideal for structured configuration blobs. |
| `color` | WordPress colour picker input. | Outputs an `<input type="text">` with the WP colour picker attached and sanitises to a valid hex value. |
| `color_palette` | Palette selector using colour chips. | Provide `options` with hex codes and labels; sanitisation ensures selections are restricted to allowed values. |
| `date` | `<input type="date">` field. | Accepts `min`, `max`, and default values; sanitiser enforces the `Y-m-d` format. |
| `datetime` | `<input type="datetime-local">` field. | Supports `min`, `max`, and step attributes. Sanitised to `Y-m-d H:i`. |
| `email` | `<input type="email">`. | Validates addresses, honours placeholders and default values, and integrates with dependencies. |
| `file` | Native file upload control. | Exposes `pom_framework_max_file_size` and `pom_framework_allowed_mime_types` filters so you can restrict uploads; the default sanitiser returns the raw payload for your own handling. |
| `font` | Composite font manager with nested repeaters for families, fallbacks, and variants. | Persists metadata describing each font variant, restricts uploads to allowed MIME types, and leverages the disk helper for storage. |
| `font_picker` | Upload field for individual font files inside the font manager. | Sanitises file arrays, stores assets under the custom font directory, and exposes URLs for front-end usage. |
| `gallery` | WordPress media modal gallery selector. | Returns comma-separated attachment IDs, allows reordering, and sanitises IDs to digits and commas. |
| `hidden` | Hidden metadata field. | Useful for storing configuration without UI; sanitiser strips unexpected content. |
| `icon_picker` | SVG and icon font selector. | Lists available icon libraries, supports search, sanitises selected icon URLs, and shows a clear action by default. Pass `clearable => false` when a selected icon must always be kept. |
| `image_picker` | WordPress media modal image selector. | Stores image URLs, supports custom button text, and sanitises to valid URLs. |
| `image_picker_id` | WordPress media modal image selector that stores attachment IDs. | Keeps a hidden ID input, renders previews from the attachment URL, and sanitises to numeric IDs. |
| `number` | `<input type="number">`. | Provides placeholder support, respects dependencies, and sanitises the submission to numeric characters. |
| `password` | `<input type="password">`. | Displays saved values when provided, honours dependency metadata, and sanitises submissions using `sanitize_text_field()`. |
| `quantity` | Numeric input with plus/minus controls. | JavaScript increments/decrements values, while the sanitiser normalises numeric output. |
| `radio` | Radio button group. | Provide `options` as value/label pairs; sanitiser ensures only allowed values persist. |
| `radio_icons` | Radio group rendered with SVG icons. | Expects `options` with `label`, `icon`, and `value`. Includes a restore-default shortcut and sanitises icon selections. |
| `range` | Slider paired with a numeric input. | Supports `min`, `max`, `step`, optional `suffix`, and a “restore default” action. Enqueues dedicated JS/CSS assets. |
| `repeater` | Dynamic group of nested fields. | Configure `fields`, `min_items`, `max_items`, `title_field`, and `button_labels`. Supports drag-and-drop sorting and nested repeaters. |
| `select` | Select dropdown with optional multi-select and optgroups. | Accepts `options`, `multiple`, and `placeholder`, supports optgroup structures, enqueues the framework select script, and stores multi-select choices as comma-separated lists. |
| `separator` | Heading and description block. | Use to break long forms into sections; no value is stored. |
| `signature` | Canvas-based signature pad. | Saves base64 images through the disk helper, stores the generated file path, and leaves the value untouched during sanitisation because validation happens when writing the file. |
| `tel` | `<input type="tel">`. | Validates against an international phone pattern (optional leading `+` and 8–15 digits) and returns `false` when the input is invalid. |
| `text` | Single-line text input. | Supports `placeholder`, `used_for_title` (for repeater headings), and dependency metadata. Sanitises to plain text. |
| `textarea` | Multi-line textarea. | Accepts `rows`, `placeholder`, and dependency metadata. Sanitiser removes HTML entirely, keeping plain text for storage. |
| `time` | `<input type="time">`. | Supports step increments; sanitiser ensures valid time strings. |
| `tinymce` | WordPress TinyMCE editor. | Pass `custom_attrs` such as `textarea_rows`, `teeny`, `quicktags`, `wpautop`, or `media_buttons` to control the toolbar. Sanitiser cleans output using the configured allowed HTML. |
| `toggle` | Styled on/off switch built on a checkbox. | Provides automatic IDs, enqueues slider CSS, and sanitises to `yes`/`no`. |
| `trbl` | Four-number control for top, right, bottom, and left values with an optional sync/lock toggle. | Pass `units` as an array; multiple entries render a unit dropdown per side, while a single entry renders a static badge. `sync` toggles the lock button and mirrors both numbers and units when active. |
| `url` | `<input type="url">`. | Sanitises to absolute URLs, trims whitespace, and cooperates with dependency logic. |


```php
[
    'type'        => 'Trbl',
    'name'        => 'example_input',
    'label'       => __('Spacing', 'pom-framework'),
    'description' => __('Control top, right, bottom, and left values.', 'pom-framework'),
    'default'     => [
        'top'    => ['value' => 10, 'unit' => 'px'],
        'right'  => ['value' => 10, 'unit' => 'px'],
        'bottom' => ['value' => 10, 'unit' => 'px'],
        'left'   => ['value' => 10, 'unit' => 'px'],
        'sync'   => 'yes',
    ],
    'units'       => ['px', '%'], // Single entry renders a badge; multiple entries render a dropdown.
    'sync'        => 'yes',         // Set to false to hide the lock button and keep sides independent.
    'disabled'    => false,
    'dependency'  => [],
]
```

## Helper classes

POM Framework exposes several helper classes so you can inspect configuration, generate dependency metadata, and manipulate files without digging into WordPress internals yourself.

### `POM_Framework_Helper`

- **Dependency attributes** – `get_dependencies_data_attr()` converts the `dependency` structure you define in a field into the JSON payload that drives conditional display in JavaScript.
- **Utility conversions** – Use `convert_array_to_html_attributes()` and `convert_html_attributes_to_array()` to move between associative arrays and attribute strings when you extend the framework.
- **Runtime helpers** – Functions such as `generate_random_string()`, `write_log()`, and `path_to_url()` help you build dynamic field IDs, debug issues, and turn filesystem paths into public URLs.
- **Settings lookups** – `get_settings()` returns the raw settings array for a given tab/subsection and `get_allowed_html()` exposes the HTML tags that text editors sanitise against.

### `POM_Framework_Disk`

- **Automatic directories** – `create_settings_dir()` provisions `wp-content/settings/pom-framework/<site>/<slug>/` (including `.htaccess`) the first time a page saves values, making the storage multisite-aware.
- **File serialization** – `generate_file_content()` turns an array into a PHP file with metadata headers, while `save_to_file()` writes arbitrary content such as code editor values, removes the previous managed file when a value is cleared, and returns the saved path.
- **Reading and cleanup** – `read_file()` and `delete_file()` provide convenient access to stored configuration and let you remove generated assets when a module is disabled.
- **Font and signature support** – The constructor hooks into `upload_dir`/`upload_mimes` so custom fonts are stored under `/fonts`, only attachments stored under `/uploads/fonts/` are hidden from the standard Media Library (the Pomatio font picker modal can still browse them), and the signature helpers persist base64 canvases under a locked-down directory.

### `POM_Framework_Settings`

- **Navigation helpers** – `get_current_tab()` and `get_current_subsection()` inspect the current request (or default to the first entries) so you can render context-aware navigation or run callbacks only on the active screen.
- **Field metadata** – `read_fields()` loads the `fields.php` definition for a given setting, `get_effective_enabled_settings()` merges stored flags with auto-enabled tweaks, and `is_setting_enabled()` checks whether a module is active.
- **Value retrieval** – `get_setting_value()` reads the saved PHP file and optionally re-sanitises the value using the field type, which is perfect for use in templates or business logic.
- **Font face generation** – When you pass `font` as the type, each repeater item includes a `font_face` entry with the generated `@font-face` CSS (variable fonts emit a single block; normal fonts emit one per variant). You can also post-process existing arrays with `POM_Framework_Settings::add_font_face_to_fonts()`.

```php
$fonts = POM_Framework_Settings::get_setting_value(
    'pom-theme-options',
    'custom_fonts',
    'font_families',
    'font'
);

foreach ($fonts['new'] ?? [] as $font) {
    echo $font['font_face']['value'];
}
```

Leverage these helpers together with the automatic save routine to keep your own code focused on business logic rather than boilerplate persistence.

## Settings pages in depth

POM Framework can dynamically generate settings pages for your plugin or theme. You describe the tabs and fields in a PHP array, register a WordPress admin page, and call the framework helpers to render the UI and persist values for you.

### Registering the admin page

```php
use POMFramework\POM_Framework;
use POMFramework\POM_Framework_Settings;

add_action('admin_menu', function () {
    $hook_suffix = add_submenu_page(
        'options-general.php',
        __('Your Plugin', 'your-plugin'),
        __('Your Plugin', 'your-plugin'),
        'manage_options',
        'your-plugin',
        'your_plugin_settings_page'
    );

    POM_Framework::register_settings_page($hook_suffix);
});
```

### Rendering the settings form

```php
function your_plugin_settings_page() {
    $settings_path = require __DIR__ . '/settings.php';
    POM_Framework_Settings::render('your-plugin', $settings_path);
}
```

### Overriding tab content

You can replace the markup of specific tabs while still relying on the framework to render the rest. Fetch the current tab with `POM_Framework_Settings::get_current_tab()` and conditionally output your own HTML.

```php
function your_plugin_settings_page() {
    $settings_path = require __DIR__ . '/settings.php';
    $current_tab = POM_Framework_Settings::get_current_tab($settings_path);

    if ($current_tab === 'your_plugin_api_keys') {
        // Your custom markup and form handling here.
    } else {
        POM_Framework_Settings::render('your-plugin', $settings_path);
    }
}
```

### Rendering the tab navigation

Hook into `in_admin_header` and call `POM_Framework_Settings::render_tabs()` when the current screen ID matches your settings page to display the navigation tabs above the screen title.

```php
add_action('in_admin_header', function () {
    $screen = get_current_screen();

    if (substr($screen->id, -strlen('your-plugin')) === 'your-plugin') {
        $settings_path = require __DIR__ . '/settings.php';
        POM_Framework_Settings::render_tabs('your-plugin', $settings_path);
    }
});
```

### Handling callbacks after saving

Each settings definition can include a custom `callback` key alongside the usual `title`, `description`, and `settings_dir`. After the user saves, POM Framework fires the `pom_framework_after_save_settings` action with the page slug, current tab, and subsection so you can inspect the active configuration and execute those callbacks.

```php
add_action('pom_framework_after_save_settings', function ($page_slug, $tab, $subsection) {
    $settings = require YOUR_PLUGIN_PATH . 'admin/settings.php';
    $groups = POMFramework\POM_Framework_Helper::get_settings($settings, $tab, $subsection);

    foreach ($groups as $setting_key => $group) {
        if (!empty($group['callback']) && is_callable($group['callback'])) {
            call_user_func($group['callback'], $page_slug, $setting_key);
        }
    }
});
```

This pattern lets you invalidate caches, rebuild derived data, or synchronise external services whenever an admin updates a specific section of your settings page.

### Loading tweak definitions

If you use tweak folders or other modular features, call `POM_Framework_Settings::get_effective_enabled_settings()` inside your admin class constructor. The helper loads `enabled_settings.php`, marks any `requires_initialization => false` tweaks as active, and persists the merged result so bootstrapping code sees the default modules immediately.

```php
use POMFramework\POM_Framework_Settings;

$settings_definition = require plugin_dir_path(__FILE__) . 'settings.php';
$enabled_settings = POM_Framework_Settings::get_effective_enabled_settings('your-plugin', $settings_definition);

foreach ($enabled_settings as $module => $status) {
    if ($status === '1') {
        $module_path = plugin_dir_path(__FILE__) . "settings/{$module}/";

        if (file_exists("{$module_path}bootstrap.php")) {
            include "{$module_path}bootstrap.php";
        }
    }
}
```

### Retrieving saved values

When a settings form is submitted the save handler accepts only fields present in the server-side settings definition, infers each field type from that definition, and calls the corresponding `sanitize_pom_framework_{type}` helper before the data touches disk. Closed controls are also validated against their declared options. That means a URL field always runs through `sanitize_pom_framework_url()`, a number field strips non-numeric characters with `sanitize_pom_framework_number()`, and a checkbox collapses to either `yes` or `no` regardless of what the browser sends.

Consider this fragment from a `fields.php` file:

```php
'settings' => [
    'webhook-url' => [
        'type' => 'url',
    ],
    'retry-count' => [
        'type' => 'number',
    ],
    'send-digest' => [
        'type' => 'checkbox',
    ],
],
```

If the admin submits `https://example.com/hooks  ` for the URL, `3 retries` for the number, and anything other than `yes` for the checkbox, the stored PHP array becomes:

```php
[
    'webhook-url' => 'https://example.com/hooks',
    'retry-count' => '3',
    'send-digest' => 'no',
]
```

Anywhere in your plugin you can access the saved settings via `POM_Framework_Settings::get_setting_value()`. Pass the same type that the field used (`url`, `number`, `checkbox`, etc.) to re-sanitise the stored value at read time.

```php
$webhook = POM_Framework_Settings::get_setting_value('your-plugin', 'general', 'webhook-url', 'url');
```

By mirroring the input type you ensure the runtime value has been filtered by the same logic that protects the form submission, guarding against manual edits or tampering in `wp-content/settings/`.

## Product licensing client

`src/Licensing/` (namespace `POMFramework\Licensing`) is the shared client for the POM product licensing platform served by account.pom.es (`pom-lic/v2`). Product plugins and themes consume it through thin adapters and must not duplicate storage, HTTP, caching, or UI code.

### License_Registry

One registry option `pom_product_licenses` keyed by canonical product slug:

- Single site: non-autoloaded option. Multisite: network option, so the common POM Standard updater can read every product key while target plugins are inactive. Service-scoped products may also require an explicit per-blog entry as described below.
- Each entry stores the encrypted key (`sodium:`/`openssl:` envelope, key derived from WordPress salts via versioned KDF `v1`), a masked display value, the network UUID, the last validation result, `next_check_at`, and `grace_until`.
- If salts change and decryption fails, `License_Registry::needs_reconnect()` is true and the administrator must paste the key again; ciphertext is never logged or exposed.
- `ensure_network_uuid()` creates the stable random network UUID sent with multisite payloads (`pom_license_network_uuid` option).

Storage contract consumers: `POM_Private_Updates` in POM Standard reads this registry through its own minimal reader (`plugins/pom-pomstandard/includes/license-registry-reader.php`). Any change to the option name, entry schema, KDF, or envelope must update both sides and bump the version markers.

#### Service-scoped products (POM AI) and multisite scopes

`License_Registry::SERVICE_SCOPED_PRODUCTS` (currently only `pom-ai`) marks products whose *service* license is per-blog on multisite: subsites — which may belong to third parties — must store their own key in a per-blog non-autoloaded option with the same `pom_product_licenses` name. That key authorizes the subsite domain to consume credits belonging to the license owner's account. Plugin updates always use the network entry, so a subsite-only license never grants updates and a network key never silently grants services to a subsite.

Three scopes exist (`SCOPE_SITE`, `SCOPE_NETWORK`, `SCOPE_BLOG`), and two resolvers:

- `management_scope( $slug )` — where the license is *edited* on the current site: `site` on single site; `network` on multisite (including the main site, whose network entry doubles as its own service license); `blog` only for service-scoped products on a subsite.
- `effective_scope( $slug )` — where the key that *serves* the product lives. It matches the management scope; a service-scoped product on a subsite never falls back to `network`.

Every registry method (`get`, `get_key`, `store_key`, `store_validation`, `get_validation`, `needs_reconnect`, `in_grace`, `forget`) accepts an optional trailing `$scope`; empty means reads resolve through `effective_scope()` and writes through `management_scope()`. A subsite write can therefore never touch the network entry, and a subsite's validation cache lands in its own blog entry. `POMFramework\Licensing\Private_Updater` and the POM Standard registry reader pin update-key reads to `SCOPE_NETWORK` explicitly.

The resulting behavior for POM AI:

| Case | Update license | AI service license & credits |
| --- | --- | --- |
| Normal WordPress | The site's license | The same license |
| Multisite, network-active | Main site / network entry | Explicit key required on every subsite that uses AI |
| Multisite, active on main site + subsites | Main site / network entry | One per site |
| Multisite, active only on a subsite | No updates (no network entry) | The subsite's own license and credits |

Products that consume another product's service key (POM Translate machine translation calls `License_Registry::get_key( 'pom-ai' )`) automatically use the current subsite's explicitly saved key through the same resolution. A network owner may deliberately share one account by saving the same key on several subsites when the plan authorizes all of those domains.

### License_Client

HTTP wrapper for `pom-lic/v2`: `validate()`, `status()`, `deactivate_domain()`, `check_update()`, `validate_and_cache()`, `cached_or_revalidate()`. Every request containing a license key is signed over its exact JSON body with `X-POM-Timestamp`, a random `X-POM-Nonce`, and `X-POM-Signature` (SHA-256 HMAC keyed by the license key). `signed_headers()` is public for product APIs such as POM AI that use the same transport contract. The API base is filterable via `pom_license_api_base` for local/staging account servers.

`Private_Updater` is the licensed v2 compatibility fallback for an active product when the common POM Standard updater is unavailable. It exposes no metadata without a stored key, refreshes the one-time token at installation time, and verifies the package SHA-256. The common updater remains authoritative because it can discover inactive plugins/themes.

Grace behavior: a `network_error` (account outage) keeps the previously cached valid state while `grace_until` (7 days after the last valid check) has not passed; deterministic failures (invalid key, revoked, expired, product mismatch) clear grace immediately.

### License_Field and the License settings tab

`License_Field` renders the reusable license UI: masked key after save, status line, Save/Replace, Revalidate, and Unlink actions posted to `admin-post.php` (`pom_license_field_action`, per-product nonce). A replacement candidate is validated before it overwrites the working key, and unlink keeps the local key unless remote deactivation succeeds. Multisite keys can only be changed by network administrators; site administrators see masked status only — except blog-scoped service products (POM AI) on a subsite, where the field manages the subsite's own entry and that site's administrators (`manage_options`) may edit it.

On a subsite, the field for a service-scoped product automatically switches to the site-license presentation: title "*<Product> license for this site*", description explaining that the key authorizes this domain to use the product and consume credits from the license owner's account, plus a permanent note that updates still depend on the main-site/network license. A missing blog entry remains missing even when a network key exists. All actions (save, revalidate, unlink) are pinned server-side to the management scope, so a subsite form can never revalidate or unlink the network key used for updates.

Products never register their own license submenu. Instead they add a "License" section to their existing framework settings page through their settings filter:

```php
use POMFramework\Licensing\License_Field;

add_filter('my_plugin_settings', static function ($settings) {
    if (is_array($settings) && class_exists(License_Field::class)) {
        $settings['license'] = License_Field::settings_section('my-product', 'My Product');
    }

    return $settings;
});
```

That renders the tab at `options-general.php?page=<page>&section=license`. A consumer that already owns a settings section can instead use `License_Field::settings_tab($product_slug, $label)` and assign it to `$settings['<section>']['tab']['license']`.

Because the form posts to `admin-post.php` (which never fires settings page hooks), adapters must register the handler on every admin request:

```php
add_action('init', static function (): void {
    if (class_exists(License_Field::class)) {
        License_Field::boot();
    }
}, 5);
```

After a successful save-and-validate the `pom_license_saved` action fires with `($product_slug, $result)` — POM AI uses it to delete its legacy key-pair option.

### License_Notices

`License_Notices::register( $slug, $label, $settings_path )` (called from each
adapter's `init` hook) adds the product to one combined, dismissible wp-admin
notice that only appears when a license actually needs action: no stored key,
undecryptable key, or a deterministic validation failure (invalid, expired,
revoked, product mismatch, domain problems). Valid licenses, outage grace
windows, and transient network errors never nag. On multisite the notice
respects the network-scoped registry: a valid network license silences every
subsite, and when something is missing only users with
`manage_network_options` see it, and only in the network admin or on the main
site — subsite screens always stay clean.

Blog-scoped service products (POM AI) are the exception on subsite screens:
that site's own administrators see a notice whenever their blog-scoped entry
is missing or needs action, regardless of the network entry. The notice links
to the subsite's own license tab and explains that services and account-owned
credits — not updates — are what the site license controls.

The shared client also reports `multisite: '1'` to account.pom.es on every
call; the server rejects update checks from multisite installs whose plan
lacks `allows_multisite` (`multisite_not_allowed`) while leaving service
operations untouched, and validation responses include `multisite_allowed`.

### Custom subsection rendering with render_callback

`POM_Framework_Settings::render_content()` supports a `render_callback` key on any subsection definition. When present and callable, the callback receives `($page_slug, $section, $subsection)` and fully replaces the standard settings form for that tab. The license tab is built on this, and any product can use it for custom tab content without intercepting its whole settings page callback.

### Writing a product license adapter

See `includes/license-adapter.php` in POM Translate/Tweaks/Cache/LMS/Events or `themes/pom/includes/license-adapter.php`. An adapter only declares the canonical product slug, the settings filter to extend, and product-specific constraints (e.g. POM Cache never touches cache hot paths; POM Events owns a plugin settings page even though its event runtime requires the POM Theme). Adapters never invent key options and never bypass the registry.

Coordination rule: many plugins bundle this framework in their own `vendor/pom-labs/pom-framework` copy, and the first registered autoloader wins for the whole request. Rolling out changes to `src/Licensing/` or `POM_Framework_Settings` therefore requires a `composer update pom-labs/pom-framework` in every bundling repository, not only in the licensing consumers.

## Additional resources

- Browse the `src/Fields` directory for renderer implementations you can extend.
- Inspect `class-sanitize.php` to understand how custom sanitizers should be structured before registering them with the framework.
- Review the JavaScript assets enqueued from `assets/js` to customise behaviours such as repeater drag-and-drop or Select2 initialisation.

![Placeholder – resources collage](screenshots/resources-placeholder.png)
