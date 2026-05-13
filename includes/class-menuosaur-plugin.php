<?php
if (!defined('ABSPATH')) {
    exit;
}

class Menuosaur_Plugin {
    const IMAGE_CACHE_CRON_HOOK = 'menuosaur_image_cache_batch';

    /**
     * @var Menuosaur_Plugin|null
     */
    private static $instance = null;

    /**
     * @var Menuosaur_Manager
     */
    private $manager;

    /**
     * @var array
     */
    private $settings;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function activate() {
        Menuosaur_Manager::install_tables();
        update_option('menuosaur_db_version', defined('MENUOSAUR_DB_VERSION') ? MENUOSAUR_DB_VERSION : '1');

        $defaults = self::default_settings();
        $existing = get_option('menuosaur_settings', array());
        update_option('menuosaur_settings', wp_parse_args(is_array($existing) ? $existing : array(), $defaults));

        Menuosaur_Manager::schedule_sync_event();
    }

    public static function deactivate() {
        Menuosaur_Manager::clear_sync_event();
        wp_clear_scheduled_hook(self::IMAGE_CACHE_CRON_HOOK);
    }

    public static function default_settings() {
        return array(
            'square_environment' => 'production',
            'square_access_token' => '',
            'square_api_version' => '2026-01-22',
            'square_location_id' => '',
            'sort_variations_by_price' => 0,
            'hide_currency_symbol' => 0,
            'admin_menu_label' => 'Menuosaur',
        );
    }

    private function __construct() {
        $this->settings = wp_parse_args(get_option('menuosaur_settings', array()), self::default_settings());
        $this->manager = new Menuosaur_Manager();
        $this->maybe_upgrade_database();

        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_filter('admin_footer_text', array($this, 'filter_admin_footer_text'), 20, 1);

        add_action('admin_post_menuosaur_create_shortcode', array($this, 'handle_create_shortcode'));
        add_action('admin_post_menuosaur_save_shortcode', array($this, 'handle_save_shortcode'));
        add_action('admin_post_menuosaur_save_settings', array($this, 'handle_save_settings'));
        add_action('admin_post_menuosaur_sync_catalog', array($this, 'handle_sync_catalog'));
        add_action('admin_post_menuosaur_test_square_connection', array($this, 'handle_test_square_connection'));

        add_action(Menuosaur_Manager::CRON_HOOK, array($this, 'handle_scheduled_sync'));
        add_action(self::IMAGE_CACHE_CRON_HOOK, array($this, 'handle_image_cache_batch'));
        add_shortcode('menuosaur', array($this, 'render_shortcode'));
    }

    public function get_manager() {
        return $this->manager;
    }

    private function maybe_upgrade_database() {
        $target_version = defined('MENUOSAUR_DB_VERSION') ? (string) MENUOSAUR_DB_VERSION : '1';
        $installed_version = (string) get_option('menuosaur_db_version', '');

        if ($installed_version === $target_version) {
            return;
        }

        Menuosaur_Manager::install_tables();
        update_option('menuosaur_db_version', $target_version);
    }

    public function register_admin_menu() {
        $admin_menu_label = $this->get_admin_menu_label();

        add_menu_page(
            __('Menuosaur', 'menuosaur'),
            $admin_menu_label,
            'manage_options',
            'menuosaur-menus',
            array($this, 'render_admin_page'),
            MENUOSAUR_PLUGIN_URL . 'assets/icon.svg',
            56
        );

        add_submenu_page(
            'menuosaur-menus',
            __('Menus', 'menuosaur'),
            __('Menus', 'menuosaur'),
            'manage_options',
            'menuosaur-menus',
            array($this, 'render_admin_page')
        );

        add_submenu_page(
            'menuosaur-menus',
            __('Catalog Sync', 'menuosaur'),
            __('Catalog Sync', 'menuosaur'),
            'manage_options',
            'menuosaur-sync',
            array($this, 'render_admin_page')
        );

        add_submenu_page(
            'menuosaur-menus',
            __('Menuosaur Settings', 'menuosaur'),
            __('Settings', 'menuosaur'),
            'manage_options',
            'menuosaur-settings',
            array($this, 'render_admin_page')
        );

        add_submenu_page(
            'menuosaur-menus',
            __('About Menuosaur', 'menuosaur'),
            __('About', 'menuosaur'),
            'manage_options',
            'menuosaur-about',
            array($this, 'render_admin_page')
        );
    }

    public function enqueue_admin_assets($hook_suffix) {
        wp_enqueue_style(
            'menuosaur-menu-icon',
            MENUOSAUR_PLUGIN_URL . 'assets/css/menu-icon.css',
            array(),
            MENUOSAUR_VERSION
        );

        if (strpos((string) $hook_suffix, 'menuosaur') === false) {
            return;
        }

        wp_enqueue_style(
            'menuosaur-fontawesome',
            MENUOSAUR_PLUGIN_URL . 'assets/fontawesome/css/all.min.css',
            array(),
            MENUOSAUR_VERSION
        );

        wp_enqueue_style(
            'menuosaur-admin',
            MENUOSAUR_PLUGIN_URL . 'assets/css/admin.css',
            array('menuosaur-fontawesome'),
            MENUOSAUR_VERSION
        );

        wp_enqueue_script(
            'menuosaur-admin',
            MENUOSAUR_PLUGIN_URL . 'assets/js/admin.js',
            array(),
            MENUOSAUR_VERSION,
            true
        );

        wp_localize_script(
            'menuosaur-admin',
            'menuosaurAdmin',
            array(
                'categoryPlaceholder' => __('Choose a category to show matching items.', 'menuosaur'),
                'noItemsLabel' => __('No cached items match this category yet. Sync the Square catalog or choose another category.', 'menuosaur'),
            )
        );
    }

    public function filter_admin_footer_text($footer_text) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (strpos($page, 'menuosaur') !== 0) {
            return $footer_text;
        }

        return '';
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $tab = $this->get_current_admin_tab();

        echo '<div class="wrap menuosaur-wrap">';
        echo '<h1><span class="menuosaur-heading-icon" aria-hidden="true"></span>' . esc_html__('Menuosaur', 'menuosaur') . '</h1>';

        $this->render_admin_notice();
        $this->render_admin_tabs($tab);

        switch ($tab) {
            case 'sync':
                $this->render_sync_tab();
                break;
            case 'settings':
                $this->render_settings_tab();
                break;
            case 'about':
                $this->render_about_tab();
                break;
            case 'menus':
            default:
                $this->render_menus_tab();
                break;
        }

        echo '</div>';
    }

    private function get_current_admin_tab() {
        $tabs = $this->get_admin_tabs();
        $requested_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        if ($requested_tab !== '' && isset($tabs[$requested_tab])) {
            return $requested_tab;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'menuosaur-menus';
        $page_map = array(
            'menuosaur-sync' => 'sync',
            'menuosaur-settings' => 'settings',
            'menuosaur-about' => 'about',
            'menuosaur-menus' => 'menus',
        );

        return isset($page_map[$page]) ? $page_map[$page] : 'menus';
    }

    private function get_admin_tabs() {
        return array(
            'menus' => array(
                'label' => __('Menus', 'menuosaur'),
                'icon' => 'fa-list-check',
            ),
            'sync' => array(
                'label' => __('Catalog Sync', 'menuosaur'),
                'icon' => 'fa-arrows-rotate',
            ),
            'settings' => array(
                'label' => __('Settings', 'menuosaur'),
                'icon' => 'fa-gear',
            ),
            'about' => array(
                'label' => __('About', 'menuosaur'),
                'icon' => 'fa-circle-info',
            ),
        );
    }

    private function render_admin_tabs($active_tab) {
        echo '<nav class="nav-tab-wrapper menuosaur-nav-tabs">';

        foreach ($this->get_admin_tabs() as $tab_key => $tab) {
            $url = add_query_arg(
                array(
                    'page' => 'menuosaur-menus',
                    'tab' => $tab_key,
                ),
                admin_url('admin.php')
            );
            $classes = array('nav-tab');
            if ($tab_key === $active_tab) {
                $classes[] = 'nav-tab-active';
            }

            echo '<a class="' . esc_attr(implode(' ', $classes)) . '" href="' . esc_url($url) . '">';
            echo '<i class="fa-duotone ' . esc_attr($tab['icon']) . '" aria-hidden="true"></i> ';
            echo esc_html($tab['label']);
            echo '</a>';
        }

        echo '</nav>';
    }

    private function render_menus_tab() {
        $edit_id = isset($_GET['shortcode_id']) ? absint($_GET['shortcode_id']) : 0;
        $shortcode = $edit_id ? $this->manager->get_shortcode_by_id($edit_id) : null;

        if ($edit_id && $shortcode) {
            $this->render_shortcode_builder($shortcode);
        } elseif ($edit_id) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__('That shortcode could not be found.', 'menuosaur') . '</p></div>';
        }

        $this->render_create_shortcode_card();
        $this->render_shortcodes_list();
    }

    private function render_create_shortcode_card() {
        echo '<div class="menuosaur-card menuosaur-create-card">';
        echo '<h2><i class="fa-duotone fa-circle-plus" aria-hidden="true"></i> ' . esc_html__('Create a shortcode', 'menuosaur') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="menuosaur-inline-form">';
        wp_nonce_field('menuosaur_create_shortcode_action', 'menuosaur_nonce');
        echo '<input type="hidden" name="action" value="menuosaur_create_shortcode" />';
        echo '<span class="menuosaur-input-decor menuosaur-input-wide">';
        echo '<span class="menuosaur-input-icon"><i class="fa-duotone fa-pen-to-square" aria-hidden="true"></i></span>';
        echo '<input type="text" name="name" class="regular-text" placeholder="' . esc_attr__('Wine menu, lunch menu, tasting list...', 'menuosaur') . '" required />';
        echo '</span>';
        echo '<button type="submit" class="button button-primary"><i class="fa-duotone fa-circle-plus" aria-hidden="true"></i> ' . esc_html__('Create Shortcode', 'menuosaur') . '</button>';
        echo '</form>';
        echo '</div>';
    }

    private function render_shortcodes_list() {
        $shortcodes = $this->manager->get_shortcodes();

        echo '<div class="menuosaur-card">';
        echo '<h2><i class="fa-duotone fa-list-check" aria-hidden="true"></i> ' . esc_html__('Saved shortcodes', 'menuosaur') . '</h2>';

        if (empty($shortcodes)) {
            echo '<p class="description">' . esc_html__('No menu shortcodes have been created yet.', 'menuosaur') . '</p>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped menuosaur-shortcodes-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Name', 'menuosaur') . '</th>';
        echo '<th>' . esc_html__('Shortcode', 'menuosaur') . '</th>';
        echo '<th>' . esc_html__('Category', 'menuosaur') . '</th>';
        echo '<th>' . esc_html__('Items', 'menuosaur') . '</th>';
        echo '<th>' . esc_html__('Status', 'menuosaur') . '</th>';
        echo '<th>' . esc_html__('Updated', 'menuosaur') . '</th>';
        echo '<th>' . esc_html__('Actions', 'menuosaur') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($shortcodes as $shortcode) {
            $config = isset($shortcode['config']) ? $shortcode['config'] : $this->manager->default_shortcode_config();
            $selected_category_ids = $this->get_shortcode_category_ids($shortcode);
            $category_labels = array();
            foreach ($selected_category_ids as $category_id) {
                $category = $this->manager->get_catalog_object($category_id);
                if ($category) {
                    $category_labels[] = $this->get_category_display_label($category);
                }
            }
            $edit_url = add_query_arg(
                array(
                    'page' => 'menuosaur-menus',
                    'tab' => 'menus',
                    'shortcode_id' => (int) $shortcode['id'],
                ),
                admin_url('admin.php')
            );

            echo '<tr>';
            echo '<td><strong>' . esc_html($shortcode['name']) . '</strong></td>';
            echo '<td><code>[menuosaur id="' . esc_html($shortcode['slug']) . '"]</code></td>';
            echo '<td>' . esc_html(!empty($category_labels) ? implode(', ', $category_labels) : __('Not selected', 'menuosaur')) . '</td>';
            echo '<td>' . esc_html(number_format_i18n(count($config['item_order']))) . '</td>';
            echo '<td>' . $this->get_status_pill($shortcode['status'] === 'active') . '</td>';
            echo '<td>' . esc_html($this->format_admin_datetime($shortcode['updated_at'])) . '</td>';
            echo '<td><a class="button button-small" href="' . esc_url($edit_url) . '">' . esc_html__('Edit', 'menuosaur') . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    private function render_shortcode_builder($shortcode) {
        $categories = $this->filter_regular_categories($this->manager->get_categories());
        $regular_category_lookup = array();
        foreach ($categories as $category) {
            $regular_category_lookup[(string) $category['object_id']] = true;
        }

        $all_items = $this->manager->get_all_items();
        $variations_by_item = array();
        $item_lookup = array();
        $variation_lookup = array();
        $config = $shortcode['config'];
        $display = isset($config['display']) && is_array($config['display']) ? $config['display'] : $this->manager->default_shortcode_config()['display'];
        $image_size = $this->sanitize_image_size(isset($display['image_size']) ? $display['image_size'] : 'square_original');
        $selected_items = isset($config['item_order']) ? $config['item_order'] : array();
        $selected_item_lookup = array_fill_keys($selected_items, true);
        $items = array();

        foreach ($all_items as $item) {
            $item_id = (string) $item['object_id'];
            if (!isset($selected_item_lookup[$item_id]) && !$this->item_belongs_to_category_lookup($item, $regular_category_lookup)) {
                continue;
            }

            $items[] = $item;
            $item_id = (string) $item['object_id'];
            $item_lookup[$item_id] = $item;
            $variations_by_item[$item_id] = $this->manager->get_variations_by_item($item_id);
            foreach ($variations_by_item[$item_id] as $variation) {
                $variation_lookup[(string) $variation['object_id']] = $variation;
            }
        }

        $selected_category_ids = array_values(
            array_filter(
                $this->get_shortcode_category_ids($shortcode),
                function ($category_id) use ($regular_category_lookup) {
                    return isset($regular_category_lookup[(string) $category_id]);
                }
            )
        );
        $item_positions = array();
        $position = 1;
        foreach ($selected_items as $item_id) {
            $item_positions[$item_id] = $position;
            $position++;
        }
        $custom_attribute_options = $this->get_available_custom_attribute_options();
        $selected_attribute_keys = isset($display['custom_attribute_keys']) && is_array($display['custom_attribute_keys']) ? $display['custom_attribute_keys'] : array();
        foreach ($selected_attribute_keys as $selected_attribute_key) {
            if (!isset($custom_attribute_options[$selected_attribute_key])) {
                $custom_attribute_options[$selected_attribute_key] = $this->format_custom_attribute_option($selected_attribute_key, $selected_attribute_key);
            }
        }

        usort(
            $items,
            function ($a, $b) use ($item_positions) {
                $a_id = (string) $a['object_id'];
                $b_id = (string) $b['object_id'];
                $a_selected = isset($item_positions[$a_id]);
                $b_selected = isset($item_positions[$b_id]);

                if ($a_selected && $b_selected) {
                    return $item_positions[$a_id] < $item_positions[$b_id] ? -1 : 1;
                }

                if ($a_selected !== $b_selected) {
                    return $a_selected ? -1 : 1;
                }

                return strnatcasecmp($a['name'], $b['name']);
            }
        );

        echo '<div class="menuosaur-card menuosaur-builder-card" id="menuosaur-builder">';
        echo '<h2><i class="fa-duotone fa-list-check" aria-hidden="true"></i> ' . esc_html__('Configure shortcode', 'menuosaur') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="menuosaur-builder-form">';
        wp_nonce_field('menuosaur_save_shortcode_action', 'menuosaur_nonce');
        echo '<input type="hidden" name="action" value="menuosaur_save_shortcode" />';
        echo '<input type="hidden" name="shortcode_id" value="' . esc_attr((string) $shortcode['id']) . '" />';

        echo '<div class="menuosaur-builder-grid">';
        echo '<div>';
        echo '<label for="menuosaur_shortcode_name">' . esc_html__('Name', 'menuosaur') . '</label>';
        echo '<span class="menuosaur-input-decor menuosaur-input-wide">';
        echo '<span class="menuosaur-input-icon"><i class="fa-duotone fa-pen-to-square" aria-hidden="true"></i></span>';
        echo '<input type="text" name="name" id="menuosaur_shortcode_name" class="regular-text" value="' . esc_attr($shortcode['name']) . '" required />';
        echo '</span>';
        echo '</div>';

        echo '<div>';
        echo '<label for="menuosaur_shortcode_slug">' . esc_html__('Shortcode ID', 'menuosaur') . '</label>';
        echo '<span class="menuosaur-input-decor menuosaur-input-wide">';
        echo '<span class="menuosaur-input-icon"><i class="fa-duotone fa-link" aria-hidden="true"></i></span>';
        echo '<input type="text" name="slug" id="menuosaur_shortcode_slug" class="regular-text" value="' . esc_attr($shortcode['slug']) . '" required />';
        echo '</span>';
        echo '</div>';

        echo '<div>';
        echo '<label for="menuosaur_shortcode_code">' . esc_html__('Paste this shortcode', 'menuosaur') . '</label>';
        echo '<input type="text" id="menuosaur_shortcode_code" class="regular-text code menuosaur-copy-field" readonly value="' . esc_attr('[menuosaur id="' . $shortcode['slug'] . '"]') . '" />';
        echo '</div>';

        echo '<div>';
        echo '<label for="menuosaur_shortcode_status">' . esc_html__('Status', 'menuosaur') . '</label>';
        echo '<select name="status" id="menuosaur_shortcode_status">';
        echo '<option value="active"' . selected($shortcode['status'], 'active', false) . '>' . esc_html__('Active', 'menuosaur') . '</option>';
        echo '<option value="inactive"' . selected($shortcode['status'], 'inactive', false) . '>' . esc_html__('Inactive', 'menuosaur') . '</option>';
        echo '</select>';
        echo '</div>';
        echo '</div>';

        echo '<hr />';

        echo '<div class="menuosaur-builder-options">';
        echo '<label for="menuosaur_category_ids"><strong>' . esc_html__('Regular Square categories', 'menuosaur') . '</strong></label>';
        echo '<select name="category_ids[]" id="menuosaur_category_ids" class="menuosaur-category-select" multiple size="8">';
        foreach ($categories as $category) {
            echo '<option value="' . esc_attr($category['object_id']) . '"' . selected(in_array($category['object_id'], $selected_category_ids, true), true, false) . '>' . esc_html($this->get_category_display_label($category)) . '</option>';
        }
        echo '</select>';
        echo '<p class="description menuosaur-category-help">' . esc_html__('Only Square regular categories are shown. Select categories to browse their items, or use search below to add products individually.', 'menuosaur') . '</p>';

        echo '<label class="menuosaur-checkbox-label"><input type="checkbox" name="show_category_heading" value="1" ' . checked($shortcode['show_category_heading'], true, false) . ' /> ' . esc_html__('Show category as a heading', 'menuosaur') . '</label>';
        echo '<label class="menuosaur-checkbox-label"><input type="checkbox" name="show_variation_labels" value="1" ' . checked($shortcode['show_variation_labels'], true, false) . ' /> ' . esc_html__('Show variation labels before prices', 'menuosaur') . '</label>';
        echo '</div>';

        echo '<div class="menuosaur-display-panel">';
        echo '<h3>' . esc_html__('Displayed content', 'menuosaur') . '</h3>';
        echo '<div class="menuosaur-display-options">';
        echo '<label class="menuosaur-checkbox-label"><input type="checkbox" name="display_show_item_name" value="1" ' . checked(!empty($display['show_item_name']), true, false) . ' /> ' . esc_html__('Item name', 'menuosaur') . '</label>';
        echo '<label class="menuosaur-checkbox-label"><input type="checkbox" name="display_show_item_image" value="1" ' . checked(!empty($display['show_item_image']), true, false) . ' /> ' . esc_html__('Item image', 'menuosaur') . '</label>';
        echo '<label class="menuosaur-checkbox-label"><input type="checkbox" name="display_show_description" value="1" ' . checked(!empty($display['show_description']), true, false) . ' /> ' . esc_html__('Description', 'menuosaur') . '</label>';
        echo '<label class="menuosaur-checkbox-label"><input type="checkbox" name="display_show_prices" value="1" ' . checked(!empty($display['show_prices']), true, false) . ' /> ' . esc_html__('Prices', 'menuosaur') . '</label>';
        echo '</div>';
        echo '<div class="menuosaur-image-size-field">';
        echo '<label for="menuosaur_display_image_size">' . esc_html__('Image source / size', 'menuosaur') . '</label>';
        echo '<select name="display_image_size" id="menuosaur_display_image_size">';
        foreach ($this->get_image_size_options() as $size_value => $size_label) {
            echo '<option value="' . esc_attr($size_value) . '"' . selected($image_size, $size_value, false) . '>' . esc_html($size_label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('WordPress sizes are cached into the Media Library from Square images during sync or first render.', 'menuosaur') . '</p>';
        echo '</div>';

        echo '<div class="menuosaur-custom-attributes">';
        echo '<h4>' . esc_html__('Custom attributes', 'menuosaur') . '</h4>';
        if (empty($custom_attribute_options)) {
            echo '<p class="description">' . esc_html__('No item or variation custom attributes are cached yet. Sync the Square catalog after adding custom attributes in Square.', 'menuosaur') . '</p>';
        } else {
            echo '<div class="menuosaur-attribute-options">';
            foreach ($custom_attribute_options as $attribute_key => $attribute_option) {
                echo '<label class="menuosaur-checkbox-label"><input type="checkbox" name="display_custom_attribute_keys[]" value="' . esc_attr($attribute_key) . '" ' . checked(in_array($attribute_key, $selected_attribute_keys, true), true, false) . ' /> ' . esc_html($attribute_option['label']) . '</label>';
            }
            echo '</div>';
            echo '<label class="menuosaur-checkbox-label menuosaur-attribute-label-toggle"><input type="checkbox" name="display_show_custom_attribute_labels" value="1" ' . checked(!empty($display['show_custom_attribute_labels']), true, false) . ' /> ' . esc_html__('Show attribute labels on the menu', 'menuosaur') . '</label>';
        }
        echo '</div>';
        echo '</div>';

        $this->render_builder_warnings($shortcode, $item_lookup, $variation_lookup);

        echo '<div class="menuosaur-builder-browser">';
        echo '<div class="menuosaur-selected-panel">';
        echo '<div class="menuosaur-panel-head">';
        echo '<h3>' . esc_html__('Selected items', 'menuosaur') . '</h3>';
        echo '<p class="description">' . esc_html__('Drag selected items to set the public menu order.', 'menuosaur') . '</p>';
        echo '</div>';
        echo '<p class="menuosaur-selected-empty" hidden>' . esc_html__('No items selected yet.', 'menuosaur') . '</p>';
        echo '<div class="menuosaur-selected-items">';

        foreach ($items as $item) {
            $item_id = (string) $item['object_id'];
            $is_selected = isset($selected_item_lookup[$item_id]);
            if (!$is_selected) {
                continue;
            }

            $this->render_builder_item_card($item, isset($variations_by_item[$item_id]) ? $variations_by_item[$item_id] : array(), $config, true, isset($item_positions[$item_id]) ? (int) $item_positions[$item_id] : 100);
        }

        echo '</div>';
        echo '</div>';

        echo '<div class="menuosaur-available-panel">';
        echo '<div class="menuosaur-panel-head">';
        echo '<h3>' . esc_html__('Add items', 'menuosaur') . '</h3>';
        echo '<label for="menuosaur_item_search" class="screen-reader-text">' . esc_html__('Search items', 'menuosaur') . '</label>';
        echo '<input type="search" id="menuosaur_item_search" class="regular-text menuosaur-item-search" placeholder="' . esc_attr__('Search product names...', 'menuosaur') . '" autocomplete="off" />';
        echo '</div>';
        echo '<p class="menuosaur-picker-placeholder">' . esc_html__('Choose a regular category or search by product name.', 'menuosaur') . '</p>';
        echo '<p class="menuosaur-picker-empty" hidden>' . esc_html__('No cached items match this category or search.', 'menuosaur') . '</p>';
        echo '<div class="menuosaur-available-items">';

        foreach ($items as $item) {
            $item_id = (string) $item['object_id'];
            if (isset($selected_item_lookup[$item_id])) {
                continue;
            }

            $this->render_builder_item_card($item, isset($variations_by_item[$item_id]) ? $variations_by_item[$item_id] : array(), $config, false, 100);
        }

        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo '<p class="submit">';
        echo '<button type="submit" class="button button-primary"><i class="fa-duotone fa-floppy-disk" aria-hidden="true"></i> ' . esc_html__('Save Shortcode', 'menuosaur') . '</button>';
        echo '</p>';
        echo '</form>';
        echo '</div>';
    }

    private function render_builder_item_card($item, $variations, $config, $is_selected, $item_order) {
        $item_id = (string) $item['object_id'];
        $category_ids = isset($item['category_ids']) && is_array($item['category_ids']) ? $item['category_ids'] : array();
        $category_ids_json = wp_json_encode($category_ids);
        $has_saved_variations = isset($config['variations'][$item_id]) && is_array($config['variations'][$item_id]);
        $selected_variations = $has_saved_variations ? $config['variations'][$item_id] : array();
        $selected_variation_lookup = array_fill_keys($selected_variations, true);
        $search_parts = array($item['name']);
        foreach ($variations as $variation) {
            $search_parts[] = isset($variation['name']) ? (string) $variation['name'] : '';
        }

        $classes = 'menuosaur-item-card' . ($is_selected ? ' is-selected' : '');
        echo '<div class="' . esc_attr($classes) . '" data-category-ids="' . esc_attr($category_ids_json) . '" data-item-id="' . esc_attr($item_id) . '" data-search="' . esc_attr(strtolower(wp_strip_all_tags(implode(' ', $search_parts)))) . '"' . ($is_selected ? ' draggable="true"' : '') . '>';
        echo '<div class="menuosaur-item-card-head">';
        echo '<button type="button" class="menuosaur-drag-handle" aria-label="' . esc_attr__('Drag to reorder item', 'menuosaur') . '"><i class="fa-duotone fa-grip-dots-vertical" aria-hidden="true"></i></button>';
        echo '<input type="checkbox" class="menuosaur-selected-item-input" name="selected_items[]" value="' . esc_attr($item_id) . '" ' . checked($is_selected, true, false) . ' />';
        echo '<strong class="menuosaur-item-title">' . esc_html($item['name']) . '</strong>';
        echo '<div class="menuosaur-variation-list">';
        if (empty($variations)) {
            echo '<span class="menuosaur-variation-empty">' . esc_html__('No active variations', 'menuosaur') . '</span>';
        } else {
            $variation_order = 1;
            foreach ($variations as $variation) {
                $variation_id = (string) $variation['object_id'];
                $price = $this->format_square_money($variation['price_amount'], $variation['currency']);
                $variation_label = trim($variation['name'] . ($price !== '' ? ' - ' . wp_strip_all_tags($price) : ''));
                $variation_checked = $is_selected
                    ? (!$has_saved_variations || isset($selected_variation_lookup[$variation_id]))
                    : true;

                echo '<label class="menuosaur-variation-chip"><input type="checkbox" name="selected_variations[' . esc_attr($item_id) . '][]" value="' . esc_attr($variation_id) . '" ' . checked($variation_checked, true, false) . disabled(!$is_selected, true, false) . ' /> ' . esc_html($variation_label) . '</label>';
                echo '<input type="hidden" class="menuosaur-variation-order-input" name="variation_order[' . esc_attr($variation_id) . ']" value="' . esc_attr((string) $variation_order) . '" ' . disabled(!$is_selected, true, false) . ' />';
                $variation_order++;
            }
        }
        echo '</div>';
        echo '<input type="hidden" class="menuosaur-item-order-input" name="item_order[' . esc_attr($item_id) . ']" value="' . esc_attr((string) $item_order) . '" ' . disabled(!$is_selected, true, false) . ' />';
        echo '<button type="button" class="button button-small menuosaur-add-item">' . esc_html__('Add', 'menuosaur') . '</button>';
        echo '<button type="button" class="button button-small menuosaur-remove-item">' . esc_html__('Remove', 'menuosaur') . '</button>';
        echo '</div>';
        echo '</div>';
    }

    private function render_builder_warnings($shortcode, $item_lookup, $variation_lookup) {
        $config = isset($shortcode['config']) ? $shortcode['config'] : $this->manager->default_shortcode_config();
        $warnings = array();

        foreach ($config['item_order'] as $item_id) {
            if (!isset($item_lookup[$item_id])) {
                $warnings[] = sprintf(__('Selected item %s is no longer in the cached Square catalog and will not render.', 'menuosaur'), $item_id);
            }
        }

        foreach ($config['variations'] as $item_id => $variation_ids) {
            foreach ((array) $variation_ids as $variation_id) {
                if (!isset($variation_lookup[$variation_id])) {
                    $warnings[] = sprintf(__('Selected variation %s is no longer in the cached Square catalog and will not render.', 'menuosaur'), $variation_id);
                }
            }
        }

        if (empty($warnings)) {
            return;
        }

        echo '<div class="notice notice-warning inline"><ul class="menuosaur-warning-list">';
        foreach ($warnings as $warning) {
            echo '<li>' . esc_html($warning) . '</li>';
        }
        echo '</ul></div>';
    }

    private function render_sync_tab() {
        $counts = $this->manager->get_catalog_counts();
        $logs = $this->manager->get_recent_sync_logs(10);
        $has_token = $this->get_square_access_token() !== '';
        $next_sync = wp_next_scheduled(Menuosaur_Manager::CRON_HOOK);

        echo '<div class="menuosaur-grid menuosaur-grid-overview">';
        $this->render_metric_card(__('Categories', 'menuosaur'), $counts['categories'], 'fa-layer-group');
        $this->render_metric_card(__('Items', 'menuosaur'), $counts['items'], 'fa-utensils');
        $this->render_metric_card(__('Variations', 'menuosaur'), $counts['variations'], 'fa-tags');
        echo '</div>';

        echo '<div class="menuosaur-card">';
        echo '<h2><i class="fa-duotone fa-arrows-rotate" aria-hidden="true"></i> ' . esc_html__('Catalog sync', 'menuosaur') . '</h2>';

        if (!$has_token) {
            $settings_url = add_query_arg(array('page' => 'menuosaur-menus', 'tab' => 'settings'), admin_url('admin.php'));
            echo '<div class="notice notice-warning inline"><p>' . wp_kses_post(sprintf(__('Add a Square access token in <a href="%s">Settings</a> before syncing.', 'menuosaur'), esc_url($settings_url))) . '</p></div>';
        }

        echo '<p class="description">' . esc_html__('Manual sync refreshes the local cache immediately. WordPress cron also refreshes it hourly when the site receives traffic.', 'menuosaur') . '</p>';
        echo '<p><strong>' . esc_html__('Next scheduled sync:', 'menuosaur') . '</strong> ' . esc_html($next_sync ? $this->format_admin_datetime(gmdate('Y-m-d H:i:s', $next_sync)) : __('Not scheduled', 'menuosaur')) . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('menuosaur_sync_catalog_action', 'menuosaur_nonce');
        echo '<input type="hidden" name="action" value="menuosaur_sync_catalog" />';
        echo '<button type="submit" class="button button-primary" ' . disabled(!$has_token, true, false) . '><i class="fa-duotone fa-arrows-rotate" aria-hidden="true"></i> ' . esc_html__('Sync Now', 'menuosaur') . '</button>';
        echo '</form>';
        echo '</div>';

        echo '<div class="menuosaur-card">';
        echo '<h2><i class="fa-duotone fa-clock-rotate-left" aria-hidden="true"></i> ' . esc_html__('Recent sync log', 'menuosaur') . '</h2>';

        if (empty($logs)) {
            echo '<p class="description">' . esc_html__('No sync runs have been logged yet.', 'menuosaur') . '</p>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>' . esc_html__('Started', 'menuosaur') . '</th><th>' . esc_html__('Trigger', 'menuosaur') . '</th><th>' . esc_html__('Status', 'menuosaur') . '</th><th>' . esc_html__('Counts', 'menuosaur') . '</th><th>' . esc_html__('Message', 'menuosaur') . '</th></tr></thead><tbody>';
        foreach ($logs as $log) {
            $counts_label = sprintf(
                __('%1$d categories, %2$d items, %3$d variations', 'menuosaur'),
                (int) $log['categories_count'],
                (int) $log['items_count'],
                (int) $log['variations_count']
            );
            echo '<tr>';
            echo '<td>' . esc_html($this->format_admin_datetime($log['started_at'])) . '</td>';
            echo '<td>' . esc_html(ucfirst((string) $log['trigger_type'])) . '</td>';
            echo '<td>' . $this->get_sync_status_pill($log['status'] === 'success') . '</td>';
            echo '<td>' . esc_html($counts_label) . '</td>';
            echo '<td>' . esc_html($log['message']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    private function render_settings_tab() {
        echo '<div class="menuosaur-card">';
        echo '<h2><i class="fa-duotone fa-gear" aria-hidden="true"></i> ' . esc_html__('Square settings', 'menuosaur') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('menuosaur_save_settings_action', 'menuosaur_nonce');
        echo '<input type="hidden" name="action" value="menuosaur_save_settings" />';
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="menuosaur_square_environment">' . esc_html__('Environment', 'menuosaur') . '</label></th><td>';
        echo '<select name="square_environment" id="menuosaur_square_environment">';
        echo '<option value="production"' . selected($this->settings['square_environment'], 'production', false) . '>' . esc_html__('Production', 'menuosaur') . '</option>';
        echo '<option value="sandbox"' . selected($this->settings['square_environment'], 'sandbox', false) . '>' . esc_html__('Sandbox', 'menuosaur') . '</option>';
        echo '</select>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="menuosaur_square_access_token">' . esc_html__('Access token', 'menuosaur') . '</label></th><td>';
        echo '<span class="menuosaur-input-decor menuosaur-input-wide">';
        echo '<span class="menuosaur-input-icon"><i class="fa-duotone fa-key" aria-hidden="true"></i></span>';
        echo '<input type="password" name="square_access_token" id="menuosaur_square_access_token" class="regular-text" autocomplete="new-password" placeholder="' . esc_attr($this->get_square_access_token() === '' ? __('Paste Square access token', 'menuosaur') : __('Saved token is masked; paste a new token to replace it', 'menuosaur')) . '" />';
        echo '</span>';
        if ($this->get_square_access_token() !== '') {
            echo '<p><label><input type="checkbox" name="clear_square_access_token" value="1" /> ' . esc_html__('Clear saved access token', 'menuosaur') . '</label></p>';
        }
        echo '<p class="description">' . esc_html__('The token is stored in WordPress options and only sent server-side to Square.', 'menuosaur') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="menuosaur_square_api_version">' . esc_html__('Square API version', 'menuosaur') . '</label></th><td>';
        echo '<span class="menuosaur-input-decor menuosaur-input-medium">';
        echo '<span class="menuosaur-input-icon"><i class="fa-duotone fa-code-branch" aria-hidden="true"></i></span>';
        echo '<input type="text" name="square_api_version" id="menuosaur_square_api_version" value="' . esc_attr($this->settings['square_api_version']) . '" class="regular-text" />';
        echo '</span>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="menuosaur_square_location_id">' . esc_html__('Location ID', 'menuosaur') . '</label></th><td>';
        echo '<span class="menuosaur-input-decor menuosaur-input-wide">';
        echo '<span class="menuosaur-input-icon"><i class="fa-duotone fa-location-dot" aria-hidden="true"></i></span>';
        echo '<input type="text" name="square_location_id" id="menuosaur_square_location_id" value="' . esc_attr($this->settings['square_location_id']) . '" class="regular-text" />';
        echo '</span>';
        echo '<p class="description">' . esc_html__('Optional. When set, Square item search is filtered to items enabled for this location.', 'menuosaur') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="menuosaur_admin_menu_label">' . esc_html__('Sidebar menu name', 'menuosaur') . '</label></th><td>';
        echo '<span class="menuosaur-input-decor menuosaur-input-medium">';
        echo '<span class="menuosaur-input-icon"><i class="fa-duotone fa-sidebar" aria-hidden="true"></i></span>';
        echo '<input type="text" name="admin_menu_label" id="menuosaur_admin_menu_label" value="' . esc_attr($this->get_admin_menu_label()) . '" class="regular-text" maxlength="80" />';
        echo '</span>';
        echo '<p class="description">' . esc_html__('This changes the top-level WordPress admin sidebar label. Leave it blank to use Menuosaur.', 'menuosaur') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Menu display', 'menuosaur') . '</th><td>';
        echo '<p><label><input type="checkbox" name="sort_variations_by_price" value="1" ' . checked(!empty($this->settings['sort_variations_by_price']), true, false) . ' /> ' . esc_html__('Always display cheaper price variations first', 'menuosaur') . '</label></p>';
        echo '<p class="description">' . esc_html__('When enabled, public menus ignore saved variation order for prices and sort selected variations from cheapest to most expensive.', 'menuosaur') . '</p>';
        echo '<p><label><input type="checkbox" name="hide_currency_symbol" value="1" ' . checked(!empty($this->settings['hide_currency_symbol']), true, false) . ' /> ' . esc_html__('Remove currency symbols from prices', 'menuosaur') . '</label></p>';
        echo '<p class="description">' . esc_html__('Example: display 16 instead of $16.', 'menuosaur') . '</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
        echo '<p class="submit"><button type="submit" class="button button-primary"><i class="fa-duotone fa-floppy-disk" aria-hidden="true"></i> ' . esc_html__('Save Settings', 'menuosaur') . '</button></p>';
        echo '</form>';
        echo '</div>';

        echo '<div class="menuosaur-card">';
        echo '<h2><i class="fa-duotone fa-plug" aria-hidden="true"></i> ' . esc_html__('Test connection', 'menuosaur') . '</h2>';
        echo '<p class="description">' . esc_html__('This checks the saved token against the Square Catalog API without changing cached menu data.', 'menuosaur') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('menuosaur_test_square_connection_action', 'menuosaur_nonce');
        echo '<input type="hidden" name="action" value="menuosaur_test_square_connection" />';
        echo '<button type="submit" class="button" ' . disabled($this->get_square_access_token() === '', true, false) . '><i class="fa-duotone fa-plug" aria-hidden="true"></i> ' . esc_html__('Test Square Connection', 'menuosaur') . '</button>';
        echo '</form>';
        echo '</div>';
    }

    private function render_about_tab() {
        echo '<div class="menuosaur-card">';
        echo '<h2><i class="fa-duotone fa-circle-info" aria-hidden="true"></i> ' . esc_html__('About Menuosaur', 'menuosaur') . '</h2>';
        echo '<table class="widefat striped menuosaur-about-table"><tbody>';
        echo '<tr><th>' . esc_html__('Plugin version', 'menuosaur') . '</th><td><code>' . esc_html(MENUOSAUR_VERSION) . '</code></td></tr>';
        echo '<tr><th>' . esc_html__('WordPress version', 'menuosaur') . '</th><td><code>' . esc_html(get_bloginfo('version')) . '</code></td></tr>';
        echo '<tr><th>' . esc_html__('Square environment', 'menuosaur') . '</th><td><code>' . esc_html($this->settings['square_environment']) . '</code></td></tr>';
        echo '<tr><th>' . esc_html__('Square token', 'menuosaur') . '</th><td>' . ($this->get_square_access_token() !== '' ? '<span class="menuosaur-status-pill menuosaur-status-active">' . esc_html__('Saved', 'menuosaur') . '</span>' : '<span class="menuosaur-status-pill menuosaur-status-inactive">' . esc_html__('Missing', 'menuosaur') . '</span>') . '</td></tr>';
        echo '</tbody></table>';
        echo '<p class="menuosaur-about-credit">' . esc_html__('Built by', 'menuosaur') . ' <strong>' . esc_html__('Alex Burgess', 'menuosaur') . '</strong> &copy; ' . esc_html(wp_date('Y')) . '</p>';
        echo '</div>';
    }

    private function render_metric_card($label, $value, $icon) {
        echo '<div class="menuosaur-card menuosaur-metric">';
        echo '<h2><i class="fa-duotone ' . esc_attr($icon) . '" aria-hidden="true"></i> ' . esc_html($label) . '</h2>';
        echo '<p class="menuosaur-metric-value">' . esc_html(number_format_i18n((int) $value)) . '</p>';
        echo '</div>';
    }

    public function handle_create_shortcode() {
        $this->assert_admin_post('menuosaur_create_shortcode_action');

        $name = isset($_POST['name']) ? wp_unslash($_POST['name']) : '';
        $result = $this->manager->create_shortcode($name);
        if (is_wp_error($result)) {
            $this->redirect_with_result($this->admin_tab_url('menus'), $result, '');
        }

        $url = add_query_arg(
            array(
                'page' => 'menuosaur-menus',
                'tab' => 'menus',
                'shortcode_id' => (int) $result,
            ),
            admin_url('admin.php')
        );

        $this->redirect_with_result($url, true, __('Shortcode created. Choose a category, items, and variations.', 'menuosaur'));
    }

    public function handle_save_shortcode() {
        $this->assert_admin_post('menuosaur_save_shortcode_action');

        $shortcode_id = isset($_POST['shortcode_id']) ? absint($_POST['shortcode_id']) : 0;
        $category_ids = $this->sanitize_category_ids_from_request();
        $category_id = !empty($category_ids) ? $category_ids[0] : '';
        $config = $this->build_shortcode_config_from_request($category_ids);
        $this->ensure_shortcode_image_objects($config);

        $result = $this->manager->update_shortcode(
            $shortcode_id,
            array(
                'name' => isset($_POST['name']) ? wp_unslash($_POST['name']) : '',
                'slug' => isset($_POST['slug']) ? wp_unslash($_POST['slug']) : '',
                'category_id' => $category_id,
                'show_category_heading' => isset($_POST['show_category_heading']),
                'show_variation_labels' => isset($_POST['show_variation_labels']),
                'status' => isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : 'active',
                'config' => $config,
            )
        );

        $url = add_query_arg(
            array(
                'page' => 'menuosaur-menus',
                'tab' => 'menus',
                'shortcode_id' => $shortcode_id,
            ),
            admin_url('admin.php')
        );

        if (!is_wp_error($result)) {
            $this->queue_shortcode_config_images($config);
        }

        $this->redirect_with_result($url, $result, __('Shortcode saved.', 'menuosaur'));
    }

    public function handle_save_settings() {
        $this->assert_admin_post('menuosaur_save_settings_action');

        $current = wp_parse_args(get_option('menuosaur_settings', array()), self::default_settings());
        $environment = isset($_POST['square_environment']) ? sanitize_key(wp_unslash($_POST['square_environment'])) : $current['square_environment'];
        if (!in_array($environment, array('production', 'sandbox'), true)) {
            $environment = 'production';
        }

        $token = $current['square_access_token'];
        if (isset($_POST['clear_square_access_token'])) {
            $token = '';
        } elseif (isset($_POST['square_access_token'])) {
            $posted_token = trim(sanitize_text_field(wp_unslash($_POST['square_access_token'])));
            if ($posted_token !== '') {
                $token = $posted_token;
            }
        }

        $api_version = isset($_POST['square_api_version']) ? trim(sanitize_text_field(wp_unslash($_POST['square_api_version']))) : $current['square_api_version'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $api_version)) {
            $api_version = self::default_settings()['square_api_version'];
        }

        $updated = array(
            'square_environment' => $environment,
            'square_access_token' => $token,
            'square_api_version' => $api_version,
            'square_location_id' => isset($_POST['square_location_id']) ? trim(sanitize_text_field(wp_unslash($_POST['square_location_id']))) : $current['square_location_id'],
            'sort_variations_by_price' => isset($_POST['sort_variations_by_price']) ? 1 : 0,
            'hide_currency_symbol' => isset($_POST['hide_currency_symbol']) ? 1 : 0,
            'admin_menu_label' => isset($_POST['admin_menu_label']) ? $this->sanitize_admin_menu_label(wp_unslash($_POST['admin_menu_label'])) : $this->sanitize_admin_menu_label($current['admin_menu_label']),
        );

        update_option('menuosaur_settings', $updated);
        $this->settings = wp_parse_args($updated, self::default_settings());
        Menuosaur_Manager::schedule_sync_event();

        $this->redirect_with_result($this->admin_tab_url('settings'), true, __('Settings saved.', 'menuosaur'));
    }

    public function handle_sync_catalog() {
        $this->assert_admin_post('menuosaur_sync_catalog_action');

        $result = $this->sync_square_catalog('manual');
        $this->redirect_with_result($this->admin_tab_url('sync'), $result, __('Square catalog synced.', 'menuosaur'));
    }

    public function handle_test_square_connection() {
        $this->assert_admin_post('menuosaur_test_square_connection_action');

        $result = $this->test_square_connection();
        $this->redirect_with_result($this->admin_tab_url('settings'), $result, __('Square connection succeeded.', 'menuosaur'));
    }

    public function handle_scheduled_sync() {
        if ($this->get_square_access_token() === '') {
            return;
        }

        $this->sync_square_catalog('cron');
    }

    public function render_shortcode($atts) {
        $atts = shortcode_atts(
            array(
                'id' => '',
            ),
            $atts,
            'menuosaur'
        );

        $shortcode = $this->manager->get_shortcode_by_slug($atts['id']);
        if (!$shortcode || $shortcode['status'] !== 'active') {
            return '';
        }

        $config = $shortcode['config'];
        if (empty($config['item_order'])) {
            return '';
        }
        $display = isset($config['display']) && is_array($config['display'])
            ? $config['display']
            : $this->manager->default_shortcode_config()['display'];
        $show_item_name = !empty($display['show_item_name']);
        $show_item_image = !empty($display['show_item_image']);
        $show_description = !empty($display['show_description']);
        $show_prices = !empty($display['show_prices']);
        $image_size = $this->sanitize_image_size(isset($display['image_size']) ? $display['image_size'] : 'square_original');
        $selected_attribute_keys = isset($display['custom_attribute_keys']) && is_array($display['custom_attribute_keys'])
            ? $display['custom_attribute_keys']
            : array();
        $show_attribute_labels = !empty($display['show_custom_attribute_labels']);

        wp_enqueue_style(
            'menuosaur-frontend',
            MENUOSAUR_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            MENUOSAUR_VERSION
        );

        $selected_category_ids = $this->get_shortcode_category_ids($shortcode);
        $category = count($selected_category_ids) === 1 ? $this->manager->get_catalog_object($selected_category_ids[0]) : null;
        $parts = array();

        $parts[] = '<div class="menuosaur-menu" data-menuosaur-id="' . esc_attr($shortcode['slug']) . '">';

        if ($shortcode['show_category_heading'] && $category && empty($category['is_deleted']) && empty($category['is_archived'])) {
            $parts[] = '<h4 class="menuosaur-category-heading">' . esc_html($category['name']) . '</h4>';
        }

        foreach ($config['item_order'] as $item_id) {
            $item = $this->manager->get_catalog_object($item_id);
            if (!$item || $item['object_type'] !== 'ITEM' || $item['is_deleted'] || $item['is_archived']) {
                continue;
            }

            $variation_ids = isset($config['variations'][$item_id]) && is_array($config['variations'][$item_id]) ? $config['variations'][$item_id] : array();
            $variation_objects = array();
            $variation_parts = array();
            foreach ($variation_ids as $variation_id) {
                $variation = $this->manager->get_catalog_object($variation_id);
                if (!$variation || $variation['object_type'] !== 'ITEM_VARIATION' || $variation['is_deleted'] || $variation['is_archived']) {
                    continue;
                }
                $variation_objects[] = $variation;
            }

            if (!empty($this->settings['sort_variations_by_price'])) {
                $this->sort_variations_by_price($variation_objects);
            }

            if ($show_prices) {
                foreach ($variation_objects as $variation) {
                    $price = $this->format_square_money($variation['price_amount'], $variation['currency']);
                    if ($shortcode['show_variation_labels']) {
                        $label = trim((string) $variation['name']);
                        $variation_parts[] = $price !== ''
                            ? esc_html($label . ' ' . wp_strip_all_tags($price))
                            : esc_html($label);
                    } elseif ($price !== '') {
                        $variation_parts[] = esc_html(wp_strip_all_tags($price));
                    }
                }
            }

            $image = $show_item_image ? $this->get_item_image_data($item, $image_size) : null;
            $attribute_parts = $this->get_selected_custom_attribute_parts($item, $variation_objects, $selected_attribute_keys, $show_attribute_labels);
            if (!$show_item_name && !$image && (!$show_description || empty($item['description'])) && empty($variation_parts) && empty($attribute_parts)) {
                continue;
            }

            $parts[] = '<div class="menuosaur-item">';
            if ($image) {
                $size_attrs = '';
                if (!empty($image['width'])) {
                    $size_attrs .= ' width="' . esc_attr((string) $image['width']) . '"';
                }
                if (!empty($image['height'])) {
                    $size_attrs .= ' height="' . esc_attr((string) $image['height']) . '"';
                }
                $parts[] = '<div class="menuosaur-item-image"><img src="' . esc_url($image['url']) . '" alt="' . esc_attr($image['alt'] !== '' ? $image['alt'] : $item['name']) . '" loading="lazy"' . $size_attrs . ' /></div>';
            }
            if ($show_item_name) {
                $parts[] = '<p class="menuosaur-item-name">' . esc_html($item['name']) . '</p>';
            }
            if ($show_description && !empty($item['description'])) {
                $parts[] = '<p class="menuosaur-item-description">' . esc_html(wp_strip_all_tags($item['description'])) . '</p>';
            }
            if (!empty($attribute_parts)) {
                $parts[] = '<p class="menuosaur-item-attributes">' . implode(' <span class="menuosaur-attribute-separator">|</span> ', $attribute_parts) . '</p>';
            }
            if (!empty($variation_parts)) {
                $parts[] = '<p class="menuosaur-variation-prices">' . implode(' <span class="menuosaur-price-separator">|</span> ', $variation_parts) . '</p>';
            }
            $parts[] = '</div>';
        }

        $parts[] = '</div>';

        return implode('', $parts);
    }

    public function sync_square_catalog($trigger_type = 'manual') {
        $started_at = $this->manager->now_gmt();

        if ($this->get_square_access_token() === '') {
            $error = new WP_Error('menuosaur_missing_square_token', __('Square access token is missing.', 'menuosaur'));
            $this->manager->log_sync(array(
                'status' => 'error',
                'trigger_type' => $trigger_type,
                'message' => $error->get_error_message(),
                'started_at' => $started_at,
                'finished_at' => $this->manager->now_gmt(),
            ));
            return $error;
        }

        $catalog_objects = $this->fetch_square_catalog_objects(array('CATEGORY'));
        if (is_wp_error($catalog_objects)) {
            $this->log_failed_sync($trigger_type, $started_at, $catalog_objects);
            return $catalog_objects;
        }

        $items = $this->fetch_square_catalog_items();
        if (is_wp_error($items)) {
            $this->log_failed_sync($trigger_type, $started_at, $items);
            return $items;
        }

        if (empty($items)) {
            $fallback_items = $this->fetch_square_catalog_objects(array('ITEM'));
            if (is_wp_error($fallback_items)) {
                $this->log_failed_sync($trigger_type, $started_at, $fallback_items);
                return $fallback_items;
            }
            $items = $fallback_items;
        }

        $normalized = array();
        foreach ($catalog_objects as $catalog_object) {
            if (!is_array($catalog_object) || empty($catalog_object['type'])) {
                continue;
            }

            if ($catalog_object['type'] === 'CATEGORY') {
                $normal = $this->normalize_square_category($catalog_object);
            } else {
                $normal = null;
            }

            if ($normal) {
                $normalized[] = $normal;
            }
        }

        foreach ($items as $item) {
            $item_objects = $this->normalize_square_item_with_variations($item);
            foreach ($item_objects as $object) {
                $normalized[] = $object;
            }
        }

        $result = $this->manager->replace_catalog_cache($normalized);
        if (is_wp_error($result)) {
            $this->log_failed_sync($trigger_type, $started_at, $result);
            return $result;
        }
        $result['image_cache_queue'] = $this->queue_selected_shortcode_images();

        $this->manager->log_sync(array(
            'status' => 'success',
            'trigger_type' => $trigger_type,
            'message' => __('Catalog cache updated.', 'menuosaur'),
            'categories_count' => $result['categories'],
            'items_count' => $result['items'],
            'variations_count' => $result['variations'],
            'started_at' => $started_at,
            'finished_at' => $this->manager->now_gmt(),
        ));

        return $result;
    }

    public function handle_image_cache_batch() {
        $queue = get_option('menuosaur_image_cache_queue', array());
        if (!is_array($queue) || empty($queue)) {
            return;
        }

        $remaining = $queue;
        $processed = 0;
        foreach ($queue as $cache_key => $job) {
            if ($processed >= 5) {
                break;
            }

            unset($remaining[$cache_key]);
            $processed++;

            if (!is_array($job) || empty($job['source_url'])) {
                continue;
            }

            $attempts = isset($job['attempts']) ? absint($job['attempts']) : 0;
            $result = $this->cache_remote_image(
                $cache_key,
                (string) $job['source_url'],
                isset($job['title']) ? (string) $job['title'] : '',
                isset($job['alt']) ? (string) $job['alt'] : '',
                isset($job['version']) ? (int) $job['version'] : 0
            );

            if (is_wp_error($result) && $attempts < 2) {
                $job['attempts'] = $attempts + 1;
                $remaining[$cache_key] = $job;
            }
        }

        update_option('menuosaur_image_cache_queue', $remaining, false);
        if (!empty($remaining) && !wp_next_scheduled(self::IMAGE_CACHE_CRON_HOOK)) {
            wp_schedule_single_event(time() + 120, self::IMAGE_CACHE_CRON_HOOK);
        }
    }

    private function test_square_connection() {
        if ($this->get_square_access_token() === '') {
            return new WP_Error('menuosaur_missing_square_token', __('Square access token is missing.', 'menuosaur'));
        }

        $response = $this->square_request(
            'POST',
            '/catalog/search',
            array(
                'object_types' => array('CATEGORY'),
                'limit' => 1,
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        return true;
    }

    private function fetch_square_catalog_objects($object_types) {
        $objects = array();
        $cursor = '';

        do {
            $body = array(
                'object_types' => array_values($object_types),
                'include_deleted_objects' => false,
                'limit' => 100,
            );
            if ($cursor !== '') {
                $body['cursor'] = $cursor;
            }

            $response = $this->square_request('POST', '/catalog/search', $body);
            if (is_wp_error($response)) {
                return $response;
            }

            if (!empty($response['objects']) && is_array($response['objects'])) {
                foreach ($response['objects'] as $object) {
                    $objects[] = $object;
                }
            }

            $cursor = isset($response['cursor']) ? (string) $response['cursor'] : '';
        } while ($cursor !== '');

        return $objects;
    }

    private function fetch_square_catalog_objects_by_ids($object_ids) {
        $object_ids = array_values(
            array_filter(
                array_unique(array_map('strval', (array) $object_ids)),
                function ($object_id) {
                    return $object_id !== '';
                }
            )
        );

        if (empty($object_ids)) {
            return array();
        }

        $objects = array();
        foreach (array_chunk($object_ids, 100) as $chunk) {
            $response = $this->square_request(
                'POST',
                '/catalog/batch-retrieve',
                array(
                    'object_ids' => array_values($chunk),
                    'include_related_objects' => false,
                )
            );
            if (is_wp_error($response)) {
                return $response;
            }

            if (!empty($response['objects']) && is_array($response['objects'])) {
                foreach ($response['objects'] as $object) {
                    $objects[] = $object;
                }
            }
        }

        return $objects;
    }

    private function fetch_square_catalog_items() {
        $items = array();
        $cursor = '';

        do {
            $body = array(
                'archived_state' => 'ARCHIVED_STATE_NOT_ARCHIVED',
                'limit' => 100,
                'sort_order' => 'ASC',
            );

            if (!empty($this->settings['square_location_id'])) {
                $body['enabled_location_ids'] = array($this->settings['square_location_id']);
            }

            if ($cursor !== '') {
                $body['cursor'] = $cursor;
            }

            $response = $this->square_request('POST', '/catalog/search-catalog-items', $body);
            if (is_wp_error($response)) {
                return $response;
            }

            if (!empty($response['items']) && is_array($response['items'])) {
                foreach ($response['items'] as $item) {
                    $items[] = $item;
                }
            } elseif (!empty($response['objects']) && is_array($response['objects'])) {
                foreach ($response['objects'] as $item) {
                    $items[] = $item;
                }
            }

            $cursor = isset($response['cursor']) ? (string) $response['cursor'] : '';
        } while ($cursor !== '');

        return $items;
    }

    private function square_request($method, $path, $body = null) {
        $token = $this->get_square_access_token();
        if ($token === '') {
            return new WP_Error('menuosaur_missing_square_token', __('Square access token is missing.', 'menuosaur'));
        }

        $base_url = $this->settings['square_environment'] === 'sandbox'
            ? 'https://connect.squareupsandbox.com/v2'
            : 'https://connect.squareup.com/v2';

        $args = array(
            'method' => strtoupper((string) $method),
            'timeout' => 45,
            'headers' => array(
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Square-Version' => $this->settings['square_api_version'],
            ),
        );

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = strtoupper((string) $method) === 'POST'
            ? wp_remote_post($base_url . $path, $args)
            : wp_remote_request($base_url . $path, $args);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw_body = (string) wp_remote_retrieve_body($response);
        $decoded = $raw_body !== '' ? json_decode($raw_body, true) : array();
        if (!is_array($decoded)) {
            $decoded = array();
        }

        if ($code < 200 || $code >= 300) {
            return new WP_Error('menuosaur_square_api_error', $this->format_square_error_message($decoded, $code));
        }

        return $decoded;
    }

    private function normalize_square_category($object) {
        if (!is_array($object) || !isset($object['type']) || $object['type'] !== 'CATEGORY') {
            return null;
        }

        $data = isset($object['category_data']) && is_array($object['category_data']) ? $object['category_data'] : array();
        $name = isset($data['name']) ? (string) $data['name'] : '';
        if ($name === '') {
            return null;
        }

        return array(
            'object_id' => isset($object['id']) ? (string) $object['id'] : '',
            'object_type' => 'CATEGORY',
            'version' => isset($object['version']) ? (int) $object['version'] : 0,
            'name' => $name,
            'category_type' => isset($data['category_type']) ? (string) $data['category_type'] : '',
            'is_deleted' => !empty($object['is_deleted']),
            'is_archived' => false,
            'raw_json' => $object,
        );
    }

    private function normalize_square_image($object) {
        if (!is_array($object) || !isset($object['type']) || $object['type'] !== 'IMAGE') {
            return null;
        }

        $data = isset($object['image_data']) && is_array($object['image_data']) ? $object['image_data'] : array();
        $url = isset($data['url']) ? (string) $data['url'] : '';
        if ($url === '') {
            return null;
        }

        $name = isset($data['name']) && $data['name'] !== ''
            ? (string) $data['name']
            : (isset($data['caption']) ? (string) $data['caption'] : '');

        return array(
            'object_id' => isset($object['id']) ? (string) $object['id'] : '',
            'object_type' => 'IMAGE',
            'version' => isset($object['version']) ? (int) $object['version'] : 0,
            'name' => $name,
            'description' => isset($data['caption']) ? (string) $data['caption'] : '',
            'is_deleted' => !empty($object['is_deleted']),
            'is_archived' => false,
            'raw_json' => $object,
        );
    }

    private function normalize_square_item_with_variations($object) {
        if (!is_array($object) || !isset($object['type']) || $object['type'] !== 'ITEM') {
            return array();
        }

        $data = isset($object['item_data']) && is_array($object['item_data']) ? $object['item_data'] : array();
        $item_id = isset($object['id']) ? (string) $object['id'] : '';
        $name = isset($data['name']) ? (string) $data['name'] : '';
        if ($item_id === '' || $name === '') {
            return array();
        }

        $category_ids = $this->extract_square_item_category_ids($data);
        $description = isset($data['description']) ? (string) $data['description'] : '';
        if ($description === '' && !empty($data['description_html'])) {
            $description = wp_strip_all_tags((string) $data['description_html']);
        }
        $objects = array(
            array(
                'object_id' => $item_id,
                'object_type' => 'ITEM',
                'version' => isset($object['version']) ? (int) $object['version'] : 0,
                'name' => $name,
                'description' => $description,
                'category_id' => !empty($category_ids) ? (string) $category_ids[0] : '',
                'category_ids' => $category_ids,
                'is_deleted' => !empty($object['is_deleted']),
                'is_archived' => !empty($data['is_archived']),
                'raw_json' => $object,
            ),
        );

        $variations = isset($data['variations']) && is_array($data['variations']) ? $data['variations'] : array();
        foreach ($variations as $variation) {
            if (!is_array($variation) || !isset($variation['type']) || $variation['type'] !== 'ITEM_VARIATION') {
                continue;
            }

            $variation_data = isset($variation['item_variation_data']) && is_array($variation['item_variation_data']) ? $variation['item_variation_data'] : array();
            $price_money = isset($variation_data['price_money']) && is_array($variation_data['price_money']) ? $variation_data['price_money'] : array();

            $objects[] = array(
                'object_id' => isset($variation['id']) ? (string) $variation['id'] : '',
                'object_type' => 'ITEM_VARIATION',
                'version' => isset($variation['version']) ? (int) $variation['version'] : 0,
                'name' => isset($variation_data['name']) ? (string) $variation_data['name'] : '',
                'item_id' => $item_id,
                'price_amount' => isset($price_money['amount']) ? (int) $price_money['amount'] : null,
                'currency' => isset($price_money['currency']) ? (string) $price_money['currency'] : '',
                'is_deleted' => !empty($variation['is_deleted']),
                'is_archived' => !empty($variation_data['is_archived']),
                'raw_json' => $variation,
            );
        }

        return $objects;
    }

    private function extract_square_item_category_ids($item_data) {
        $category_ids = array();

        if (!empty($item_data['category_id'])) {
            $category_ids[] = (string) $item_data['category_id'];
        }

        if (!empty($item_data['categories']) && is_array($item_data['categories'])) {
            foreach ($item_data['categories'] as $category) {
                if (is_array($category) && !empty($category['id'])) {
                    $category_ids[] = (string) $category['id'];
                } elseif (is_string($category) && $category !== '') {
                    $category_ids[] = $category;
                }
            }
        }

        return array_values(array_unique(array_filter($category_ids)));
    }

    private function sort_variations_for_builder(&$variations, $variation_positions) {
        usort(
            $variations,
            function ($a, $b) use ($variation_positions) {
                $a_id = (string) $a['object_id'];
                $b_id = (string) $b['object_id'];
                $a_selected = isset($variation_positions[$a_id]);
                $b_selected = isset($variation_positions[$b_id]);

                if ($a_selected && $b_selected) {
                    return $variation_positions[$a_id] < $variation_positions[$b_id] ? -1 : 1;
                }

                if ($a_selected !== $b_selected) {
                    return $a_selected ? -1 : 1;
                }

                return strnatcasecmp($a['name'], $b['name']);
            }
        );
    }

    private function sort_variations_by_price(&$variations) {
        usort(
            $variations,
            function ($a, $b) {
                $a_price = isset($a['price_amount']) && $a['price_amount'] !== null ? (int) $a['price_amount'] : PHP_INT_MAX;
                $b_price = isset($b['price_amount']) && $b['price_amount'] !== null ? (int) $b['price_amount'] : PHP_INT_MAX;

                if ($a_price === $b_price) {
                    return strnatcasecmp((string) $a['name'], (string) $b['name']);
                }

                return $a_price < $b_price ? -1 : 1;
            }
        );
    }

    private function get_shortcode_category_ids($shortcode) {
        $config = isset($shortcode['config']) && is_array($shortcode['config']) ? $shortcode['config'] : array();
        $category_ids = isset($config['category_ids']) && is_array($config['category_ids'])
            ? array_values(array_filter(array_map('strval', $config['category_ids'])))
            : array();

        if (empty($category_ids) && !empty($shortcode['category_id'])) {
            $category_ids[] = (string) $shortcode['category_id'];
        }

        return array_values(array_unique($category_ids));
    }

    private function sanitize_category_ids_from_request() {
        if (isset($_POST['category_ids']) && is_array($_POST['category_ids'])) {
            return array_values(
                array_filter(
                    array_map('sanitize_text_field', wp_unslash($_POST['category_ids'])),
                    function ($value) {
                        return $value !== '';
                    }
                )
            );
        }

        if (isset($_POST['category_id'])) {
            $category_id = sanitize_text_field(wp_unslash($_POST['category_id']));
            return $category_id !== '' ? array($category_id) : array();
        }

        return array();
    }

    private function get_item_image_data($item, $preferred_size = 'square_original') {
        $source = $this->get_item_image_source($item);
        if (!$source) {
            return null;
        }

        $preferred_size = $this->sanitize_image_size($preferred_size);
        if ($preferred_size !== 'square_original') {
            $cached = $this->get_cached_remote_image_data(
                $source['cache_key'],
                $source['url'],
                $source['title'],
                $source['alt'],
                $source['version'],
                $preferred_size
            );
            if ($cached) {
                return $cached;
            }
        }

        return array(
            'url' => $source['url'],
            'alt' => $source['alt'],
        );
    }

    private function get_item_image_source($item) {
        if (empty($item['raw_json']['item_data']) || !is_array($item['raw_json']['item_data'])) {
            return null;
        }

        $item_data = $item['raw_json']['item_data'];
        $image_ids = array();

        if (!empty($item_data['image_ids']) && is_array($item_data['image_ids'])) {
            $image_ids = $item_data['image_ids'];
        } elseif (!empty($item_data['ecom_image_uris']) && is_array($item_data['ecom_image_uris'])) {
            $first_uri = reset($item_data['ecom_image_uris']);
            if (is_string($first_uri) && $first_uri !== '') {
                return array(
                    'cache_key' => 'ecom-' . md5($first_uri),
                    'url' => $first_uri,
                    'title' => isset($item['name']) ? (string) $item['name'] : '',
                    'alt' => isset($item['name']) ? (string) $item['name'] : '',
                    'version' => 0,
                );
            }
        }

        foreach ($image_ids as $image_id) {
            $image = $this->manager->get_catalog_object($image_id);
            if (!$image || $image['object_type'] !== 'IMAGE' || $image['is_deleted']) {
                $this->fetch_and_cache_square_images(array($image_id));
                $image = $this->manager->get_catalog_object($image_id);
                if (!$image || $image['object_type'] !== 'IMAGE' || $image['is_deleted']) {
                    continue;
                }
            }

            $raw = isset($image['raw_json']['image_data']) && is_array($image['raw_json']['image_data']) ? $image['raw_json']['image_data'] : array();
            $url = isset($raw['url']) ? (string) $raw['url'] : '';
            if ($url === '') {
                continue;
            }

            $alt = isset($raw['caption']) && $raw['caption'] !== ''
                ? (string) $raw['caption']
                : (isset($image['name']) ? (string) $image['name'] : '');

            return array(
                'cache_key' => (string) $image['object_id'],
                'url' => $url,
                'title' => isset($image['name']) && $image['name'] !== '' ? (string) $image['name'] : (isset($item['name']) ? (string) $item['name'] : ''),
                'alt' => $alt,
                'version' => isset($image['version']) ? (int) $image['version'] : 0,
            );
        }

        return null;
    }

    private function get_cached_remote_image_data($cache_key, $source_url, $title, $alt, $version, $size) {
        $attachment_id = $this->get_cached_attachment_id($cache_key, $source_url, $version);
        if (!$attachment_id) {
            $this->queue_remote_image_cache($cache_key, $source_url, $title, $alt, $version);
            return null;
        }

        $image = wp_get_attachment_image_src($attachment_id, $this->sanitize_image_size($size));
        if (!$image || empty($image[0])) {
            $image = wp_get_attachment_image_src($attachment_id, 'full');
        }

        if (!$image || empty($image[0])) {
            return null;
        }

        return array(
            'url' => $image[0],
            'alt' => $alt !== '' ? $alt : $title,
            'width' => isset($image[1]) ? (int) $image[1] : 0,
            'height' => isset($image[2]) ? (int) $image[2] : 0,
        );
    }

    private function queue_selected_shortcode_images() {
        $queued = 0;
        foreach ($this->manager->get_shortcodes() as $shortcode) {
            $this->ensure_shortcode_image_objects($shortcode['config']);
            $queued += $this->queue_shortcode_config_images($shortcode['config']);
        }

        return $queued;
    }

    private function ensure_shortcode_image_objects($config) {
        if (empty($config['display']['show_item_image']) || empty($config['item_order']) || !is_array($config['item_order'])) {
            return 0;
        }

        $image_ids = array();
        foreach ($config['item_order'] as $item_id) {
            $item = $this->manager->get_catalog_object($item_id);
            if (!$item || $item['object_type'] !== 'ITEM' || $item['is_deleted'] || $item['is_archived']) {
                continue;
            }

            foreach ($this->get_item_square_image_ids($item) as $image_id) {
                $image_ids[] = $image_id;
            }
        }

        return $this->fetch_and_cache_square_images($image_ids);
    }

    private function queue_shortcode_config_images($config) {
        if (empty($config['display']['show_item_image']) || empty($config['item_order']) || !is_array($config['item_order'])) {
            return 0;
        }

        $image_size = $this->sanitize_image_size(isset($config['display']['image_size']) ? $config['display']['image_size'] : 'square_original');
        if ($image_size === 'square_original') {
            return 0;
        }

        $queued = 0;
        foreach ($config['item_order'] as $item_id) {
            $item = $this->manager->get_catalog_object($item_id);
            if (!$item || $item['object_type'] !== 'ITEM' || $item['is_deleted'] || $item['is_archived']) {
                continue;
            }

            $source = $this->get_item_image_source($item);
            if ($source && $this->queue_remote_image_cache($source['cache_key'], $source['url'], $source['title'], $source['alt'], $source['version'])) {
                $queued++;
            }
        }

        return $queued;
    }

    private function get_item_square_image_ids($item) {
        if (empty($item['raw_json']['item_data']) || !is_array($item['raw_json']['item_data'])) {
            return array();
        }

        $item_data = $item['raw_json']['item_data'];
        if (empty($item_data['image_ids']) || !is_array($item_data['image_ids'])) {
            return array();
        }

        return array_values(
            array_filter(
                array_unique(array_map('strval', $item_data['image_ids'])),
                function ($image_id) {
                    return $image_id !== '';
                }
            )
        );
    }

    private function fetch_and_cache_square_images($image_ids) {
        $image_ids = array_values(
            array_filter(
                array_unique(array_map('strval', (array) $image_ids)),
                function ($image_id) {
                    return $image_id !== '';
                }
            )
        );

        if (empty($image_ids) || $this->get_square_access_token() === '') {
            return 0;
        }

        $objects = $this->fetch_square_catalog_objects_by_ids($image_ids);
        if (is_wp_error($objects)) {
            return 0;
        }

        $normalized = array();
        foreach ($objects as $object) {
            $normal = $this->normalize_square_image($object);
            if ($normal) {
                $normalized[] = $normal;
            }
        }

        if (empty($normalized)) {
            return 0;
        }

        $result = $this->manager->upsert_catalog_cache($normalized);
        return is_wp_error($result) ? 0 : (int) $result['images'];
    }

    private function queue_remote_image_cache($cache_key, $source_url, $title, $alt, $version) {
        $cache_key = sanitize_key((string) $cache_key);
        $source_url = esc_url_raw((string) $source_url);
        if ($cache_key === '' || $source_url === '' || $this->get_cached_attachment_id($cache_key, $source_url, $version)) {
            return false;
        }

        $queue = get_option('menuosaur_image_cache_queue', array());
        $queue = is_array($queue) ? $queue : array();
        $existing_attempts = isset($queue[$cache_key]['attempts']) ? absint($queue[$cache_key]['attempts']) : 0;
        $queue[$cache_key] = array(
            'source_url' => $source_url,
            'title' => wp_strip_all_tags((string) $title),
            'alt' => wp_strip_all_tags((string) $alt),
            'version' => (int) $version,
            'attempts' => $existing_attempts,
            'queued_at' => $this->manager->now_gmt(),
        );
        update_option('menuosaur_image_cache_queue', $queue, false);

        if (!wp_next_scheduled(self::IMAGE_CACHE_CRON_HOOK)) {
            wp_schedule_single_event(time() + 60, self::IMAGE_CACHE_CRON_HOOK);
        }

        return true;
    }

    private function get_cached_attachment_id($cache_key, $source_url, $version) {
        $cache_key = sanitize_key((string) $cache_key);
        $source_url = esc_url_raw((string) $source_url);
        if ($cache_key === '' || $source_url === '') {
            return 0;
        }

        $cache = get_option('menuosaur_image_cache', array());
        $cache = is_array($cache) ? $cache : array();
        $existing = isset($cache[$cache_key]) && is_array($cache[$cache_key]) ? $cache[$cache_key] : array();
        $existing_id = !empty($existing['attachment_id']) ? absint($existing['attachment_id']) : 0;
        if (
            $existing_id > 0
            && get_post($existing_id)
            && isset($existing['source_url'], $existing['version'])
            && (string) $existing['source_url'] === $source_url
            && (int) $existing['version'] === (int) $version
        ) {
            return $existing_id;
        }

        return 0;
    }

    private function cache_remote_image($cache_key, $source_url, $title, $alt, $version) {
        $cache_key = sanitize_key((string) $cache_key);
        $source_url = esc_url_raw((string) $source_url);
        if ($cache_key === '' || $source_url === '') {
            return new WP_Error('menuosaur_invalid_image_cache_source', __('Invalid image cache source.', 'menuosaur'));
        }

        $existing_id = $this->get_cached_attachment_id($cache_key, $source_url, $version);
        if ($existing_id) {
            return $existing_id;
        }

        $cache = get_option('menuosaur_image_cache', array());
        $cache = is_array($cache) ? $cache : array();
        $stale_attachment_id = !empty($cache[$cache_key]['attachment_id']) ? absint($cache[$cache_key]['attachment_id']) : 0;

        $this->load_media_dependencies();

        $tmp = download_url($source_url, 45);
        if (is_wp_error($tmp)) {
            return $tmp;
        }

        $file_array = array(
            'name' => $this->build_cached_image_filename($cache_key, $source_url, $title),
            'tmp_name' => $tmp,
        );

        $attachment_id = media_handle_sideload($file_array, 0, $title !== '' ? $title : __('Menuosaur Square image', 'menuosaur'));
        if (is_wp_error($attachment_id)) {
            if (file_exists($tmp)) {
                wp_delete_file($tmp);
            }
            return $attachment_id;
        }

        if ($alt !== '') {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', wp_strip_all_tags($alt));
        }
        update_post_meta($attachment_id, '_menuosaur_square_image_key', $cache_key);
        update_post_meta($attachment_id, '_menuosaur_square_image_source_url', $source_url);
        update_post_meta($attachment_id, '_menuosaur_square_image_version', (int) $version);

        $cache[$cache_key] = array(
            'attachment_id' => (int) $attachment_id,
            'source_url' => $source_url,
            'version' => (int) $version,
            'cached_at' => $this->manager->now_gmt(),
        );
        update_option('menuosaur_image_cache', $cache, false);

        if ($stale_attachment_id > 0 && $stale_attachment_id !== (int) $attachment_id && get_post($stale_attachment_id)) {
            wp_delete_attachment($stale_attachment_id, true);
        }

        return (int) $attachment_id;
    }

    private function build_cached_image_filename($cache_key, $source_url, $title) {
        $path = wp_parse_url($source_url, PHP_URL_PATH);
        $extension = $path ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';
        if (!in_array($extension, array('jpg', 'jpeg', 'png', 'gif', 'webp'), true)) {
            $extension = 'jpg';
        }

        $base = sanitize_file_name($title !== '' ? $title : 'menuosaur-square-image');
        if ($base === '') {
            $base = 'menuosaur-square-image';
        }

        return $base . '-' . substr(md5($cache_key . '|' . $source_url), 0, 10) . '.' . $extension;
    }

    private function load_media_dependencies() {
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }
        if (!function_exists('wp_read_image_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
    }

    private function get_image_size_options() {
        return array(
            'square_original' => __('Original Square image URL', 'menuosaur'),
            'thumbnail' => __('WordPress thumbnail', 'menuosaur'),
            'medium' => __('WordPress medium', 'menuosaur'),
            'large' => __('WordPress large', 'menuosaur'),
        );
    }

    private function sanitize_image_size($size) {
        $size = sanitize_key((string) $size);
        return array_key_exists($size, $this->get_image_size_options()) ? $size : 'square_original';
    }

    private function get_available_custom_attribute_options() {
        $options = array();
        $items = $this->manager->get_all_items();

        foreach ($items as $item) {
            $this->collect_custom_attribute_options_from_raw($item['raw_json'], $options);
            foreach ($this->manager->get_variations_by_item($item['object_id']) as $variation) {
                $this->collect_custom_attribute_options_from_raw($variation['raw_json'], $options);
            }
        }

        uasort(
            $options,
            function ($a, $b) {
                return strnatcasecmp($a['label'], $b['label']);
            }
        );

        return $options;
    }

    private function collect_custom_attribute_options_from_raw($raw_object, &$options) {
        if (!is_array($raw_object) || empty($raw_object['custom_attribute_values']) || !is_array($raw_object['custom_attribute_values'])) {
            return;
        }

        foreach ($raw_object['custom_attribute_values'] as $map_key => $attribute) {
            if (!is_array($attribute)) {
                continue;
            }

            $key = isset($attribute['key']) && $attribute['key'] !== '' ? (string) $attribute['key'] : (string) $map_key;
            if ($key === '') {
                continue;
            }

            $label = isset($attribute['name']) && $attribute['name'] !== ''
                ? (string) $attribute['name']
                : $this->humanize_custom_attribute_key($key);
            $options[$key] = $this->format_custom_attribute_option($key, $label);
        }
    }

    private function format_custom_attribute_option($key, $label) {
        return array(
            'key' => (string) $key,
            'label' => (string) $label,
        );
    }

    private function get_selected_custom_attribute_parts($item, $variation_objects, $selected_keys, $show_labels) {
        if (empty($selected_keys)) {
            return array();
        }

        $parts = array();
        $seen = array();
        foreach ($this->extract_custom_attribute_parts_from_raw($item['raw_json'], $selected_keys, $show_labels) as $part) {
            $fingerprint = $this->normalize_custom_attribute_part($part);
            if ($fingerprint === '' || isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;
            $parts[] = esc_html($part);
        }

        foreach ($variation_objects as $variation) {
            foreach ($this->extract_custom_attribute_parts_from_raw($variation['raw_json'], $selected_keys, $show_labels) as $part) {
                $fingerprint = $this->normalize_custom_attribute_part($part);
                if ($fingerprint === '' || isset($seen[$fingerprint])) {
                    continue;
                }
                $seen[$fingerprint] = true;
                $parts[] = esc_html($part);
            }
        }

        return $parts;
    }

    private function extract_custom_attribute_parts_from_raw($raw_object, $selected_keys, $show_labels) {
        if (!is_array($raw_object) || empty($raw_object['custom_attribute_values']) || !is_array($raw_object['custom_attribute_values'])) {
            return array();
        }

        $selected_lookup = array_fill_keys($selected_keys, true);
        $parts = array();
        foreach ($raw_object['custom_attribute_values'] as $map_key => $attribute) {
            if (!is_array($attribute)) {
                continue;
            }

            $key = isset($attribute['key']) && $attribute['key'] !== '' ? (string) $attribute['key'] : (string) $map_key;
            if (!isset($selected_lookup[$key]) && !isset($selected_lookup[(string) $map_key])) {
                continue;
            }

            $value = $this->format_custom_attribute_value($attribute);
            if ($value === '') {
                continue;
            }

            if ($show_labels) {
                $label = isset($attribute['name']) && $attribute['name'] !== ''
                    ? (string) $attribute['name']
                    : $this->humanize_custom_attribute_key($key);
                $parts[] = $label . ': ' . $value;
            } else {
                $parts[] = $value;
            }
        }

        return $parts;
    }

    private function format_custom_attribute_value($attribute) {
        if (isset($attribute['string_value']) && $attribute['string_value'] !== '') {
            return (string) $attribute['string_value'];
        }

        if (isset($attribute['number_value']) && $attribute['number_value'] !== '') {
            return (string) $attribute['number_value'];
        }

        if (array_key_exists('boolean_value', $attribute)) {
            return !empty($attribute['boolean_value']) ? __('Yes', 'menuosaur') : __('No', 'menuosaur');
        }

        if (!empty($attribute['selection_uid_values']) && is_array($attribute['selection_uid_values'])) {
            return implode(', ', array_map('sanitize_text_field', $attribute['selection_uid_values']));
        }

        if (!empty($attribute['selection_values']) && is_array($attribute['selection_values'])) {
            $labels = array();
            foreach ($attribute['selection_values'] as $selection_value) {
                if (is_array($selection_value) && !empty($selection_value['name'])) {
                    $labels[] = (string) $selection_value['name'];
                } elseif (is_string($selection_value) && $selection_value !== '') {
                    $labels[] = $selection_value;
                }
            }
            return implode(', ', $labels);
        }

        return '';
    }

    private function normalize_custom_attribute_part($part) {
        $part = trim(wp_strip_all_tags((string) $part));
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($part, 'UTF-8');
        }

        return strtolower($part);
    }

    private function humanize_custom_attribute_key($key) {
        $key = (string) $key;
        if (strpos($key, ':') !== false) {
            $parts = explode(':', $key);
            $key = end($parts);
        }

        $key = str_replace(array('-', '_'), ' ', $key);
        return ucwords(trim($key));
    }

    private function build_shortcode_config_from_request($category_ids) {
        $regular_categories = $this->filter_regular_categories($this->manager->get_categories());
        $regular_category_lookup = array();
        foreach ($regular_categories as $category) {
            $regular_category_lookup[(string) $category['object_id']] = true;
        }

        $category_ids = is_array($category_ids)
            ? array_values(
                array_filter(
                    array_map('sanitize_text_field', $category_ids),
                    function ($category_id) use ($regular_category_lookup) {
                        return isset($regular_category_lookup[(string) $category_id]);
                    }
                )
            )
            : array();
        $category_items = array_values(
            array_filter(
                $this->manager->get_all_items(),
                function ($item) use ($regular_category_lookup) {
                    return $this->item_belongs_to_category_lookup($item, $regular_category_lookup);
                }
            )
        );
        $item_lookup = array();
        foreach ($category_items as $item) {
            $item_lookup[(string) $item['object_id']] = $item;
        }

        $selected_items = isset($_POST['selected_items']) && is_array($_POST['selected_items'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['selected_items']))
            : array();
        $selected_items = array_values(
            array_filter(
                array_unique($selected_items),
                function ($item_id) use ($item_lookup) {
                    return isset($item_lookup[$item_id]);
                }
            )
        );

        $item_order = isset($_POST['item_order']) && is_array($_POST['item_order']) ? wp_unslash($_POST['item_order']) : array();
        usort(
            $selected_items,
            function ($a, $b) use ($item_order, $item_lookup) {
                $a_order = isset($item_order[$a]) ? absint($item_order[$a]) : 9999;
                $b_order = isset($item_order[$b]) ? absint($item_order[$b]) : 9999;
                if ($a_order === $b_order) {
                    return strnatcasecmp($item_lookup[$a]['name'], $item_lookup[$b]['name']);
                }

                return $a_order < $b_order ? -1 : 1;
            }
        );

        $posted_variations = isset($_POST['selected_variations']) && is_array($_POST['selected_variations']) ? wp_unslash($_POST['selected_variations']) : array();
        $variation_order = isset($_POST['variation_order']) && is_array($_POST['variation_order']) ? wp_unslash($_POST['variation_order']) : array();
        $config = array(
            'item_order' => $selected_items,
            'variations' => array(),
            'display' => array(
                'show_item_name' => isset($_POST['display_show_item_name']) ? 1 : 0,
                'show_item_image' => isset($_POST['display_show_item_image']) ? 1 : 0,
                'show_description' => isset($_POST['display_show_description']) ? 1 : 0,
                'show_prices' => isset($_POST['display_show_prices']) ? 1 : 0,
                'image_size' => isset($_POST['display_image_size']) ? $this->sanitize_image_size(wp_unslash($_POST['display_image_size'])) : 'square_original',
                'custom_attribute_keys' => isset($_POST['display_custom_attribute_keys']) && is_array($_POST['display_custom_attribute_keys'])
                    ? array_values(array_filter(array_map('sanitize_text_field', wp_unslash($_POST['display_custom_attribute_keys']))))
                    : array(),
                'show_custom_attribute_labels' => isset($_POST['display_show_custom_attribute_labels']) ? 1 : 0,
            ),
            'category_ids' => $category_ids,
        );

        foreach ($selected_items as $item_id) {
            $valid_variations = $this->manager->get_variations_by_item($item_id);
            $valid_lookup = array();
            foreach ($valid_variations as $variation) {
                $valid_lookup[(string) $variation['object_id']] = $variation;
            }

            $variation_ids = isset($posted_variations[$item_id]) && is_array($posted_variations[$item_id])
                ? array_map('sanitize_text_field', $posted_variations[$item_id])
                : array();
            $variation_ids = array_values(
                array_filter(
                    array_unique($variation_ids),
                    function ($variation_id) use ($valid_lookup) {
                        return isset($valid_lookup[$variation_id]);
                    }
                )
            );

            usort(
                $variation_ids,
                function ($a, $b) use ($variation_order, $valid_lookup) {
                    $a_order = isset($variation_order[$a]) ? absint($variation_order[$a]) : 9999;
                    $b_order = isset($variation_order[$b]) ? absint($variation_order[$b]) : 9999;
                    if ($a_order === $b_order) {
                        return strnatcasecmp($valid_lookup[$a]['name'], $valid_lookup[$b]['name']);
                    }

                    return $a_order < $b_order ? -1 : 1;
                }
            );

            $config['variations'][$item_id] = $variation_ids;
        }

        return $config;
    }

    private function assert_admin_post($nonce_action) {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'menuosaur'));
        }

        check_admin_referer($nonce_action, 'menuosaur_nonce');
    }

    private function redirect_with_result($url, $result, $success_message) {
        $status = is_wp_error($result) ? 'error' : 'success';
        $message = is_wp_error($result) ? $result->get_error_message() : $success_message;
        $message = wp_strip_all_tags((string) $message);
        if (strlen($message) > 350) {
            $message = substr($message, 0, 347) . '...';
        }

        wp_safe_redirect(
            add_query_arg(
                array(
                    'menuosaur_status' => $status,
                    'menuosaur_message' => rawurlencode($message),
                ),
                $url
            )
        );
        exit;
    }

    private function render_admin_notice() {
        if (empty($_GET['menuosaur_status']) || empty($_GET['menuosaur_message'])) {
            return;
        }

        $status = sanitize_key(wp_unslash($_GET['menuosaur_status']));
        $message = rawurldecode(sanitize_text_field(wp_unslash($_GET['menuosaur_message'])));
        $class = $status === 'success' ? 'notice-success' : 'notice-error';

        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    private function log_failed_sync($trigger_type, $started_at, $error) {
        $this->manager->log_sync(array(
            'status' => 'error',
            'trigger_type' => $trigger_type,
            'message' => is_wp_error($error) ? $error->get_error_message() : __('Unknown sync error.', 'menuosaur'),
            'started_at' => $started_at,
            'finished_at' => $this->manager->now_gmt(),
        ));
    }

    private function format_square_error_message($decoded, $code) {
        if (!empty($decoded['errors']) && is_array($decoded['errors'])) {
            $messages = array();
            foreach ($decoded['errors'] as $error) {
                if (!is_array($error)) {
                    continue;
                }
                $parts = array();
                if (!empty($error['category'])) {
                    $parts[] = (string) $error['category'];
                }
                if (!empty($error['code'])) {
                    $parts[] = (string) $error['code'];
                }
                if (!empty($error['detail'])) {
                    $parts[] = (string) $error['detail'];
                }
                if (!empty($parts)) {
                    $messages[] = implode(': ', $parts);
                }
            }
            if (!empty($messages)) {
                return implode(' | ', $messages);
            }
        }

        return sprintf(__('Square API request failed with HTTP %d.', 'menuosaur'), $code);
    }

    private function get_square_access_token() {
        return isset($this->settings['square_access_token']) ? trim((string) $this->settings['square_access_token']) : '';
    }

    private function get_admin_menu_label() {
        return $this->sanitize_admin_menu_label(isset($this->settings['admin_menu_label']) ? $this->settings['admin_menu_label'] : '');
    }

    private function sanitize_admin_menu_label($label) {
        $label = trim(sanitize_text_field((string) $label));
        if ($label === '') {
            return 'Menuosaur';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($label, 0, 80, 'UTF-8');
        }

        return substr($label, 0, 80);
    }

    private function get_category_display_label($category) {
        $label = isset($category['name']) ? (string) $category['name'] : '';
        $category_type = !empty($category['category_type']) ? strtoupper((string) $category['category_type']) : '';
        if ($category_type !== '' && $category_type !== 'REGULAR_CATEGORY') {
            $label .= ' (' . $category['category_type'] . ')';
        }

        return $label;
    }

    private function filter_regular_categories($categories) {
        return array_values(
            array_filter(
                is_array($categories) ? $categories : array(),
                function ($category) {
                    return isset($category['category_type']) && strtoupper((string) $category['category_type']) === 'REGULAR_CATEGORY';
                }
            )
        );
    }

    private function item_belongs_to_category_lookup($item, $category_lookup) {
        if (empty($category_lookup) || empty($item['category_ids']) || !is_array($item['category_ids'])) {
            return false;
        }

        foreach ($item['category_ids'] as $category_id) {
            if (isset($category_lookup[(string) $category_id])) {
                return true;
            }
        }

        return false;
    }

    private function format_square_money($amount, $currency) {
        if ($amount === null || $amount === '') {
            return '';
        }

        $currency = strtoupper((string) $currency);
        $symbols = array(
            'USD' => '$',
            'CAD' => '$',
            'AUD' => '$',
            'NZD' => '$',
            'GBP' => '£',
            'EUR' => '€',
        );
        $symbol = isset($symbols[$currency]) ? $symbols[$currency] : ($currency !== '' ? $currency . ' ' : '');
        $value = ((int) $amount) / 100;
        $decimals = abs($value - round($value)) < 0.0001 ? 0 : 2;

        return (!empty($this->settings['hide_currency_symbol']) ? '' : $symbol) . number_format_i18n($value, $decimals);
    }

    private function format_admin_datetime($mysql_gmt) {
        $timestamp = strtotime((string) $mysql_gmt . ' UTC');
        if (!$timestamp) {
            return '';
        }

        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    private function get_status_pill($active) {
        return $active
            ? '<span class="menuosaur-status-pill menuosaur-status-active">' . esc_html__('Active', 'menuosaur') . '</span>'
            : '<span class="menuosaur-status-pill menuosaur-status-inactive">' . esc_html__('Inactive', 'menuosaur') . '</span>';
    }

    private function get_sync_status_pill($success) {
        return $success
            ? '<span class="menuosaur-status-pill menuosaur-status-active">' . esc_html__('Success', 'menuosaur') . '</span>'
            : '<span class="menuosaur-status-pill menuosaur-status-inactive">' . esc_html__('Error', 'menuosaur') . '</span>';
    }

    private function admin_tab_url($tab) {
        return add_query_arg(
            array(
                'page' => 'menuosaur-menus',
                'tab' => $tab,
            ),
            admin_url('admin.php')
        );
    }
}
