---
title: "Chained/relational sync silently skips on first-time create due to stale synced_object snapshot"
category: logic-errors
tags: [salesforce, object-sync, bulk-sync, chained-sync, acf, php-pass-by-value, stale-reference, wordpress-id, pull-success, relational-sync]
module: object-sync-for-salesforce
symptom: "Bulk pull of Salesforce records not yet in WordPress never fires relational/chained sync rules and never populates ACF relationship fields. No error is logged. Records that already exist in WordPress (updates) chain correctly."
root_cause: "In create_called_from_salesforce(), do_action('pull_success') fires with a stale $synced_object snapshot built before the WP post is created. Because PHP arrays are pass-by-value, $synced_object['mapping_object']['wordpress_id'] remains 0 at hook time. The real $wordpress_id is only written to the local $mapping_object at line ~1879, after the hook already fired."
affected_files:
  - "classes/class-object-sync-sf-salesforce-pull.php"
  - "classes/class-object-sync-sf-relational-sync.php"
  - "classes/class-object-sync-sf-bulk-sync.php"
date: 2026-05-19
---

# Chained/relational sync silently skips on first-time Salesforce pull (create path)

## Symptom

When using the bulk sync tool (`osf_bulk_pull` or `osf_resync_pull`) to pull Salesforce records that do **not** yet exist in WordPress:

- The sync appears to succeed (no PHP errors, no log entries)
- No relational/chained sync rules run
- ACF relationship fields on the newly created WP posts remain empty
- No related parent objects are pulled

Records that **already exist** in WordPress (updates) chain correctly — this is the key indicator that the bug is on the create path only.

The same bug also affects the scheduled queue-based pull for new records (same `create_called_from_salesforce()` code path).

---

## Investigation Steps

1. Reproduced: bulk sync completed without errors, but relational sync rules never fired for newly created WP records.
2. Added logging to `Object_Sync_Sf_Relational_Sync::handle_pull_success()` — confirmed it was being called but returning early every time during bulk sync.
3. Traced the early return to the `! $wp_id` guard (line 64 of relational sync class) — `$synced_object['mapping_object']['wordpress_id']` was always `0` on create.
4. Audited `create_called_from_salesforce()` in `class-object-sync-sf-salesforce-pull.php`: the `do_action('pull_success')` fires at line 1826 using `$synced_object`, a PHP array (pass-by-value) snapshot built **before** the WordPress post is created. The real WordPress ID is written at line 1879 — after the hook already fired.
5. Confirmed updates work because the update path loads the mapping object fresh from the DB, so `wordpress_id` is already populated before `$synced_object` is built.

---

## Root Cause

`$synced_object` is a PHP array (pass-by-value). In `create_called_from_salesforce()`, the sequence was wrong:

```
1. Build $synced_object snapshot     ← wordpress_id is 0 here
2. do_action('pull_success', ..., $synced_object)  ← relational sync sees 0, returns early
3. Create the WP post → resolve $wordpress_id
4. $mapping_object['wordpress_id'] = $wordpress_id  ← too late, hook already fired
```

The real WordPress ID exists in the `$result['parent']` key of the log-entry array and in the local `$wordpress_id` variable at step 2, but was never written back into `$synced_object['mapping_object']` before the hook fired.

**Why updates were unaffected:** `update_called_from_salesforce()` loads the mapping object from the DB at the start of the operation. The `wordpress_id` is already present and correct when `$synced_object` is built. Only the create path built the snapshot pre-creation.

**The push class already had the correct pattern:** `salesforce_push_object_crud()` explicitly writes `$synced_object['mapping_object'] = $mapping_object` before firing `push_success` and `push_fail` (lines 1214, 1275, 1324 of the push class). The pull create path was missing this write-back.

---

## The PHP Array Pass-by-Value Gotcha

In PHP, arrays are passed and captured **by value**, not by reference. When `get_synced_object()` builds `$synced_object`, it captures the current state of `$mapping_object` as a value copy. Any mutations to `$mapping_object` after that point do NOT flow back into `$synced_object['mapping_object']` — they are entirely separate variables.

```php
// This is what the code did (WRONG):
$synced_object = get_synced_object($object, $mapping_object, $fieldmap);
// $synced_object['mapping_object']['wordpress_id'] is 0

$wordpress_id = $this->wordpress->object_create(...); // creates the post
$mapping_object['wordpress_id'] = $wordpress_id;     // updates LOCAL var only

do_action('pull_success', $op, $result, $synced_object);
// $synced_object['mapping_object']['wordpress_id'] is STILL 0
```

The fix: explicitly write the resolved value back into `$synced_object` before firing the hook.

---

## The Fix

Four changes in the branch `fix/chained-sync-bulk-pull`.

### Part 1 — Core fix (class-object-sync-sf-salesforce-pull.php, ~line 1822)

Write `$wordpress_id` back into `$synced_object` before `do_action('pull_success')`:

```php
// save the wordpress id to the mapping object in the synced object.
if ( 0 !== $wordpress_id ) {
    $synced_object['mapping_object']['wordpress_id'] = $wordpress_id;
}
// hook for pull success.
do_action( $this->option_prefix . 'pull_success', $op, $result, $synced_object );
```

The `if ( 0 !== $wordpress_id )` guard matches the Yoda-condition style used at lines 1650 and 1878 of the same function. The `do_action` already only fires in the success branch (`if ( empty( $result['errors'] ) )`), so `$wordpress_id` will never legitimately be 0 here.

### Part 2 — Debug logging (class-object-sync-sf-relational-sync.php, line 63)

The early-return guard now logs when `$wp_id` is 0 so future regressions are immediately diagnosable:

```php
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

### Part 3 — try/finally recursion guard (class-object-sync-sf-relational-sync.php)

Wrap the rule-processing loop in `try/finally` so `self::$syncing_ids[$sf_id]` is always cleared even if an exception is thrown mid-loop:

```php
self::$syncing_ids[ $sf_id ] = true;
try {
    foreach ( $relational_rules['rules'] as $rule ) { ... }
    // filter-based extra rules...
} finally {
    unset( self::$syncing_ids[ $sf_id ] );
}
```

### Part 4 — Bulk endpoint hardening (class-object-sync-sf-bulk-sync.php)

SF ID format validation in `process_pull()`:

```php
if ( ! preg_match( '/^[a-zA-Z0-9]{15}([a-zA-Z0-9]{3})?$/', $sf_id ) ) {
    return array(
        'sf_id'   => $sf_id,
        'sf_type' => '',
        'status'  => 'error',
        'message' => __( 'Invalid Salesforce ID format.', 'object-sync-for-salesforce' ),
    );
}
```

200-item batch cap in `bulk_pull()`:

```php
$truncated = false;
if ( count( $sf_ids ) > 200 ) {
    $sf_ids    = array_slice( $sf_ids, 0, 200 );
    $truncated = true;
}
// ... foreach loop ...
if ( $truncated ) {
    $response['warning'] = __( 'Only the first 200 IDs were processed. Submit in batches of 200 or fewer.', 'object-sync-for-salesforce' );
}
```

---

## Prevention

### How to detect this class of bug

Grep for `do_action` calls in the pull class that pass `$synced_object`:

```bash
grep -n "do_action.*pull_success\|do_action.*pull_fail" classes/class-object-sync-sf-salesforce-pull.php
```

For each result, scroll up and verify that every key a hook handler might read from `$synced_object['mapping_object']` — especially `wordpress_id` — has been **explicitly** written to `$synced_object` after the WordPress object was saved.

**Red flag pattern:** `$mapping_object['wordpress_id'] = $wordpress_id` appears *after* `$synced_object` was built AND before a `do_action()` passes `$synced_object`. The value is in the local variable, not in the snapshot.

### Developer checklist for new do_action() calls in this plugin

Before firing any `do_action()` that passes `$synced_object`:

- [ ] Has `$wordpress_id` been resolved from the DB operation result (not pre-operation state)?
- [ ] Is `$synced_object['mapping_object']['wordpress_id']` explicitly set to the resolved value?
- [ ] Compare to the push class: is there a `$synced_object['mapping_object'] = $mapping_object` line before the `do_action()`? If not, justify why omitting it is safe.
- [ ] Add a PHPDoc comment on the `do_action()` documenting what keys consumers can rely on.

### Testing the fix

**Manual test steps:**

1. Find (or create) a Salesforce record that has NO corresponding WordPress post. Its fieldmap should have relational rules configured.
2. Run a bulk pull with that SF ID via the admin Bulk Sync tab.
3. In WP admin, verify the new WP post was created AND that the ACF relationship fields are populated.
4. Check `debug.log` — there should be NO `[OSF Relational Sync] handle_pull_success: skipping — wordpress_id is 0` messages for the new record.
5. Check the OSF log (Settings > Object Sync > Log Entries) — the entry's `parent` field should be the WP post ID, not `0`.
6. Repeat with a record whose related parent also does not exist in WP — this tests the recursive chain.

**What confirms the bug is regressed:**
- ACF relationship field is empty on the newly created WP post
- `debug.log` contains `wordpress_id is 0 for sf_id [X]`

---

## Known Limitations / Follow-up Items

- **Recursion depth is one level by design:** `self::$syncing_ids` prevents a related object's OWN relational rules from cascading. A → B's rules run; B's rules do NOT automatically cascade to C. This is intentional but undocumented in the class.
- **`pull_fail` on create still passes `wordpress_id = 0`:** This is correct behavior (no WP post was created), but `last_sync_status` and other `$mapping_object` mutations are also missing from `$synced_object` for fail cases. The push class's full write-back pattern (`$synced_object['mapping_object'] = $mapping_object`) is the more robust long-term approach.
- **Performance — large bulk runs:** Each relational pull is a synchronous Salesforce REST API call. Bulk syncing > ~20 new records that each have unsynced related objects will approach PHP `max_execution_time`. Action Scheduler deferral is the recommended long-term fix for large initial imports.
- **Pre-existing: stored XSS in VEM plugin:** `GW_Volunteers__Description__c` is written to `post_content` without `wp_kses_post()` in `vemgmt-class-object-sync.php:152`. Not related to this fix but surfaced during security review.
- **`$reset = true` is dead code:** `load_object_maps_by_salesforce_id($sf_id, [], true)` in the relational sync class passes `$reset = true` but `get_all_object_maps()` always queries the DB directly and ignores the flag.

---

## References

- Fix branch: `fix/chained-sync-bulk-pull` in object-sync-for-salesforce plugin
- Plan doc: `docs/plans/2026-05-19-fix-chained-sync-missing-on-bulk-pull-plan.md`
- Push class write-back precedent: `class-object-sync-sf-salesforce-push.php` lines 1214, 1275, 1324
- Existing OSF hook reference: `docs/all-developer-hooks.md`
