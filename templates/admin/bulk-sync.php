<?php
/**
 * Admin template for the Bulk Sync tab (Carkeek fork).
 *
 * Provides three sections:
 * 1. Bulk Pull — paste SF IDs or upload CSV to pull records into WordPress.
 * 2. Quick Resync — re-pull by SF ID or re-push by WP Post ID.
 * 3. Browse Mapped Records — paginated table of SF↔WP object map with batch resync.
 *
 * @package Object_Sync_Salesforce
 */
?>
<div class="csfbs-wrap">

	<?php /* ── SECTION 1: Bulk Pull by SF ID ── */ ?>
	<div class="csfbs-section">
		<h2><?php esc_html_e( 'Bulk Pull from Salesforce (SF → WP)', 'object-sync-for-salesforce' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Paste Salesforce record IDs below (one per line) or upload a CSV file. The plugin will auto-detect the object type from your configured fieldmaps and pull each record into WordPress.', 'object-sync-for-salesforce' ); ?>
		</p>

		<div class="csfbs-input-row">
			<textarea
				id="csfbs-bulk-pull-ids"
				class="csfbs-id-textarea"
				placeholder="<?php esc_attr_e( 'e.g. 0035g000002ABCDEF&#10;a0T5g000003XYZABC', 'object-sync-for-salesforce' ); ?>"
				rows="6"
			></textarea>
		</div>

		<div class="csfbs-action-row">
			<label class="csfbs-file-label">
				<input type="file" id="csfbs-bulk-pull-file" accept=".csv,.txt" class="csfbs-file-input">
				<?php esc_html_e( 'Upload CSV', 'object-sync-for-salesforce' ); ?>
			</label>
			<button type="button" id="csfbs-bulk-pull-btn" class="button button-primary">
				<?php esc_html_e( 'Pull Records', 'object-sync-for-salesforce' ); ?> &rarr;
			</button>
			<span class="csfbs-spinner spinner"></span>
		</div>

		<div id="csfbs-bulk-pull-results" class="csfbs-results-wrap" style="display:none;">
			<h3><?php esc_html_e( 'Results', 'object-sync-for-salesforce' ); ?></h3>
			<table class="widefat csfbs-results-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Salesforce ID', 'object-sync-for-salesforce' ); ?></th>
						<th><?php esc_html_e( 'Object Type', 'object-sync-for-salesforce' ); ?></th>
						<th><?php esc_html_e( 'Status', 'object-sync-for-salesforce' ); ?></th>
						<th><?php esc_html_e( 'Message', 'object-sync-for-salesforce' ); ?></th>
					</tr>
				</thead>
				<tbody id="csfbs-bulk-pull-tbody"></tbody>
			</table>
		</div>
	</div>

	<?php /* ── SECTION 2: Quick Resync ── */ ?>
	<div class="csfbs-section">
		<h2><?php esc_html_e( 'Resync Existing Records — Quick', 'object-sync-for-salesforce' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Re-sync records that are already mapped between Salesforce and WordPress. Use Re-Pull to refresh WP from SF, or Re-Push to update SF from WP.', 'object-sync-for-salesforce' ); ?>
		</p>

		<div class="csfbs-two-col">
			<div class="csfbs-col">
				<h3><?php esc_html_e( 'Re-Pull (SF → WP)', 'object-sync-for-salesforce' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Paste Salesforce IDs (one per line).', 'object-sync-for-salesforce' ); ?></p>
				<textarea
					id="csfbs-resync-pull-ids"
					class="csfbs-id-textarea"
					placeholder="<?php esc_attr_e( 'Salesforce ID, one per line', 'object-sync-for-salesforce' ); ?>"
					rows="5"
				></textarea>
				<div class="csfbs-action-row">
					<button type="button" id="csfbs-resync-pull-btn" class="button button-secondary">
						<?php esc_html_e( 'Re-Pull', 'object-sync-for-salesforce' ); ?> &rarr;
					</button>
					<span class="csfbs-spinner spinner csfbs-spinner-repull"></span>
				</div>
				<div id="csfbs-resync-pull-results" class="csfbs-results-wrap" style="display:none;">
					<table class="widefat csfbs-results-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'SF ID', 'object-sync-for-salesforce' ); ?></th>
								<th><?php esc_html_e( 'Type', 'object-sync-for-salesforce' ); ?></th>
								<th><?php esc_html_e( 'Status', 'object-sync-for-salesforce' ); ?></th>
								<th><?php esc_html_e( 'Message', 'object-sync-for-salesforce' ); ?></th>
							</tr>
						</thead>
						<tbody id="csfbs-resync-pull-tbody"></tbody>
					</table>
				</div>
			</div>

			<div class="csfbs-col">
				<h3><?php esc_html_e( 'Re-Push (WP → SF)', 'object-sync-for-salesforce' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Paste WordPress Post IDs (one per line).', 'object-sync-for-salesforce' ); ?></p>
				<textarea
					id="csfbs-resync-push-ids"
					class="csfbs-id-textarea"
					placeholder="<?php esc_attr_e( 'WordPress Post ID, one per line', 'object-sync-for-salesforce' ); ?>"
					rows="5"
				></textarea>
				<div class="csfbs-action-row">
					<button type="button" id="csfbs-resync-push-btn" class="button button-secondary">
						<?php esc_html_e( 'Re-Push', 'object-sync-for-salesforce' ); ?> &rarr;
					</button>
					<span class="csfbs-spinner spinner csfbs-spinner-repush"></span>
				</div>
				<div id="csfbs-resync-push-results" class="csfbs-results-wrap" style="display:none;">
					<table class="widefat csfbs-results-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'WP ID', 'object-sync-for-salesforce' ); ?></th>
								<th><?php esc_html_e( 'Type', 'object-sync-for-salesforce' ); ?></th>
								<th><?php esc_html_e( 'Status', 'object-sync-for-salesforce' ); ?></th>
								<th><?php esc_html_e( 'Message', 'object-sync-for-salesforce' ); ?></th>
							</tr>
						</thead>
						<tbody id="csfbs-resync-push-tbody"></tbody>
					</table>
				</div>
			</div>
		</div>
	</div>

	<?php /* ── SECTION 3: Browse Mapped Records ── */ ?>
	<div class="csfbs-section">
		<h2><?php esc_html_e( 'Resync Existing Records — Browse', 'object-sync-for-salesforce' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Browse all mapped Salesforce ↔ WordPress records. Select rows and use the buttons below to re-pull or re-push.', 'object-sync-for-salesforce' ); ?>
		</p>

		<div class="csfbs-browse-controls">
			<label for="csfbs-browse-type-filter">
				<?php esc_html_e( 'Filter by object type:', 'object-sync-for-salesforce' ); ?>
			</label>
			<select id="csfbs-browse-type-filter">
				<option value=""><?php esc_html_e( 'All', 'object-sync-for-salesforce' ); ?></option>
			</select>
			&nbsp;
			<input
				type="text"
				id="csfbs-browse-search"
				placeholder="<?php esc_attr_e( 'Search SF ID or WP ID…', 'object-sync-for-salesforce' ); ?>"
				class="regular-text"
			>
			<button type="button" id="csfbs-browse-load-btn" class="button">
				<?php esc_html_e( 'Load Records', 'object-sync-for-salesforce' ); ?>
			</button>
			<span class="csfbs-spinner spinner csfbs-spinner-browse"></span>
		</div>

		<div id="csfbs-browse-results" class="csfbs-results-wrap">
			<table class="widefat csfbs-browse-table">
				<thead>
					<tr>
						<th class="csfbs-check-col">
							<input type="checkbox" id="csfbs-select-all" title="<?php esc_attr_e( 'Select all', 'object-sync-for-salesforce' ); ?>">
						</th>
						<th><?php esc_html_e( 'Salesforce ID', 'object-sync-for-salesforce' ); ?></th>
						<th><?php esc_html_e( 'WP ID', 'object-sync-for-salesforce' ); ?></th>
						<th><?php esc_html_e( 'WP Type', 'object-sync-for-salesforce' ); ?></th>
						<th><?php esc_html_e( 'Last Sync', 'object-sync-for-salesforce' ); ?></th>
						<th><?php esc_html_e( 'Status', 'object-sync-for-salesforce' ); ?></th>
					</tr>
				</thead>
				<tbody id="csfbs-browse-tbody">
					<tr>
						<td colspan="6" class="csfbs-empty-row">
							<?php esc_html_e( 'Click "Load Records" to browse mapped objects.', 'object-sync-for-salesforce' ); ?>
						</td>
					</tr>
				</tbody>
			</table>

			<div class="csfbs-browse-footer">
				<div class="csfbs-browse-actions">
					<button type="button" id="csfbs-browse-repull-btn" class="button button-secondary" disabled>
						<?php esc_html_e( 'Re-Pull Selected', 'object-sync-for-salesforce' ); ?>
					</button>
					<button type="button" id="csfbs-browse-repush-btn" class="button button-secondary" disabled>
						<?php esc_html_e( 'Re-Push Selected', 'object-sync-for-salesforce' ); ?>
					</button>
					<span class="csfbs-spinner spinner csfbs-spinner-browse-action"></span>
				</div>
				<div class="csfbs-browse-pagination">
					<button type="button" id="csfbs-browse-prev" class="button" disabled>&larr; <?php esc_html_e( 'Prev', 'object-sync-for-salesforce' ); ?></button>
					<span id="csfbs-browse-page-info"></span>
					<button type="button" id="csfbs-browse-next" class="button" disabled><?php esc_html_e( 'Next', 'object-sync-for-salesforce' ); ?> &rarr;</button>
				</div>
			</div>

			<div id="csfbs-browse-action-results" class="csfbs-results-wrap" style="display:none;">
				<h4><?php esc_html_e( 'Resync Results', 'object-sync-for-salesforce' ); ?></h4>
				<table class="widefat csfbs-results-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ID', 'object-sync-for-salesforce' ); ?></th>
							<th><?php esc_html_e( 'Type', 'object-sync-for-salesforce' ); ?></th>
							<th><?php esc_html_e( 'Status', 'object-sync-for-salesforce' ); ?></th>
							<th><?php esc_html_e( 'Message', 'object-sync-for-salesforce' ); ?></th>
						</tr>
					</thead>
					<tbody id="csfbs-browse-action-tbody"></tbody>
				</table>
			</div>
		</div>
	</div>

</div><!-- .csfbs-wrap -->
