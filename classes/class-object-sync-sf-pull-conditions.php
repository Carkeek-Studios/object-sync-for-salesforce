<?php
/**
 * Conditional pull filtering for Object Sync for Salesforce (Carkeek fork).
 *
 * Reads pull_conditions from each fieldmap and evaluates them against the
 * incoming Salesforce record before OSF decides whether to create or update
 * the corresponding WordPress object. If any condition fails the pull is
 * skipped (existing WP posts are NOT deleted).
 *
 * @class   Object_Sync_Sf_Pull_Conditions
 * @package Object_Sync_Salesforce
 */

defined( 'ABSPATH' ) || exit;

class Object_Sync_Sf_Pull_Conditions {

	/**
	 * Supported comparison operators mapped to human-readable labels.
	 *
	 * @var array<string, string>
	 */
	public static $operators = array(
		'eq'       => 'equals',
		'neq'      => 'not equals',
		'gt'       => 'greater than',
		'lt'       => 'less than',
		'gte'      => 'greater than or equal',
		'lte'      => 'less than or equal',
		'empty'    => 'is empty',
		'notempty' => 'is not empty',
	);

	/**
	 * Constructor. Registers the pull_object_allowed filter.
	 */
	public function __construct() {
		// Priority 20 — runs after the built-in user-object filter at 10.
		add_filter( 'object_sync_for_salesforce_pull_object_allowed', array( $this, 'check_conditions' ), 20, 5 );
	}

	/**
	 * Filter: decide whether to allow pulling a Salesforce record into WordPress.
	 *
	 * @param bool   $pull_allowed     Current allow decision.
	 * @param string $object_type      Salesforce object type (e.g. GW_Volunteers__Volunteer_Job__c).
	 * @param array  $object           Full Salesforce field-value map for the record.
	 * @param int    $sf_sync_trigger  Bitmask for the sync trigger event.
	 * @param array  $salesforce_mapping The fieldmap row from OSF.
	 * @return bool Updated allow decision.
	 */
	public function check_conditions( $pull_allowed, $object_type, $object, $sf_sync_trigger, $salesforce_mapping ) {
		// Respect an upstream veto.
		if ( ! $pull_allowed ) {
			return $pull_allowed;
		}

		$pull_conditions = isset( $salesforce_mapping['pull_conditions'] ) ? $salesforce_mapping['pull_conditions'] : array();
		if ( empty( $pull_conditions['enabled'] ) || empty( $pull_conditions['conditions'] ) ) {
			return $pull_allowed;
		}

		foreach ( $pull_conditions['conditions'] as $condition ) {
			if ( ! $this->evaluate( $condition, $object ) ) {
				return false;
			}
		}

		return $pull_allowed;
	}

	/**
	 * Evaluate a single condition against the Salesforce object's field values.
	 *
	 * @param array $condition {
	 *     @type string $sf_field SF field API name.
	 *     @type string $operator Operator key (eq, neq, gt, lt, gte, lte, empty, notempty).
	 *     @type string $value    Comparison value; may include {today} / {now} tokens.
	 * }
	 * @param array $object Salesforce field-value map.
	 * @return bool Whether the condition passes.
	 */
	private function evaluate( array $condition, array $object ): bool {
		$sf_field = isset( $condition['sf_field'] ) ? $condition['sf_field'] : '';
		$operator = isset( $condition['operator'] ) ? $condition['operator'] : '';
		$value    = isset( $condition['value'] ) ? $this->resolve_tokens( $condition['value'] ) : '';

		if ( empty( $sf_field ) || empty( $operator ) ) {
			return true; // incomplete condition — treat as passing.
		}

		$field_value = isset( $object[ $sf_field ] ) ? (string) $object[ $sf_field ] : '';

		switch ( $operator ) {
			case 'eq':
				return $this->loose_equal( $field_value, $value );
			case 'neq':
				return ! $this->loose_equal( $field_value, $value );
			case 'gt':
				return $field_value > $value;
			case 'lt':
				return $field_value < $value;
			case 'gte':
				return $field_value >= $value;
			case 'lte':
				return $field_value <= $value;
			case 'empty':
				return '' === $field_value || null === ( $object[ $sf_field ] ?? null );
			case 'notempty':
				return '' !== $field_value && null !== ( $object[ $sf_field ] ?? null );
			default:
				return true;
		}
	}

	/**
	 * Case-insensitive equality that also normalises Salesforce boolean values.
	 *
	 * Salesforce checkbox fields return the string "true" or "false". This method
	 * treats true/1 as equivalent and false/0 as equivalent so that a condition
	 * value of "1" matches a Salesforce boolean field that returns "true".
	 *
	 * @param string $a Field value from Salesforce.
	 * @param string $b Configured condition value.
	 * @return bool
	 */
	private function loose_equal( string $a, string $b ): bool {
		if ( 0 === strcasecmp( $a, $b ) ) {
			return true;
		}
		// Normalise boolean equivalents.
		static $bool_map = array( 'true' => '1', '1' => '1', 'false' => '0', '0' => '0' );
		$a_key = strtolower( $a );
		$b_key = strtolower( $b );
		if ( isset( $bool_map[ $a_key ], $bool_map[ $b_key ] ) ) {
			return $bool_map[ $a_key ] === $bool_map[ $b_key ];
		}
		return false;
	}

	/**
	 * Replace dynamic tokens in a condition value.
	 *
	 * {today} → Y-m-d (UTC)
	 * {now}   → Y-m-d H:i:s (UTC)
	 *
	 * @param string $value Raw condition value.
	 * @return string Value with tokens replaced.
	 */
	private function resolve_tokens( string $value ): string {
		$now   = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$value = str_replace( '{today}', $now->format( 'Y-m-d' ), $value );
		$value = str_replace( '{now}', $now->format( 'Y-m-d H:i:s' ), $value );
		return $value;
	}
}
