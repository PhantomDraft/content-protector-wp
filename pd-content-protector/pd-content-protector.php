<?php
/**
 * Plugin Name: PD Content Protector
 * Plugin URI: https://github.com/PhantomDraft/content-protector-wp
 * Description: A plugin for protecting content with custom authentication. It supports posts, pages, WooCommerce products, bbPress forums, categories, and tags.
 * Version:     1.2
 * Author:      PD
 * Author URI:  https://guides.phantom-draft.com/
 * License:     GPL2
 * Text Domain: pd-content-protector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PD_Content_Protector {
    private static $instance = null;
    private $option_name = 'pd_content_protector_options';

    /**
     * Singleton pattern: Get the single instance of this class.
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor: set up actions for admin menus, settings registration, and front-end content protection.
     */
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'template_redirect', [ $this, 'protect_content' ] );
    }

    /**
     * Register plugin settings and fields.
     */
    public function register_settings() {
        register_setting( $this->option_name, $this->option_name, [ $this, 'sanitize_settings' ] );

        add_settings_section( 'pd_protector_main', 'Protection Settings', null, $this->option_name );

        add_settings_field( 'protection_mode', 'Protection Mode', [ $this, 'field_protection_mode' ], $this->option_name, 'pd_protector_main' );
        add_settings_field( 'protected_items', 'IDs/Slugs/Taxonomies (passwords separated by `:`)', [ $this, 'field_protected_items' ], $this->option_name, 'pd_protector_main' );
        add_settings_field( 'global_username', 'Global Username', [ $this, 'field_global_username' ], $this->option_name, 'pd_protector_main' );
        add_settings_field( 'global_password', 'Global Password', [ $this, 'field_global_password' ], $this->option_name, 'pd_protector_main' );
    }

    /**
     * Sanitize settings before saving.
     */
    public function sanitize_settings( $input ) {
        return [
            'protection_mode'  => sanitize_text_field( $input['protection_mode'] ?? 'full' ),
            'protected_items'  => sanitize_text_field( $input['protected_items'] ?? '' ),
            'global_username'  => sanitize_text_field( $input['global_username'] ?? '' ),
            'global_password'  => ! empty( $input['global_password'] ) ? sanitize_text_field( $input['global_password'] ) : ( get_option( $this->option_name )['global_password'] ?? '' )
        ];
    }

    /**
     * Retrieve plugin options with default values.
     */
    private function get_options() {
        $options = get_option( $this->option_name, [] );

        return [
            'protection_mode'  => isset( $options['protection_mode'] ) ? $options['protection_mode'] : 'full',
            'protected_items'  => isset( $options['protected_items'] ) ? $options['protected_items'] : '',
            'global_username'  => isset( $options['global_username'] ) ? $options['global_username'] : '',
            'global_password'  => isset( $options['global_password'] ) ? $options['global_password'] : ''
        ];
    }

    /**
     * Display the Protection Mode field.
     */
    public function field_protection_mode() {
        $options = $this->get_options();
        ?>
        <select name="<?php echo esc_attr( $this->option_name ); ?>[protection_mode]">
            <option value="full" <?php selected( $options['protection_mode'], 'full' ); ?>>Entire Site</option>
            <option value="selected" <?php selected( $options['protection_mode'], 'selected' ); ?>>Specific IDs/Slugs</option>
        </select>
        <?php
    }

    /**
     * Display the field for entering protected items (IDs, slugs, or taxonomies with passwords).
     */
    public function field_protected_items() {
        $options = $this->get_options();
        ?>
        <textarea name="<?php echo esc_attr( $this->option_name ); ?>[protected_items]" rows="5"><?php echo esc_textarea( $options['protected_items'] ); ?></textarea>
        <p class="description">
            Enter IDs, slugs, or taxonomies with passwords separated by `:` (e.g., <code>12:pass123, about-us:secret</code>).
        </p>
        <?php
    }

    /**
     * Display the Global Username field.
     */
    public function field_global_username() {
        $options = $this->get_options();
        ?>
        <input type="text" name="<?php echo esc_attr( $this->option_name ); ?>[global_username]" value="<?php echo esc_attr( $options['global_username'] ); ?>" />
        <p class="description">Enter the global username.</p>
        <?php
    }

    /**
     * Display the Global Password field.
     */
    public function field_global_password() {
        ?>
        <input type="password" name="<?php echo esc_attr( $this->option_name ); ?>[global_password]" value="" placeholder="********" />
        <p class="description">Enter a new global password or leave empty to keep the current one.</p>
        <?php
    }

    /**
     * Add the plugin menu items in the admin area.
     */
    public function add_admin_menu() {
        // Register global PD menu if not already registered
        if ( ! defined( 'PD_GLOBAL_MENU_REGISTERED' ) ) {
            add_menu_page(
                'PD',                   // Title in admin area
                'PD',                   // Menu title
                'manage_options',       // Required capability
                'pd_main_menu',         // Global menu slug
                'pd_global_menu_callback', // Callback function for the global menu page
                'dashicons-shield',     // Shield icon
                2                       // Menu position
            );
            define( 'PD_GLOBAL_MENU_REGISTERED', true );
        }

        // Add plugin-specific submenu
        add_submenu_page(
            'pd_main_menu',
            'PD Content Protector',
            'PD Content Protector',
            'manage_options',
            'pd_content_protector',
            [ $this, 'options_page_html' ]
        );
    }

    /**
     * Template for the plugin settings page.
     */
    public function options_page_html() {
        ?>
        <div class="wrap">
            <h1>PD Content Protector Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( $this->option_name );
                do_settings_sections( $this->option_name );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Protect content using custom authentication.
     * Blocks access to posts, pages, WooCommerce products, bbPress forums, categories, and tags.
     */
    public function protect_content() {
        // Do not protect content in admin, AJAX, or REST API requests.
        if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return;
        }

        $options = $this->get_options();

        // Global Protection Mode: block entire site
        if ( isset( $options['protection_mode'] ) && $options['protection_mode'] === 'full' ) {
            $expected_password = $options['global_password'];
            if ( isset( $_POST['pd_password'] ) && $_POST['pd_password'] === $expected_password ) {
                setcookie( 'pd_protector_global', '1', time() + 3600, '/' );
                wp_redirect( $_SERVER['REQUEST_URI'] );
                exit;
            }
            if ( ! isset( $_COOKIE['pd_protector_global'] ) ) {
                wp_die( '<form method="POST"><p>Please enter the password to access the entire site:</p><input type="password" name="pd_password" required /><button type="submit">Login</button></form>' );
            }
            return;
        }

        // For "selected" mode, check protected items
        $protected_items = array_map( 'trim', explode( ',', $options['protected_items'] ) );
        $current_id = get_queried_object_id(); // Get the current post/page ID
        $current_slug = get_query_var( 'name' ); // Get the current post slug

        $is_protected = false;
        $expected_password = '';

        // Loop through each protected item rule
        foreach ( $protected_items as $item ) {
            $parts = explode( ':', trim( $item ), 2 );
            $id_or_slug = isset( $parts[0] ) ? trim( $parts[0] ) : '';
            $password = isset( $parts[1] ) ? trim( $parts[1] ) : '';

            if ( empty( $id_or_slug ) || empty( $password ) ) {
                continue;
            }

            // If current content matches the rule (by ID or slug), mark it as protected
            if ( $id_or_slug == $current_id || $id_or_slug == $current_slug ) {
                $is_protected = true;
                $expected_password = $password;
                break;
            }
        }

        if ( $is_protected ) {
            // Check if the correct password has been submitted
            if ( isset( $_POST['pd_password'] ) && $_POST['pd_password'] === $expected_password ) {
                // Set a cookie to grant access for 1 hour
                setcookie( 'pd_protector_access_' . $current_id, '1', time() + 3600, '/' );
                wp_redirect( $_SERVER['REQUEST_URI'] );
                exit;
            }
            // If access cookie is not set, show the password form
            if ( ! isset( $_COOKIE['pd_protector_access_' . $current_id] ) ) {
                wp_die( '<form method="POST"><p>Please enter the password to access this content:</p><input type="password" name="pd_password" required /><button type="submit">Login</button></form>' );
            }
        }
    }

    /**
     * Get the country code based on IP.
     */
    private function get_country_by_ip( $ip ) {
        $response = wp_remote_get( "https://ipinfo.io/{$ip}/json" );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return $data['country'] ?? 'UNKNOWN';
    }

    /**
     * Parse raw country-specific rules into an array.
     * Each rule is in the format: identifier|country
     */
    private function parse_country_specific_rules( $raw ) {
        $rules = [];
        $lines = preg_split( "/\r\n|\n|\r/", trim( $raw ) );
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( empty( $line ) ) {
                continue;
            }
            $parts = explode( '|', $line );
            $identifier = isset( $parts[0] ) ? sanitize_text_field( trim( $parts[0] ) ) : '';
            $country = isset( $parts[1] ) ? sanitize_text_field( trim( $parts[1] ) ) : '';
            if ( ! empty( $identifier ) && ! empty( $country ) ) {
                $rules[] = [
                    'identifier' => $identifier,
                    'country'    => strtoupper( $country ), // Convert to uppercase for consistency
                ];
            }
        }
        return $rules;
    }
}

if ( ! function_exists( 'pd_global_menu_callback' ) ) {
    function pd_global_menu_callback() {
        ?>
        <div class="wrap">
            <h1>PD Global Menu</h1>
            <p>Please visit our GitHub page:</p>
            <p><a href="https://github.com/PhantomDraft" target="_blank">https://github.com/PhantomDraft</a></p>
        </div>
        <?php
    }
}

PD_Content_Protector::get_instance();