<?php
/*
 * Plugin Name: Post Meta Revisions
 * Plugin URI: https://github.com/adamsilverstein/wp-post-meta-revisions
 * Description: Post Meta Revisions
 * Version: 1.0.0
 * Author: Adam Silverstein - code developed with others
 * at https://core.trac.wordpress.org/ticket/20564
 * License: GPLv2 or later
*/

class WP_Post_Meta_Revisioning {

	/**
	 * Set up the plugin actions
	 */
	public function __construct() {

		// Actions
		//
		// When restoring a revision, also restore that revisions's revisioned meta.
		add_action( 'wp_restore_post_revision', array( $this, '_wp_restore_post_revision_meta' ), 10, 2 );

		// When creating or updating an autosave, save any revisioned meta fields.
		add_action( 'wp_creating_autosave', array( $this, '_wp_autosave_post_revisioned_meta_fields' ) );
		add_action( 'wp_before_creating_autosave', array( $this, '_wp_autosave_post_revisioned_meta_fields' ) );

		// When creating a revision, also save any revisioned meta.
		add_action( '_wp_put_post_revision', array( $this, '_wp_save_revisioned_meta_fields' ) );

		//Filters
		// When revisioned post meta has changed, trigger a revision save.
		add_filter( 'wp_save_post_revision_post_has_changed', array( $this, '_wp_check_revisioned_meta_fields_have_changed' ), 10, 3 );

		// Add the revisioned meta to the JS data for the revisions interface.
		add_filter( 'wp_prepare_revision_for_js', array( $this, '_wp_add_meta_to_prepare_revision_for_js' ), 10, 3 );

		// Filter the diff ui returned for the revisions screen.
		add_filter( 'wp_get_revision_ui_diff', array( $this, '_wp_filter_revision_ui_diff' ), 10, 3 );

		// Add the revisioned meta to get_post_metadata for preview meta data.
		add_filter( 'get_post_metadata', array( $this, '_wp_preview_meta_filter' ), 10, 4 );
	}

	/**
	 * Filter the revisions ui diff, adding revisioned meta fields.
	 *
	 * @param array   fields        Revision UI fields. Each item is an array of id, name and diff.
	 * @param WP_Post $compare_from The revision post to compare from.
	 * @param WP_Post $compare_to   The revision post to compare to.
	 */
	function _wp_filter_revision_ui_diff( $fields, $compare_from, $compare_to ) {

		// Do we have revisioned meta fields?
		$revisioned_meta_keys = $this->_wp_post_revision_meta_keys();
		if ( ! empty( $revisioned_meta_keys ) ) {

			// Only add the header once, if we have a non-empty meta field.
			$meta_header_added = false;

			// Check each meta comparison for non empty diffs.
			foreach ( $revisioned_meta_keys as $meta_key ) {
				$meta_from = get_post_meta( $compare_from->ID, $meta_key, true );
				$meta_to   = get_post_meta( $compare_to->ID,   $meta_key, true );
				$args      = array( 'show_split_view' => true );
				$args      = apply_filters( 'revision_text_diff_options', $args, end( $fields ), $compare_from, $compare_to );
				$diff      = wp_text_diff( !empty($meta_from) ? $meta_from[0] : '', !empty($meta_to) ? $meta_to[0] : '', $args );

				// Add this meta field if it has a diff.
				if ( ! empty( $diff ) ) {

					$new_field = array(
						'id'   => $meta_key,
						'name' => $meta_key,
						'diff' => $diff
					);

					/**
					 * Filter revisioned meta fields used for the revisions UI.
					 *
					 * The dynamic portion of the hook name, `$meta_key`, refers to
					 * the revisioned meta key.
					 *
					 * @since 4.6.0
					 *
					 * @param object $new_field     Object with id, name and diff for the UI.
					 * @param WP_Post $compare_from The revision post to compare from.
					 * @param WP_Post $compare_to   The revision post to compare to.
					 */
					$new_field = apply_filters( 'revisioned_meta_ui_field_{$meta_key}', $new_field, $compare_from, $compare_to );

					$fields[ sizeof( $fields ) ] = $new_field;
				}
			}
		}

		return $fields;
	}

	/**
	 * Add the revisioned meta fields to the revisions interface.
	 *
	 * Include filters to enable customization of the meta display.
	 */
	function _wp_add_meta_to_prepare_revision_for_js( $revisions_data, $revision, $post ) {

		$revisions_data['revisionedMeta'] = array();

		// Go thru revisioned meta fields, adding them to the display data.
		foreach ( $this->_wp_post_revision_meta_keys() as $meta_key ) {
			$revisions_data['revisionedMeta'][] = array(
					$meta_key => get_post_meta( $revisions_data['id'], $meta_key, true ),
				);
		}
		return $revisions_data;
	}

	/**
	 * Autosave the revisioned meta fields.
	 *
	 * Iterates thru the revisioned meta fields and checks each to see if they are set,
	 * and have a changed value. If so, the meta value is saved and attached to the autosave.
	 *
	 * @since 4.5.0
	 *
	 * @param Post object $new_autosave The new post being autosaved.
	 */
	public function _wp_autosave_post_revisioned_meta_fields( $new_autosave ) {

		/**
		 * The post data arrives as either $_POST['data']['wp_autosave'] or the $_POST
		 * itself. This sets $posted_data to the correct variable.
		 */
		$posted_data = isset( $_POST['data'] ) ? $_POST['data']['wp_autosave'] : $_POST; // WPCS: CSRF ok. input var ok. sanitization ok.

		/**
		 * Go thru the revisioned meta keys and save them as part of the autosave, if
		 * the meta key is part of the posted data, the meta value is not blank and
		 * the the meta value has changes from the last autosaved value.
		 */
		foreach ( $this->_wp_post_revision_meta_keys() as $meta_key ) {

			if (
				isset( $posted_data[ $meta_key ] ) &&
				get_post_meta( $new_autosave['ID'], $meta_key, true ) !== wp_unslash( $posted_data[ $meta_key ] )
			) {

				/*
				 * Use the underlying delete_metadata() and add_metadata() functions
				 * vs delete_post_meta() and add_post_meta() to make sure we're working
				 * with the actual revision meta.
				 */
				delete_metadata( 'post', $new_autosave['ID'], $meta_key );

				/**
				 * One last check to ensure meta value not empty().
				 */
				if ( ! empty( $posted_data[ $meta_key ] ) ) {

					/**
					 * Add the revisions meta data to the autosave.
					 */
					add_metadata( 'post', $new_autosave['ID'], $meta_key, $posted_data[ $meta_key ] );
				}
			}
		}
	}

	/**
	 * Determine which post meta fields should be revisioned.
	 *
	 * @access public
	 * @since 4.5.0
	 *
	 * @return array An array of meta keys to be revisioned.
	 */
	public function _wp_post_revision_meta_keys() {
		/**
		 * Filter the list of post meta keys to be revisioned.
		 *
		 * @since 4.5.0
		 *
		 * @param array $keys An array of default meta fields to be revisioned.
		 */
		return apply_filters( 'wp_post_revision_meta_keys', array() );
	}

	/**
	 * Determine which revisioned post meta fields are single.
	 *
	 * @access public
	 * @since 4.5.0
	 *
	 * @return array An array of meta keys that are single.
	 */
	public function _wp_post_revision_single_meta_keys() {
		/**
		 * Filter the list of post meta keys that are single.
		 *
		 * @since 4.5.0
		 *
		 * @param array $keys An array of meta fields that are single.
		 */
		return apply_filters( 'wp_post_revision_single_meta_keys', array() );
	}

	/**
	 * Check whether revisioned post meta fields have changed.
	 *
	 * @since 4.5.0
	 */
	public function _wp_check_revisioned_meta_fields_have_changed( $post_has_changed, WP_Post $last_revision, WP_Post $post ) {

		$single_keys = $this->_wp_post_revision_single_meta_keys();

		foreach ( $this->_wp_post_revision_meta_keys() as $meta_key ) {
			if ( get_post_meta( $post->ID, $meta_key, in_array( $meta_key, $single_keys ) ) != get_post_meta( $last_revision->ID, $meta_key, in_array( $meta_key, $single_keys ) ) ) {
				$post_has_changed = true;
				break;
			}
		}
		return $post_has_changed;
	}

	/**
	 * Save the revisioned meta fields.
	 *
	 * @since 4.5.0
	 */
	public function _wp_save_revisioned_meta_fields( $revision_id ) {
		$revision = get_post( $revision_id );
		$post_id  = $revision->post_parent;
		$single_keys = $this->_wp_post_revision_single_meta_keys();
		// Save revisioned meta fields.
		foreach ( $this->_wp_post_revision_meta_keys() as $meta_key ) {
			$meta_values = get_post_meta( $post_id, $meta_key, in_array( $meta_key, $single_keys ) );

			foreach( $meta_values as $meta_value ) {
				/*
				 * Use the underlying add_metadata() function vs add_post_meta()
				 * to ensure metadata is added to the revision post and not its parent.
				 */
				add_metadata( 'post', $revision_id, $meta_key, $meta_value );
			}
		}
	}

	/**
	 * Restore the revisioned meta values for a post.
	 *
	 * @since 4.5.0
	 */
	public function _wp_restore_post_revision_meta( $post_id, $revision_id ) {
		// Restore revisioned meta fields.
		$metas_revisioned = $this->_wp_post_revision_meta_keys();
		if ( isset( $metas_revisioned ) && 0 !== sizeof( $metas_revisioned ) ) {
			foreach ( $metas_revisioned as $meta_key ) {
				// Clear any existing metas
				delete_post_meta( $post_id, $meta_key );
				// Get the stored meta, not stored === blank
				$meta_values = get_post_meta( $revision_id, $meta_key );
				if ( 0 !== sizeof( $meta_values ) && is_array( $meta_values ) ) {
					foreach ( $meta_values as $meta_value ) {
						add_post_meta( $post_id, $meta_key, $meta_value );
					}
				}
			}
		}
	}

	/**
	 * Filters post meta retrieval to get values from the actual autosave post,
	 * and not its parent.
	 *
	 * Filters revisioned meta keys only.
	 *
	 * @access public
	 * @since 4.5.0
	 *
	 * @param mixed  $value     Meta value to filter.
	 * @param int    $object_id Object ID.
	 * @param string $meta_key  Meta key to filter a value for.
	 * @param bool   $single    Whether to return a single value. Default false.
	 * @return mixed Original meta value if the meta key isn't revisioned, the object doesn't exist,
	 *               the post type is a revision or the post ID doesn't match the object ID.
	 *               Otherwise, the revisioned meta value is returned for the preview.
	 */
	public function _wp_preview_meta_filter( $value, $object_id, $meta_key, $single ) {

		// Only filter previews.
		if ( ! is_preview() ) {
			return $value;
		}

		$post = get_post();
		if (
			empty( $post ) ||
			$post->ID !== $object_id ||
			! in_array( $meta_key, $this->_wp_post_revision_meta_keys(), true ) ||
			'revision' === $post->post_type
		) {
			return $value;
		}

		// Grab the autosave.
		$preview = wp_get_post_autosave( $post->ID );
		if ( ! is_object( $preview ) ) {
			return $value;
		}

		// Return the autosave revisioned meta.
		return get_post_meta( $preview->ID, $meta_key, $single );
	}
}

$wp_post_meta_revisioning = new WP_Post_Meta_Revisioning;
