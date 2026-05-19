---
title: "fix: Chained sync silently skipped during bulk pull of new records"
type: fix
status: completed
date: 2026-05-19
deepened: 2026-05-19
---

# fix: Chained sync silently skipped during bulk pull of new records

## Enhancement Summary

**Deepened on:** 2026-05-19
**Research agents used:** security-sentinel, performance-oracle, code-simplicity-reviewer, architecture-strategist, best-practices-researcher, pattern-recognition-specialist, spec-flow-analyzer

### Key Improvements from Research

1. **Confirmed implementation style:** Drop the `if (0 !== $wordpress_id)` guard from the proposed fix per the simplicity reviewer — BUT the pattern reviewer confirmed that exact guard is used at lines 1650 and 1878 of the same function, and the push class uses the same write-back pattern (`$synced_object['mapping_object'] = $mapping_object`). Both are valid; the guard is kept for codebase consistency.
2. **Silent failure must be logged:** `handle_pull_success` currently returns silently when `$wp_id` is 0. After the fix lands, this guard should emit a debug log so future regressions are diagnosable.
3. **Performance hazard with bulk relational pulls:** Synchronous Salesforce API calls for each related object will exceed PHP `max_execution_time` at ~20+ records with unsynced related objects. Action Scheduler deferral is the required long-term solution for the bulk path.
4. **Security hardening for bulk endpoint:** Two medium-severity gaps discovered — missing SF ID format validation and no batch size cap.
5. **Stored XSS in VEM plugin (pre-existing, surfaced by review):** `GW_Volunteers__Description__c` written to `post_content` without `wp_kses_post()`.
6. **9 additional acceptance criteria discovered** covering edge cases the original plan missed.
7. **Recursion guard is depth-one by design** — related records' own relational rules are suppressed. This is intentional but undocumented.

### New Considerations Discovered

- `VEMgmt_Object_Sync::sf_pull_success()` reads `$result['parent']` not `$synced_object['mapping_object']['wordpress_id']` — it is coincidentally unaffected by the bug. That pattern is not a contract; it should not be relied upon by future consumers.
- The `$reset = true` parameter in `load_object_maps_by_salesforce_id()` is dead code — `get_all_object_maps()` always queries the DB directly and ignores the reset flag.
- Three separate consumers of `pull_success` exist: `Object_Sync_Sf_Relational_Sync`, `VEMgmt_Object_Sync`, and `VEMgmt_Rest_Api`.

---

## Overview

When the bulk sync tool pulls Salesforce records that do not yet exist in WordPress (a create operation), the chained/relational sync fires but immediately returns without processing any relational rules. The result: related parent objects are never pulled and ACF relationship fields are never populated. The bug is silent — no error is logged.

Bulk sync of records that **already exist** in WordPress (updates) chains correctly. This is why the issue is specific to bulk sync: bulk sync's primary use case is initial population of new records.

**Note:** The same timing bug exists on the scheduled queue path for new records (same `create_called_from_salesforce` code path). Fixing the root cause fixes both paths.

---

## Problem Statement

`Object_Sync_Sf_Relational_Sync::handle_pull_success()` receives a `$synced_object` array with an empty or zero `wordpress_id` in its `mapping_object` key whenever a Salesforce record is being **created** in WordPress for the first time. The guard at line 64 of `class-object-sync-sf-relational-sync.php` performs an early return:

```php
// class-object-sync-sf-relational-sync.php:63-64
$wp_id = isset( $map_object['wordpress_id'] ) ? (int) $map_object['wordpress_id'] : 0;
if ( ! $wp_id ) {
    return; // silently aborts — no chaining happens
}
```

Because the temporary ID string (`pull_<hash>`) cast to `int` evaluates to `0`, even the temporary mapping row fails this check.

---

## Root Cause

**File:** `classes/class-object-sync-sf-salesforce-pull.php`

The sequence inside `create_called_from_salesforce()`:

1. **Line 1507** (call site): `$synced_object` is built via `get_synced_object()` *before* `create_called_from_salesforce` is entered. At this point `$mapping_object` is either empty (fresh record) or points to the old DB row — no real `wordpress_id` yet.

2. **Line 1756**: Inside `create_called_from_salesforce`, a temporary object map row is created with `generate_temporary_id('pull')` as the `wordpress_id` placeholder. The local `$mapping_object` variable is updated (lines 1757–1763), but `$synced_object['mapping_object']` is **not** updated — PHP array assignment is by value.

3. **Lines 1774/1780**: WordPress record is created. The real `$wordpress_id` is extracted from `$result['data']` at lines 1788–1791.

4. **Line 1826**: `do_action('pull_success', $op, $result, $synced_object)` fires. At this moment, `$synced_object['mapping_object']['wordpress_id']` is still `0` or the temporary string. The real `$wordpress_id` exists only as a local variable.

5. **Lines 1878–1882**: *After* the action fires, `$mapping_object['wordpress_id'] = $wordpress_id` is set and `update_object_map()` persists it to the DB.

**Why updates work:** `update_called_from_salesforce()` receives a `$synced_object` whose `mapping_object` was loaded from the DB *before* the function is entered (line 1503). It already contains the correct `wordpress_id`. The action at line 1976 fires with accurate data.

**PHP array semantics note (confirmed by research):** WordPress `do_action()` passes all arguments by value. Arrays are copy-on-write. Modifying a local array variable after it has been captured into `$synced_object` has no effect on the snapshot already held in `$synced_object`. This is the canonical WordPress gotcha with array-based hook payloads — the array must be sealed with correct data *before* `do_action()` is called.

**The push class already gets this right:** `salesforce_push_object_crud()` explicitly does `$synced_object['mapping_object'] = $mapping_object` before firing `push_success` and `push_fail` (lines 1214, 1275, 1324 of the push class). The pull create path is missing this write-back.

---

## Proposed Solution

### Part 1 — The Correctness Fix (Required, 2 lines)

In `create_called_from_salesforce()`, between the `$mapping_object` status setup block (line 1823) and the existing `// hook for pull success.` comment (line 1825), add:

```php
// save the wordpress id to the mapping object in the synced object.
if ( 0 !== $wordpress_id ) {
    $synced_object['mapping_object']['wordpress_id'] = $wordpress_id;
}
// hook for pull success.
do_action( $this->option_prefix . 'pull_success', $op, $result, $synced_object );
```

**Style notes (from pattern review):**
- The `if ( 0 !== $wordpress_id )` Yoda guard is the codebase's established pattern: used identically at lines 1650 and 1878 of this same function.
- The comment `// save the wordpress id to the mapping object in the synced object.` matches the codebase's convention of naming what the block does before the code (all lowercase, no period at end of non-sentence labels).
- The push class sets this at lines 1214, 1275, and 1324 — this fix brings the pull create path into parity.

That is the complete code change for correctness. No other files need modification.

### Part 2 — Add Debug Logging to the Early-Return Guard (Recommended)

The `handle_pull_success` early return should log at debug level so future regressions are diagnosable. WordPress best practice: wrap in `WP_DEBUG` gate, use `error_log()` with PHPCS ignore comment:

```php
// class-object-sync-sf-relational-sync.php:63-66
$wp_id = isset( $map_object['wordpress_id'] ) ? (int) $map_object['wordpress_id'] : 0;
if ( ! $wp_id ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log( sprintf(
            '[OSF Relational Sync] handle_pull_success: skipping — wordpress_id is 0 for sf_id %s.',
            esc_attr( $sf_id )
        ) );
    }
    return;
}
```

### Part 3 — Recursion Guard: Use try/finally (Recommended)

The current guard adds the SF ID to `self::$syncing_ids` but only clears it on the normal path. If an uncaught exception occurs inside the rule loop, the ID remains in `$syncing_ids` permanently for the request, silently preventing reprocessing. Wrap in `try/finally`:

```php
// class-object-sync-sf-relational-sync.php — in handle_pull_success
self::$syncing_ids[ $sf_id ] = true;
try {
    foreach ( $relational_rules['rules'] as $rule ) {
        // ...
    }
    // extra rules via filter...
} finally {
    unset( self::$syncing_ids[ $sf_id ] );
}
```

### Part 4 — Security: SF ID Validation and Batch Cap (Recommended)

In `class-object-sync-sf-bulk-sync.php`:

```php
// In process_pull(), after sanitize_text_field, before resolve_sf_type:
if ( ! preg_match( '/^[a-zA-Z0-9]{15}([a-zA-Z0-9]{3})?$/', $sf_id ) ) {
    return array(
        'sf_id'   => $sf_id,
        'sf_type' => '',
        'status'  => 'error',
        'message' => __( 'Invalid Salesforce ID format.', 'object-sync-for-salesforce' ),
    );
}

// In bulk_pull(), after parse_ids_from_post, before the foreach:
if ( count( $sf_ids ) > 200 ) {
    $sf_ids = array_slice( $sf_ids, 0, 200 );
    // optionally add a note to the response that truncation occurred
}
```

### Part 5 — Performance: Action Scheduler Deferral for Bulk Path (Future Work)

**Critical context:** Synchronous chained Salesforce API calls during bulk sync will exceed PHP `max_execution_time` at approximately 20+ primary records that each have unsynced related objects (each related pull requires a blocking Salesforce REST API call, ~150–400ms each). For 50 records × 2 rules = 100 extra SF calls = ~30s of HTTP wait alone, before DB writes.

The fix in Part 1 enables chaining to work correctly. For small bulk operations (<20 records, or when related objects are already in WP), this is acceptable. For large initial population runs, a defer mode using Action Scheduler is needed:

```php
// In Object_Sync_Sf_Relational_Sync
private static $defer_mode = false;

public static function set_defer_mode( bool $mode ): void {
    self::$defer_mode = $mode;
}

// In handle_pull_success, when defer_mode is true:
// Enqueue an AS action instead of processing inline.
$osf->queue->add(
    'object_sync_for_salesforce_process_relational_rules',
    array( 'sf_id' => $sf_id, 'wp_id' => $wp_id, 'mapping_id' => $mapping['id'] ),
    'relational-sync'
);
return;
```

Set `defer_mode = true` in `Object_Sync_Sf_Bulk_Sync::process_pull()` around the `manual_pull()` call. Action Scheduler is already a dependency of this plugin.

**Short-term mitigation** (if AS deferral work is deferred): add to `bulk_pull()`:
```php
set_time_limit( 0 );
ignore_user_abort( true );
```

---

## Technical Considerations

- **No side effects on existing behavior:** The three registered consumers of `pull_success` are `Object_Sync_Sf_Relational_Sync::handle_pull_success`, `VEMgmt_Object_Sync::sf_pull_success`, and `VEMgmt_Rest_Api::maybe_bust_cache_on_pull`. Of these, only the first reads `$synced_object['mapping_object']['wordpress_id']`. The second reads `$result['parent']` (coincidentally correct but not the hook contract). The third reads only `$synced_object['mapping']['wordpress_object']`. None are negatively affected.
- **Recursion depth:** `self::$syncing_ids` creates a depth-one limit — a related record pulled by a rule will not have *its own* relational rules processed. This is intentional but undocumented. Document in the class docblock.
- **`$reset = true` is dead code:** `load_object_maps_by_salesforce_id($sf_id, array(), true)` at line 149 of the relational sync class passes `$reset = true` but `get_all_object_maps()` always queries the DB directly without caching, so the flag has no effect. Not a bug, but misleading code.
- **Timing invariant after fix:** `$synced_object['mapping_object']['wordpress_id']` will be non-zero when `pull_success` fires on any successful create or upsert. The real WP ID is already resolved at line 1789; the fix simply writes it back into the snapshot before the hook fires.

---

## System-Wide Impact

- **Interaction graph:** `do_action('pull_success')` → `handle_pull_success()` → `process_rule()` → (if related object not in WP) `$osf->pull->manual_pull()` → `create_called_from_salesforce()` → `do_action('pull_success')` (nested, guarded by `self::$syncing_ids`). The fix applies at every level of this chain since it's in the shared creation path.
- **State lifecycle:** The object map row has its real `wordpress_id` written at line 1882, *after* the fix fires. `process_rule()` queries the DB with `$reset = true` after its nested `manual_pull` returns, so it always finds the real WP ID for the related object. No race condition within a single request.
- **Concurrent requests:** `self::$syncing_ids` is per-process in-memory state. Parallel AJAX requests from two browser windows processing the same SF ID run in separate PHP-FPM workers with independent guard arrays. Both can proceed to create the same WP post. This is a pre-existing limitation, not introduced by this fix. The existing `generate_temporary_id` + upsert logic provides partial protection; the prematch/upsert feature of OSF fieldmaps is the correct mitigation.
- **Error propagation:** If WP creation fails (`$wordpress_id === 0`), the `if ( 0 !== $wordpress_id )` guard prevents writing a false zero, and `pull_fail` fires instead of `pull_success` — the relational sync handler is not called.
- **No DB schema changes:** Pure PHP logic fix.

---

## Acceptance Criteria

### Original Criteria

- [ ] Bulk pull a Salesforce record that does not yet exist in WordPress, where the fieldmap has relational rules configured → related parent object is pulled and ACF relationship field is populated on the primary record.
- [ ] Bulk pull a record that already exists in WordPress → behavior unchanged (updates + chaining continue to work).
- [ ] Chained pull of a related object that is itself new → ACF field on the grandchild is set (recursive chain works at depth-one).
- [ ] Bulk pull a record with no relational rules → behavior unchanged.
- [ ] No PHP errors or log warnings introduced.

### New Criteria from Research

- [ ] **AC-7 — `$synced_object` integrity:** At the moment `pull_success` fires for any new record create, `$synced_object['mapping_object']['wordpress_id']` equals the real WP post ID (not zero, not the temporary `pull_<hash>` string).
- [ ] **AC-8 — Queue-based scheduled pull of new records:** A new SF record processed through the Action Scheduler queue (not manual/bulk pull) must also trigger relational chain processing and populate ACF relationship fields.
- [ ] **AC-9 — No active fieldmap for related SF type:** When a relational rule references a `target_object` SF type with no active fieldmap configured, that rule is skipped without fatal error, remaining rules continue executing, and a log entry is written.
- [ ] **AC-10 — Related SF record deleted or inaccessible:** When `manual_pull` is called for a related SF ID that returns a Salesforce 404 / deletion error, no garbage WP post is created, no ACF field is populated, the error is logged, and the parent's other relational rules continue.
- [ ] **AC-11 — `update_field` failure is surfaced:** When `update_field()` returns false (wrong field key, ACF inactive, wrong post type), a log warning is written including the WP post ID, ACF field key, and the related WP post ID that was attempted.
- [ ] **AC-12 — Filter-registered relational rules:** A rule added via the `object_sync_for_salesforce_relational_rules` filter on a bulk-pull new record produces identical results to an equivalent stored rule.
- [ ] **AC-13 — `osf_resync_pull` alias:** Submitting the same SF IDs via `osf_resync_pull` produces identical relational chaining outcomes as `osf_bulk_pull`. Both must be explicitly exercised.
- [ ] **AC-14 — Push path (scope documented):** The `resync_push` path does not trigger relational sync (by design — it fires `push_success` not `pull_success`). This is confirmed and documented, not treated as a regression.
- [ ] **AC-15 — SF ID format validation:** Submitting a malformed string (e.g., a 32-char UUID) to the bulk endpoint returns an error result for that ID without making a Salesforce API call.

---

## Implementation Checklist

### class-object-sync-sf-salesforce-pull.php (around line 1822)

```php
// save the wordpress id to the mapping object in the synced object.
if ( 0 !== $wordpress_id ) {
    $synced_object['mapping_object']['wordpress_id'] = $wordpress_id;
}
// hook for pull success.
do_action( $this->option_prefix . 'pull_success', $op, $result, $synced_object );
```

### class-object-sync-sf-relational-sync.php (line 63-66)

Add debug logging and `try/finally` around the rules loop (Parts 2 and 3 above).

### class-object-sync-sf-bulk-sync.php

Add SF ID format validation in `process_pull()` and a 200-item batch cap in `bulk_pull()` (Part 4 above).

### Docblocks to add

- `create_called_from_salesforce()`: document that `$synced_object['mapping_object']['wordpress_id']` is patched to the real WP ID before `pull_success` fires.
- `Object_Sync_Sf_Relational_Sync` class: document that the recursion guard is depth-one; related records' relational rules are not cascaded.
- `pull_success` action: document the invariant that `$synced_object['mapping_object']['wordpress_id']` is non-zero on any successful create/upsert.

---

## Pre-existing Issues Surfaced (Out of Scope for This Fix)

- **Stored XSS (Medium):** `GW_Volunteers__Description__c` is written to `post_content` in `vemgmt-class-object-sync.php:154` without `wp_kses_post()` sanitization. Raw Salesforce HTML including `<script>` tags and event handlers is stored and potentially rendered. Fix: `$content = wp_kses_post( $sf_object['GW_Volunteers__Description__c'] );` before `wp_update_post`.
- **Missing `salesforce_id` index:** Verify `wp_object_sync_sf_object_map.salesforce_id` is indexed — `SHOW INDEX FROM wp_object_sync_sf_object_map WHERE Column_name = 'salesforce_id'`. Without it, every relational lookup is a full table scan.
- **`$reset = true` dead code:** `load_object_maps_by_salesforce_id($sf_id, array(), true)` in `class-object-sync-sf-relational-sync.php:149` — the `$reset` parameter is ignored by `get_all_object_maps()`. The code works correctly by coincidence (DB queries are uncached anyway), but the parameter is misleading.

---

## Sources & References

### Internal References

- Root cause location: `classes/class-object-sync-sf-salesforce-pull.php:1826`
- Early-return guard: `classes/class-object-sync-sf-relational-sync.php:63-64`
- Relational rule processor: `classes/class-object-sync-sf-relational-sync.php:119-159`
- Bulk sync entry point: `classes/class-object-sync-sf-bulk-sync.php:147-207`
- `get_synced_object()`: `classes/class-object-sync-sf-salesforce-pull.php:1558-1571`
- Object map write (real WP ID): `classes/class-object-sync-sf-salesforce-pull.php:1878-1882`
- Yoda guard pattern: `classes/class-object-sync-sf-salesforce-pull.php:1650, 1878`
- Push class write-back precedent: push class lines 1214, 1275, 1324
- VEMgmt pull handler: `includes/vemgmt-class-object-sync.php:124-220`
- VEM stored XSS: `includes/vemgmt-class-object-sync.php:152-157`

### External References

- [WordPress Plugin Handbook — Using Custom Action Hooks](https://developer.wordpress.org/plugins/hooks/actions/)
- [Action Scheduler library](https://actionscheduler.org/) — for async deferral of bulk relational pulls
- [WordPress Coding Standards — error_log sniff](https://github.com/WordPress/WordPress-Coding-Standards) — `phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log`
