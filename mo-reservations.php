<?php
/**
 * Plugin Name: MO Reservations
 * Description: Jednoduchý rezervační systém s řetězením slotů, e-mailovým potvrzením, ICS feedem a storno odkazem.
 * Version: 0.4.20
 * Author: MO
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define('MORES_VER', '0.4.20');
define('MORES_PATH', plugin_dir_path(__FILE__));
define('MORES_URL', plugin_dir_url(__FILE__));

require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$updateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/MarekTP/mo-reservations',
    __FILE__,
    'mo-reservations'
);

$updateChecker->getVcsApi()->enableReleaseAssets();

require_once MORES_PATH . 'includes/class-mores-db.php';
require_once MORES_PATH . 'includes/class-mores-woo.php';
require_once MORES_PATH . 'includes/class-mores-plugin.php';
require_once MORES_PATH . 'includes/class-mores-logger.php';
require_once MORES_PATH . 'includes/class-mores-availability.php';
require_once MORES_PATH . 'includes/class-mores-email.php';
require_once MORES_PATH . 'includes/class-mores-ics.php';
require_once MORES_PATH . 'includes/helpers.php';

register_activation_hook(__FILE__, ['MORES_DB', 'activate']);
register_uninstall_hook(__FILE__, 'mores_uninstall');



function mores_uninstall() {
    // Odstranění tabulek dle nastavení
    $drop = get_option('mores_drop_tables_on_uninstall', 0);
    if ($drop) {
        MORES_DB::uninstall();
    }
}


// --- Dependency: WooCommerce required ---
function mores_require_woo_on_activate() {
    if ( ! function_exists('is_plugin_active') ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $has_woo = class_exists('WooCommerce') || is_plugin_active('woocommerce/woocommerce.php');
    if ( ! $has_woo ) {
        deactivate_plugins( plugin_basename(__FILE__) );
        wp_die(__('Plugin MO Reservations vyžaduje aktivní WooCommerce. Nejprve prosím nainstalujte a aktivujte WooCommerce.', 'mo-reservations'), __('Aktivace přerušena', 'mo-reservations'), ['back_link' => true]);
    }
}
register_activation_hook(__FILE__, 'mores_require_woo_on_activate');

add_action('admin_init', function(){
    if ( ! class_exists('WooCommerce') ) {
        if ( current_user_can('activate_plugins') ) {
            deactivate_plugins( plugin_basename(__FILE__) );
            add_action('admin_notices', function(){
                echo '<div class="notice notice-error"><p><strong>MO Reservations</strong>: vyžaduje WooCommerce. Plugin byl deaktivován.</p></div>';
            });
        }
    }
});

add_action('plugins_loaded', function() {
    MORES_DB::maybe_update();
    if ( class_exists('WooCommerce') ) { MORES_Woo::init(); }
    new MORES_Plugin();
    new MORES_ICS();
});

