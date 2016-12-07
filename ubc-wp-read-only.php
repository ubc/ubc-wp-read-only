<?php

/**
 *
 * @wordpress-plugin
 * Plugin Name:	   UBC WP Read Only
 * Plugin URI:		http://ctlt.ubc.ca/
 * Description:	   Puts a site into read-only mode to make migrating servers easier. Be signed in as a Super Admin before activating.
 * Version:		   0.1.0
 * Author:			Richard Tape
 * Author URI:		http://blogs.ubc.ca/richardtape
 * License:		   GPL-2.0+
 * License URI:	   http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:	   ubc-wp-read-only
*/

/*
	Previous art, with thanks:
	force-reauthentication
	code-freeze
	wp-schedules-read-only
*/

namespace UBC;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Return if this is loaded via WP CLI for now
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	return;
}

// If we're during the install process, we don't do anything
if ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) {
	return;
}

class WP_Read_Only {

	/**
	 * The path to this file
	 *
	 * @since 0.1.0
	 * @access public
	 * @var string $plugin_path
	 */

	public static $plugin_path = '';

	/**
	 * The url to this file
	 *
	 * @since 0.1.0
	 *
	 * @access public
	 * @var (string) $plugin_url
	 */

	public static $plugin_url = '';

	/**
	 * Called directly and super early within muplugins_loaded
	 * Set up our actions and filters and set properties
	 *
	 * @since 1.0.0
	 *
	 * @param null
	 * @return null
	 */

	public function init() {

		// Set the plugin path as where this file resides
		self::$plugin_path = trailingslashit( dirname( __FILE__ ) );

		// And the URL
		self::$plugin_url = trailingslashit( plugins_url( '', __FILE__ ) );

		// Add our hooks
		self::add_hooks();

	}/* init() */


	/**
	 * Add our actions and filters
	 *
	 * @since 1.0.0
	 *
	 * @param null
	 * @return null
	 */

	public static function add_hooks() {

		self::add_actions();
		self::add_filters();

	}/* add_hooks() */

	/**
	 * Add our actions
	 *
	 * @since 1.0.0
	 *
	 * @param null
	 * @return null
	 */

	public static function add_actions() {

		// Force logout for everyone that isn't a super admin
		add_action( 'init', array( __CLASS__, 'init__log_out_non_superadmins' ) );
		add_action( 'admin_init', array( __CLASS__, 'init__log_out_non_superadmins' ) );

		// Disable cron if it's not already done
		add_action( 'plugins_loaded', array( __CLASS__, 'plugins_loaded__disable_cron' ), 1 );

		// Disable update checks, should be covered by disabling cron, but better safe than sorry
		add_action( 'site_transient_update_plugins', '__return_false' );
		add_action( 'site_transient_update_themes', '__return_false' );

		// Hijack adding an option. Not pretty.
		add_action( 'add_option', array( __CLASS__, 'add_option__hijack_my_name_is_not_jack' ), 1, 2 );

		// Prevent heartbeat.
		add_action( 'init', array( __CLASS__, 'init__disable_heartbeat' ), 1 );

		// Kill the wp-login.php file
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'login_enqueue_scripts__kill_login_php' ), 1 );

		// Kill the wp-signup.php file
		add_action( 'before_signup_header', array( __CLASS__, 'before_signup_header__kill_signup' ), 1 );

		// Kill password resets, lost pw etc.
		add_action( 'lost_password', array( __CLASS__, 'generic__kill_switch' ) );
		add_action( 'retrieve_password', array( __CLASS__, 'generic__kill_switch' ) );
		add_action( 'password_reset', array( __CLASS__, 'generic__kill_switch' ) );

	}/* add_actions() */

	/**
	 * Add our filters
	 *
	 * @since 1.0.0
	 *
	 * @param null
	 * @return null
	 */

	public static function add_filters() {

		// Close comments, pingbacks
		add_filter( 'the_posts', array( __CLASS__, 'the_posts__disable' ) );
		add_filter( 'comments_open', '__return_false', 999, 2 );
		add_filter( 'pings_open', '__return_false', 999, 2 );

		// Change message from 'comments are closed' to be more useful, only useful when no comments.php file in theme
		add_filter( 'comment_form_defaults', array( __CLASS__, 'comment_form_defaults__change_comments_closed_message' ), 999, 1 );

		// For bbPress, no forms for you
		add_filter( 'bbp_current_user_can_access_create_topic_form', '__return_false' );
		add_filter( 'bbp_current_user_can_access_create_reply_form', '__return_false' );

		// Ensure no options can be updated
		add_filter( 'pre_update_option', array( __CLASS__, 'pre_update_option__disable_options_updates' ), 999, 3 );

		// Disable AJAX, pre WP 4.7
		add_action( 'admin_init', array( __CLASS__, 'admin_init__disable_ajax' ) );

		// 4.7+ Only. Disable AJAX. Other AJAX killing methods can be removed when 4.7+ is min. req. for this plugin.
		add_filter( 'wp_doing_ajax', array( __CLASS__, 'wp_doing_ajax__disable_ajax' ) );

	}/* add_filters() */


	/**
	 * Set comment status to be closed.
	 * 'the_posts' filter is applied to the list of posts queried from the database
	 * after minimal processing for permissions and draft status on single-post pages
	 *
	 * @since 1.0.0
	 *
	 * @param (array) $posts - an array of posts passed to a query/loop
	 * @return (array) Posts with the comments status forced to be closed
	 */

	public static function the_posts__disable( $posts ) {

		if ( empty( $posts ) || ! is_array( $posts ) ) {
			return $posts;
		}

		// Loop over each post and disable comments and status
		foreach ( $posts as $key => $post_object ) {
			$posts[ $key ]->comment_status = 'closed';
			$posts[ $key ]->post_status = 'closed';
		}

		return $posts;

	}/* the_posts__disable() */


	/**
	 * WP, by default, has a 'comments are closed' message. We should be a little more discriptive
	 * here to say they're temporarily closed.
	 *
	 * @since 1.0.0
	 *
	 * @param (array) The default comment form arguments.
	 * @return null
	 */

	public static function comment_form_defaults__change_comments_closed_message( $defaults ) {

		$defaults['title'] = __( 'Comments are temporarily closed because this site is in read-only mode.', 'ubc-wp-read-only' );

		return $defaults;

	}/* comment_form_defaults__change_comments_closed_message() */


	/**
	 * Log out all non-super admins.
	 *
	 * @since 1.0.0
	 *
	 * @param null
	 * @return null
	 */

	public static function init__log_out_non_superadmins() {

		// Not logged in? Can't log you out then.
		if ( ! is_user_logged_in() ) {
			return;
		}

		// Super admin? You're a special flower.
		if ( is_super_admin() ) {
			return;
		}

		// Off you go
		wp_logout();

	}/* init__log_out_non_superadmins() */


	/**
	 * Before an option is updated, the pre_update_option filter is run.
	 * And just after this filter is applied, update_option checks to see
	 * if the old value of the option is equal to the new value. If they
	 * equate, the the function bails. So, let's make them equate.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $value     The new, unserialized option value.
	 * @param string $option    Name of the option.
	 * @param mixed  $old_value The old option value.
	 * @return The value of the $old_value to always short circuit this update
	 */

	public static function pre_update_option__disable_options_updates( $value, $option, $old_value ) {

		return $old_value;

	}/* pre_update_option__disable_options_updates() */


	/**
	 * Disable cron because of db updates.
	 *
	 * @since 1.0.0
	 *
	 * @param null
	 * @return null
	 */

	public static function plugins_loaded__disable_cron() {

		if ( ! defined( 'DISABLE_WP_CRON' ) ) {
			define( 'DISABLE_WP_CRON', true );
		}

	}/* plugins_loaded__disable_cron() */


	/**
	 * Disable WP heartbeat
	 *
	 * @since 1.0.0
	 *
	 * @param null
	 * @return null
	 */

	public static function init__disable_heartbeat() {

		wp_deregister_script( 'heartbeat' );

	}/* init__disable_heartbeat() */


	/**
	 * I shouldn't be allowed to write code. I am sorry.
	 *
	 * If add_option() is called, we need to make sure no db writes get attempted.
	 * This is tricky. There's a default_option_$option filter but it only gets
	 * run if wp_cache_get() gives us nadda and we don't want to ~hello~hijack that.
	 * So, the only thing we can really do is hook into add_option() which is called
	 * before any DB queries are made and run away.
	 *
	 * I also shouldn't be allowed to name methods. I am not sorry.
	 *
	 * @since 1.0.0
	 *
	 * @param string $option Name of the option to add.
	 * @param mixed  $value  Value of the option.
	 * @return null (apart from tears)
	 */

	public static function add_option__hijack_my_name_is_not_jack( $option, $value ) {

		wp_die( esc_html__( 'This site is currently in read-only mode. Updates are currently prohibited.', 'ubc-wp-read-only' ) );

	}/* add_option__hijack_my_name_is_not_jack() */


	/**
	 * Kill the wp-login.php early in the process, preventing direct access to the file
	 * or redirects to it.
	 *
	 * @since 1.0.0
	 *
	 * @param null
	 * @return null
	 */

	public static function login_enqueue_scripts__kill_login_php() {

		?>
			</head>
		</html>

		<?php

		wp_die( esc_html__( 'This site is currently in read-only mode. Log in is currently prohibited.' ) );

	}/* login_enqueue_scripts__kill_login_php() */


	/**
	 * Kill direct access to wp-signup.php
	 *
	 * @since 1.0.0
	 *
	 * @param null
	 * @return null
	 */

	public static function before_signup_header__kill_signup() {

		wp_die( esc_html__( 'This site is currently in read-only mode. Sign up is currently prohibited.' ) );

	}/* before_signup_header__kill_signup() */


	/**
	 * A generic method to show a wp_die() message.
	 *
	 * @since 1.0.0
	 *
	 * @param null
	 * @return null
	 */

	public static function generic__kill_switch() {

		wp_die( esc_html__( 'This site is currently in read-only mode.' ) );

	}/* generic__kill_switch() */


	/**
	 * Hook in as early as we can, determine if we are DOING_AJAX, and if so, kill it
	 * ~with fire~.
	 *
	 * @since 1.0.0
	 *
	 * @param null
	 * @return null
	 */

	public static function admin_init__disable_ajax() {

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			die( esc_html__( 'This site is currently in read-only mode.' ) );
		}

	}/* admin_init__disable_ajax() */


	/**
	 * Kill switch for when an AJAX request is being made.
	 * Requires WordPress 4.7.0+
	 *
	 * @since 1.0.0
	 *
	 * @param (bool) $doing_ajax - Whether we're currently performing an AJAX request
	 * @return null
	 */

	public static function wp_doing_ajax__disable_ajax( $doing_ajax ) {

		die( esc_html__( 'This site is currently in read-only mode.' ) );

	}/* wp_doing_ajax__disable_ajax() */


	/**
	 * Quick getter for the plugin path
	 * Usage: \UBC\WP_Read_Only::get_plugin_path()
	 *
	 * @since 1.0.0
	 *
	 * @param null
	 * @return (string) The path of this plugin
	 */

	public static function get_plugin_path() {

		return static::$plugin_path;

	}/* get_plugin_path() */


	/**
	 * Quick getter for the plugin URL
	 * Usage: \UBC\WP_Read_Only::get_plugin_url()
	 *
	 * @since 1.0.0
	 *
	 * @param null
	 * @return (string) The url to this plugin root
	 */

	public static function get_plugin_url() {

		return static::$plugin_url;

	}/* get_plugin_url() */

}/* class WP_Read_Only */

// Fire it up, priority 11 because some sites use a mu-plugin loader
add_action( 'muplugins_loaded', array( '\UBC\WP_Read_Only', 'init' ), 11 );
