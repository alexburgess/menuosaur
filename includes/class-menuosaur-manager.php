<?php
if (!defined('ABSPATH')) {
    exit;
}

class Menuosaur_Manager {
    const CRON_HOOK = 'menuosaur_hourly_catalog_sync';

    /**
     * @var wpdb
     */
    private $wpdb;

    /**
     * @var string
     */
    private $shortcodes_table;

    /**
     * @var string
     */
    private $catalog_table;

    /**
     * @var string
     */
    private $sync_log_table;

    public function __construct($wpdb_object = null) {
        global $wpdb;

        $this->wpdb = $wpdb_object ?: $wpdb;
        $this->shortcodes_table = $this->wpdb->prefix . 'menuosaur_shortcodes';
        $this->catalog_table = $this->wpdb->prefix . 'menuosaur_catalog_cache';
        $this->sync_log_table = $this->wpdb->prefix . 'menuosaur_sync_log';
    }

    public static function install_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $shortcodes_table = $wpdb->prefix . 'menuosaur_shortcodes';
        $catalog_table = $wpdb->prefix . 'menuosaur_catalog_cache';
        $sync_log_table = $wpdb->prefix . 'menuosaur_sync_log';

        $shortcodes_sql = "CREATE TABLE {$shortcodes_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(120) NOT NULL,
            name VARCHAR(191) NOT NULL,
            category_id VARCHAR(191) NULL,
            show_category_heading TINYINT(1) NOT NULL DEFAULT 1,
            show_variation_labels TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            config LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY status (status)
        ) {$charset_collate};";

        $catalog_sql = "CREATE TABLE {$catalog_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            object_id VARCHAR(191) NOT NULL,
            object_type VARCHAR(40) NOT NULL,
            version BIGINT UNSIGNED NOT NULL DEFAULT 0,
            name VARCHAR(255) NULL,
            normalized_name VARCHAR(255) NULL,
            category_id VARCHAR(191) NULL,
            category_ids LONGTEXT NULL,
            item_id VARCHAR(191) NULL,
            category_type VARCHAR(80) NULL,
            description LONGTEXT NULL,
            price_amount BIGINT NULL,
            currency VARCHAR(10) NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            is_archived TINYINT(1) NOT NULL DEFAULT 0,
            raw_json LONGTEXT NULL,
            synced_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY object_id (object_id),
            KEY object_type (object_type),
            KEY category_id (category_id),
            KEY item_id (item_id)
        ) {$charset_collate};";

        $sync_log_sql = "CREATE TABLE {$sync_log_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            status VARCHAR(30) NOT NULL,
            trigger_type VARCHAR(30) NOT NULL,
            message TEXT NULL,
            categories_count INT UNSIGNED NOT NULL DEFAULT 0,
            items_count INT UNSIGNED NOT NULL DEFAULT 0,
            variations_count INT UNSIGNED NOT NULL DEFAULT 0,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY started_at (started_at),
            KEY status (status)
        ) {$charset_collate};";

        dbDelta($shortcodes_sql);
        dbDelta($catalog_sql);
        dbDelta($sync_log_sql);
    }

    public static function schedule_sync_event() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK);
        }
    }

    public static function clear_sync_event() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    public function get_shortcodes_table_name() {
        return $this->shortcodes_table;
    }

    public function get_catalog_table_name() {
        return $this->catalog_table;
    }

    public function get_sync_log_table_name() {
        return $this->sync_log_table;
    }

    public function create_shortcode($name) {
        $name = trim(sanitize_text_field((string) $name));
        if ($name === '') {
            return new WP_Error('menuosaur_shortcode_name_required', __('Shortcode name is required.', 'menuosaur'));
        }

        $now = $this->now_gmt();
        $slug = $this->ensure_unique_shortcode_slug(sanitize_title($name));
        $inserted = $this->wpdb->insert(
            $this->shortcodes_table,
            array(
                'slug' => $slug,
                'name' => $name,
                'category_id' => '',
                'show_category_heading' => 1,
                'show_variation_labels' => 0,
                'status' => 'active',
                'config' => wp_json_encode($this->default_shortcode_config()),
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s')
        );

        if (!$inserted) {
            return new WP_Error('menuosaur_shortcode_create_failed', __('Could not create shortcode.', 'menuosaur'));
        }

        return (int) $this->wpdb->insert_id;
    }

    public function update_shortcode($id, $data) {
        $id = absint($id);
        if ($id <= 0) {
            return new WP_Error('menuosaur_invalid_shortcode', __('Invalid shortcode.', 'menuosaur'));
        }

        $existing = $this->get_shortcode_by_id($id);
        if (!$existing) {
            return new WP_Error('menuosaur_shortcode_not_found', __('Shortcode was not found.', 'menuosaur'));
        }

        $name = isset($data['name']) ? trim(sanitize_text_field((string) $data['name'])) : $existing['name'];
        if ($name === '') {
            return new WP_Error('menuosaur_shortcode_name_required', __('Shortcode name is required.', 'menuosaur'));
        }

        $slug = isset($data['slug']) ? sanitize_title((string) $data['slug']) : $existing['slug'];
        if ($slug === '') {
            $slug = sanitize_title($name);
        }
        $slug = $this->ensure_unique_shortcode_slug($slug, $id);

        $category_id = isset($data['category_id']) ? sanitize_text_field((string) $data['category_id']) : '';
        $status = isset($data['status']) && $data['status'] === 'inactive' ? 'inactive' : 'active';
        $config = isset($data['config']) && is_array($data['config']) ? $data['config'] : $this->default_shortcode_config();

        $updated = $this->wpdb->update(
            $this->shortcodes_table,
            array(
                'slug' => $slug,
                'name' => $name,
                'category_id' => $category_id,
                'show_category_heading' => !empty($data['show_category_heading']) ? 1 : 0,
                'show_variation_labels' => !empty($data['show_variation_labels']) ? 1 : 0,
                'status' => $status,
                'config' => wp_json_encode($this->normalize_shortcode_config($config)),
                'updated_at' => $this->now_gmt(),
            ),
            array('id' => $id),
            array('%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s'),
            array('%d')
        );

        if ($updated === false) {
            return new WP_Error('menuosaur_shortcode_update_failed', __('Could not save shortcode.', 'menuosaur'));
        }

        return true;
    }

    public function get_shortcodes() {
        $rows = $this->wpdb->get_results(
            "SELECT * FROM {$this->shortcodes_table} ORDER BY updated_at DESC, id DESC",
            ARRAY_A
        );

        if (!$rows) {
            return array();
        }

        return array_map(array($this, 'hydrate_shortcode_row'), $rows);
    }

    public function get_shortcode_by_id($id) {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->shortcodes_table} WHERE id = %d",
                absint($id)
            ),
            ARRAY_A
        );

        return $row ? $this->hydrate_shortcode_row($row) : null;
    }

    public function get_shortcode_by_slug($slug) {
        $slug = sanitize_title((string) $slug);
        if ($slug === '') {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->shortcodes_table} WHERE slug = %s",
                $slug
            ),
            ARRAY_A
        );

        return $row ? $this->hydrate_shortcode_row($row) : null;
    }

    public function replace_catalog_cache($objects) {
        if (!is_array($objects)) {
            return new WP_Error('menuosaur_invalid_catalog_cache', __('Invalid catalog cache payload.', 'menuosaur'));
        }

        $now = $this->now_gmt();
        $replace_types = array();
        foreach ($objects as $object) {
            if (is_array($object) && !empty($object['object_type'])) {
                $replace_types[(string) $object['object_type']] = true;
            }
        }

        if (!empty($replace_types)) {
            $types = array_keys($replace_types);
            $placeholders = implode(', ', array_fill(0, count($types), '%s'));
            $this->wpdb->query(
                $this->wpdb->prepare(
                    "UPDATE {$this->catalog_table} SET is_deleted = 1, updated_at = %s WHERE object_type IN ({$placeholders})",
                    array_merge(array($now), $types)
                )
            );
        }

        return $this->upsert_catalog_cache($objects);
    }

    public function upsert_catalog_cache($objects) {
        if (!is_array($objects)) {
            return new WP_Error('menuosaur_invalid_catalog_cache', __('Invalid catalog cache payload.', 'menuosaur'));
        }

        $counts = array(
            'CATEGORY' => 0,
            'ITEM' => 0,
            'ITEM_VARIATION' => 0,
            'IMAGE' => 0,
        );

        $now = $this->now_gmt();
        foreach ($objects as $object) {
            if (!is_array($object) || empty($object['object_id']) || empty($object['object_type'])) {
                continue;
            }

            $type = (string) $object['object_type'];
            if (!isset($counts[$type])) {
                continue;
            }

            $data = array(
                'object_id' => sanitize_text_field((string) $object['object_id']),
                'object_type' => sanitize_text_field($type),
                'version' => isset($object['version']) ? (int) $object['version'] : 0,
                'name' => isset($object['name']) ? sanitize_text_field((string) $object['name']) : '',
                'normalized_name' => $this->normalize_text(isset($object['name']) ? (string) $object['name'] : ''),
                'category_id' => isset($object['category_id']) ? sanitize_text_field((string) $object['category_id']) : '',
                'category_ids' => isset($object['category_ids']) && is_array($object['category_ids'])
                    ? wp_json_encode(array_values(array_map('sanitize_text_field', $object['category_ids'])))
                    : wp_json_encode(array()),
                'item_id' => isset($object['item_id']) ? sanitize_text_field((string) $object['item_id']) : '',
                'category_type' => isset($object['category_type']) ? sanitize_text_field((string) $object['category_type']) : '',
                'description' => isset($object['description']) ? wp_kses_post((string) $object['description']) : '',
                'price_amount' => isset($object['price_amount']) && $object['price_amount'] !== null ? (int) $object['price_amount'] : null,
                'currency' => isset($object['currency']) ? sanitize_text_field((string) $object['currency']) : '',
                'is_deleted' => !empty($object['is_deleted']) ? 1 : 0,
                'is_archived' => !empty($object['is_archived']) ? 1 : 0,
                'raw_json' => isset($object['raw_json']) ? wp_json_encode($object['raw_json']) : '',
                'synced_at' => $now,
                'updated_at' => $now,
            );

            $replaced = $this->wpdb->replace(
                $this->catalog_table,
                $data,
                array('%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s')
            );

            if ($replaced === false) {
                return new WP_Error('menuosaur_catalog_cache_failed', __('Could not update catalog cache.', 'menuosaur'));
            }

            if (empty($object['is_deleted']) && empty($object['is_archived'])) {
                $counts[$type]++;
            }
        }

        return array(
            'categories' => $counts['CATEGORY'],
            'items' => $counts['ITEM'],
            'variations' => $counts['ITEM_VARIATION'],
            'images' => $counts['IMAGE'],
        );
    }

    public function get_categories() {
        $rows = $this->wpdb->get_results(
            "SELECT * FROM {$this->catalog_table}
            WHERE object_type = 'CATEGORY' AND is_deleted = 0 AND is_archived = 0
            ORDER BY name ASC, category_type ASC",
            ARRAY_A
        );

        return $this->hydrate_catalog_rows($rows);
    }

    public function get_items_by_category($category_id = '') {
        $rows = $this->wpdb->get_results(
            "SELECT * FROM {$this->catalog_table}
            WHERE object_type = 'ITEM' AND is_deleted = 0 AND is_archived = 0
            ORDER BY name ASC",
            ARRAY_A
        );

        $items = $this->hydrate_catalog_rows($rows);
        $category_id = (string) $category_id;
        if ($category_id === '') {
            return $items;
        }

        return array_values(
            array_filter(
                $items,
                function ($item) use ($category_id) {
                    $category_ids = isset($item['category_ids']) && is_array($item['category_ids']) ? $item['category_ids'] : array();
                    return in_array($category_id, $category_ids, true);
                }
            )
        );
    }

    public function get_all_items() {
        return $this->get_items_by_category('');
    }

    public function get_items_by_categories($category_ids = array()) {
        $category_ids = is_array($category_ids) ? array_values(array_filter(array_map('strval', $category_ids))) : array();
        if (empty($category_ids)) {
            return array();
        }

        $items = $this->get_all_items();
        return array_values(
            array_filter(
                $items,
                function ($item) use ($category_ids) {
                    $item_category_ids = isset($item['category_ids']) && is_array($item['category_ids']) ? $item['category_ids'] : array();
                    return (bool) array_intersect($category_ids, $item_category_ids);
                }
            )
        );
    }

    public function get_variations_by_item($item_id) {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->catalog_table}
                WHERE object_type = 'ITEM_VARIATION' AND item_id = %s AND is_deleted = 0 AND is_archived = 0
                ORDER BY name ASC, object_id ASC",
                (string) $item_id
            ),
            ARRAY_A
        );

        return $this->hydrate_catalog_rows($rows);
    }

    public function get_catalog_object($object_id) {
        $object_id = sanitize_text_field((string) $object_id);
        if ($object_id === '') {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->catalog_table} WHERE object_id = %s",
                $object_id
            ),
            ARRAY_A
        );

        return $row ? $this->hydrate_catalog_row($row) : null;
    }

    public function get_catalog_counts() {
        $defaults = array(
            'categories' => 0,
            'items' => 0,
            'variations' => 0,
        );

        $rows = $this->wpdb->get_results(
            "SELECT object_type, COUNT(*) AS total
            FROM {$this->catalog_table}
            WHERE is_deleted = 0 AND is_archived = 0
            GROUP BY object_type",
            ARRAY_A
        );

        foreach ((array) $rows as $row) {
            $type = isset($row['object_type']) ? (string) $row['object_type'] : '';
            $total = isset($row['total']) ? (int) $row['total'] : 0;
            if ($type === 'CATEGORY') {
                $defaults['categories'] = $total;
            } elseif ($type === 'ITEM') {
                $defaults['items'] = $total;
            } elseif ($type === 'ITEM_VARIATION') {
                $defaults['variations'] = $total;
            }
        }

        return $defaults;
    }

    public function log_sync($args) {
        $defaults = array(
            'status' => 'success',
            'trigger_type' => 'manual',
            'message' => '',
            'categories_count' => 0,
            'items_count' => 0,
            'variations_count' => 0,
            'started_at' => $this->now_gmt(),
            'finished_at' => $this->now_gmt(),
        );

        $args = wp_parse_args($args, $defaults);

        $inserted = $this->wpdb->insert(
            $this->sync_log_table,
            array(
                'status' => sanitize_key((string) $args['status']),
                'trigger_type' => sanitize_key((string) $args['trigger_type']),
                'message' => sanitize_textarea_field((string) $args['message']),
                'categories_count' => absint($args['categories_count']),
                'items_count' => absint($args['items_count']),
                'variations_count' => absint($args['variations_count']),
                'started_at' => sanitize_text_field((string) $args['started_at']),
                'finished_at' => sanitize_text_field((string) $args['finished_at']),
            ),
            array('%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s')
        );

        return $inserted ? (int) $this->wpdb->insert_id : 0;
    }

    public function get_recent_sync_logs($limit = 10) {
        $limit = max(1, min(50, absint($limit)));
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->sync_log_table} ORDER BY started_at DESC, id DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        return $rows ?: array();
    }

    public function get_last_sync_log() {
        $row = $this->wpdb->get_row(
            "SELECT * FROM {$this->sync_log_table} ORDER BY started_at DESC, id DESC LIMIT 1",
            ARRAY_A
        );

        return $row ?: null;
    }

    public function now_gmt() {
        return current_time('mysql', true);
    }

    public function default_shortcode_config() {
        return array(
            'item_order' => array(),
            'variations' => array(),
            'heading_text' => '',
            'display' => array(
                'show_item_name' => 1,
                'show_item_image' => 0,
                'show_description' => 1,
                'show_prices' => 1,
                'image_size' => 'square_original',
                'custom_attribute_keys' => array(),
                'show_custom_attribute_labels' => 0,
            ),
            'category_ids' => array(),
        );
    }

    public function normalize_shortcode_config($config) {
        $normalized = $this->default_shortcode_config();

        if (isset($config['item_order']) && is_array($config['item_order'])) {
            $normalized['item_order'] = array_values(
                array_filter(
                    array_map('sanitize_text_field', $config['item_order']),
                    function ($value) {
                        return $value !== '';
                    }
                )
            );
        }

        if (isset($config['variations']) && is_array($config['variations'])) {
            foreach ($config['variations'] as $item_id => $variation_ids) {
                $item_id = sanitize_text_field((string) $item_id);
                if ($item_id === '' || !is_array($variation_ids)) {
                    continue;
                }

                $normalized['variations'][$item_id] = array_values(
                    array_filter(
                        array_map('sanitize_text_field', $variation_ids),
                        function ($value) {
                            return $value !== '';
                        }
                    )
                );
            }
        }

        if (isset($config['heading_text'])) {
            $normalized['heading_text'] = trim(sanitize_text_field((string) $config['heading_text']));
        }

        if (isset($config['display']) && is_array($config['display'])) {
            $display = $config['display'];
            $normalized['display']['show_item_name'] = !empty($display['show_item_name']) ? 1 : 0;
            $normalized['display']['show_item_image'] = !empty($display['show_item_image']) ? 1 : 0;
            $normalized['display']['show_description'] = !empty($display['show_description']) ? 1 : 0;
            $normalized['display']['show_prices'] = !empty($display['show_prices']) ? 1 : 0;
            $normalized['display']['show_custom_attribute_labels'] = !empty($display['show_custom_attribute_labels']) ? 1 : 0;
            if (isset($display['image_size']) && in_array($display['image_size'], array('square_original', 'thumbnail', 'medium', 'large'), true)) {
                $normalized['display']['image_size'] = $display['image_size'];
            }

            if (isset($display['custom_attribute_keys']) && is_array($display['custom_attribute_keys'])) {
                $normalized['display']['custom_attribute_keys'] = array_values(
                    array_filter(
                        array_map('sanitize_text_field', $display['custom_attribute_keys']),
                        function ($value) {
                            return $value !== '';
                        }
                    )
                );
            }
        }

        if (isset($config['category_ids']) && is_array($config['category_ids'])) {
            $normalized['category_ids'] = array_values(
                array_filter(
                    array_map('sanitize_text_field', $config['category_ids']),
                    function ($value) {
                        return $value !== '';
                    }
                )
            );
        }

        return $normalized;
    }

    private function hydrate_shortcode_row($row) {
        $row['id'] = isset($row['id']) ? (int) $row['id'] : 0;
        $row['show_category_heading'] = !empty($row['show_category_heading']);
        $row['show_variation_labels'] = !empty($row['show_variation_labels']);
        $config = isset($row['config']) ? json_decode((string) $row['config'], true) : null;
        $row['config'] = $this->normalize_shortcode_config(is_array($config) ? $config : array());

        return $row;
    }

    private function ensure_unique_shortcode_slug($slug, $exclude_id = 0) {
        $slug = sanitize_title((string) $slug);
        if ($slug === '') {
            $slug = 'menu';
        }

        $base = $slug;
        $suffix = 2;
        while ($this->shortcode_slug_exists($slug, $exclude_id)) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    private function shortcode_slug_exists($slug, $exclude_id = 0) {
        $exclude_id = absint($exclude_id);
        if ($exclude_id > 0) {
            $found = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT id FROM {$this->shortcodes_table} WHERE slug = %s AND id <> %d LIMIT 1",
                    $slug,
                    $exclude_id
                )
            );
        } else {
            $found = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT id FROM {$this->shortcodes_table} WHERE slug = %s LIMIT 1",
                    $slug
                )
            );
        }

        return !empty($found);
    }

    private function hydrate_catalog_rows($rows) {
        if (!$rows) {
            return array();
        }

        return array_map(array($this, 'hydrate_catalog_row'), $rows);
    }

    private function hydrate_catalog_row($row) {
        $row['id'] = isset($row['id']) ? (int) $row['id'] : 0;
        $row['version'] = isset($row['version']) ? (int) $row['version'] : 0;
        $row['price_amount'] = isset($row['price_amount']) && $row['price_amount'] !== null ? (int) $row['price_amount'] : null;
        $row['is_deleted'] = !empty($row['is_deleted']);
        $row['is_archived'] = !empty($row['is_archived']);

        $category_ids = isset($row['category_ids']) ? json_decode((string) $row['category_ids'], true) : array();
        $row['category_ids'] = is_array($category_ids) ? array_values(array_filter(array_map('strval', $category_ids))) : array();

        $raw = isset($row['raw_json']) ? json_decode((string) $row['raw_json'], true) : null;
        $row['raw_json'] = is_array($raw) ? $raw : array();

        return $row;
    }

    private function normalize_text($value) {
        $value = wp_strip_all_tags((string) $value);
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }
}
