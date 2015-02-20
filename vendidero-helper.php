<?php
/*
Plugin Name: Vendidero Helper
Plugin URI: http://vendidero.de
Description: Will help vendidero users manage their licenses and receive automatic update notifications
Version: 1.0.0
Author: Vendidero
Author URI: http://vendidero.de
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

final class Vendidero_Helper {

    /**
     * Single instance of Vendidero Main Class
     *
     * @var object
     */
    protected static $_instance = null;

    public $version = '1.0.0';

    private $token = 'vendidero-api';
    private $api_url = 'http://localhost/vendisale/vd-api/';
    private $products = array();
    public $api = null;
    public $plugins = array();
    public $themes = array();

    /**
     * Main Vendidero Instance
     *
     * Ensures that only one instance of Vendidero is loaded or can be loaded.
     *
     * @static
     */
    public static function instance() {
        if ( is_null( self::$_instance ) )
            self::$_instance = new self();
        return self::$_instance;
    }

    public function __construct() {

        // Auto-load classes on demand
        if ( function_exists( "__autoload" ) )
            spl_autoload_register( "__autoload" );
        spl_autoload_register( array( $this, 'autoload' ) );

        register_activation_hook( __FILE__, array( $this, 'install' ) );

        // Hooks
        add_action( 'plugins_loaded', array( $this, 'load_plugin_textdomain' ) );
        
        if ( is_admin() )
            $this->init();
    }

    public function init() {
        // Hook
        $this->api = new VD_API();
        $this->includes();
        add_action( 'admin_init', array( $this, 'set_data' ), 0 );
        add_action( 'admin_init', array( $this, 'register_products' ), 1 );
        add_action( 'admin_init', array( $this, 'update_products' ), 2 );
    }

    public function set_data() {
        $this->plugins = get_plugins();
        $themes = wp_get_themes();
        if ( ! empty( $themes ) ) {
            foreach ( $themes as $theme )
                $this->themes[ basename( $theme->__get( 'stylesheet_dir' ) ) . '/style.css' ] = $theme;
        }
    }

    public function install() {
        if ( $this->version != '' )
            update_option( 'vendidero_version', $this->version );
    }

    /**
     * Load Localisation files for WooCommerce Germanized.
     */
    public function load_plugin_textdomain() {
        $domain = 'vendidero-helper';
        $locale = apply_filters( 'plugin_locale', get_locale(), $domain );

        load_textdomain( $domain, trailingslashit( WP_LANG_DIR ) . $domain . '/' . $domain . '-' . $locale . '.mo' );
        load_plugin_textdomain( $domain, FALSE, basename( dirname( __FILE__ ) ) . '/i18n/' );
    }

    /**
     * Auto-load
     *
     * @param mixed   $class
     * @return void
     */
    public function autoload( $class ) {
        $path = $this->plugin_path() . '/includes/';
        $class = strtolower( $class );
        $file = 'class-' . str_replace( '_', '-', $class ) . '.php';
        if ( $path && is_readable( $path . $file ) ) {
            include_once( $path . $file );
            return;
        }
    }

    public function includes() {
        include_once( $this->plugin_path() . '/includes/class-vd-admin.php' );
    }

    public function register_products() {
        $products = apply_filters( 'vendidero_updateable_products', array() );
        if ( ! empty( $products ) && is_array( $products ) )
            foreach ( $products as $plugin )
                if ( is_object( $plugin ) && ! empty( $plugin->file ) && ! empty( $plugin->product_id ) )
                    $this->add_product( $plugin->file, $plugin->product_id );
        // Self update
        $this->add_product( 'vendidero-helper/vendidero-helper.php', 2711, true );
    }

    public function update_products() {
        if ( ! empty( $this->products ) ) {
            foreach ( $this->products as $key => $product ) {
                if ( $product->is_registered() )
                    $product->updater = new VD_Updater( $product );
            }
        }
    }

    public function add_product( $file, $product_id, $free = false ) {
        if ( $file != '' && ! isset( $this->products[ $file ] ) )
            $this->products[ $file ] = ( strpos( $file, 'style.css' ) ? new VD_Product_Theme( $file, $product_id, $free ) : new VD_Product( $file, $product_id, $free ) );
    }

    public function remove_product( $file ) {
        $response = false;
        if ( $file != '' && in_array( $file, array_keys( $this->products ) ) ) {
            unset( $this->products[ $file ] );
            $response = true;
        }
        return $response;
    }

    public function get_products( $show_free = true ) {
        $products = $this->products;
        if ( ! $show_free ) {
            foreach ( $this->products as $key => $product ) {
                if ( $product->free )
                    unset( $products[ $key ] );
            }
        }
        return $products;
    }

    public function get_api_url() {
        return $this->api_url;
    }

    public function get_token() {
        return $this->token;
    }

    /**
     * Get the plugin url.
     *
     * @return string
     */
    public function plugin_url() {
        return untrailingslashit( plugins_url( '/', __FILE__ ) );
    }

    /**
     * Get the plugin path.
     *
     * @return string
     */
    public function plugin_path() {
        return untrailingslashit( plugin_dir_path( __FILE__ ) );
    }

}

function VD() {
    return Vendidero_Helper::instance();
}

$GLOBALS['vendidero_helper'] = VD();

?>