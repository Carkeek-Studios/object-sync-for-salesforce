<?php
/**
 * The form to add and edit fieldmaps, which map a WordPress and Salesforce object type together.
 *
 * @package Object_Sync_Salesforce
 */

// Defaults for Carkeek-fork sections when adding a new fieldmap (no $map row yet).
if ( ! isset( $relational_rules ) ) {
	$relational_rules = array( 'enabled' => 0, 'rules' => array() );
}
if ( ! isset( $pull_conditions ) ) {
	$pull_conditions = array( 'enabled' => 0, 'conditions' => array() );
}
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="<?php echo esc_html( $fieldmap_class ); ?>">
	<input type="hidden" name="redirect_url_error" value="<?php echo esc_url( $error_url ); ?>" />
	<input type="hidden" name="redirect_url_success" value="<?php echo esc_url( $success_url ); ?>" />
	<?php if ( isset( $transient ) ) { ?>
	<input type="hidden" name="transient" value="<?php echo esc_html( $transient ); ?>" />
	<?php } ?>
	<input type="hidden" name="action" value="post_fieldmap" >
	<input type="hidden" name="method" value="<?php echo esc_attr( $method ); ?>" />
	<?php if ( 'edit' === $method ) { ?>
	<input type="hidden" name="id" value="<?php echo absint( $map['id'] ); ?>" />
	<?php } ?>
	<div class="fieldmap_label">
		<label for="label"><?php echo esc_html__( 'Label', 'object-sync-for-salesforce' ); ?>: </label>
		<input type="text" id="label" name="label" required value="<?php echo isset( $label ) ? esc_html( $label ) : ''; ?>" />
	</div>
	<fieldset class="wordpress_side">
		<div class="wordpress_object">
			<label for="wordpress_object"><?php echo esc_html__( 'WordPress Object', 'object-sync-for-salesforce' ); ?>: </label>
			<div class="spinner spinner-wordpress"></div>
			<select id="wordpress_object" name="wordpress_object" required>
				<option value="">- <?php echo esc_html__( 'Select Object Type', 'object-sync-for-salesforce' ); ?> -</option>
				<?php
				$wordpress_objects = $this->wordpress->wordpress_objects;
				foreach ( $wordpress_objects as $object ) {
					if ( isset( $wordpress_object ) && $wordpress_object === $object ) {
						$selected = ' selected';
					} else {
						$selected = '';
					}
					echo sprintf(
						'<option value="%1$s"%2$s>%3$s</option>',
						esc_html( $object ),
						esc_attr( $selected ),
						esc_html( $object )
					);
				}
				?>
			</select>
		</div>
	</fieldset>
	<fieldset class="salesforce_side">
		<div class="salesforce_object">
			<label for="salesforce_object"><?php echo esc_html__( 'Salesforce Object', 'object-sync-for-salesforce' ); ?>: </label>
			<div class="spinner spinner-salesforce"></div>
			<select id="salesforce_object" name="salesforce_object" required>
				<option value="">- <?php echo esc_html__( 'Select Object Type', 'object-sync-for-salesforce' ); ?> -</option>
				<?php
				$sfapi          = $this->salesforce['sfapi'];
				$object_filters = maybe_unserialize( get_option( 'salesforce_api_object_filters' ), array() );
				$conditions     = array();
				if ( is_array( $object_filters ) && in_array( 'updateable', $object_filters, true ) ) {
					$conditions['updateable'] = true;
				}
				if ( is_array( $object_filters ) && in_array( 'triggerable', $object_filters, true ) ) {
					$conditions['triggerable'] = true;
				}
				$salesforce_objects = $sfapi->objects( $conditions );
				// allow for api name or field label to be the display value in the <select>.
				$display_value = get_option( $this->option_prefix . 'salesforce_field_display_value', 'field_label' );
				foreach ( $salesforce_objects as $object ) {

					if ( 'api_name' === $display_value ) {
						$object['label'] = $object['name'];
					}

					if ( isset( $salesforce_object ) && $salesforce_object === $object['name'] ) {
						$selected = ' selected';
					} else {
						$selected = '';
					}
					echo sprintf(
						'<option value="%1$s"%2$s>%3$s</option>',
						esc_html( $object['name'] ),
						esc_attr( $selected ),
						esc_html( $object['label'] )
					);
				}
				?>
			</select>
			<?php if ( empty( $salesforce_objects ) ) : ?>
				<p class="description">
					<?php
					echo sprintf(
						// translators: 1) is the link to troubleshooting object maps in the plugin documentation.
						'<strong>' . esc_html__( 'The plugin is unable to access any Salesforce objects for object mapping.', 'object-sync-for-salesforce' ) . '</strong>' . esc_html__( ' This is most likely a permissions issue. See %1$s in the plugin documentation for more information and possible solutions.', 'object-sync-for-salesforce' ) . '</strong>',
						'<a href="https://github.com/MinnPost/object-sync-for-salesforce/blob/271-object-map-permission-issues/docs/troubleshooting.md#object-map-issues">troubleshooting object maps</a>'
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<div class="salesforce_record_types_allowed">
			<?php
			if ( isset( $salesforce_record_types_allowed ) ) :
				$record_types = $this->get_salesforce_object_description(
					array(
						'salesforce_object' => $salesforce_object,
						'include'           => 'recordTypeInfos',
					)
				);
				if ( isset( $record_types['recordTypeInfos'] ) ) :
					?>
					<label for="salesforce_record_types_allowed"><?php echo esc_html__( 'Allowed Record Types', 'object-sync-for-salesforce' ); ?>:</label>
					<div class="checkboxes">
					<?php foreach ( $record_types['recordTypeInfos'] as $key => $value ) : ?>
						<?php
						if ( in_array( $key, $salesforce_record_types_allowed, true ) ) {
							$checked = ' checked';
						} else {
							$checked = '';
						}
						echo sprintf(
							'<label><input type="checkbox" class="form-checkbox" value="%1$s" name="%2$s" id="%3$s"%4$s>%5$s</label>',
							esc_html( $key ),
							esc_attr( 'salesforce_record_types_allowed[' . $key . ']' ),
							esc_attr( 'salesforce_record_types_allowed-' . $key ),
							esc_html( $checked ),
							esc_html( $value )
						);
						?>
					<?php endforeach; ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<div class="salesforce_record_type_default">
			<?php
			if ( isset( $salesforce_record_type_default ) ) :
				$record_types = $this->get_salesforce_object_description(
					array(
						'salesforce_object' => $salesforce_object,
						'include'           => 'recordTypeInfos',
					)
				);
				if ( isset( $record_types['recordTypeInfos'] ) ) :
					?>
					<label for="salesforce_record_type_default"><?php echo esc_html__( 'Default Record Type', 'object-sync-for-salesforce' ); ?>:</label>
					<select id="salesforce_record_type_default" name="salesforce_record_type_default" required><option value="">- <?php echo esc_html__( 'Select record type', 'object-sync-for-salesforce' ); ?> -</option>
					<?php
					foreach ( $record_types['recordTypeInfos'] as $key => $value ) :
						if ( isset( $salesforce_record_type_default ) && $salesforce_record_type_default === $key ) {
							$selected = ' selected';
						} else {
							$selected = '';
						}
						if ( ! isset( $salesforce_record_types_allowed ) || in_array( $key, $salesforce_record_types_allowed, true ) ) {
							echo sprintf(
								'<option value="%1$s"%2$s>%3$s</option>',
								esc_attr( $key ),
								esc_attr( $selected ),
								esc_html( $value )
							);
						}
					endforeach;
					?>
					</select>
					<?php
				endif;
			endif;
			?>
		</div>
		<div class="pull_trigger_field">
			<?php if ( isset( $pull_trigger_field ) ) : ?>
				<label for="pull_trigger_field"><?php echo esc_html__( 'Date Field to Trigger Pull', 'object-sync-for-salesforce' ); ?>:</label>
				<?php
				$object_fields = $this->get_salesforce_object_fields(
					array(
						'salesforce_object' => $salesforce_object,
						'type'              => 'datetime',
					)
				);
				?>
				<select name="pull_trigger_field" id="pull_trigger_field">
				<?php
				$display_value = get_option( $this->option_prefix . 'salesforce_field_display_value', 'field_label' );
				foreach ( $object_fields as $key => $value ) {
					if ( 'api_name' === $display_value ) {
						$value['label'] = $value['name'];
					}
					if ( $pull_trigger_field === $value['name'] ) {
						$selected = ' selected';
					} else {
						$selected = '';
					}
					echo sprintf(
						'<option value="%1$s"%2$s>%3$s</option>',
						esc_attr( $value['name'] ),
						esc_attr( $selected ),
						esc_html( $value['label'] )
					);
				}
				?>
				</select>
				<p class="description"><?php echo esc_html__( 'When the plugin checks for data to bring from Salesforce into WordPress, it will use the selected field to determine what relevant changes have occurred in Salesforce.', 'object-sync-for-salesforce' ); ?></p>
			<?php endif; ?>
		</div>
	</fieldset>
	<fieldset class="fields">
		<legend><?php echo esc_html__( 'Fieldmap', 'object-sync-for-salesforce' ); ?></legend>
		<table class="wp-list-table widefat striped fields">
			<thead>
				<tr>
					<th class="column-wordpress_field"><?php echo esc_html__( 'WordPress Field', 'object-sync-for-salesforce' ); ?></th>
					<th class="column-salesforce_field"><?php echo esc_html__( 'Salesforce Field', 'object-sync-for-salesforce' ); ?></th>
					<th class="column-is_prematch"><?php echo esc_html__( 'Prematch', 'object-sync-for-salesforce' ); ?></th>
					<th class="column-is_key"><?php echo esc_html__( 'Salesforce Key', 'object-sync-for-salesforce' ); ?></th>
					<th class="column-direction"><?php echo esc_html__( 'Direction', 'object-sync-for-salesforce' ); ?></th>
					<th class="column-is_delete"><?php echo esc_html__( 'Delete', 'object-sync-for-salesforce' ); ?></th>
				</tr>
			</thead>
			<tfoot>
				<tr>
					<td colspan="6">
						<p><small>
							<?php
							// translators: the placeholders refer to: 1) the cache clear link, 2) the cache clear link text.
							echo sprintf(
								'<strong>' .
								esc_html__( 'Note:', 'object-sync-for-salesforce' ) . '</strong>' . esc_html__( ' to map a custom meta field (such as wp_postmeta, wp_usermeta, wp_termmeta, etc.), WordPress must have at least one value for that field. If you add a new meta field and want to map it, make sure to add a value for it and ', 'object-sync-for-salesforce' ) . '<a href="%1$s" id="clear-sfwp-cache">%2$s</a>' . esc_html__( ' to see the field listed here.', 'object-sync-for-salesforce' ),
								esc_url( get_admin_url( null, 'options-general.php?page=object-sync-salesforce-admin&tab=clear_cache' ) ),
								esc_html__( 'clear the plugin cache', 'object-sync-for-salesforce' )
							);
							?>
						</small></p>
						<p><small>
							<?php
							echo sprintf( '<strong>' . esc_html__( 'Note:', 'object-sync-for-salesforce' ) . '</strong>' . esc_html__( ' when mapping Salesforce fields, a * in the field name designates a required field for this object type.', 'object-sync-for-salesforce' ) );
							?>
						</small></p>
						<p><small>
							<?php
							echo sprintf( '<strong>' . esc_html__( 'Note:', 'object-sync-for-salesforce' ) . '</strong>' . esc_html__( ' when mapping WordPress fields, a 🔒 in the field name designates a field that the plugin is not able to edit. By default, this is only autogenerated ID fields, though developers can modify which fields are editable with additional code. A WordPress field that cannot be edited means you can push data from that field but you cannot pull data to it. When mapping Salesforce fields, a 🔒 in the field name designates a locked Salesforce field. This means you can pull data from that field but you cannot push data to it.', 'object-sync-for-salesforce' ) );
							?>
						</small></p>
					</td>
				</tr>
			</tfoot>
			<tbody>
				<?php
				if ( isset( $fieldmap_fields ) && null !== $fieldmap_fields && is_array( $fieldmap_fields ) ) {
					foreach ( $fieldmap_fields as $key => $value ) {
						$key = md5( $key . time() );
						?>
				<tr data-key="<?php echo esc_attr( $key ); ?>" class="already-saved-fields">
					<td class="column-wordpress_field">
						<select name="wordpress_field[<?php echo esc_attr( $key ); ?>]" id="wordpress_field-<?php echo esc_attr( $key ); ?>">
							<option value="">- <?php echo esc_html__( 'Select WordPress Field', 'object-sync-for-salesforce' ); ?> -</option>
							<?php
							$wordpress_fields = $this->get_wordpress_object_fields( $wordpress_object );
							foreach ( $wordpress_fields as $wordpress_field ) {
								if ( isset( $value['wordpress_field']['label'] ) && $value['wordpress_field']['label'] === $wordpress_field['key'] ) {
									$selected = ' selected';
								} else {
									$selected = '';
								}
								if ( isset( $wordpress_field['editable'] ) && false === $wordpress_field['editable'] ) {
									$locked = ' 🔒';
								} else {
									$locked = '';
								}
								echo sprintf(
									'<option value="%1$s"%2$s>%3$s%4$s</option>',
									esc_attr( $wordpress_field['key'] ),
									esc_attr( $selected ),
									esc_html( $wordpress_field['key'] ),
									esc_attr( $locked )
								);
							}
							?>
						</select>

					</td>
					<td class="column-salesforce_field">
						<select name="salesforce_field[<?php echo esc_attr( $key ); ?>]" id="salesforce_field-<?php echo esc_attr( $key ); ?>">
							<option value="">- <?php echo esc_html__( 'Select Salesforce Field', 'object-sync-for-salesforce' ); ?> -</option>
							<?php
							$salesforce_fields = $this->get_salesforce_object_fields(
								array(
									'salesforce_object' => $salesforce_object,
								)
							);
							// allow for api name or field label to be the display value in the <select>.
							$display_value = get_option( $this->option_prefix . 'salesforce_field_display_value', 'field_label' );
							foreach ( $salesforce_fields as $salesforce_field ) {
								if ( isset( $value['salesforce_field']['name'] ) && $value['salesforce_field']['name'] === $salesforce_field['name'] ) {
									$selected = ' selected';
								} elseif ( isset( $value['salesforce_field']['label'] ) && $value['salesforce_field']['label'] === $salesforce_field['name'] ) {
									// this conditional is for versions up to 1.1.2, but i think it's fine to leave it for now. if we remove it, people's fieldmaps will not show correctly in the admin.
									$selected = ' selected';
								} else {
									$selected = '';
								}

								if ( 'api_name' === $display_value ) {
									$salesforce_field['label'] = $salesforce_field['name'];
								}

								if ( false === $salesforce_field['nillable'] ) {
									$salesforce_field['label'] .= ' *';
								}

								if ( false === $salesforce_field['updateable'] ) {
									$locked = ' 🔒';
								} else {
									$locked = '';
								}

								echo sprintf(
									'<option value="%1$s"%2$s>%3$s%4$s</option>',
									esc_attr( $salesforce_field['name'] ),
									esc_attr( $selected ),
									esc_html( $salesforce_field['label'] ),
									esc_attr( $locked )
								);
							}
							?>
						</select>

					</td>
					<td class="column-is_prematch">
						<?php
						if ( isset( $value['is_prematch'] ) && '1' === $value['is_prematch'] ) {
							$checked = ' checked';
						} else {
							$checked = '';
						}
						?>
						<input type="checkbox" name="is_prematch[<?php echo esc_attr( $key ); ?>]" id="is_prematch-<?php echo esc_attr( $key ); ?>" value="1" <?php echo esc_attr( $checked ); ?> title="<?php echo esc_html__( 'This pair should be checked for existing matches in Salesforce before adding', 'object-sync-for-salesforce' ); ?>" />
					</td>
					<td class="column-is_key">
						<?php
						if ( isset( $value['is_key'] ) && '1' === $value['is_key'] ) {
							$checked = ' checked';
						} else {
							$checked = '';
						}
						?>
						<input type="checkbox" name="is_key[<?php echo esc_attr( $key ); ?>]" id="is_key-<?php echo esc_attr( $key ); ?>" value="1" <?php echo esc_attr( $checked ); ?> title="<?php echo esc_html__( 'This Salesforce field is an External ID in Salesforce', 'object-sync-for-salesforce' ); ?>" />
					</td>
					<td class="column-direction">
						<?php
						if ( isset( $value['direction'] ) ) {
							if ( 'sf_wp' === $value['direction'] ) {
								$checked_sf_wp = ' checked';
								$checked_wp_sf = '';
								$checked_sync  = '';
							} elseif ( 'wp_sf' === $value['direction'] ) {
								$checked_sf_wp = '';
								$checked_wp_sf = ' checked';
								$checked_sync  = '';
							} else {
								$checked_sf_wp = '';
								$checked_wp_sf = '';
								$checked_sync  = ' checked';
							}
						} else {
							$checked_sf_wp = '';
							$checked_wp_sf = '';
							$checked_sync  = ' checked'; // by default, start with Sync checked.
						}
						?>
						<div class="radios">
							<label><input type="radio" value="sf_wp" name="direction[<?php echo esc_attr( $key ); ?>]" id="direction-<?php echo esc_attr( $key ); ?>-sf-wp" <?php echo esc_attr( $checked_sf_wp ); ?> required> <?php echo esc_html__( 'Salesforce to WordPress', 'object-sync-for-salesforce' ); ?></label>
							<label><input type="radio" value="wp_sf" name="direction[<?php echo esc_attr( $key ); ?>]" id="direction-<?php echo esc_attr( $key ); ?>-wp-sf" <?php echo esc_attr( $checked_wp_sf ); ?> required> <?php echo esc_html__( 'WordPress to Salesforce', 'object-sync-for-salesforce' ); ?></label>
							<label><input type="radio" value="sync" name="direction[<?php echo esc_attr( $key ); ?>]" id="direction-<?php echo esc_attr( $key ); ?>-sync" <?php echo esc_attr( $checked_sync ); ?> required> <?php echo esc_html__( 'Sync', 'object-sync-for-salesforce' ); ?></label>
						</div>
					</td>
					<td class="column-is_delete">
						<input type="checkbox" name="is_delete[<?php echo esc_attr( $key ); ?>]" id="is_delete-<?php echo esc_attr( $key ); ?>" value="1" />
					</td>
				</tr>
						<?php
					} // End foreach() method.
				} // End if() statement.
				?>
				<tr data-key="0" class="fieldmap-template">
					<td class="column-wordpress_field">
						<select name="wordpress_field[0]" id="wordpress_field-0">
							<option value="">- <?php echo esc_html__( 'Select WordPress Field', 'object-sync-for-salesforce' ); ?> -</option>
							<?php
							if ( isset( $wordpress_object ) ) {
								$wordpress_fields = $this->get_wordpress_object_fields( $wordpress_object );
								foreach ( $wordpress_fields as $wordpress_field ) {
									$disabled              = '';
									$disable_mapped_fields = get_option( $this->option_prefix . 'disable_mapped_fields', false );
									$disable_mapped_fields = filter_var( $disable_mapped_fields, FILTER_VALIDATE_BOOLEAN );
									if ( true === $disable_mapped_fields ) {
										$key    = null;
										$needle = $wordpress_field['key']; // the current WP field.
										// check the already mapped fields for the current field.
										array_walk(
											$fieldmap_fields,
											function( $v, $k ) use ( &$key, $needle ) {
												if ( in_array( $needle, $v['wordpress_field'], true ) ) {
													$key = $k;
												}
											}
										);
										// disable fields that are already mapped.
										if ( null !== $key ) {
											$disabled = ' disabled';
										}
									}

									if ( isset( $wordpress_field['editable'] ) && false === $wordpress_field['editable'] ) {
										$locked = ' 🔒';
									} else {
										$locked = '';
									}

									echo sprintf(
										'<option value="%1$s"%3$s>%2$s%4$s</option>',
										esc_attr( $wordpress_field['key'] ),
										esc_html( $wordpress_field['key'] ),
										esc_attr( $disabled ),
										esc_attr( $locked )
									);
								}
							}
							?>
						</select>
					</td>
					<td class="column-salesforce_field">
						<select name="salesforce_field[0]" id="salesforce_field-0">
							<option value="">- <?php echo esc_html__( 'Select Salesforce Field', 'object-sync-for-salesforce' ); ?> -</option>
							<?php
							if ( isset( $salesforce_object ) ) {
								$salesforce_fields = $this->get_salesforce_object_fields(
									array(
										'salesforce_object' => $salesforce_object,
									)
								);
								$display_value     = get_option( $this->option_prefix . 'salesforce_field_display_value', 'field_label' );
								foreach ( $salesforce_fields as $salesforce_field ) {

									$disabled              = '';
									$disable_mapped_fields = get_option( $this->option_prefix . 'disable_mapped_fields', false );
									$disable_mapped_fields = filter_var( $disable_mapped_fields, FILTER_VALIDATE_BOOLEAN );
									if ( true === $disable_mapped_fields ) {
										$key    = null;
										$needle = $salesforce_field['name']; // the current Salesforce field.
										// check the already mapped fields for the current field.
										array_walk(
											$fieldmap_fields,
											function( $v, $k ) use ( &$key, $needle ) {
												if ( in_array( $needle, $v['salesforce_field'], true ) ) {
													$key = $k;
												}
											}
										);
										// disable fields that are already mapped.
										if ( null !== $key ) {
											$disabled = ' disabled';
										}
									}

									if ( 'api_name' === $display_value ) {
										$salesforce_field['label'] = $salesforce_field['name'];
									}

									if ( false === $salesforce_field['nillable'] ) {
										$salesforce_field['label'] .= ' *';
									}

									if ( false === $salesforce_field['updateable'] ) {
										$locked = ' 🔒';
									} else {
										$locked = '';
									}

									echo sprintf(
										'<option value="%1$s"%2$s>%3$s%4$s</option>',
										esc_attr( $salesforce_field['name'] ),
										esc_attr( $disabled ),
										esc_html( $salesforce_field['label'] ),
										esc_attr( $locked )
									);
								}
							}
							?>
						</select>
					</td>
					<td class="column-is_prematch">
						<input type="checkbox" name="is_prematch[0]" id="is_prematch-0" value="1" />
					</td>
					<td class="column-is_key">
						<input type="checkbox" name="is_key[0]" id="is_key-0" value="1" />
					</td>
					<td class="column-direction">
						<div class="radios">
							<label><input type="radio" value="sf_wp" name="direction[0]" id="direction-0-sf-wp" required> <?php echo esc_html__( 'Salesforce to WordPress', 'object-sync-for-salesforce' ); ?></label>
							<label><input type="radio" value="wp_sf" name="direction[0]" id="direction-0-wp-sf" required> <?php echo esc_html__( 'WordPress to Salesforce', 'object-sync-for-salesforce' ); ?></label>
							<label><input type="radio" value="sync" name="direction[0]" id="direction-0-sync" required checked> <?php echo esc_html__( 'Sync', 'object-sync-for-salesforce' ); ?></label>
						</div>
					</td>
					<td class="column-is_delete">
						<input type="checkbox" name="is_delete[0]" id="is_delete-0" value="1" />
					</td>
				</tr>
			</tbody>
		</table>
		<!--<div class="spinner"></div>-->
		<?php
		$add_button_label_more  = esc_html__( 'Add another field mapping', 'object-sync-for-salesforce' );
		$add_button_label_first = esc_html__( 'Add field mapping', 'object-sync-for-salesforce' );
		if ( isset( $fieldmap_fields ) && null !== $fieldmap_fields ) {
			$add_button_label = $add_button_label_more;
		} else {
			$add_button_label = $add_button_label_first;
		}
		?>
		<p><button type="button" id="add-field-mapping" class="button button-secondary" data-add-first="<?php echo esc_attr( $add_button_label_first ); ?>" data-add-more="<?php echo esc_attr( $add_button_label_more ); ?>" data-error-missing-object="<?php echo esc_html__( 'You have to pick a WordPress object and a Salesforce object to add field mapping.', 'object-sync-for-salesforce' ); ?>"><?php echo esc_attr( $add_button_label ); ?></button></p>
		<p class="description"><?php echo esc_html__( 'A checked Prematch (when saving data in either WordPress or Salesforce) or Salesforce Key (only when saving data from WordPress to Salesforce) field will cause the plugin to check for a match on that value before creating new records.', 'object-sync-for-salesforce' ); ?></p>
	</fieldset>
	<fieldset class="fieldmap_settings sync_triggers">
		<legend><?php echo esc_html__( 'Action Triggers', 'object-sync-for-salesforce' ); ?></legend>
		<div class="checkboxes">
			<?php
			$wordpress_create_checked  = '';
			$wordpress_update_checked  = '';
			$wordpress_delete_checked  = '';
			$salesforce_create_checked = '';
			$salesforce_update_checked = '';
			$salesforce_delete_checked = '';
			if ( isset( $sync_triggers ) && is_array( $sync_triggers ) ) {
				foreach ( $sync_triggers as $trigger ) {
					switch ( $trigger ) {
						case $this->mappings->sync_wordpress_create:
							$wordpress_create_checked = ' checked';
							break;
						case $this->mappings->sync_wordpress_update:
							$wordpress_update_checked = ' checked';
							break;
						case $this->mappings->sync_wordpress_delete:
							$wordpress_delete_checked = ' checked';
							break;
						case $this->mappings->sync_sf_create:
							$salesforce_create_checked = ' checked';
							break;
						case $this->mappings->sync_sf_update:
							$salesforce_update_checked = ' checked';
							break;
						case $this->mappings->sync_sf_delete:
							$salesforce_delete_checked = ' checked';
							break;
					}
				}
			}
			?>
			<label><input type="checkbox" value="<?php echo esc_html( $this->mappings->sync_wordpress_create ); ?>" name="sync_triggers[]" id="sync_triggers-wordpress-create" <?php echo esc_attr( $wordpress_create_checked ); ?>><?php echo esc_html__( 'WordPress Create', 'object-sync-for-salesforce' ); ?></label>
			<label><input type="checkbox" value="<?php echo esc_html( $this->mappings->sync_wordpress_update ); ?>" name="sync_triggers[]" id="sync_triggers-wordpress-update" <?php echo esc_attr( $wordpress_update_checked ); ?>><?php echo esc_html__( 'WordPress Update', 'object-sync-for-salesforce' ); ?></label>
			<label><input type="checkbox" value="<?php echo esc_html( $this->mappings->sync_wordpress_delete ); ?>" name="sync_triggers[]" id="sync_triggers-wordpress-delete" <?php echo esc_attr( $wordpress_delete_checked ); ?>><?php echo esc_html__( 'WordPress Delete', 'object-sync-for-salesforce' ); ?></label>
			<label><input type="checkbox" value="<?php echo esc_html( $this->mappings->sync_sf_create ); ?>" name="sync_triggers[]" id="sync_triggers-salesforce-create" <?php echo esc_attr( $salesforce_create_checked ); ?>><?php echo esc_html__( 'Salesforce Create', 'object-sync-for-salesforce' ); ?></label>
			<label><input type="checkbox" value="<?php echo esc_html( $this->mappings->sync_sf_update ); ?>" name="sync_triggers[]" id="sync_triggers-salesforce-update" <?php echo esc_attr( $salesforce_update_checked ); ?>><?php echo esc_html__( 'Salesforce Update', 'object-sync-for-salesforce' ); ?></label>
			<label><input type="checkbox" value="<?php echo esc_html( $this->mappings->sync_sf_delete ); ?>" name="sync_triggers[]" id="sync_triggers-salesforce-delete" <?php echo esc_attr( $salesforce_delete_checked ); ?>><?php echo esc_html__( 'Salesforce Delete', 'object-sync-for-salesforce' ); ?></label>
			<p class="description">
				<?php echo esc_html__( 'Select which actions on WordPress objects and Salesforce objects should trigger a synchronization. The WordPress Create, WordPress Update, and WordPress Delete actions push data from WordPress to Salesforce. The Salesforce Create, Salesforce Update, and Salesforce Delete actions pull data from Salesforce to WordPress.', 'object-sync-for-salesforce' ); ?>
			</p>
			<p class="description">
				<?php echo '<strong>' . esc_html__( 'If you select both WordPress Create and Salesforce Create trigger on a fieldmap, you should almost always also select Process Asynchronously on that fieldmap.', 'object-sync-for-salesforce' ) . '</strong> '; ?>
				<?php echo esc_html__( 'If you do not do this, you will likely run into problems with duplicate records because the two methods run closely together without the structure of the queue.', 'object-sync-for-salesforce' ); ?>
			</p>
		</div>
		<div class="checkboxes">
			<label><input type="checkbox" name="push_async" id="process-async" value="1" <?php echo isset( $push_async ) && '1' === $push_async ? ' checked' : ''; ?>><?php echo esc_html__( 'Process Asynchronously', 'object-sync-for-salesforce' ); ?></label>
			<p class="description">
				<?php echo esc_html__( 'If selected, push data will be added to the queue, rather than being sent to Salesforce immediately. Usually a pushed record that is added to the queue runs within a few seconds, but it is not instantaneous. Having these tasks run in a queue can benefit site performance.', 'object-sync-for-salesforce' ); ?>
			</p>
		</div>
		<div class="checkboxes">
			<label><input type="checkbox" name="always_delete_object_maps_on_delete" id="always-delete-object-maps-on-delete" value="1" <?php echo isset( $always_delete_object_maps_on_delete ) && '1' === $always_delete_object_maps_on_delete ? ' checked' : ''; ?>><?php echo esc_html__( 'Always Delete Object Maps When Fieldmap Records Are Deleted', 'object-sync-for-salesforce' ); ?></label>
			<p class="description"><?php echo esc_html__( 'If selected, when a record in either the WordPress or Salesforce object type of this fieldmap is deleted, the plugin will check for object maps connected to the record that was deleted even if the delete action trigger is not checked. If it finds those object maps, they will be deleted.', 'object-sync-for-salesforce' ); ?></p>
		</div>
	</fieldset>
	<fieldset class="fieldmap_settings other_settings">
		<legend><?php echo esc_html__( 'Fieldmap Settings', 'object-sync-for-salesforce' ); ?></legend>
		<div class="checkboxes">
			<label><input type="checkbox" name="push_drafts" id="push-drafts" value="1" <?php echo isset( $push_drafts ) && '1' === $push_drafts ? ' checked' : ''; ?>><?php echo esc_html__( 'Push Drafts', 'object-sync-for-salesforce' ); ?></label>
			<p class="description"><?php echo esc_html__( 'If selected, WordPress will send drafts of this object type (if it creates drafts for it) to Salesforce.', 'object-sync-for-salesforce' ); ?></p>
		</div>
		<div class="checkboxes">
			<label><input type="checkbox" name="pull_to_drafts" id="pull-to-drafts" value="1" <?php echo isset( $pull_to_drafts ) && '1' === $pull_to_drafts ? ' checked' : ''; ?>><?php echo esc_html__( 'Pull to Drafts', 'object-sync-for-salesforce' ); ?></label>
			<p class="description"><?php echo esc_html__( 'If selected, WordPress will check for matches against existing drafts of this object type, and will also update existing drafts.', 'object-sync-for-salesforce' ); ?></p>
		</div>
		<div class="select pull_default_status">
			<label for="pull_default_status"><?php echo esc_html__( 'Default status for new synced objects', 'object-sync-for-salesforce' ); ?>: </label>
			<select id="pull_default_status" name="pull_default_status" class="select-small">
				<option value="publish"<?php echo ( ! isset( $pull_default_status ) || 'publish' === $pull_default_status ) ? ' selected' : ''; ?>><?php echo esc_html__( 'Published', 'object-sync-for-salesforce' ); ?></option>
				<option value="draft"<?php echo ( isset( $pull_default_status ) && 'draft' === $pull_default_status ) ? ' selected' : ''; ?>><?php echo esc_html__( 'Draft', 'object-sync-for-salesforce' ); ?></option>
			</select>
			<p class="description"><?php echo esc_html__( 'Post status for newly created objects pulled from Salesforce. Existing objects are not affected.', 'object-sync-for-salesforce' ); ?></p>
		</div>
		<div class="select fieldmap_status">
			<label for="fieldmap_status"><?php echo esc_html__( 'Fieldmap Status', 'object-sync-for-salesforce' ); ?>: </label>
			<select id="fieldmap_status" name="fieldmap_status" class="select-small" required>
				<?php
				$fieldmap_statuses = $this->mappings->fieldmap_statuses;
				foreach ( $fieldmap_statuses as $key => $value ) :
					if ( '' !== $value ) :
						$selected = '';
						if ( ! isset( $fieldmap_status ) ) {
							$fieldmap_status = 'active';
						}
						if ( $fieldmap_status === $key ) {
							$selected = ' selected';
						}
						?>
						<option value="<?php echo esc_attr( $key ); ?>"<?php echo esc_html( $selected ); ?>><?php echo esc_html( $value ); ?></option>
						<?php
					endif;
				endforeach;
				?>
			</select>
			<p class="description"><?php echo esc_html__( 'By default, fieldmaps are saved as "Active." If you would like to work with a fieldmap without it being used for sync operations, save it as "Inactive."', 'object-sync-for-salesforce' ); ?></p>
		</div>
	</fieldset>

	<?php
	$existing_rules      = ! empty( $relational_rules['rules'] ) ? $relational_rules['rules'] : array();
	$existing_conditions = ! empty( $pull_conditions['conditions'] ) ? $pull_conditions['conditions'] : array();
	$operators           = Object_Sync_Sf_Pull_Conditions::$operators;
	?>

	<?php /* ── Carkeek: Relational Rules ── */ ?>
	<fieldset class="fieldmap_settings osf_relational_rules">
		<legend><?php esc_html_e( 'Related Object Pull (SF → WP)', 'object-sync-for-salesforce' ); ?></legend>
		<p class="description">
			<?php esc_html_e( 'When this object is pulled from Salesforce, automatically pull any related parent objects and wire them to an ACF relationship field on this post. Useful for chaining Shifts → Jobs → Locations.', 'object-sync-for-salesforce' ); ?>
		</p>
		<div class="checkboxes">
			<label>
				<input type="checkbox" id="osf-relational-rules-enabled" name="relational_rules_enabled" value="1" <?php checked( ! empty( $relational_rules['enabled'] ) ); ?>>
				<?php esc_html_e( 'Automatically pull related objects when this object is synced', 'object-sync-for-salesforce' ); ?>
			</label>
		</div>

		<div id="osf-relational-rules-rows">
			<?php foreach ( $existing_rules as $rule_idx => $rule ) : ?>
			<div class="osf-relational-rule-row osf-rule-row" style="background:#f9f9f9;border:1px solid #ddd;padding:12px 16px;margin:8px 0;border-radius:3px;">
				<table style="width:100%;border-collapse:collapse;">
					<tr>
						<td style="width:33%;padding:4px 8px 4px 0;vertical-align:top;">
							<label style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'SF Lookup Field', 'object-sync-for-salesforce' ); ?></label>
							<select class="osf-sf-field-select"
								name="relational_rule_sf_field[<?php echo esc_attr( $rule_idx ); ?>]"
								data-current="<?php echo esc_attr( $rule['sf_field'] ?? '' ); ?>"
								style="width:100%;">
								<option value=""><?php esc_html_e( '— Loading fields… —', 'object-sync-for-salesforce' ); ?></option>
							</select>
							<span class="description" style="display:block;margin-top:4px;"><?php esc_html_e( 'The SF field on this object that holds the related record\'s ID.', 'object-sync-for-salesforce' ); ?></span>
						</td>
						<td style="width:33%;padding:4px 8px;vertical-align:top;">
							<label style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Target SF Object', 'object-sync-for-salesforce' ); ?></label>
							<select class="osf-sf-object-select"
								name="relational_rule_target_object[<?php echo esc_attr( $rule_idx ); ?>]"
								data-current="<?php echo esc_attr( $rule['target_object'] ?? '' ); ?>"
								style="width:100%;">
								<option value=""><?php esc_html_e( '— Loading objects… —', 'object-sync-for-salesforce' ); ?></option>
							</select>
							<span class="description" style="display:block;margin-top:4px;"><?php esc_html_e( 'The Salesforce object type to pull (must have a fieldmap configured).', 'object-sync-for-salesforce' ); ?></span>
						</td>
						<td style="width:33%;padding:4px 0 4px 8px;vertical-align:top;">
							<label style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'WP ACF Relationship Field', 'object-sync-for-salesforce' ); ?></label>
							<select class="osf-wp-field-select"
								name="relational_rule_acf_field[<?php echo esc_attr( $rule_idx ); ?>]"
								data-current="<?php echo esc_attr( $rule['acf_field'] ?? '' ); ?>"
								style="width:100%;">
								<option value=""><?php esc_html_e( '— Loading fields… —', 'object-sync-for-salesforce' ); ?></option>
							</select>
							<span class="description" style="display:block;margin-top:4px;"><?php esc_html_e( 'ACF relationship field key or name on this post type.', 'object-sync-for-salesforce' ); ?></span>
						</td>
					</tr>
				</table>
				<button type="button" class="button osf-remove-rule" style="margin-top:8px;"><?php esc_html_e( 'Remove Rule', 'object-sync-for-salesforce' ); ?></button>
			</div>
			<?php endforeach; ?>
		</div>

		<button type="button" id="osf-add-relational-rule" class="button button-secondary" style="margin-top:8px;">
			<?php esc_html_e( '+ Add Related Object Rule', 'object-sync-for-salesforce' ); ?>
		</button>
	</fieldset>

	<?php /* ── Carkeek: Pull Conditions ── */ ?>
	<fieldset class="fieldmap_settings osf_pull_conditions">
		<legend><?php esc_html_e( 'Pull Conditions', 'object-sync-for-salesforce' ); ?></legend>
		<p class="description">
			<?php esc_html_e( 'All conditions are AND-evaluated. If any condition fails, the record is skipped — existing WP posts are not deleted, they are simply left unchanged until the next successful sync.', 'object-sync-for-salesforce' ); ?>
		</p>
		<div class="checkboxes">
			<label>
				<input type="checkbox" id="osf-pull-conditions-enabled" name="pull_conditions_enabled" value="1" <?php checked( ! empty( $pull_conditions['enabled'] ) ); ?>>
				<?php esc_html_e( 'Only pull records matching these conditions', 'object-sync-for-salesforce' ); ?>
			</label>
		</div>

		<div id="osf-pull-conditions-rows">
			<?php foreach ( $existing_conditions as $cond_idx => $cond ) : ?>
			<div class="osf-pull-condition-row osf-rule-row" style="background:#f9f9f9;border:1px solid #ddd;padding:12px 16px;margin:8px 0;border-radius:3px;">
				<table style="width:100%;border-collapse:collapse;">
					<tr>
						<td style="width:35%;padding:4px 8px 4px 0;vertical-align:top;">
							<label style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Salesforce Field', 'object-sync-for-salesforce' ); ?></label>
							<select class="osf-sf-field-select"
								name="pull_condition_sf_field[<?php echo esc_attr( $cond_idx ); ?>]"
								data-current="<?php echo esc_attr( $cond['sf_field'] ?? '' ); ?>"
								style="width:100%;">
								<option value=""><?php esc_html_e( '— Loading fields… —', 'object-sync-for-salesforce' ); ?></option>
							</select>
						</td>
						<td style="width:20%;padding:4px 8px;vertical-align:top;">
							<label style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Operator', 'object-sync-for-salesforce' ); ?></label>
							<select name="pull_condition_operator[<?php echo esc_attr( $cond_idx ); ?>]" style="width:100%;">
								<?php foreach ( $operators as $op_key => $op_label ) : ?>
								<option value="<?php echo esc_attr( $op_key ); ?>" <?php selected( $cond['operator'] ?? '', $op_key ); ?>>
									<?php echo esc_html( $op_label ); ?>
								</option>
								<?php endforeach; ?>
							</select>
						</td>
						<td style="width:40%;padding:4px 0 4px 8px;vertical-align:top;">
							<label style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Value', 'object-sync-for-salesforce' ); ?></label>
							<input type="text"
								class="regular-text"
								name="pull_condition_value[<?php echo esc_attr( $cond_idx ); ?>]"
								value="<?php echo esc_attr( $cond['value'] ?? '' ); ?>"
								style="width:100%;">
							<span class="description" style="display:block;margin-top:4px;">
								<?php esc_html_e( 'Booleans: true or false (1 and 0 also work). Dates: YYYY-MM-DD or {today}. Datetimes: YYYY-MM-DD HH:MM:SS or {now}. Tokens resolve to UTC at sync time.', 'object-sync-for-salesforce' ); ?>
							</span>
						</td>
						<td style="width:5%;padding:4px 0 4px 8px;vertical-align:top;">
							<label style="display:block;margin-bottom:4px;">&nbsp;</label>
							<button type="button" class="button osf-remove-condition"><?php esc_html_e( '✕', 'object-sync-for-salesforce' ); ?></button>
						</td>
					</tr>
				</table>
			</div>
			<?php endforeach; ?>
		</div>

		<button type="button" id="osf-add-pull-condition" class="button button-secondary" style="margin-top:8px;">
			<?php esc_html_e( '+ Add Condition', 'object-sync-for-salesforce' ); ?>
		</button>
	</fieldset>

	<script type="text/javascript">
	(function($) {
		var sfFields  = [];   // [{name, label, type}, ...]
		var sfObjects = [];   // [{value, text}, ...]  — cloned from #salesforce_object
		var wpFields  = [];   // [{key, label}, ...]

		var ruleIdx = <?php echo (int) count( $existing_rules ); ?>;
		var condIdx = <?php echo (int) count( $existing_conditions ); ?>;
		var operatorsHtml = <?php echo wp_json_encode( array_map( null, array_keys( $operators ), array_values( $operators ) ) ); ?>;
		// Build operator <options> string once.
		var opOptions = (function() {
			var ops = <?php echo wp_json_encode( $operators ); ?>;
			return Object.keys(ops).map(function(k) {
				return '<option value="' + k + '">' + ops[k] + '</option>';
			}).join('');
		}());

		/* ---- Field loading ---- */

		function buildSfFieldOptions(currentVal) {
			var html = '<option value=""><?php echo esc_js( __( '— Select field —', 'object-sync-for-salesforce' ) ); ?></option>';
			if (!sfFields.length) {
				return '<option value=""><?php echo esc_js( __( '— Select a Salesforce object first —', 'object-sync-for-salesforce' ) ); ?></option>';
			}
			sfFields.forEach(function(f) {
				var label    = f.label || f.name;
				var selected = (f.name === currentVal) ? ' selected' : '';
				html += '<option value="' + f.name + '"' + selected + '>' + label + ' (' + f.name + ')</option>';
			});
			return html;
		}

		function buildSfObjectOptions(currentVal) {
			var html = '<option value=""><?php echo esc_js( __( '— Select object —', 'object-sync-for-salesforce' ) ); ?></option>';
			sfObjects.forEach(function(o) {
				var selected = (o.value === currentVal) ? ' selected' : '';
				html += '<option value="' + o.value + '"' + selected + '>' + o.text + '</option>';
			});
			return html;
		}

		function initOsfSelect2(context) {
			if (!$.fn.select2) { return; }
			var $ctx = context ? $(context) : $(document);
			$ctx.find('.osf-sf-field-select, .osf-sf-object-select, .osf-wp-field-select').select2();
		}

		function buildWpFieldOptions(currentVal) {
			if (!wpFields.length) {
				return '<option value=""><?php echo esc_js( __( '— Select a WordPress object first —', 'object-sync-for-salesforce' ) ); ?></option>';
			}
			var html = '<option value=""><?php echo esc_js( __( '— Select field —', 'object-sync-for-salesforce' ) ); ?></option>';
			wpFields.forEach(function(f) {
				var val      = f.key || f.name || '';
				var label    = f.label || f.name || val;
				var selected = (val === currentVal) ? ' selected' : '';
				html += '<option value="' + val + '"' + selected + '>' + label + ' (' + val + ')</option>';
			});
			return html;
		}

		function populateAllWpFieldSelects() {
			$('.osf-wp-field-select').each(function() {
				var current = $(this).data('current') || $(this).val() || '';
				$(this).html(buildWpFieldOptions(current));
				if (current) { $(this).val(current); }
			});
			initOsfSelect2();
		}

		function populateAllSfFieldSelects() {
			$('.osf-sf-field-select').each(function() {
				var current = $(this).data('current') || $(this).val() || '';
				$(this).html(buildSfFieldOptions(current));
				if (current) { $(this).val(current); }
			});
			initOsfSelect2();
		}

		function populateAllSfObjectSelects() {
			$('.osf-sf-object-select').each(function() {
				var current = $(this).data('current') || $(this).val() || '';
				$(this).html(buildSfObjectOptions(current));
				if (current) { $(this).val(current); }
			});
			initOsfSelect2();
		}

		function fetchSfFields(sfObject) {
			if (!sfObject) { sfFields = []; populateAllSfFieldSelects(); return; }
			$.post(ajaxurl, { action: 'get_salesforce_object_fields', salesforce_object: sfObject },
				function(resp) {
					sfFields = (resp.data && resp.data.fields) ? resp.data.fields : [];
					populateAllSfFieldSelects();
				}
			);
		}

		function fetchWpFields(wpObject) {
			if (!wpObject) { wpFields = []; populateAllWpFieldSelects(); return; }
			$.post(ajaxurl, { action: 'get_wordpress_object_fields', wordpress_object: wpObject },
				function(resp) {
					wpFields = (resp.data && resp.data.fields) ? resp.data.fields : [];
					populateAllWpFieldSelects();
				}
			);
		}

		/* ---- Row builders ---- */

		function ruleRowHtml(idx) {
			return '<div class="osf-relational-rule-row osf-rule-row" style="background:#f9f9f9;border:1px solid #ddd;padding:12px 16px;margin:8px 0;border-radius:3px;">' +
				'<table style="width:100%;border-collapse:collapse;"><tr>' +
				'<td style="width:33%;padding:4px 8px 4px 0;vertical-align:top;">' +
				'<label style="display:block;font-weight:600;margin-bottom:4px;"><?php echo esc_js( __( 'SF Lookup Field', 'object-sync-for-salesforce' ) ); ?></label>' +
				'<select class="osf-sf-field-select" name="relational_rule_sf_field[' + idx + ']" style="width:100%;">' + buildSfFieldOptions('') + '</select>' +
				'<span class="description" style="display:block;margin-top:4px;"><?php echo esc_js( __( 'The SF field that holds the related record\'s ID.', 'object-sync-for-salesforce' ) ); ?></span>' +
				'</td>' +
				'<td style="width:33%;padding:4px 8px;vertical-align:top;">' +
				'<label style="display:block;font-weight:600;margin-bottom:4px;"><?php echo esc_js( __( 'Target SF Object', 'object-sync-for-salesforce' ) ); ?></label>' +
				'<select class="osf-sf-object-select" name="relational_rule_target_object[' + idx + ']" style="width:100%;">' + buildSfObjectOptions('') + '</select>' +
				'<span class="description" style="display:block;margin-top:4px;"><?php echo esc_js( __( 'SF object to pull (needs a fieldmap).', 'object-sync-for-salesforce' ) ); ?></span>' +
				'</td>' +
				'<td style="width:33%;padding:4px 0 4px 8px;vertical-align:top;">' +
				'<label style="display:block;font-weight:600;margin-bottom:4px;"><?php echo esc_js( __( 'WP ACF Relationship Field', 'object-sync-for-salesforce' ) ); ?></label>' +
				'<select class="osf-wp-field-select" name="relational_rule_acf_field[' + idx + ']" style="width:100%;">' + buildWpFieldOptions('') + '</select>' +
				'<span class="description" style="display:block;margin-top:4px;"><?php echo esc_js( __( 'ACF relationship field key or name.', 'object-sync-for-salesforce' ) ); ?></span>' +
				'</td>' +
				'</tr></table>' +
				'<button type="button" class="button osf-remove-rule" style="margin-top:8px;"><?php echo esc_js( __( 'Remove Rule', 'object-sync-for-salesforce' ) ); ?></button>' +
				'</div>';
		}

		function conditionRowHtml(idx) {
			return '<div class="osf-pull-condition-row osf-rule-row" style="background:#f9f9f9;border:1px solid #ddd;padding:12px 16px;margin:8px 0;border-radius:3px;">' +
				'<table style="width:100%;border-collapse:collapse;"><tr>' +
				'<td style="width:35%;padding:4px 8px 4px 0;vertical-align:top;">' +
				'<label style="display:block;font-weight:600;margin-bottom:4px;"><?php echo esc_js( __( 'Salesforce Field', 'object-sync-for-salesforce' ) ); ?></label>' +
				'<select class="osf-sf-field-select" name="pull_condition_sf_field[' + idx + ']" style="width:100%;">' + buildSfFieldOptions('') + '</select>' +
				'</td>' +
				'<td style="width:20%;padding:4px 8px;vertical-align:top;">' +
				'<label style="display:block;font-weight:600;margin-bottom:4px;"><?php echo esc_js( __( 'Operator', 'object-sync-for-salesforce' ) ); ?></label>' +
				'<select name="pull_condition_operator[' + idx + ']" style="width:100%;">' + opOptions + '</select>' +
				'</td>' +
				'<td style="width:40%;padding:4px 0 4px 8px;vertical-align:top;">' +
				'<label style="display:block;font-weight:600;margin-bottom:4px;"><?php echo esc_js( __( 'Value', 'object-sync-for-salesforce' ) ); ?></label>' +
				'<input type="text" class="regular-text" name="pull_condition_value[' + idx + ']" style="width:100%;">' +
				'<span class="description" style="display:block;margin-top:4px;"><?php echo esc_js( __( 'Booleans: true or false (1/0 also work). Dates: YYYY-MM-DD or {today}. Datetimes: {now}.', 'object-sync-for-salesforce' ) ); ?></span>' +
				'</td>' +
				'<td style="width:5%;padding:4px 0 4px 8px;vertical-align:top;">' +
				'<label style="display:block;margin-bottom:4px;">&nbsp;</label>' +
				'<button type="button" class="button osf-remove-condition"><?php echo esc_js( __( '✕', 'object-sync-for-salesforce' ) ); ?></button>' +
				'</td>' +
				'</tr></table>' +
				'</div>';
		}

		/* ---- Event wiring ---- */

		$(document).ready(function() {

			// Collect SF object options from the existing select (already rendered).
			$('#salesforce_object option').each(function() {
				if ($(this).val()) {
					sfObjects.push({ value: $(this).val(), text: $.trim($(this).text()) });
				}
			});
			populateAllSfObjectSelects();

			// Initial field load for the currently selected objects.
			fetchSfFields($('#salesforce_object').val());
			fetchWpFields($('#wordpress_object').val());

			// Repopulate when the main object selects change.
			$('#salesforce_object').on('change', function() {
				sfFields = [];
				populateAllSfFieldSelects();
				fetchSfFields($(this).val());
			});
			$('#wordpress_object').on('change', function() {
				wpFields = [];
				populateAllWpFieldSelects();
				fetchWpFields($(this).val());
			});

			// Add rule.
			$('#osf-add-relational-rule').on('click', function() {
				var $row = $(ruleRowHtml(ruleIdx++));
				$('#osf-relational-rules-rows').append($row);
				initOsfSelect2($row[0]);
			});

			// Add condition.
			$('#osf-add-pull-condition').on('click', function() {
				var $row = $(conditionRowHtml(condIdx++));
				$('#osf-pull-conditions-rows').append($row);
				initOsfSelect2($row[0]);
			});

			// Remove (delegated so it works for dynamically added rows).
			$(document).on('click', '.osf-remove-rule', function() {
				$(this).closest('.osf-relational-rule-row').remove();
			});
			$(document).on('click', '.osf-remove-condition', function() {
				$(this).closest('.osf-pull-condition-row').remove();
			});
		});

	}(jQuery));
	</script>

	<?php
		submit_button(
			// translators: the placeholder refers to the currently selected method (add, edit, or clone).
			sprintf( esc_html__( '%1$s fieldmap', 'object-sync-for-salesforce' ), ucfirst( $method ) )
		);
		?>
</form>
