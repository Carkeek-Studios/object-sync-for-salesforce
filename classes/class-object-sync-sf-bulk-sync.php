<?php
/**
 * Bulk Sync AJAX handlers for Object Sync for Salesforce (Carkeek fork).
 *
 * Provides four AJAX endpoints:
 * - osf_bulk_pull:          Pull SF records into WP by SF ID list
 * - osf_resync_pull:        Alias of osf_bulk_pull (same handler)
 * - osf_resync_push:        Re-push already-mapped WP records to SF by WP Post ID
 * - osf_get_mapped_records: Paginated list of SF↔WP object map records
 *
 * Ported from the standalone carkeek-sf-bulk-sync plugin. The core pull/push
 * mechanism relies on manual_pull / manual_push already present in the OSF fork.
 *
 * @class   Object_Sync_Sf_Bulk_Sync
 * @package Object_Sync_Salesforce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Object_Sync_Sf_Bulk_Sync class.
 */
class Object_Sync_Sf_Bulk_Sync {

	/**
	 * Constructor — registers AJAX action hooks.
	 *
	 * osf_resync_pull is intentionally aliased to bulk_pull — they are
	 * functionally identical (both pull SF records by SF ID).
	 */
	public function __construct() {
		add_action( 'wp_ajax_osf_bulk_pull',          array( $this, 'bulk_pull' ) );
		add_action( 'wp_ajax_osf_resync_pull',         array( $this, 'bulk_pull' ) );
		add_action( 'wp_ajax_osf_resync_push',         array( $this, 'resync_push' ) );
		add_action( 'wp_ajax_osf_get_mapped_records',  array( $this, 'get_mapped_records' ) );
	}

	/**
	 * Verify nonce and capability. Dies on failure.
	 */
	private function verify_request(): void {
		check_ajax_referer( 'osf_bulk_sync_nonce', 'nonce' );
		if ( ! current_user_can( 'configure_salesforce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'object-sync-for-salesforce' ) ), 403 );
			wp_die();
		}
	}

	/**
	 * Parse a newline- or comma-separated list of IDs from a POST field.
	 *
	 * @param string $field POST field name.
	 * @return string[]
	 */
	private function parse_ids_from_post( string $field ): array {
		$raw = isset( $_POST[ $field ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $field ] ) ) : '';
		$ids = preg_split( '/[\r\n,]+/', $raw );
		$ids = array_map( 'trim', $ids );
		$ids = array_filter( $ids );
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Resolve the Salesforce object type for a given SF ID.
	 *
	 * Strategy:
	 * 1. If already in the object map, get the wordpress_object, then look up the
	 *    matching fieldmap to get the salesforce_object.
	 * 2. Otherwise, iterate all configured fieldmaps and return the first SF type
	 *    for which the Salesforce API confirms the record exists.
	 *
	 * @param string $sf_id Salesforce record ID.
	 * @param object $osf   Object Sync for Salesforce instance.
	 * @return array|false Array with 'sf_type' and 'wp_type' keys, or false if not found.
	 */
	public function resolve_sf_type( string $sf_id, $osf ) {
		if ( ! isset( $osf->mappings ) ) {
			return false;
		}

		// 1. Check existing object map — gives us wordpress_object.
		$maps = $osf->mappings->load_all_by_salesforce( $sf_id );
		if ( ! empty( $maps ) && is_array( $maps ) ) {
			$map     = $maps[0];
			$wp_type = isset( $map['wordpress_object'] ) ? $map['wordpress_object'] : '';

			if ( ! empty( $wp_type ) ) {
				$fieldmaps = $osf->mappings->get_fieldmaps( null, array( 'wordpress_object' => $wp_type ) );
				if ( ! empty( $fieldmaps ) && is_array( $fieldmaps ) ) {
					$sf_type = isset( $fieldmaps[0]['salesforce_object'] ) ? $fieldmaps[0]['salesforce_object'] : '';
					if ( ! empty( $sf_type ) ) {
						return array(
							'sf_type' => $sf_type,
							'wp_type' => $wp_type,
						);
					}
				}
			}
		}

		// 2. Try each configured fieldmap.
		$fieldmaps = $osf->mappings->get_fieldmaps();
		if ( empty( $fieldmaps ) || ! is_array( $fieldmaps ) ) {
			return false;
		}

		foreach ( $fieldmaps as $fieldmap ) {
			$sf_object_type = isset( $fieldmap['salesforce_object'] ) ? $fieldmap['salesforce_object'] : '';
			$wp_object_type = isset( $fieldmap['wordpress_object'] ) ? $fieldmap['wordpress_object'] : '';

			if ( empty( $sf_object_type ) || empty( $osf->salesforce['sfapi'] ) ) {
				continue;
			}

			try {
				$sf_object = $osf->salesforce['sfapi']->object_read(
					$sf_object_type,
					$sf_id,
					array( 'cache' => false )
				);

				if ( ! is_wp_error( $sf_object )
					&& isset( $sf_object['data'] )
					&& ! empty( $sf_object['data'] )
					&& empty( $sf_object['data']['errorCode'] ) ) {
					return array(
						'sf_type' => $sf_object_type,
						'wp_type' => $wp_object_type,
					);
				}
			} catch ( Exception $e ) {
				continue;
			}
		}

		return false;
	}

	/**
	 * Process a single SF ID pull and return a result array.
	 *
	 * Public so WP-CLI and other callers can use it without going through AJAX.
	 *
	 * @param string $sf_id Salesforce record ID.
	 * @return array{sf_id: string, sf_type: string, status: string, message: string}
	 */
	public function process_pull( string $sf_id ): array {
		$sf_id = sanitize_text_field( $sf_id );

		if ( empty( $sf_id ) ) {
			return array(
				'sf_id'   => $sf_id,
				'sf_type' => '',
				'status'  => 'error',
				'message' => __( 'Empty ID.', 'object-sync-for-salesforce' ),
			);
		}

		$osf = object_sync_for_salesforce();
		if ( ! $osf || ! isset( $osf->pull ) ) {
			return array(
				'sf_id'   => $sf_id,
				'sf_type' => '',
				'status'  => 'error',
				'message' => __( 'Object Sync for Salesforce is not ready.', 'object-sync-for-salesforce' ),
			);
		}

		$type_info = $this->resolve_sf_type( $sf_id, $osf );

		if ( false === $type_info || empty( $type_info['sf_type'] ) ) {
			return array(
				'sf_id'   => $sf_id,
				'sf_type' => '',
				'status'  => 'error',
				'message' => __( 'No matching fieldmap found for this Salesforce ID.', 'object-sync-for-salesforce' ),
			);
		}

		$result = $osf->pull->manual_pull( $type_info['sf_type'], $sf_id );

		if ( isset( $result['code'] ) && '201' === (string) $result['code'] ) {
			return array(
				'sf_id'   => $sf_id,
				'sf_type' => $type_info['sf_type'],
				'status'  => 'success',
				'message' => __( 'Record pulled successfully.', 'object-sync-for-salesforce' ),
			);
		}

		$error_msg = __( 'Pull failed.', 'object-sync-for-salesforce' );
		if ( isset( $result['data']['success'] ) && is_array( $result['data']['success'] ) ) {
			foreach ( $result['data']['success'] as $res ) {
				if ( isset( $res['message'] ) ) {
					$error_msg = esc_html( $res['message'] );
					break;
				}
			}
		}

		return array(
			'sf_id'   => $sf_id,
			'sf_type' => $type_info['sf_type'],
			'status'  => 'error',
			'message' => $error_msg,
		);
	}

	/**
	 * AJAX: Bulk pull SF records into WordPress by a list of Salesforce IDs.
	 * Also handles osf_resync_pull (aliased in constructor).
	 *
	 * POST params:
	 *   nonce  - osf_bulk_sync_nonce
	 *   sf_ids - newline/comma-separated Salesforce IDs
	 */
	public function bulk_pull(): void {
		$this->verify_request();

		$sf_ids = $this->parse_ids_from_post( 'sf_ids' );

		if ( empty( $sf_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No Salesforce IDs provided.', 'object-sync-for-salesforce' ) ) );
		}

		$results = array();
		foreach ( $sf_ids as $sf_id ) {
			$results[] = $this->process_pull( $sf_id );
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * AJAX: Re-push already-mapped WP records to Salesforce by WordPress Post ID.
	 *
	 * POST params:
	 *   nonce  - osf_bulk_sync_nonce
	 *   wp_ids - newline/comma-separated WordPress Post IDs (integers)
	 */
	public function resync_push(): void {
		$this->verify_request();

		$raw_ids = $this->parse_ids_from_post( 'wp_ids' );

		if ( empty( $raw_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No WordPress Post IDs provided.', 'object-sync-for-salesforce' ) ) );
		}

		$osf = object_sync_for_salesforce();
		if ( ! $osf || ! isset( $osf->push ) ) {
			wp_send_json_error( array( 'message' => __( 'Object Sync for Salesforce is not ready.', 'object-sync-for-salesforce' ) ) );
			wp_die();
		}

		$results = array();

		foreach ( $raw_ids as $raw_id ) {
			$wp_id = absint( $raw_id );

			if ( 0 === $wp_id ) {
				$results[] = array(
					'wp_id'   => $raw_id,
					'wp_type' => '',
					'status'  => 'error',
					'message' => __( 'Invalid WordPress Post ID.', 'object-sync-for-salesforce' ),
				);
				continue;
			}

			$wp_type = get_post_type( $wp_id );

			// Fall back to 'user' type if not a post.
			if ( false === $wp_type ) {
				$user = get_user_by( 'ID', $wp_id );
				if ( $user ) {
					$wp_type = 'user';
				}
			}

			if ( empty( $wp_type ) ) {
				$results[] = array(
					'wp_id'   => $wp_id,
					'wp_type' => '',
					'status'  => 'error',
					'message' => __( 'WordPress object not found for this ID.', 'object-sync-for-salesforce' ),
				);
				continue;
			}

			$result = $osf->push->manual_push( $wp_type, $wp_id, 'PUT' );

			if ( isset( $result['code'] ) && in_array( (string) $result['code'], array( '201', '204' ), true ) ) {
				$results[] = array(
					'wp_id'   => $wp_id,
					'wp_type' => $wp_type,
					'status'  => 'success',
					'message' => __( 'Record pushed to Salesforce.', 'object-sync-for-salesforce' ),
				);
			} else {
				$error_msg = __( 'Push failed.', 'object-sync-for-salesforce' );
				if ( isset( $result['result'] ) && is_array( $result['result'] ) ) {
					foreach ( $result['result'] as $res ) {
						if ( isset( $res['message'] ) ) {
							$error_msg = esc_html( $res['message'] );
							break;
						}
					}
				}
				$results[] = array(
					'wp_id'   => $wp_id,
					'wp_type' => $wp_type,
					'status'  => 'error',
					'message' => $error_msg,
				);
			}
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * AJAX: Return a paginated list of SF↔WP object map records for the browse table.
	 *
	 * POST params:
	 *   nonce    - osf_bulk_sync_nonce
	 *   page     - current page number (int, default 1)
	 *   per_page - records per page (int, default 25)
	 *   wp_type  - filter by wordpress_object (string, optional)
	 *   search   - search SF ID or WP ID (string, optional)
	 */
	public function get_mapped_records(): void {
		$this->verify_request();

		global $wpdb;

		// phpcs:disable WordPress.Security.NonceVerification
		$page     = isset( $_POST['page'] )     ? absint( $_POST['page'] )                                    : 1;
		$per_page = isset( $_POST['per_page'] )  ? absint( $_POST['per_page'] )                                : 25;
		$wp_type  = isset( $_POST['wp_type'] )   ? sanitize_text_field( wp_unslash( $_POST['wp_type'] ) )     : '';
		$search   = isset( $_POST['search'] )    ? sanitize_text_field( wp_unslash( $_POST['search'] ) )      : '';
		// phpcs:enable WordPress.Security.NonceVerification

		$page     = max( 1, $page );
		$per_page = ( $per_page < 1 || $per_page > 100 ) ? 25 : $per_page;

		$table  = $wpdb->prefix . 'object_sync_sf_object_map';
		$offset = ( $page - 1 ) * $per_page;

		$where  = '1=1';
		$params = array();

		if ( ! empty( $wp_type ) ) {
			$where   .= ' AND wordpress_object = %s';
			$params[] = $wp_type;
		}

		if ( ! empty( $search ) ) {
			$where   .= ' AND ( salesforce_id LIKE %s OR wordpress_id LIKE %s )';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! empty( $params ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$where}", $params ) );
		} else {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
		}

		$limit_params = array_merge( $params, array( $per_page, $offset ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, salesforce_id, wordpress_id, wordpress_object, object_updated, last_sync_status
				FROM `{$table}` WHERE {$where}
				ORDER BY object_updated DESC
				LIMIT %d OFFSET %d",
				$limit_params
			),
			ARRAY_A
		);

		$types = $wpdb->get_col( "SELECT DISTINCT wordpress_object FROM `{$table}` ORDER BY wordpress_object" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		wp_send_json_success(
			array(
				'rows'       => $rows,
				'total'      => $total,
				'page'       => $page,
				'per_page'   => $per_page,
				'totalPages' => $total > 0 ? (int) ceil( $total / $per_page ) : 0,
				'types'      => $types,
			)
		);
	}
}
