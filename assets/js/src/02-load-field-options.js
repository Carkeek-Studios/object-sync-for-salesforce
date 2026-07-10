/**
 * Fetch the available field list for a WordPress or Salesforce object.
 * @param {string}   system     'wordpress' or 'salesforce'.
 * @param {string}   objectName the value for the object name from the <select>.
 * @param {function} callback   receives the fields array (empty array on none).
 */
function fetchObjectFields( system, objectName, callback ) {
	if ( '' === objectName || 'undefined' === typeof objectName ) {
		callback( [] );
		return;
	}
	var data = { 'action': 'get_' + system + '_object_fields' };
	if ( 'wordpress' === system ) {
		data['wordpress_object'] = objectName;
	} else if ( 'salesforce' === system ) {
		data['salesforce_object'] = objectName;
	} else {
		callback( [] );
		return;
	}

	$.ajax( {
		type: 'POST',
		url: ajaxurl,
		data: data,
		beforeSend: function() {
			$( '.spinner-' + system ).addClass( 'is-active' );
		},
		success: function( response ) {
			var fields = ( response && response.data && response.data.fields ) ? response.data.fields : [];
			callback( fields );
		},
		complete: function() {
			$( '.spinner-' + system ).removeClass( 'is-active' );
		}
	} );
}

/**
 * Build an <option> list markup string for a system's fields.
 * @param {string} system        'wordpress' or 'salesforce'.
 * @param {Array}  fields        field objects from the AJAX response.
 * @param {string} firstLabel    text for the leading blank option.
 * @param {string} selectedValue value to mark as selected (optional).
 * @return {string} option markup.
 */
function buildFieldOptions( system, fields, firstLabel, selectedValue ) {
	var html = '<option value="">' + firstLabel + '</option>';
	$.each( fields, function( index, value ) {
		var optValue, optLabel;
		if ( 'wordpress' === system ) {
			optValue = value.key;
			optLabel = value.key;
		} else {
			optValue = value.name;
			optLabel = ( 'undefined' !== typeof value.label ) ? value.label : value.name;
		}
		var isSelected = ( selectedValue && optValue === selectedValue ) ? ' selected="selected"' : '';
		html += '<option value="' + optValue + '"' + isSelected + '>' + optLabel + '</option>';
	} );
	return html;
}

/**
 * Populate empty field selects on page load without clobbering existing selections.
 * Used for the add flow (and any select that has no value yet).
 * @param {string} system     'wordpress' or 'salesforce'.
 * @param {string} objectName the value for the object name from the <select>.
 */
function loadFieldOptions( system, objectName ) {
	var selectField = '.column-' + system + '_field select';
	// If a value is already selected (edit/clone), the server rendered the options — leave them.
	if ( '' !== $( selectField ).val() ) {
		return;
	}
	var firstField = $( selectField + ' option' ).first().text();
	fetchObjectFields( system, objectName, function( fields ) {
		$( selectField ).html( buildFieldOptions( system, fields, firstField ) );
	} );
}

/**
 * When the WordPress or Salesforce object type changes, repopulate every row's select on
 * THAT side with the new object's fields — preserving each row's current selection when
 * the field still exists on the new object, and blanking it only when it does not. Rows
 * are never removed and the other side is left untouched, so cloning a fieldmap to a
 * different object that shares the same fields keeps the mapping intact.
 *
 * @param {string} system     'wordpress' or 'salesforce'.
 * @param {string} objectName the newly selected object name.
 */
function repopulateChangedSide( system, objectName ) {
	var selectField = '.column-' + system + '_field select';
	var firstField = $( selectField + ' option' ).first().text();

	fetchObjectFields( system, objectName, function( fields ) {
		// Set of valid values on the new object, for quick membership testing.
		var valid = {};
		$.each( fields, function( index, value ) {
			var v = ( 'wordpress' === system ) ? value.key : value.name;
			valid[ v ] = true;
		} );

		$( selectField ).each( function() {
			var $sel = $( this );
			var prev = $sel.val();
			// Keep the previous selection only if the field still exists on the new object.
			var keep = ( prev && valid[ prev ] ) ? prev : '';

			// select2 wraps the native select, so tear it down before swapping options.
			if ( jQuery.fn.select2 && $sel.hasClass( 'select2-hidden-accessible' ) ) {
				$sel.select2( 'destroy' );
			}
			$sel.html( buildFieldOptions( system, fields, firstField, keep ) );
			$sel.val( keep );
			if ( jQuery.fn.select2 ) {
				$sel.select2();
			}
		} );

		// Keep the "disable already mapped" state consistent if that feature is enabled.
		if ( 'function' === typeof disableAlreadyMappedFields ) {
			disableAlreadyMappedFields( system );
		}
	} );
}

// Repopulate (preserving matches) when the WordPress object changes.
$( document ).on( 'change', 'select#wordpress_object', function() {
	repopulateChangedSide( 'wordpress', $( this ).val() );
} );

// Repopulate (preserving matches) when the Salesforce object changes.
$( document ).on( 'change', 'select#salesforce_object', function() {
	repopulateChangedSide( 'salesforce', $( this ).val() );
} );

/**
 * When the plugin loads:
 * Populate empty field selects for the currently selected WordPress/Salesforce objects.
 */
$( document ).ready( function() {
	loadFieldOptions( 'wordpress', $( 'select#wordpress_object' ).val() );
	loadFieldOptions( 'salesforce', $( 'select#salesforce_object' ).val() );
} );
