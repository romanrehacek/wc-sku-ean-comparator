# AGENTS.md - WC SKU/EAN Comparator

## Project Overview

WordPress/WooCommerce plugin for importing price lists (CSV/XLS/XLSX), comparing
products against existing WooCommerce products by SKU, EAN, and product name, and
generating output CSV files with match results. The plugin is **informational only** --
it never modifies or deletes WooCommerce products.

- **Language:** PHP (>= 8.0)
- **Platform:** WordPress >= 6.x, WooCommerce >= 7.x
- **Admin location:** Tools > WC SKU/EAN Comparator
- **Upload directory:** `wp-content/uploads/wc-sku-ean-comparator/`
- **Status:** Early development. `PRD.md` has full requirements. Files in `_temp/`
  are prototypes for inspiration only -- not production code.

### Agent Capabilities
- This project utilizes **WordPress Agent Skills** (wp-plugin-development, wp-abilities-api, wp-rest-api). 
- Always refer to these skills when implementing WordPress-specific logic, security checks, or plugin architecture.

---

## Build / Lint / Test Commands

There is currently no build tooling, test framework, or CI/CD pipeline configured.
When these are set up, follow the conventions below.

### PHP Linting (planned)
```bash
# Install PHP_CodeSniffer with WordPress standards
composer require --dev wp-coding-standards/wpcs dealerdirect/phpcodesniffer-composer-installer
# Lint the entire plugin
vendor/bin/phpcs --standard=WordPress .
# Fix auto-fixable issues
vendor/bin/phpcbf --standard=WordPress .
# Lint a single file
vendor/bin/phpcs --standard=WordPress path/to/file.php
```

### Testing (planned)
```bash
# Run all tests
vendor/bin/phpunit
# Run a single test file
vendor/bin/phpunit tests/path/to/SomeTest.php
# Run a single test method
vendor/bin/phpunit --filter test_method_name
```

### Static Analysis (planned)
```bash
composer require --dev phpstan/phpstan szepeviktor/phpstan-wordpress
vendor/bin/phpstan analyse
```

### No JS/CSS Build Step
This plugin has no JavaScript or CSS build pipeline. If one is introduced later,
use `wp_enqueue_script()` / `wp_enqueue_style()` for asset loading.

---

## Code Style Guidelines

Follow the **WordPress Coding Standards (WPCS)** for all PHP code.
Reference: https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/

### Indentation and Formatting
- Use **tabs** for indentation, not spaces.
- Opening braces on the **same line** as the statement (K&R style).
- **Spaces inside parentheses** of control structures: `if ( $condition )`, not `if ($condition)`.
- Spaces around operators: `$a = $b + $c;`
- Spaces on both sides of the concatenation operator: `'Hello ' . $name`
- No space between `require_once` and parenthesis: `require_once( 'file.php' );`
- Single blank line between logical sections. Use comment block separators sparingly.

### Naming Conventions
- **Files:** lowercase kebab-case: `class-comparator-engine.php`, `admin-page.php`
- **Classes:** `Upper_Snake_Case` per WP convention: `class WC_SKU_EAN_Comparator {}`
- **Methods/Functions:** `snake_case`: `function compare_products() {}`
- **Variables:** `snake_case`: `$product_id`, `$sku_to_id_map` (never camelCase)
- **Constants:** `UPPER_SNAKE_CASE`: `define( 'WC_SEC_VERSION', '1.0.0' );`
- **Hooks:** prefix with plugin slug: `wc_sec_before_compare`, `wc_sec_output_row`
- **Database tables:** prefix with `$wpdb->prefix`: `{$wpdb->prefix}wc_sec_history`
- **Nonces:** descriptive action names: `wc_sec_run_comparison`
- **Text domain:** `wc-sku-ean-comparator`

### PHP Version and Type Usage
- Target **PHP 8.0+**. Use union types, named arguments, `match`, and `null-safe`
  operator where they improve clarity.
- Add **type declarations** on all function parameters and return types.
- Add **PHPDoc blocks** on every class, method, and function:
  ```php
  /**
   * Compare imported products against WooCommerce catalog.
   *
   * @param array<string, mixed> $imported_rows Rows from the imported file.
   * @param string[]             $brand_slugs   Selected brand slugs.
   * @return array{matched: int, total: int}
   */
  public function compare( array $imported_rows, array $brand_slugs ): array {
  ```
- Prefer strict comparisons (`===`, `!==`) over loose ones.

### Imports and Autoloading
- Use **Composer PSR-4 autoloading** when `composer.json` is present.
- Namespace: `WC_SKU_EAN_Comparator\`
- Avoid manual `require` / `include` in production code -- rely on the autoloader.
- If not using Composer, use `require_once` and guard with `defined( 'ABSPATH' )`.

### WordPress Security (mandatory)
- **Every file** must start with the ABSPATH guard:
  ```php
  if ( ! defined( 'ABSPATH' ) ) {
      exit;
  }
  ```
- **Sanitize all input:** `sanitize_text_field()`, `absint()`, `sanitize_file_name()`,
  `wp_unslash()`, etc.
- **Escape all output:** `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses()`.
- **Nonce verification** on every form submission and AJAX handler:
  ```php
  check_admin_referer( 'wc_sec_run_comparison', 'wc_sec_nonce' );
  ```
- **Capability checks:** `current_user_can( 'manage_options' )` before any action.
- **Prepared statements** for all database queries:
  ```php
  $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s LIMIT %d", 'product', $limit );
  ```
- Never use `die()` -- use `wp_die()` or `wp_send_json_error()`.

### Error Handling
- Use `WP_Error` objects for recoverable errors, not exceptions.
- Use `wp_send_json_error()` / `wp_send_json_success()` in AJAX handlers.
- Log errors with `error_log()` or a dedicated logger -- never echo errors to users.
- Validate file types and sizes before processing uploads.
- Handle `fopen()` / file operation failures gracefully with user-facing messages.

### Internationalization (i18n)
- Wrap all user-facing strings in translation functions:
  ```php
  __( 'Comparison complete', 'wc-sku-ean-comparator' )
  esc_html__( 'No products found', 'wc-sku-ean-comparator' )
  ```
- Use `_n()` for plurals, `_x()` for context-disambiguated strings.

### Architecture
- Use **OOP with single-responsibility classes**, not procedural scripts.
- Main plugin file (`wc-sku-ean-comparator.php`) should only bootstrap.
- Suggested directory structure:
  ```
  wc-sku-ean-comparator/
  ├── wc-sku-ean-comparator.php   # Plugin header + bootstrap
  ├── includes/
  │   ├── class-plugin.php        # Main plugin orchestrator
  │   ├── class-admin-page.php    # Admin menu + page rendering
  │   ├── class-file-handler.php  # Upload, parse CSV/XLS/XLSX
  │   ├── class-comparator.php    # Core comparison logic
  │   ├── class-history.php       # DB table CRUD for comparison history
  │   └── class-ajax-handler.php  # AJAX endpoint handlers
  ├── assets/
  │   ├── css/
  │   └── js/
  ├── templates/                  # Admin page templates
  ├── languages/                  # .pot/.po/.mo files
  ├── tests/
  ├── _temp/                      # Prototypes (not loaded by plugin)
  ├── PRD.md
  ├── AGENTS.md
  ├── composer.json
  └── phpcs.xml.dist
  ```
- Register hooks in the main plugin file or a dedicated loader class:
  ```php
  add_action( 'admin_menu', [ $admin_page, 'register_menu' ] );
  add_action( 'wp_ajax_wc_sec_compare', [ $ajax_handler, 'handle_compare' ] );
  ```
- Process large datasets in **batches via AJAX** (batch size ~500 products) to
  avoid PHP memory/timeout limits.
- Use `wp_cache_flush()` between batches to manage memory.

### Database
- Custom table: `{$wpdb->prefix}wc_sec_history` (created on activation via `dbDelta()`).
- Always use `$wpdb->prepare()` for parameterized queries.
- Use `$wpdb->prefix` for table name references.
- Product SKU stored in `_sku` post meta; EAN in `_global_unique_id` post meta.

### Asset Enqueuing
- Use `wp_enqueue_script()` and `wp_enqueue_style()` -- never output inline
  `<script>` or `<link>` tags directly.
- Enqueue only on the plugin's admin pages using the `$hook_suffix` check.
- Use `wp_localize_script()` or `wp_add_inline_script()` to pass PHP data to JS.
