;(function($) {
"use strict";

/**
 * Generates the Salesforce object fields based on the dropdown activity and API results.
 * This also generates other query fields that are object-specific, like date fields, record types allowed, etc.
 */
function salesforceObjectFields() {
  var delay = function () {
    var timer = 0;
    return function (callback, ms) {
      clearTimeout(timer);
      timer = setTimeout(callback, ms);
    };
  }();
  if (0 === $('.salesforce_record_types_allowed > *').length) {
    $('.salesforce_record_types_allowed').hide();
  }
  if (0 === $('.salesforce_record_type_default > *').length) {
    $('.salesforce_record_type_default').hide();
  }
  if (0 === $('.pull_trigger_field > *').length) {
    $('.pull_trigger_field').hide();
  }
  $('#salesforce_object').on('change', function () {
    var that = this;
    var delayTime = 1000;
    delay(function () {
      var data = {
        'action': 'get_salesforce_object_description',
        'include': ['fields', 'recordTypeInfos'],
        'field_type': 'datetime',
        'salesforce_object': that.value
      };
      $.post(ajaxurl, data, function (response) {
        var recordTypesAllowedMarkup = '';
        var recordTypeDefaultMarkup = '';
        var dateMarkup = '';
        if (0 < $(response.data.recordTypeInfos).length) {
          recordTypesAllowedMarkup += '<label for="salesforce_record_types_allowed">Allowed Record Types:</label><div class="checkboxes">';
          $.each(response.data.recordTypeInfos, function (index, value) {
            recordTypesAllowedMarkup += '<label><input type="checkbox" class="form-checkbox" value="' + index + '" name="salesforce_record_types_allowed[' + index + ']" id="salesforce_record_types_allowed-' + index + '"> ' + value + '</label>';
          });
          recordTypesAllowedMarkup += '</div>';
          recordTypeDefaultMarkup += '<label for="salesforce_record_type_default">Default Record Type:</label>';
          recordTypeDefaultMarkup += '<select name="salesforce_record_type_default" id="salesforce_record_type_default"><option value="">- Select record type -</option>';
          $.each(response.data.recordTypeInfos, function (index, value) {
            recordTypeDefaultMarkup += '<option value="' + index + '">' + value + '</option>';
          });
        }
        $('.salesforce_record_types_allowed').html(recordTypesAllowedMarkup);
        $('.salesforce_record_type_default').html(recordTypeDefaultMarkup);
        if (0 < $(response.data.fields).length) {
          dateMarkup += '<label for="pull_trigger_field">Date field to trigger pull:</label>';
          dateMarkup += '<select name="pull_trigger_field" id="pull_trigger_field"><option value="">- Select date field -</option>';
          $.each(response.data.fields, function (index, value) {
            var fieldLabel = '';
            if ('undefined' !== typeof value.label) {
              fieldLabel = value.label;
            } else {
              fieldLabel = value.name;
            }
            dateMarkup += '<option value="' + value.name + '">' + fieldLabel + '</option>';
          });
          dateMarkup += '</select>';
          dateMarkup += '<p class="description">These are date fields that can cause WordPress to pull an update from Salesforce, according to the <code>salesforce_pull</code> class.</p>';
        }
        $('.pull_trigger_field').html(dateMarkup);
        if ('' !== recordTypesAllowedMarkup) {
          $('.salesforce_record_types_allowed').show();
        } else {
          $('.salesforce_record_types_allowed').hide();
        }
        if ('' !== recordTypeDefaultMarkup) {
          $('.salesforce_record_type_default').show();
        } else {
          $('.salesforce_record_type_default').hide();
        }
        if ('' !== dateMarkup) {
          $('.pull_trigger_field').show();
        } else {
          $('.pull_trigger_field').hide();
        }
        if (jQuery.fn.select2) {
          $('select#salesforce_record_type_default').select2();
          $('select#pull_trigger_field').select2();
        }
      });
    }, delayTime);
  });
}

/**
 * When the plugin loads:
 * Manage the display for Salesforce object fields based on API response
 */
$(document).ready(function () {
  // get the available Salesforce object choices
  salesforceObjectFields();
});
"use strict";

/**
 * Fetch the available field list for a WordPress or Salesforce object.
 */
function fetchObjectFields(system, objectName, callback) {
  if ('' === objectName || 'undefined' === typeof objectName) {
    callback([]);
    return;
  }
  var data = {
    'action': 'get_' + system + '_object_fields'
  };
  if ('wordpress' === system) {
    data['wordpress_object'] = objectName;
  } else if ('salesforce' === system) {
    data['salesforce_object'] = objectName;
  } else {
    callback([]);
    return;
  }
  $.ajax({
    type: 'POST',
    url: ajaxurl,
    data: data,
    beforeSend: function () {
      $('.spinner-' + system).addClass('is-active');
    },
    success: function (response) {
      var fields = response && response.data && response.data.fields ? response.data.fields : [];
      callback(fields);
    },
    complete: function () {
      $('.spinner-' + system).removeClass('is-active');
    }
  });
}

/**
 * Build an <option> list markup string for a system's fields.
 */
function buildFieldOptions(system, fields, firstLabel, selectedValue) {
  var html = '<option value="">' + firstLabel + '</option>';
  $.each(fields, function (index, value) {
    var optValue, optLabel;
    if ('wordpress' === system) {
      optValue = value.key;
      optLabel = value.key;
    } else {
      optValue = value.name;
      optLabel = 'undefined' !== typeof value.label ? value.label : value.name;
    }
    var isSelected = selectedValue && optValue === selectedValue ? ' selected="selected"' : '';
    html += '<option value="' + optValue + '"' + isSelected + '>' + optLabel + '</option>';
  });
  return html;
}

/**
 * Populate empty field selects on page load without clobbering existing selections.
 */
function loadFieldOptions(system, objectName) {
  var selectField = '.column-' + system + '_field select';
  if ('' !== $(selectField).val()) {
    return;
  }
  var firstField = $(selectField + ' option').first().text();
  fetchObjectFields(system, objectName, function (fields) {
    $(selectField).html(buildFieldOptions(system, fields, firstField));
  });
}

/**
 * When the object type changes, repopulate every row's select on THAT side with the new
 * object's fields — preserving each row's current selection when the field still exists,
 * blanking it only when it does not. Rows are never removed; the other side is untouched.
 */
function repopulateChangedSide(system, objectName) {
  var selectField = '.column-' + system + '_field select';
  var firstField = $(selectField + ' option').first().text();
  fetchObjectFields(system, objectName, function (fields) {
    var valid = {};
    $.each(fields, function (index, value) {
      var v = 'wordpress' === system ? value.key : value.name;
      valid[v] = true;
    });
    $(selectField).each(function () {
      var $sel = $(this);
      var prev = $sel.val();
      var keep = prev && valid[prev] ? prev : '';
      if (jQuery.fn.select2 && $sel.hasClass('select2-hidden-accessible')) {
        $sel.select2('destroy');
      }
      $sel.html(buildFieldOptions(system, fields, firstField, keep));
      $sel.val(keep);
      if (jQuery.fn.select2) {
        $sel.select2();
      }
    });
    if ('function' === typeof disableAlreadyMappedFields) {
      disableAlreadyMappedFields(system);
    }
  });
}

// Repopulate (preserving matches) when the WordPress object changes.
$(document).on('change', 'select#wordpress_object', function () {
  repopulateChangedSide('wordpress', $(this).val());
});

// Repopulate (preserving matches) when the Salesforce object changes.
$(document).on('change', 'select#salesforce_object', function () {
  repopulateChangedSide('salesforce', $(this).val());
});

/**
 * When the plugin loads:
 * Populate empty field selects for the currently selected WordPress/Salesforce objects.
 */
$(document).ready(function () {
  loadFieldOptions('wordpress', $('select#wordpress_object').val());
  loadFieldOptions('salesforce', $('select#salesforce_object').val());
});
"use strict";

/**
 * Duplicates the fields for a new row in the fieldmap options screen.
 * this appears not to work with data() instead of attr()
 */
function addFieldMappingRow(button) {
  var salesforceObject = $('#salesforce_object').val();
  var wordpressObject = $('#wordpress_object').val();
  var newKey = new Date().getUTCMilliseconds();
  var lastRow = $('table.fields tbody tr').last();
  var oldKey = lastRow.attr('data-key');
  oldKey = new RegExp(oldKey, 'g');
  if ('' !== wordpressObject && '' !== salesforceObject) {
    fieldmapFields(oldKey, newKey, lastRow);
    button.parent().find('.missing-object').remove();
    button.text(button.data('add-more'));
  } else {
    button.text(button.data('add-first'));
    button.parent().prepend('<div class="error missing-object"><span>' + button.data('error-missing-object') + '</span></div>');
  }
  return false;
}

/**
 * Clones the fieldset markup provided by the server-side template and appends it at the end.
 * this appears not to work with data() instead of attr()
 * @param {string} oldKey the data key attribute of the set that is being cloned
 * @param {string} newKey the data key attribute for the one we're appending
 * @param {object} lastRow the last set of the fieldmap
 */
function fieldmapFields(oldKey, newKey, lastRow) {
  var nextRow = '';
  if (jQuery.fn.select2) {
    nextRow = lastRow.find('select').select2('destroy').end().clone(true).removeClass('fieldmap-template');
  } else {
    nextRow = lastRow.find('select').end().clone(true).removeClass('fieldmap-template');
  }
  $(nextRow).attr('data-key', newKey);
  $(nextRow).each(function () {
    $(this).html(function (i, h) {
      return h.replace(oldKey, newKey);
    });
  });
  $('table.fields tbody').append(nextRow);
  if (jQuery.fn.select2) {
    lastRow.find('select').select2();
    nextRow.find('select').select2();
  }
}

// load available options if the WordPress object changes
$(document).on('change', '.column-wordpress_field select', function () {
  disableAlreadyMappedFields('wordpress');
});
// load available options if the Salesforce object changes
$(document).on('change', '.column-salesforce_field select', function () {
  disableAlreadyMappedFields('salesforce');
});

/**
 * Disable fields that are already mapped from being mapped again.
 * @param {string} system whether we want WordPress or Salesforce data
 */
function disableAlreadyMappedFields(system) {
  // load the select statements for Salesforce or WordPress.
  var select = $('.fieldmap-disable-mapped-fields .column-' + system + '_field select');
  var allSelected = [];
  // add each currently selected value to an array, then make it unique.
  select.each(function (i, fieldChoice) {
    var selectedValue = $(fieldChoice).find('option:selected').val();
    if (null !== selectedValue && '' !== selectedValue) {
      allSelected.push(selectedValue);
    }
  });
  allSelected = allSelected.filter((v, i, a) => a.indexOf(v) === i);
  // disable the items that are selected in another select, enable them otherwise.
  $('option', select).removeProp('disabled');
  $('option', select).prop('disabled', false);
  $.each(allSelected, function (key, value) {
    $('option[value=' + value + ']:not(:selected)', select).prop('disabled', true);
  });
  // reinitialize select2 if it's active.
  if (jQuery.fn.select2) {
    $('.column-' + system + '_field select').select2();
  }
}

/**
 * Handle click event for the Add another field mapping button.
 * It duplicates the fields for a new row in the fieldmap options screen.
 */
$(document).on('click', '#add-field-mapping', function () {
  addFieldMappingRow($(this));
});

/**
 * As the Drupal plugin does, we only allow one field to be a prematch
 */
$(document).on('click', '.column-is_prematch input', function () {
  $('.column-is_prematch input').not(this).prop('checked', false);
});

/**
 * As the Drupal plugin does, we only allow one field to be a key
 */
$(document).on('click', '.column-is_key input', function () {
  $('.column-is_key input').not(this).prop('checked', false);
});

/**
 * When the plugin loads:
 * Disable fields that are already selected
 * Select2 on select fields
 */
$(document).ready(function () {
  // disable the option values for fields that have already been mapped.
  disableAlreadyMappedFields('salesforce');
  disableAlreadyMappedFields('wordpress');

  // setup the select2 fields if the library is present
  if (jQuery.fn.select2) {
    $('select#wordpress_object').select2();
    $('select#salesforce_object').select2();
    $('select#salesforce_record_type_default').select2();
    $('select#pull_trigger_field').select2();
    $('.column-wordpress_field select').select2();
    $('.column-salesforce_field select').select2();
  }
});
"use strict";

/**
 * Handle manual push of objects to Salesforce
 */
function pushObjects() {
  $('.salesforce_user_ajax_message').hide();
  if (0 < $('#wordpress_object_ajax').length) {
    $('.push_to_salesforce_button').on('click', function () {
      var wordpressObject = $('#wordpress_object_ajax').val();
      var wordpressId = $('#wordpress_id_ajax').val();
      var salesforceId = $('#salesforce_id_ajax').val();
      var data = {
        'action': 'push_to_salesforce',
        'wordpress_object': wordpressObject,
        'wordpress_id': wordpressId,
        'salesforce_id': salesforceId
      };
      $.post(ajaxurl, data, function (response) {
        if (true === response.success) {
          updateSalesforceUserSummary();
          $('.salesforce_user_ajax_message').width($('.mapped-salesforce-user').width() - 27);
          $('.salesforce_user_ajax_message').html('<p>This object has been pushed to Salesforce.</p>').fadeIn().delay(4000).fadeOut();
        }
      });
      return false;
    });
  }
}

/**
 * Handle manual pull of objects from Salesforce
 */
function pullObjects() {
  $('.salesforce_user_ajax_message').hide();
  $('.pull_from_salesforce_button').on('click', function () {
    var salesforceId = $('#salesforce_id_ajax').val();
    var wordpressObject = $('#wordpress_object_ajax').val();
    var data = {
      'action': 'pull_from_salesforce',
      'salesforce_id': salesforceId,
      'wordpress_object': wordpressObject
    };
    $.post(ajaxurl, data, function (response) {
      if (true === response.success) {
        updateSalesforceUserSummary();
        $('.salesforce_user_ajax_message').width($('.mapped-salesforce-user').width() - 27);
        $('.salesforce_user_ajax_message').html('<p>This object has been pulled from Salesforce.</p>').fadeIn().delay(4000).fadeOut();
      }
    });
    return false;
  });
}

/**
 * Updates the user profile summary of Salesforce info.
 */
function updateSalesforceUserSummary() {
  var mappingId = $('#mapping_id_ajax').val();
  var data = {
    'action': 'refresh_mapped_data',
    'mapping_id': mappingId
  };
  $.post(ajaxurl, data, function (response) {
    if (true === response.success) {
      $('td.last_sync_message').text(response.data.last_sync_message);
      $('td.last_sync_action').text(response.data.last_sync_action);
      $('td.last_sync_status').text(response.data.last_sync_status);
      $('td.last_sync').text(response.data.last_sync);
      if ('1' === response.data.last_sync_status) {
        $('td.last_sync_status').text('success');
      } else {
        $('td.last_sync_status').text('error');
      }
    }
  });
}

/**
 * Clear the plugin cache via Ajax request.
 */
function clearSfwpCacheLink() {
  $('#clear-sfwp-cache').click(function () {
    var data = {
      'action': 'clear_sfwp_cache'
    };
    var that = $(this);
    $.post(ajaxurl, data, function (response) {
      if (true === response.success && true === response.data.success) {
        that.parent().html(response.data.message).fadeIn();
      }
    });
    return false;
  });
}

/**
 * Delete the deprecated value for Salesforce REST API version from the options table.
 */
function deleteRestApiVersionOption() {
  $('#salesforce-delete-rest-api-version').click(function () {
    var data = {
      'action': 'delete_salesforce_api_version'
    };
    var that = $(this);
    $.post(ajaxurl, data, function (response) {
      if (true === response.success && true === response.data.success) {
        that.parent().html(response.data.message).fadeIn();
      } else {
        that.parent().html(response.data.message).fadeIn();
      }
    });
    return false;
  });
}

/**
 * When the plugin loads:
 * Clear plugin cache button
 * Manual push and pull
 */
$(document).ready(function () {
  // Clear the plugin cache via Ajax request.
  clearSfwpCacheLink();

  // delete legacy option value.
  deleteRestApiVersionOption();

  // Handle manual push and pull of objects
  pushObjects();

  // Handle manual pull of objects
  pullObjects();
});
"use strict";

/**
 * Don't show the WSDL file field unless SOAP is enabled
 */
function toggleSoapFields() {
  if (0 < $('.object-sync-for-salesforce-enable-soap').length) {
    if ($('.object-sync-for-salesforce-enable-soap input').is(':checked')) {
      $('.object-sync-for-salesforce-soap-wsdl-path').show();
    } else {
      $('.object-sync-for-salesforce-soap-wsdl-path').hide();
    }
  }
}

// Don't show the WSDL file field unless SOAP is enabled
$(document).on('change', '.object-sync-for-salesforce-enable-soap input', function () {
  toggleSoapFields();
});
$(document).ready(function () {
  // Don't show the WSDL file field unless SOAP is enabled
  toggleSoapFields();
});

}(jQuery));
