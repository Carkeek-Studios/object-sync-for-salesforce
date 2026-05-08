<?php
/**
 * Chained relational syncs for Object Sync for Salesforce (Carkeek fork).
 *
 * After a Salesforce record is pulled into WordPress, this class reads the
 * fieldmap's relational_rules and automatically pulls any related parent
 * objects that are missing from WP. It then updates ACF relationship fields
 * so the WP post graph matches the Salesforce object graph.
 *
 * @class   Object_Sync_Sf_Relational_Sync
 * @package Object_Sync_Salesforce
 */

defined( 'ABSPATH' ) || exit;

class Object_Sync_Sf_Relational_Sync {

	/**
	 * SF IDs currently being processed — prevents re-entrant processing.
	 *
	 * @var array<string, bool>
	 */
	private static $syncing_ids = array();

	/**
	 * Constructor. Registers the pull_success action hook.
	 */
	public function __construct() {
		add_action( 'object_sync_for_salesforce_pull_success', array( $this, 'handle_pull_success' ), 10, 3 );
	}

	/**
	 * Fires after OSF successfully pulls a Salesforce record into WordPress.
	 *
	 * @param string $op           The operation: 'create', 'update', or 'upsert'.
	 * @param array  $result       The WordPress create/update result array.
	 * @param array  $synced_object {
	 *     Combined context for this sync operation.
	 *     @type array $salesforce_object Full Salesforce field-value map for the record.
	 *     @type array $mapping_object   The OSF object map row (SF ID → WP ID).
	 *     @type array $mapping          The OSF fieldmap row (includes relational_rules).
	 * }
	 */
	public function handle_pull_success( $op, $result, $synced_object ) {
		$mapping    = isset( $synced_object['mapping'] ) ? $synced_object['mapping'] : array();
		$sf_object  = isset( $synced_object['salesforce_object'] ) ? $synced_object['salesforce_object'] : array();
		$map_object = isset( $synced_object['mapping_object'] ) ? $synced_object['mapping_object'] : array();

		$relational_rules = isset( $mapping['relational_rules'] ) ? $mapping['relational_rules'] : array();
		if ( empty( $relational_rules['enabled'] ) || empty( $relational_rules['rules'] ) ) {
			return;
		}

		$sf_id = isset( $sf_object['Id'] ) ? $sf_object['Id'] : '';
		if ( empty( $sf_id ) ) {
			return;
		}

		if ( isset( self::$syncing_ids[ $sf_id ] ) ) {
			return;
		}

		$wp_id = isset( $map_object['wordpress_id'] ) ? (int) $map_object['wordpress_id'] : 0;
		if ( ! $wp_id ) {
			return;
		}

		// Register this ID before recursing so re-entrant pull_success for the
		// same record (e.g. from a rules-driven upsert) is a no-op.
		self::$syncing_ids[ $sf_id ] = true;

		foreach ( $relational_rules['rules'] as $rule ) {
			if ( ! isset( $rule['sf_field'], $rule['target_object'], $rule['acf_field'] ) ) {
				continue;
			}
			$this->process_rule( $rule, $sf_object, $wp_id );
		}

		// Allow programmatic rules registered via filter.
		$extra_rules = apply_filters(
			'object_sync_for_salesforce_relational_rules',
			array(),
			$mapping['salesforce_object'] ?? '',
			$sf_object
		);
		foreach ( $extra_rules as $rule ) {
			if ( ! isset( $rule['source_sf_object'], $rule['relationship_field'], $rule['target_sf_object'], $rule['target_wp_field'] ) ) {
				continue;
			}
			if ( ( $mapping['salesforce_object'] ?? '' ) !== $rule['source_sf_object'] ) {
				continue;
			}
			$this->process_rule(
				array(
					'sf_field'      => $rule['relationship_field'],
					'target_object' => $rule['target_sf_object'],
					'acf_field'     => $rule['target_wp_field'],
				),
				$sf_object,
				$wp_id
			);
		}

		unset( self::$syncing_ids[ $sf_id ] );
	}

	/**
	 * Process one relational rule: ensure the related object exists in WP,
	 * then wire the ACF relationship field.
	 *
	 * @param array $rule      {
	 *     @type string $sf_field      SF field on the current object that holds the related SF ID.
	 *     @type string $target_object SF object type of the related record.
	 *     @type string $acf_field     ACF field key or name on the current WP post.
	 * }
	 * @param array $sf_object Full Salesforce field-value map for the current record.
	 * @param int   $wp_id    WP post ID of the current record.
	 */
	private function process_rule( array $rule, array $sf_object, int $wp_id ): void {
		$related_sf_id  = isset( $sf_object[ $rule['sf_field'] ] ) ? (string) $sf_object[ $rule['sf_field'] ] : '';
		$target_object  = $rule['target_object'];
		$acf_field      = $rule['acf_field'];

		if ( empty( $related_sf_id ) || empty( $target_object ) || empty( $acf_field ) ) {
			return;
		}

		// Guard against pulling a record that is already mid-sync.
		if ( isset( self::$syncing_ids[ $related_sf_id ] ) ) {
			return;
		}

		$osf  = object_sync_for_salesforce();
		$maps = $osf->mappings->load_object_maps_by_salesforce_id( $related_sf_id );

		if ( ! empty( $maps ) ) {
			$related_wp_id = (int) $maps[0]['wordpress_id'];
		} else {
			// Related object not yet in WP — pull it now.
			self::$syncing_ids[ $related_sf_id ] = true;
			$pull_result = $osf->pull->manual_pull( $target_object, $related_sf_id );
			unset( self::$syncing_ids[ $related_sf_id ] );

			if ( '201' !== (string) $pull_result['code'] ) {
				return;
			}

			// Force a fresh DB lookup (bypass the object map cache).
			$maps = $osf->mappings->load_object_maps_by_salesforce_id( $related_sf_id, array(), true );
			if ( empty( $maps ) ) {
				return;
			}
			$related_wp_id = (int) $maps[0]['wordpress_id'];
		}

		if ( $related_wp_id && function_exists( 'update_field' ) ) {
			update_field( $acf_field, array( $related_wp_id ), $wp_id );
		}
	}
}
