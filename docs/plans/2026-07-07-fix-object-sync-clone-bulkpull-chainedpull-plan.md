---
title: "Fix Object Sync clone, bulk pull object-type resolution, and chained pull ACF writes"
type: fix
status: completed
date: 2026-07-07
origin: docs/brainstorms/2026-07-07-object-sync-fixes-brainstorm.md
---

# 🐛 Fix Object Sync: Clone, Bulk Pull & Chained Pull

Three targeted bug fixes to the Carkeek fork of Object Sync for Salesforce. All root
causes are confirmed in code. Scope = **all three** (see brainstorm:
`docs/brainstorms/2026-07-07-object-sync-fixes-brainstorm.md`).

## Overview

| # | Area | Symptom | Root cause (confirmed) |
|---|------|---------|------------------------|
| 1a | Fieldmap clone (JS) | Changing WP/SF object wipes **all** mapped rows | `02-load-field-options.js` unconditionally removes every row on `change` |
| 1b | Fieldmap clone (PHP) | Saved clone silently disappears | Clone pre-fills identical `label` → `name` collides with `UNIQUE KEY name` → insert fails → error redirect lacks `id` → bounces to list |
| 2 | Bulk pull | Wrong SF object type (Job IDs pulled as Campaign) | `resolve_sf_type()` returns `fieldmaps[0]` when 2+ SF objects share one post type |
| 3 | Chained/relational pull | ACF relationship link doesn't stick | ACF value + reference meta written incorrectly; must pass the ACF field **key** to `update_field()` |

## Problem Statement / Motivation

This site maps two Salesforce objects (Campaign + Job) to the same WordPress post type and
relies on cloning fieldmaps and chained relational pulls. Each of the three bugs blocks a
real workflow: cloning is unusable (fields wiped, saves lost), bulk pull mis-types records,
and chained pulls don't populate ACF relationships.

---

## Issue 1a — Preserve fields on object-type change (JS)

**File:** `assets/js/src/02-load-field-options.js` (source) → rebuild
`assets/js/object-sync-for-salesforce-admin.js` + `.min.js`.

**Current behavior (lines 55–74):** on `change` of `#wordpress_object` or
`#salesforce_object`, after a 1s timeout:
```js
$( 'table.fields tbody tr' ).not( '.fieldmap-template' ).remove();
```
Every mapped row is destroyed regardless of whether the field still exists on the new object.

**Desired behavior (see brainstorm decision #2):**
- On change of a given side (WordPress **or** Salesforce), fetch the new object's field
  list (already available via the existing `get_<system>_object_fields` AJAX call).
- For **each existing row's `<select>` on the changed side**: rebuild its options from the
  new field list; if the previously-selected value still exists → **re-select it**; if not
  → reset that select to blank (empty option). **Do not remove the row.**
- The **unchanged side's** selections are left untouched.
- Re-initialize `select2` on rebuilt selects and preserve the current selection.

**Implementation notes:**
- `loadFieldOptions()` currently early-returns when any select already has a value
  (`if ( '' !== $( selectField ).val() ) { return; }`). This preserves selections on page
  load but prevents re-population on change. Refactor so:
  - **On load** (document.ready): keep current preserve-existing behavior.
  - **On change**: force re-population across all rows on the changed side, applying the
    keep-if-exists / blank-if-missing rule per select.
- Remove the destructive `fadeOut()` + `remove()` timeout block for both the
  `#wordpress_object` and `#salesforce_object` change handlers.
- Build option markup once per change (from the AJAX response), then iterate rows.

### Pseudocode — `assets/js/src/02-load-field-options.js`
```js
function repopulateChangedSide( system, objectName ) {
  // system = 'wordpress' | 'salesforce'
  var data = { action: 'get_' + system + '_object_fields' };
  data[ system + '_object' ] = objectName;
  $.post( ajaxurl, data, function ( response ) {
    var valueKey = ( 'wordpress' === system ) ? 'key' : 'name';
    var available = {};                     // name/key => label
    $.each( response.data.fields, function ( i, f ) {
      available[ f[ valueKey ] ] = ( f.label || f.name || f.key );
    } );
    $( '.column-' + system + '_field select' ).each( function () {
      var $sel = $( this );
      var prev = $sel.val();
      $sel.html( buildOptions( available, prev ) );      // keep prev if in available, else blank
      if ( ! available[ prev ] ) { $sel.val( '' ); }
      if ( jQuery.fn.select2 ) { $sel.select2(); }
    } );
  } );
}

$( document ).on( 'change', 'select#wordpress_object', function () {
  repopulateChangedSide( 'wordpress', $( this ).val() );   // no row removal
} );
$( document ).on( 'change', 'select#salesforce_object', function () {
  repopulateChangedSide( 'salesforce', $( this ).val() );  // no row removal
} );
```

**Build step (required):** run the project's Gulp scripts task
(`gulp` → `adminscripts` + `uglifyscripts`) to regenerate both
`object-sync-for-salesforce-admin.js` and `object-sync-for-salesforce-admin.min.js`
(the plugin enqueues the `.min.js`). If Gulp is unavailable locally, hand-apply the same
change to the concatenated `.js` and `.min.js`.

---

## Issue 1b — Cloned fieldmap must not silently disappear (PHP)

**Files:** `classes/class-object-sync-sf-admin.php`, `templates/admin/fieldmaps-add-edit-clone.php`.

**Root cause chain:**
1. Clone screen sets `$label = $map['label']` (admin ~line 690) — identical to original.
2. On save, `setup_fieldmap_data()` sets `name = sanitize_title( label )`; table has
   `UNIQUE KEY name (name)` → `$wpdb->insert` fails → `create_fieldmap()` returns `false`.
3. Error redirect goes to `...&method=clone` **without `id`**; the reload guard
   (`admin.php:688`) requires `isset($map['id'])`, so it falls through to the
   `missing_fieldmap` list redirect. No error shown; clone gone.

**Desired behavior (see brainstorm decision #3):**
- A clone saves successfully **by default** without manual renaming.
- If a genuine name collision remains, the user gets a **clear error** and keeps their
  in-progress mapping (never a silent bounce to the list).

**Implementation notes:**
- **Distinct default label for clones:** when rendering `method === 'clone'`, set the
  default label to a unique variant, e.g. `"<original> (copy)"`; if that name already
  exists, increment (`"<original> (copy 2)"`, …). Check against existing `name` values via
  `get_fieldmaps()` so the pre-filled default is collision-free.
- **Harden the save/error path:** in `prepare_fieldmap_data()`, when
  `create_fieldmap()` returns `false`, detect the duplicate-name case and pass a specific
  error message; ensure the error transient round-trips so the clone form re-renders with
  the submitted data.
- **Fix the clone error-recovery render:** in `admin.php` (~line 688), allow the clone form
  to render from a transient even without an `id` (treat `clone`-with-transient like `add`),
  so an insert failure returns the user to their populated form + error instead of the list.

### Pseudocode — `classes/class-object-sync-sf-admin.php`
```php
// When rendering the clone screen:
if ( 'clone' === $method && ! isset( $posted ) ) {
    $label = $this->unique_fieldmap_label( $map['label'] . ' (copy)' ); // dedupe against existing names
}

// Render guard (~line 688): also render clone from transient without an id
if ( 'add' === $method
    || ( 'clone' === $method && isset( $posted ) && is_array( $posted ) )
    || ( isset( $map['id'] ) ) ) {
    // ...require fieldmaps-add-edit-clone.php
}

// prepare_fieldmap_data(): surface the real failure
if ( false === $result ) {
    $this->sfwp_transients->set( $cachekey, $post_data, ... );
    $url = esc_url_raw( $post_data['redirect_url_error'] )
         . '&transient=' . $cachekey
         . '&fieldmap_error=duplicate_name'; // drives an admin notice
}
```

---

## Issue 2 — Bulk pull resolves the correct Salesforce object type

**Files:** `templates/admin/bulk-sync.php`, `assets/js/bulk-sync.js`,
`classes/class-object-sync-sf-bulk-sync.php`.

**Root cause:** `resolve_sf_type()` (bulk-sync.php) returns `$fieldmaps[0]['salesforce_object']`
when the SF ID's post type has multiple fieldmaps → Campaign always wins over Job.

**Chosen approach (see brainstorm decision #4): object-type selector (primary) + key-prefix
resolution (auto/fallback).**

### 2a. Object-type selector in the Bulk Pull UI (primary)
- Add a `<select id="csfbs-bulk-pull-object">` to Section 1 of `bulk-sync.php`, populated
  from distinct `salesforce_object` values across configured fieldmaps
  (`$this->mappings->get_fieldmaps()`), plus an **"Auto-detect"** option (`value=""`).
- Default to a required "— Select object type —" prompt (or Auto-detect) so nothing pulls
  by accident.
- `bulk-sync.js` `doBulkPull()` / `doSfPull()` includes the chosen `salesforce_object` in
  the POST body.
- `bulk_pull()` reads `$_POST['salesforce_object']`; `process_pull()` accepts an optional
  explicit type. When provided **and valid** (matches a configured fieldmap's
  `salesforce_object`), use it directly and skip inference.

### 2b. Key-prefix resolution for `resolve_sf_type()` (auto / fallback)
- Every SF ID begins with a 3-char key prefix unique per object type. Build a
  `prefix → salesforce_object` map from configured fieldmaps' describe metadata
  (`$sfapi->object_describe( $type )['data']['keyPrefix']`, cached), and resolve each ID by
  `substr( $sf_id, 0, 3 )` instead of `fieldmaps[0]`.
- This also fixes the **Re-Pull** (`osf_resync_pull`) path, which shares `resolve_sf_type()`
  and can't disambiguate from the object map alone (the object map stores no SF type).
- Unresolvable prefix → per-ID error row: `"No matching Salesforce object type for this ID."`
  (batch continues — brainstorm resolved-question).

### Pseudocode — `classes/class-object-sync-sf-bulk-sync.php`
```php
public function process_pull( string $sf_id, string $explicit_type = '' ): array {
    // ...validate id format...
    if ( '' !== $explicit_type && $this->is_configured_sf_type( $explicit_type ) ) {
        $sf_type = $explicit_type;                 // selector wins
    } else {
        $info    = $this->resolve_sf_type( $sf_id, $osf ); // now prefix-based
        $sf_type = $info ? $info['sf_type'] : '';
    }
    if ( '' === $sf_type ) {
        return $this->error_row( $sf_id, 'No matching Salesforce object type for this ID.' );
    }
    // ...manual_pull( $sf_type, $sf_id )...
}

private function prefix_map( $osf ): array {           // '701' => 'Campaign', 'a0X' => 'Job__c'
    $map = array();
    foreach ( $osf->mappings->get_fieldmaps() as $fm ) {
        $type   = $fm['salesforce_object'];
        $describe = $osf->salesforce['sfapi']->object_describe( $type ); // cached
        $prefix = $describe['data']['keyPrefix'] ?? '';
        if ( $prefix ) { $map[ $prefix ] = $type; }
    }
    return $map;
}
```

**Note:** 2b is recommended (fixes Re-Pull too); if scope must shrink, 2a alone covers the
stated single-type Bulk Pull workflow and 2b can be deferred (brainstorm open question).

---

## Issue 3 — Chained pull writes ACF relationship fields correctly

**File:** `classes/class-object-sync-sf-relational-sync.php` (`process_rule()`).

**Root cause (reported):** the relationship write set both the value meta (`field_name`)
and the ACF reference meta (`_field_name`) to the same value, so ACF can't resolve the
field reference and the relationship doesn't read back.

**Fix (confirmed with user):** ACF's `update_field( $selector, $value, $post_id )` writes
`_field_name` correctly **only when `$selector` is the field key** (e.g. `field_abc123`),
not the field name. Ensure the relational rule supplies/stores the field **key** and pass
that key to `update_field()`. If a rule holds a field name, resolve it to its key first.

**Link mode:** **Replace / overwrite** (brainstorm decision #6) — set the field to exactly
the related post(s) for that rule, clearing prior values (current `array( $related_wp_id )`
behavior is correct).

### Pseudocode — `class-object-sync-sf-relational-sync.php`
```php
if ( $related_wp_id && function_exists( 'update_field' ) ) {
    $field_key = $acf_field;
    if ( function_exists( 'acf_get_field' ) && 0 !== strpos( (string) $acf_field, 'field_' ) ) {
        $field_obj = acf_get_field( $acf_field );          // resolve name → key
        if ( $field_obj && ! empty( $field_obj['key'] ) ) {
            $field_key = $field_obj['key'];
        }
    }
    update_field( $field_key, array( $related_wp_id ), $wp_id ); // key ensures _field_name is correct
}
```
**Consideration:** storing the ACF **key** directly in `relational_rules` at save time (in
the fieldmap admin UI) would make this robust without per-write resolution. Optional
follow-up; the resolve-at-write approach above fixes the bug without a schema/UI change.

---

## System-Wide Impact

- **Shared admin template (1a/1b):** `fieldmaps-add-edit-clone.php` and the admin JS are
  shared by **add / edit / clone**. Verify the new preserve-on-change logic doesn't break
  the add flow (empty rows) or the edit flow (existing selections preserved on load).
- **`resolve_sf_type()` reuse (2):** used by both `osf_bulk_pull` and `osf_resync_pull`.
  The key-prefix fix corrects both; the selector only exists on the Bulk Pull section.
- **Chained pull under bulk pull (3):** bulk pull fires `pull_success` per record, so
  `Object_Sync_Sf_Relational_Sync` runs many times in a batch. The re-entrancy guard
  (`$syncing_ids`) already prevents recursion; the ACF-key fix is per-record and safe.
- **Explicit type override (2a):** if an operator picks a type that's wrong for some IDs in
  the batch, those pulls fail per-row (SF returns "not found"). Acceptable given the
  single-type workflow; validate the chosen type is a configured SF object before use.
- **Error propagation:** all failures surface as per-row results in the bulk-pull table or
  as an admin notice on the clone form — no silent swallowing.

---

## Acceptance Criteria

### Issue 1a — preserve fields on object change
- [x] Changing the WordPress object keeps rows whose WP field exists on the new post type
      (selection preserved) and blanks only the WP select for fields that don't exist.
- [x] Changing the Salesforce object behaves symmetrically on the SF side.
- [x] The unchanged side's selections are never altered by a change to the other side.
- [x] No mapped rows are removed on object change; `select2` re-initializes with the right
      selection (destroy → swap options → re-init).
- [x] Add and edit fieldmap flows still behave correctly (`loadFieldOptions` load path kept).
- [x] `object-sync-for-salesforce-admin.js` + `.min.js` rebuilt (bundle hand-edited in the
      02 region; `.min.js` regenerated with terser). *Run project Gulp before release to
      restore babel/sourcemap parity.*

### Issue 1b — clone save
- [x] Cloning pre-fills a **unique** default label (`"<name> (copy)"`, incremented if taken).
- [x] Saving a clone creates a new fieldmap that appears in the list.
- [x] A genuine duplicate-name save shows a clear error and re-renders the clone form with
      the user's label/object selections intact (no bounce to the list, no silent loss).
      *(Note: field-row rows are not repopulated on the rare error re-render — a pre-existing
      transient-recovery limitation; the unique default label prevents the collision in the
      first place.)*

### Issue 2 — bulk pull object type
- [x] Bulk Pull has an object-type selector populated from configured SF objects (+ Auto-detect).
- [x] With a type selected, every ID in the batch pulls as that type (Job IDs pull as Job).
- [x] `resolve_sf_type()` disambiguates by SF ID key-prefix (Campaign vs Job on the same
      post type), fixing Bulk Pull **and** Re-Pull. *(reuses `sfapi->get_sobject_type()`)*
- [x] Unresolvable IDs report a clear per-row error; the batch continues.

### Issue 3 — chained pull ACF write
- [x] After a chained pull, the child post's ACF relationship field reads back the related
      post via `get_field()` (value meta + `_field_name` = field key).
- [x] Works whether the rule stores an ACF field name or key.
- [x] Existing relationship values are replaced (overwrite mode), consistent with the decision.

### Quality gates
- [x] `php -l` clean on all changed PHP; `node --check` clean on all 3 JS bundles.
- [x] `phpcs` introduces no new violations in changed code (pre-existing fork style
      debt left untouched to keep the diff focused).
- [ ] Manual verification of each flow on the test site
      (`salesforce-for-volunteers-testing`) with real Campaign/Job records — **pending**
      (needs the live Salesforce connection; cannot be driven from the coding session).

---

## Dependencies & Risks

- **Gulp toolchain** must be runnable to regenerate the admin JS (1a). If not, hand-edit
  the concatenated + min files — risk of the min file drifting from source.
- **`object_describe` keyPrefix** (2b) depends on SF API describe data and its cache;
  confirm the cached shape exposes `keyPrefix`. Low risk (standard SF describe field).
- **ACF assumptions (3):** relies on ACF being active and `acf_get_field()` availability;
  existing `function_exists()` guards remain.
- **Shared JS regression (1a):** the change touches the add/edit/clone screen used broadly;
  test all three flows.

## Success Metrics

- Cloning a fieldmap to a different post type retains matching field mappings; the clone
  saves and persists.
- A bulk pull of Job IDs creates Job-mapped posts (0 mis-typed "object does not exist"
  errors).
- Chained pulls populate ACF relationship fields that render on the front end / in the
  editor.

---

## Sources & References

### Origin
- **Brainstorm:** [`docs/brainstorms/2026-07-07-object-sync-fixes-brainstorm.md`](../brainstorms/2026-07-07-object-sync-fixes-brainstorm.md)
  — carried-forward decisions: (1) all three fixes in scope; (2) preserve-matching-fields on
  object change; (3) distinct clone label + surfaced save errors; (4) object-type selector
  + key-prefix resolution; (5) pass ACF field **key** to `update_field()`; (6) replace mode.

### Internal references
- `assets/js/src/02-load-field-options.js:55-74` — destructive row removal (1a)
- `classes/class-object-sync-sf-admin.php:688-712` — clone render guard (1b)
- `classes/class-object-sync-sf-admin.php:1882-1914` — `prepare_fieldmap_data()` save/redirect (1b)
- `classes/class-object-sync-sf-mapping.php:424-435,605-611` — `create_fieldmap()` / `name` derivation (1b)
- `classes/class-object-sync-sf-activate.php:166` — `UNIQUE KEY name (name)` (1b)
- `classes/class-object-sync-sf-bulk-sync.php:76-137` — `resolve_sf_type()` (2)
- `templates/admin/bulk-sync.php:15-56` — Bulk Pull section (2a)
- `assets/js/bulk-sync.js:134-260` — `doBulkPull()` / `doSfPull()` (2a)
- `classes/class-object-sync-sf-salesforce-pull.php:1193-1217` — `manual_pull()` contract (2)
- `classes/class-object-sync-sf-relational-sync.php:128-168` — `process_rule()` ACF write (3)

### Open questions (from brainstorm)
- Include key-prefix **Auto-detect** now, or defer and ship the selector only? Recommended
  to include 2b because Re-Pull needs it; final call at implementation time.

## Next Step
Run `/ce:work docs/plans/2026-07-07-fix-object-sync-clone-bulkpull-chainedpull-plan.md` to
implement, or `/technical_review` for a code-focused review of this plan first.
