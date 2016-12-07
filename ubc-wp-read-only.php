<?php

/**
 *
 * @wordpress-plugin
 * Plugin Name:	   UBC WP Read Only
 * Plugin URI:		http://ctlt.ubc.ca/
 * Description:	   Puts a site into read-only mode to make migrating servers easier
 * Version:		   0.1.0
 * Author:			Richard Tape
 * Author URI:		http://blogs.ubc.ca/richardtape
 * License:		   GPL-2.0+
 * License URI:	   http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:	   ubc-wp-read-only
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

		// Hijack adding an option. Not pretty.
		add_action( 'add_option', array( __CLASS__, 'add_option__hijack_my_name_is_not_jack' ), 1, 2 );

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

		// For bbPress, no forms for you
		add_filter( 'bbp_current_user_can_access_create_topic_form', '__return_false' );
		add_filter( 'bbp_current_user_can_access_create_reply_form', '__return_false' );

		// Ensure no options can be updated
		add_filter( 'pre_update_option', array( __CLASS__, 'pre_update_option__disable_options_updates' ), 999, 3 );

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

		wp_die( __( 'This site is currently in read-only mode. Updates are currently prohibited.', 'ubc-wp-read-only' ) );

	}/* add_option__hijack_my_name_is_not_jack() */


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
