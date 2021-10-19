<?php
/*
Plugin Name: WPboke Lock Posts
Plugin URI: https://wpzhanqun.com/plugins/lock-posts
Description: This plugin allows network admin to lock down posts(support any CPT) on any site so that regular users just can't edit them.
Author: WPboke
Version: 1.0.0
Text Domain: wpboke-lock-posts
Author URI: https://wpboke.com/
*/

/*
Copyright 2012-2021 WenPai (http://wenpai.org)
Developer: WenPai

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License (Version 2 - GPLv2) as published by
the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

class Lock_Posts {

	var $post_types;
	var $lock_capabilities;

	/**
	 * PHP5 constructor
	 *
	 */
	function __construct() {

		$this->lock_capabilities = array(
			'edit_post',
			'delete_post',
		);

		add_action( 'add_meta_boxes', array( &$this, 'meta_box' ) );
		add_action( 'admin_menu', array( &$this, 'admin_page' ) );
		add_action( 'save_post', array( &$this, 'update' ) );
		add_action( 'init', array( &$this, 'check' ) );
		add_filter( 'user_has_cap', array($this, 'kill_edit_cap'), 10, 3);
		add_action( 'init', array( &$this, 'add_columns' ), 99 );
		add_action( 'plugins_loaded', array( &$this, 'lock_posts_localization' ) );
	}

	function lock_posts_localization() {
		// Load up the localization file if we're using WordPress in a different language
		// Place it in this plugin's "languages" folder and name it "wpboke-lock-posts-[value in wp-config].mo"
		load_plugin_textdomain( 'wpboke-lock-posts', false, 'wpboke-lock-posts/languages' );
	}

	/**
	 * Properly filtering out forbidden capabilities for non-super admin users.
	 */
	function kill_edit_cap ($all, $caps, $args) {
		global $post;
		if (!is_object($post) || !isset($post->post_type)) return $all; // Only proceed for pages with known post types
		if (!in_array($post->post_type, $this->lock_capabilities)) return $all; // Only proceed for allowed types

		if (!$args) return $all; // Something is wrong here.
		if (count($args) < 3) return $all; // Only proceed for individual items.
		if (!isset($args[0])) return $all; // Something is still wrong here.

		//if ('edit_post' != $args[0]) return $all; // So, not the one we're looking for.
		if (!in_array($args[0], $this->lock_capabilities)) return $all; // Not one of the forbidden capabilities
		$post_id = isset($args[2]) ? $args[2] : false;
		if (!$post_id) return $all; // Can't obtain post ID

		$post_lock_status = ('locked' == get_post_meta($post_id, '_post_lock_status', true));

		return $post_lock_status ? (is_super_admin() ? $all : false) : $all;
	}

	/**
	 * Properly manage columns
	 *
	 */
	function add_columns() {
		$this->post_types = get_post_types(array('show_ui' => true, 'public' => true));
		unset($this->post_types['attachment']);

		foreach ($this->post_types as $post_type) {
			add_filter( 'manage_edit-'.$post_type.'_columns', array( &$this, 'status_column' ), 999 );
			add_action( 'manage_'.$post_type.'_posts_custom_column', array( &$this, 'status_output' ), 99, 2 );
		}
	}

	/**
	 * Add column to posts management panel
	 *
	 */
	function status_column( $columns ) {
		$columns['lock_status'] = __( 'Lock Status', 'wpboke-lock-posts' );
		return $columns;
	}

	/**
	 * Display columns content on posts management panel
	 *
	 */
	function status_output( $column, $id ) {
		if( $column == 'lock_status' ) {
			$post_lock_status = get_post_meta( $id, '_post_lock_status' );

			if( is_array( $post_lock_status ) && isset( $post_lock_status[0] ) )
				$post_lock_status = $post_lock_status[0];

			if( 'locked' == $post_lock_status )
				echo __( 'Locked', 'wpboke-lock-posts' );
			else
				echo __( 'Unlocked', 'wpboke-lock-posts' );
		}
	}

	/**
	 * Add metabox to posts edition panel
	 *
	 */
	function meta_box() {
		if (!is_super_admin()) return;

		foreach ($this->post_types as $key => $post_type) {
			add_meta_box( 'postlock', __( 'Post Status', 'wpboke-lock-posts' ), array( &$this, 'meta_box_output' ), $post_type, 'advanced', 'high' );
		}
	}

	/**
	 * Post status metabox
	 *
	 */
	function meta_box_output( $post ) {
		if ( !is_super_admin() )
			return;

		$post_lock_status = get_post_meta( $post->ID, '_post_lock_status' );
		if( is_array( $post_lock_status ) && isset( $post_lock_status[0] ) )
			$post_lock_status = $post_lock_status[0];

		if( empty( $post_lock_status ) )
			$post_lock_status = 'unlocked';
		?>
		<div id="postlockstatus">
			<label class="hidden" for="excerpt">Post Status</label>
			<select name="post_lock_status">
				<option value="locked" <?php selected( $post_lock_status, 'locked' ) ?>><?php _e( 'Locked', 'wpboke-lock-posts' ) ?></option>
				<option value="unlocked" <?php selected( $post_lock_status, 'unlocked' ) ?>><?php _e( 'Unlocked', 'wpboke-lock-posts' ) ?></option>
			</select>
			<p><?php _e( 'Locked posts cannot be edited by anyone other than Super admins.', 'wpboke-lock-posts' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Update post status
	 *
	 */
	function update( $post_id ) {
		if ( !empty( $_POST['post_lock_status'] ) && is_super_admin() )
			update_post_meta( $post_id, '_post_lock_status', $_POST['post_lock_status'] );
	}

	/**
	 * Check post status and redirect if the user is not super admin and post is locked
	 *
	 */
	function check() {
		if ( !is_super_admin() && !empty( $_GET['action'] ) && 'edit' == $_GET['action'] && !empty( $_GET['post'] ) ) {
			$post_lock_status = get_post_meta( $_GET['post'], '_post_lock_status' );

			if ( is_array($post_lock_status) )
				$post_lock_status = $post_lock_status[0];

			if ( $post_lock_status == 'locked' )
				wp_redirect( admin_url( 'edit.php?page=post-locked&post=' . $_GET['post'] ) );
		}
	}

	/**
	 * Displayed 'locked' message
	 *
	 */
	function locked() {
		$post = get_post( $_GET['post'] );
		echo '<div class="wrap">';
		echo '<h2>' . __( 'Post Locked', 'wpboke-lock-posts' ) . '</h2>';
		echo '<p>' . sprintf( __( 'The post "%s" has been locked by a Super admin and you aren\'t able to edit it.', 'wpboke-lock-posts' ), $post->post_title ) . '</p>';
		echo '<p><a href="' . admin_url( 'edit.php?post_type=' . $post->post_type ) . '">&laquo; ' . __( 'Back to Posts List', 'wpboke-lock-posts' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Add admin page
	 *
	 */
	function admin_page() {
		global $submenu;

		add_submenu_page( 'edit.php', 'Post Locked', 'Post Locked', 'edit_posts', 'post-locked', array( &$this, 'locked' ) );

		if (isset($submenu['edit.php']) && is_array($submenu['edit.php'])) foreach( $submenu['edit.php'] as $key => $menu_item ) {
			if( isset( $menu_item[2] ) && $menu_item[2] == 'post-locked' )
				unset( $submenu['edit.php'][$key] );
		}
	}

}

$lock_posts = new Lock_Posts();
