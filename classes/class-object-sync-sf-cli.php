<?php
/**
 * WP-CLI commands for Object Sync for Salesforce (Carkeek fork).
 *
 * Usage:
 *   wp osf bulk-pull --ids=SF_ID1,SF_ID2 --object=GW_Volunteers__Volunteer_Shift__c
 *   wp osf bulk-pull --object=GW_Volunteers__Volunteer_Shift__c --all
 *
 * @class   Object_Sync_Sf_Cli
 * @package Object_Sync_Salesforce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage Salesforce sync operations from the command line.
 *
 * @when after_wp_load
 */
class Object_Sync_Sf_Cli extends WP_CLI_Command {

	/**
	 * Pull one or more Salesforce records into WordPress.
	 *
	 * Provide either `--ids` (explicit list) or `--all` (every record of the
	 * given `--object` type that matches a configured fieldmap). Both flags
	 * require `--object` unless the type can be inferred from the object map.
	 *
	 * ## OPTIONS
	 *
	 * [--ids=<ids>]
	 * : Comma-separated Salesforce record IDs to pull.
	 *
	 * [--object=<object>]
	 * : Salesforce object type (e.g. GW_Volunteers__Volunteer_Shift__c).
	 *   Required when using --all. Optional when using --ids (type is auto-detected).
	 *
	 * [--all]
	 * : Query Salesforce for all records of the given --object type and pull them.
	 *
	 * ## EXAMPLES
	 *
	 *   # Pull two specific shifts by SF ID.
	 *   wp osf bulk-pull --ids=a0T5g000003XYZABC,a0T5g000003XYZDEF
	 *
	 *   # Pull all volunteer jobs via SOQL query.
	 *   wp osf bulk-pull --object=GW_Volunteers__Volunteer_Job__c --all
	 *
	 * @subcommand bulk-pull
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments.
	 */
	public function bulk_pull( array $args, array $assoc_args ): void {
		$osf = object_sync_for_salesforce();

		if ( ! $osf || ! isset( $osf->pull ) ) {
			WP_CLI::error( 'Object Sync for Salesforce is not ready.' );
		}

		$bulk_sync = new Object_Sync_Sf_Bulk_Sync();

		$pull_all   = isset( $assoc_args['all'] );
		$sf_object  = isset( $assoc_args['object'] ) ? sanitize_text_field( $assoc_args['object'] ) : '';
		$ids_string = isset( $assoc_args['ids'] ) ? $assoc_args['ids'] : '';

		if ( ! $pull_all && empty( $ids_string ) ) {
			WP_CLI::error( 'Provide --ids=<ids> or --all (with --object=<type>).' );
		}

		if ( $pull_all ) {
			if ( empty( $sf_object ) ) {
				WP_CLI::error( '--object=<type> is required when using --all.' );
			}
			$ids = $this->query_all_sf_ids( $sf_object, $osf );
			if ( empty( $ids ) ) {
				WP_CLI::warning( "No records found for {$sf_object} in Salesforce." );
				return;
			}
			WP_CLI::line( sprintf( 'Found %d records for %s.', count( $ids ), $sf_object ) );
		} else {
			$ids = array_values( array_filter( array_map( 'trim', explode( ',', $ids_string ) ) ) );
			if ( empty( $ids ) ) {
				WP_CLI::error( 'No valid IDs found in --ids.' );
			}
		}

		$total    = count( $ids );
		$success  = 0;
		$errors   = 0;
		$progress = WP_CLI\Utils\make_progress_bar( "Pulling {$total} record(s)", $total );

		foreach ( $ids as $sf_id ) {
			$result = $bulk_sync->process_pull( $sf_id );

			if ( 'success' === $result['status'] ) {
				$success++;
				WP_CLI::debug( "OK  {$sf_id} ({$result['sf_type']})" );
			} else {
				$errors++;
				WP_CLI::debug( "ERR {$sf_id}: {$result['message']}" );
			}

			$progress->tick();
		}

		$progress->finish();

		$summary = sprintf(
			'%d record(s) pulled. %d error(s).',
			$success,
			$errors
		);

		if ( $errors > 0 ) {
			WP_CLI::warning( $summary . ' Run with --debug for details.' );
		} else {
			WP_CLI::success( $summary );
		}
	}

	/**
	 * Query Salesforce for all record IDs of the given object type.
	 *
	 * Uses the Salesforce API class already available on the OSF instance.
	 *
	 * @param string $sf_object Salesforce API object name.
	 * @param object $osf       Object Sync for Salesforce instance.
	 * @return string[] Array of Salesforce record IDs.
	 */
	private function query_all_sf_ids( string $sf_object, $osf ): array {
		if ( empty( $osf->salesforce['sfapi'] ) || true !== $osf->salesforce['is_authorized'] ) {
			WP_CLI::error( 'Salesforce is not authorized. Run the OAuth flow in the OSF admin first.' );
		}

		$ids    = array();
		$query  = "SELECT Id FROM {$sf_object}";
		$result = $osf->salesforce['sfapi']->query( $query );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( 'Salesforce query failed: ' . $result->get_error_message() );
		}

		if ( isset( $result['data']['records'] ) && is_array( $result['data']['records'] ) ) {
			foreach ( $result['data']['records'] as $record ) {
				if ( isset( $record['Id'] ) ) {
					$ids[] = $record['Id'];
				}
			}
		}

		// Handle Salesforce query-more pagination.
		while ( isset( $result['data']['nextRecordsUrl'] ) && ! empty( $result['data']['nextRecordsUrl'] ) ) {
			$next_url = $result['data']['nextRecordsUrl'];
			$result   = $osf->salesforce['sfapi']->query_more( $next_url );

			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( 'query_more failed: ' . $result->get_error_message() );
				break;
			}

			if ( isset( $result['data']['records'] ) && is_array( $result['data']['records'] ) ) {
				foreach ( $result['data']['records'] as $record ) {
					if ( isset( $record['Id'] ) ) {
						$ids[] = $record['Id'];
					}
				}
			}
		}

		return $ids;
	}
}
