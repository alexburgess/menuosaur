# Menuosaur Agent Notes

Last verified: 2026-06-19.

## Project Summary

Menuosaur is a WordPress plugin that builds frontend menu shortcodes from a cached Square catalog. It does not write back to Square. It reads Square regular categories, items, item variations, prices, descriptions, food and beverage dietary/allergen metadata, custom attributes, and selected image objects, then lets WordPress admins assemble named menu shortcodes from that cached data.

Current local plugin version: `1.0.19` in `menuosaur.php`.

Main public shortcodes:

- `[menuosaur id="your-shortcode-slug"]` renders a saved menu.
- `[menuosaur_symbol_key]` renders a dietary/allergen key for Menuosaur menus on the current page.
- `[menuosaur_key]` is an alias for the symbol key shortcode.

## Repository Layout

- `menuosaur.php`: WordPress plugin header, constants, activation/deactivation hooks, includes, and singleton bootstrap.
- `includes/class-menuosaur-plugin.php`: main controller. Owns admin pages, admin post handlers, Square API calls, catalog sync, shortcode rendering, symbol key rendering, image caching, settings, and formatting helpers.
- `includes/class-menuosaur-manager.php`: persistence layer. Creates plugin tables, manages shortcode CRUD, catalog cache reads/writes, sync logs, config defaults, and row hydration.
- `assets/js/admin.js`: admin builder behavior for category filtering, product search, add/remove selected items, drag ordering, item quantities, custom image aspect ratio toggling, and copy buttons.
- `assets/css/admin.css`: WordPress admin UI styling for Menuosaur pages.
- `assets/css/frontend.css`: intentionally light frontend styles for menu markup, images, symbols, and symbol keys.
- `assets/css/menu-icon.css` and `assets/icon.svg`: admin sidebar icon styling/assets.
- `assets/fontawesome/`: bundled Font Awesome CSS/webfonts used by admin screens.
- `README.md`: user-facing plugin documentation and example frontend CSS.

There is no Composer package, Node package, or test harness in this repository at the time of writing.

## WordPress Integration

Activation calls `Menuosaur_Manager::install_tables()`, stores `menuosaur_db_version`, merges default `menuosaur_settings`, and schedules the hourly catalog sync. Deactivation clears the hourly sync hook and the image cache batch hook.

Admin menu pages live under the top-level Menuosaur sidebar item:

- Menus
- Catalog Sync
- Symbol Key
- Settings
- About

Admin post actions:

- `menuosaur_create_shortcode`
- `menuosaur_save_shortcode`
- `menuosaur_save_settings`
- `menuosaur_sync_catalog`
- `menuosaur_test_square_connection`

All admin post handlers call `assert_admin_post()` for capability and nonce checks.

## Database And Options

Tables created with the WordPress table prefix:

- `{prefix}menuosaur_shortcodes`: saved menu shortcode definitions. Important columns include `slug`, `name`, `category_id`, `show_category_heading`, `show_variation_labels`, `status`, and serialized JSON `config`.
- `{prefix}menuosaur_catalog_cache`: cached Square objects. Important columns include `object_id`, `object_type`, `version`, `name`, `category_id`, JSON `category_ids`, `item_id`, `category_type`, `description`, `price_amount`, `currency`, deletion/archive flags, raw JSON, and timestamps.
- `{prefix}menuosaur_sync_log`: recent sync records with status, trigger, message, counts, and timestamps.

Options used:

- `menuosaur_db_version`
- `menuosaur_settings`
- `menuosaur_image_cache_queue`
- `menuosaur_image_cache`

Cron hooks:

- `menuosaur_hourly_catalog_sync`: hourly Square catalog refresh.
- `menuosaur_image_cache_batch`: single-event/batched remote image cache worker.

## Settings

Default settings are defined in `Menuosaur_Plugin::default_settings()`:

- `square_environment`: `production`
- `square_access_token`: empty by default
- `square_api_version`: `2026-01-22`
- `square_location_id`: empty by default
- `sort_variations_by_price`: off by default
- `hide_currency_symbol`: off by default
- `admin_menu_label`: `Menuosaur`

Square tokens are stored in WordPress options and are only sent server-side. The settings screen masks the saved token; entering a non-empty token replaces it, and the clear checkbox removes it.

## Square Catalog Flow

Square base URLs:

- Production: `https://connect.squareup.com/v2`
- Sandbox: `https://connect.squareupsandbox.com/v2`

Requests use WordPress HTTP APIs, bearer auth, `Content-Type: application/json`, and the configured `Square-Version` header.

Important endpoints:

- `POST /catalog/search`: used to test the token and to fetch categories.
- `POST /catalog/search-catalog-items`: primary item fetch path, optionally filtered by `square_location_id`.
- `POST /catalog/search` with `object_types: ["ITEM"]`: fallback item fetch path if item search returns empty.
- `POST /catalog/batch-retrieve`: fetches Square image objects by ID.

Sync behavior:

- Manual sync and cron both call `sync_square_catalog()`.
- Missing Square token logs a failed sync and returns a `WP_Error`.
- The plugin normalizes and caches `CATEGORY`, `ITEM`, `ITEM_VARIATION`, and `IMAGE` objects.
- `replace_catalog_cache()` marks existing objects of the fetched types deleted, then upserts the normalized result.
- After a successful sync, image cache jobs are queued for saved shortcodes that are configured to display images using a WordPress image size.

The admin builder only exposes Square categories whose `category_type` is exactly `REGULAR_CATEGORY`.

## Shortcode Config Shape

Default config comes from `Menuosaur_Manager::default_shortcode_config()`:

- `item_order`: ordered Square item IDs.
- `variations`: map of item ID to selected variation IDs.
- `enable_item_quantities`: `0` or `1`.
- `item_quantities`: map of item ID to quantity, only stored when greater than 1.
- `heading_text`: optional custom heading.
- `intro_text`: optional text below heading.
- `total_discount_percent`: discount used only for text placeholders.
- `display`: toggles for item name, image, description, prices, dietary symbols, image size/aspect ratio, custom attributes, and attribute labels.
- `category_ids`: selected regular Square category IDs.

Intro text placeholders:

- `{total}` and `{menu_total}`: discounted total.
- `{subtotal}` and `{menu_subtotal}`: pre-discount total.
- `{discount_percent}`: formatted discount percentage.

Cost totals are computed from selected active item variations and respect configured item quantities.

## Admin Builder Behavior

The Menus tab lets admins create a shortcode, then configure:

- Name, slug, status, and copyable shortcode.
- One or more regular Square categories.
- Optional heading visibility and custom heading.
- Optional variation labels before prices.
- Text below heading with total placeholders.
- A discount percentage used only by total placeholders.
- Display toggles for item name, item image, description, prices, dietary/allergen symbols, and quantities.
- Image source/size: original Square URL, WordPress thumbnail, medium, or large.
- Image aspect ratio: natural, square, several fixed portrait/landscape ratios, or a custom ratio.
- Custom Square attributes to display, with optional labels.
- Product selection by category browsing or direct product/variation search.
- Drag-and-drop selected item ordering.
- Per-item variation selection and per-item quantity.

The JS moves selected item cards between available and selected lists, updates hidden order inputs, disables fields for unselected items, filters visible cards by selected categories/search, and handles copy buttons.

## Frontend Rendering

`render_shortcode()` returns empty output when the shortcode is missing, inactive, or has no selected item order.

Frontend rendering:

- Enqueues `assets/css/frontend.css`.
- Emits a `.menuosaur-menu` wrapper with `data-menuosaur-id`, `data-menuosaur-item-count`, and `data-menuosaur-image-aspect-ratio`.
- Adds classes such as `menuosaur-image-aspect-{ratio}` and `menuosaur-item-count-{n}`.
- Uses category heading text from custom `heading_text` first, otherwise the Square category name for one-category menus.
- Skips deleted/archived items and variations.
- Can display item image, item name, quantity, dietary/allergen symbols, description, selected custom attributes, and variation prices.
- Sorts variation prices by saved order unless global `sort_variations_by_price` is enabled.
- Formats money with a small currency-symbol map for USD, CAD, GBP, EUR, AUD, and NZD, with an option to hide currency symbols.

The frontend CSS is deliberately minimal so themes can control layout and typography.

## Images

Images can come from:

- Square image objects referenced by item `image_ids`.
- The first `ecom_image_uris` entry as a fallback source.

If a menu uses a WordPress image size (`thumbnail`, `medium`, or `large`), Menuosaur queues the remote image for Media Library caching and falls back to the original Square URL until a cached attachment is available.

The image cache:

- Stores jobs in `menuosaur_image_cache_queue`.
- Processes at most 5 jobs per `menuosaur_image_cache_batch` run.
- Retries failed jobs up to 2 times.
- Stores cached attachment metadata in `menuosaur_image_cache`.
- Deletes stale cached attachments when the source/version changes.

## Dietary And Allergen Symbols

Menuosaur reads Square `food_and_beverage_details` from item raw JSON.

Dietary preferences map examples:

- `DAIRY_FREE` -> `DF`
- `GLUTEN_FREE` -> `GF`
- `HALAL` -> `H`
- `KOSHER` -> `K`
- `NUT_FREE` -> `NF`
- `VEGAN` -> `VG`
- `VEGETARIAN` -> `V`

Allergen examples include `CELERY`, `CRUSTACEANS`, `EGGS`, `FISH`, `GLUTEN`, `MILK`, `PEANUTS`, `SESAME`, `SOY`, `SULPHITES`, and `TREE_NUTS`. Allergen labels render as "Contains {label}" in the symbol key.

Unknown/custom labels get generated 1-2 character codes from their words. Symbols are de-duplicated by group and normalized label/key.

`[menuosaur_symbol_key]` first includes symbols remembered from menus already rendered during the current page request, then scans the current post content for `[menuosaur id="..."]` shortcodes unless explicit `ids` are provided.

## Publishing Target

The plugin can be published to:

```text
alex@100.65.127.83
```

SSH connectivity was tested successfully on 2026-06-19 with:

```sh
ssh -o BatchMode=yes -o ConnectTimeout=8 -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=/private/tmp/menuosaur_known_hosts alex@100.65.127.83 'hostname && pwd'
```

Result:

- Hostname: `stwlsbwb1`
- Login user: `alex`
- Login directory: `/home/alex`

Useful remote findings:

- `/home/alex/menuosaur-deploy` exists and mirrors the plugin files.
- `/home/alex/menuosaur-deploy` is not a Git repository.
- Remote `menuosaur.php` currently reports version `1.0.19`, matching local.
- A read-only search did not find `wp-content` directories under `/home/alex` to depth 5.
- Treat `/home/alex/menuosaur-deploy` as the known deploy/staging directory unless the user gives a live WordPress plugin path.

Do not publish secrets. Do not assume the remote deploy directory is live WordPress; verify the final deployment path before overwriting anything outside `/home/alex/menuosaur-deploy`.

## Practical Development Notes

- Use WordPress escaping/sanitizing functions consistently. The existing code already leans heavily on `esc_html`, `esc_attr`, `esc_url`, `sanitize_text_field`, `sanitize_key`, `wp_unslash`, `wp_kses_post`, and `wp_json_encode`.
- Keep frontend markup theme-neutral. The README explicitly positions the frontend CSS as light and easy for themes to override.
- Preserve backward compatibility for saved shortcode configs. `normalize_shortcode_config()` is the central place for defaults and migration-like cleanup.
- Be careful with Square object deletion/archive flags. Public rendering hides deleted/archived items and variations; the admin builder surfaces cleanup warnings.
- If changing displayed config, update all of: default config, normalization, admin render/save path, frontend render path, and README/docs if user-facing.
- If changing image behavior, check both Square object fetching and WordPress Media Library cache behavior.
- If changing symbol behavior, check item rendering, symbol key rendering, symbol collection from page content, and the Symbol Key admin preview.

## Basic Verification

There is no dedicated automated test suite. Useful quick checks:

```sh
php -l menuosaur.php
php -l includes/class-menuosaur-plugin.php
php -l includes/class-menuosaur-manager.php
```

For real behavior, install the plugin in WordPress, configure Square settings, use Test Square Connection, run Catalog Sync, create or edit a menu, and render `[menuosaur id="..."]` plus `[menuosaur_symbol_key]` on a page.
