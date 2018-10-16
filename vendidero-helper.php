<?php
/*
 * Plugin Name: Vendidero Helper
 * Plugin URI: http://vendidero.de
 * Description: Will help vendidero users to manage their licenses and receive automatic updates
 * Version: 1.2.1
 * Author: Vendidero
 * Author URI: http://vendidero.de
 * License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 *
 * Text Domain: vendidero-helper
 * Domain Path: /i18n/
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

final class Vendidero_Helper {

    /**
     * Single instance of Vendidero Main Class
     *
     * @var object
     */
    protected static $_instance = null;

    public $version = '1.2.1';

    private $token = 'vendidero-api';
    private $api_url = 'https://vendidero.de/vd-api/';
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

        add_filter( 'cron_schedules', array( $this, 'set_weekly_schedule' ) );

        register_activation_hook( __FILE__, array( $this, 'install' ) );

        // Hooks
        add_action( 'plugins_loaded', array( $this, 'load_plugin_textdomain' ) );
        
        if ( is_admin() )
            $this->init();

        add_action( 'vendidero_cron', array( $this, 'expire_cron' ), 0 );
    }

    public function set_weekly_schedule( $schedules ) {
        $schedules[ 'weekly' ] = array(
            'interval' => 604800,
            'display'  => __( 'Once per week', 'vendidero-helper' ),
        );
        return $schedules;
    }

    public function init() {
        // Hook
        $this->api = new VD_API();
        
        $this->includes();
        
        add_action( 'admin_init', array( $this, 'load' ), 0 );
        add_action( 'admin_init', array( $this, 'check_notice_hide' ) );
        add_action( 'admin_notices', array( $this, 'expire_notice' ), 0 );
    }

    public function load() {

	    // If multisite, plugin must be network activated. First make sure the is_plugin_active_for_network function exists
	    if( is_multisite() && ! is_network_admin() ) {

		    remove_action( 'admin_notices', 'vendidero_helper_notice' );

		    if ( ! function_exists( 'is_plugin_active_for_network' ) )
			    require_once( ABSPATH . '/wp-admin/includes/plugin.php' );

		    if( ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
			    add_action( 'admin_notices', array( $this, 'admin_notice_require_network_activation' ) );
			    return;
		    }
	    }

        $this->set_data();
        $this->register_products();
        $this->update_products();
    }

	public function admin_notice_require_network_activation() {
		echo '<div class="error"><p>' . __( 'Vendidero Helper must be network activated when in multisite environment.', 'vendidero-helper' ) . '</p></div>';
	}

    public function expire_cron() {
        $this->api = new VD_API();
        $this->includes();
        $this->load();
        if ( ! empty( $this->products ) ) {
            foreach ( $this->products as $key => $product ) {
                if ( ! $product->is_registered() )
                    continue;
                // Refresh expiration date
                $product->refresh_expiration_date();
                
                if ( $expire = $product->get_expiration_date( false ) ) {
                    $diff = VD()->get_date_diff( date( 'Y-m-d' ), $expire );
                    $notice = get_option( 'vendidero_notice_expire', array() );
                    if ( ( strtotime( $expire ) <= time() ) || ( empty( $diff[ 'y' ] ) && empty( $diff[ 'm' ] ) && $diff[ 'd' ] <= 7 ) )
                        $notice[ $key ] = true;
                    update_option( 'vendidero_notice_expire', $notice );
                }
            }
        }
    }

    public function check_notice_hide() {
        if ( isset( $_GET[ 'notice' ] ) && $_GET[ 'notice' ] == 'vd-hide-notice' && check_admin_referer( 'vd-hide-notice', 'nonce' ) ) {
            delete_option( 'vendidero_notice_expire' );
            remove_action( 'admin_notices', array( $this, 'expire_notice' ), 0 );
        }
    }

    public function expire_notice() {
        if ( get_option( 'vendidero_notice_expire' ) ) {

        	// Check whether license has been renewed already
	        $products = get_option( 'vendidero_notice_expire' );
	        $new_products = array();

	        foreach ( $products as $key => $val ) {
		        if ( isset( VD()->products[ $key ] ) ) {
			        $product = VD()->products[ $key ];

			        if ( $expire = $product->get_expiration_date( false ) ) {

			        	$diff = VD()->get_date_diff( date( 'Y-m-d' ), $expire );
				        if ( ( strtotime( $expire ) <= time() ) || ( empty( $diff[ 'y' ] ) && empty( $diff[ 'm' ] ) && $diff[ 'd' ] <= 7 ) )
					        $new_products[ $key ] = true;
			        }
		        }
	        }

	        if ( ! empty( $new_products ) ) {
		        update_option( 'vendidero_notice_expire', $new_products );
		        include_once( 'screens/screen-notice-expire.php' );
	        } else {
	        	delete_option( 'vendidero_notice_expire' );
	        }
        }
    }

    public function set_data() {
        
        if ( ! function_exists( 'get_plugins' ) )
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        
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
        
        wp_clear_scheduled_hook( 'vendidero_cron' );
        wp_schedule_event( time(), 'daily', 'vendidero_cron' );
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
        if ( ! empty( $products ) && is_array( $products ) ) {
            foreach ( $products as $plugin ) {
                if ( is_object( $plugin ) && ! empty( $plugin->file ) && ! empty( $plugin->product_id ) )
                    $this->add_product( $plugin->file, $plugin->product_id );
            }
        }
        // Self update
        $this->add_product( 'vendidero-helper/vendidero-helper.php', 2198, true );
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
        if ( $file != '' && ! isset( $this->products[ $file ] ) ) {
            $is_theme = ( strpos( $file, 'style.css' ) ? true : false );

            // Check if is right file dir
            if ( $is_theme && ! isset( $this->themes[ $file ] ) )
                return false;
            elseif ( ! $is_theme && ! isset( $this->plugins[ $file ] ) )
                return false;

            $this->products[ $file ] = ( $is_theme ? new VD_Product_Theme( $file, $product_id, $free ) : new VD_Product( $file, $product_id, $free ) );
        }
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

    public function get_product( $key ) {
        return ( isset( $this->products[ $key ] ) ? $this->products[ $key ] : false );
    }

    public function get_api_url() {
        return $this->api_url;
    }

    public function get_token() {
        return $this->token;
    }

    /**
     * PHP 5.3 backwards compatibility for getting date diff
     *  
     * @param  string $from date from
     * @param  string $to   date to
     * @return array  array containing year, month, date diff
     */
    public function get_date_diff( $from, $to ) {
        $diff = abs( strtotime( $to ) - strtotime( $from ) );
        $years = floor( $diff / (365*60*60*24) );
        $months = floor( ( $diff - $years * 365*60*60*24 ) / ( 30*60*60*24 ) );
        $days = floor( ( $diff - $years * 365*60*60*24 - $months*30*60*60*24 ) / ( 60*60*24 ) );
        return array( 'y' => $years, 'm' => $months, 'd' => $days );
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