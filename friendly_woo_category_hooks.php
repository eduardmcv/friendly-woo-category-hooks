<?php
/**
 * Plugin Name: Friendly Woo Category Hooks
 * Description: Simplifica la creación de hooks para categorías de productos en WooCommerce. 
 * Version: 2.6.0
 * Author: Eduard
 * Text Domain: woo-category-hooks
 * GitHub Plugin URI: eduardmcv/friendly-woo-category-hooks
 */

if (!defined('ABSPATH')) exit;

// Definir constantes del plugin
define('FWCH_VERSION', '2.6.0');
define('FWCH_PLUGIN_FILE', __FILE__);
define('FWCH_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FWCH_PLUGIN_URL', plugin_dir_url(__FILE__));

// Incluir el updater
require_once FWCH_PLUGIN_DIR . 'updater/plugin-updater.php';

// Inicializar el updater
if (is_admin()) {
    new FWCH_Plugin_Updater(FWCH_PLUGIN_FILE, 'eduardmcv', 'friendly-woo-category-hooks');
}

// 1. Registrar el CPT
function wch_register_cpt() {
    register_post_type('woo_category_hook', [
        'labels' => [
            'name' => 'Friendly Woo Category Hooks',
            'singular_name' => 'Hook de Categoría',
            'add_new_item' => 'Añadir nuevo hook',
            'edit_item' => 'Editar hook',
            'not_found' => 'No hay hooks aún'
        ],
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => true,
        'show_in_rest' => true,
        'supports'     => ['title', 'editor'],
        'menu_icon' => 'dashicons-table-row-before',
    ]);
}
add_action('init', 'wch_register_cpt');

// 2. Metabox: categoría asociada + posición
function wch_add_meta_boxes() {
    add_meta_box('wch_meta_box', 'Configuración del Hook', 'wch_render_meta_box', 'woo_category_hook', 'normal', 'high');
}
add_action('add_meta_boxes', 'wch_add_meta_boxes');

function wch_render_meta_box($post) {
    // Añadir campo de seguridad (nonce)
    wp_nonce_field('wch_meta_box_nonce_action', 'wch_meta_box_nonce_field');

    $selected_cat = get_post_meta($post->ID, '_wch_category', true);
    $position = get_post_meta($post->ID, '_wch_position', true);
    $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
    ?>
    <p>
        <label><strong>Categoría de producto:</strong></label><br>
        <select name="wch_category">
            <?php foreach ($categories as $cat): ?>
                <option value="<?php echo esc_attr($cat->slug); ?>" <?php selected($selected_cat, $cat->slug); ?>>
                    <?php echo esc_html($cat->name); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>
    <p>
        <label><strong>Posición:</strong></label><br>
        <select name="wch_position">
            <option value="before" <?php selected($position, 'before'); ?>>Antes de los productos</option>
            <option value="after" <?php selected($position, 'after'); ?>>Después de los productos</option>
        </select>
    </p>
    <?php
}

// Guardar los metadatos del hook
function wch_save_meta($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (isset($_POST['wch_category'])) {
        update_post_meta($post_id, '_wch_category', sanitize_text_field($_POST['wch_category']));
    }
    if (isset($_POST['wch_position'])) {
        update_post_meta($post_id, '_wch_position', sanitize_text_field($_POST['wch_position']));
    }
    if (!isset($_POST['wch_meta_box_nonce_field']) || 
    !wp_verify_nonce($_POST['wch_meta_box_nonce_field'], 'wch_meta_box_nonce_action')) {
    return;
}

if (!current_user_can('edit_post', $post_id)) return;
}
add_action('save_post', 'wch_save_meta');

// 3. Hook en WooCommerce para inyectar contenido
// Antes del listado, incluso si no hay productos
add_action('woocommerce_archive_description', function() {
    wch_render_hook('before');
}, 15);

// Después del contenido (más fiable que after_shop_loop)
add_action('woocommerce_after_main_content', function() {
    if (is_product_category()) {
        wch_render_hook('after');
    }
}, 5);

function wch_render_hook($position) {
    if (!is_product_category()) return;

    $cat = get_queried_object();
    $query = new WP_Query([
        'post_type' => 'woo_category_hook',
        'meta_query' => [
            [
                'key' => '_wch_category',
                'value' => $cat->slug,
            ],
            [
                'key' => '_wch_position',
                'value' => $position,
            ]
        ],
        'posts_per_page' => 1
    ]);

    if ($query->have_posts()) {
        $query->the_post();
        echo '<div class="wch-block wch-' . esc_attr($position) . '">';
        echo do_blocks(get_the_content());
        echo '</div>';
        wp_reset_postdata();
    }
}

add_action('save_post_woo_category_hook', 'wch_set_auto_title', 20, 3);
function wch_set_auto_title($post_id, $post, $update) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;
    if (!isset($_POST['wch_meta_box_nonce_field']) || 
        !wp_verify_nonce($_POST['wch_meta_box_nonce_field'], 'wch_meta_box_nonce_action')) return;

    // Usar los valores directos del formulario (más fiable)
    $position = isset($_POST['wch_position']) ? sanitize_text_field($_POST['wch_position']) : '';
    $category_slug = isset($_POST['wch_category']) ? sanitize_text_field($_POST['wch_category']) : '';
    if (!$position || !$category_slug) return;

    $term = get_term_by('slug', $category_slug, 'product_cat');
    if (!$term || is_wp_error($term)) return;

    $new_title = ucfirst($position) . ' - ' . $term->name;

    if (empty($post->post_title) || $post->post_title !== $new_title) {
        remove_action('save_post_woo_category_hook', 'wch_set_auto_title', 20);

        wp_update_post([
            'ID' => $post_id,
            'post_title' => $new_title,
            'post_name'  => sanitize_title($new_title),
        ]);

        add_action('save_post_woo_category_hook', 'wch_set_auto_title', 20, 3);
    }
}

add_filter('post_row_actions', 'wch_add_view_category_link', 10, 2);
function wch_add_view_category_link($actions, $post) {
    // Solo para nuestro CPT
    if ($post->post_type !== 'woo_category_hook') return $actions;

    $category_slug = get_post_meta($post->ID, '_wch_category', true);
    if (!$category_slug) return $actions;

    $term = get_term_by('slug', $category_slug, 'product_cat');
    if (!$term || is_wp_error($term)) return $actions;

    $url = get_term_link($term);

    if (!is_wp_error($url)) {
        $actions['view_category'] = '<a href="' . esc_url($url) . '" target="_blank">Ver categoría</a>';
    }

    return $actions;
}

add_action('admin_notices', 'wch_notice_alternativo');
function wch_notice_alternativo() {
    global $post;
    if (!is_admin() || get_current_screen()->post_type !== 'woo_category_hook') return;

echo '<div class="notice notice-warning" style="border-left-color: #dc3545 !important; font-size: 16px !important;">
    <p>
        <strong>Friendly Woo Category Hooks:</strong> Con el objetivo de mantener un orden claro, el título del hook se renombrará automáticamente a <strong>"Posición - Categoría"</strong> cuando guardes.
        <br><br>
        <strong>Importante:</strong> El título no se generará si dejas el campo vacío. Introduce cualquier texto antes de guardar por primera vez. (En ocasiones, puede que necesites guardar dos veces para que se actualice correctamente).
    </p>
</div>';
}