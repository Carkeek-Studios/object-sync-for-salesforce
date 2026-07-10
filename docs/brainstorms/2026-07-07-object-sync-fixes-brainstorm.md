# Brainstorm: Object Sync for Salesforce — Clone, Bulk Pull & Chained Pull Fixes

**Date:** 2026-07-07
**Author:** patty@carkeekstudios.com
**Plugin:** object-sync-for-salesforce (Carkeek fork)
**Status:** Ready for planning

---

## What We're Building

Three targeted bug fixes to the Carkeek fork of Object Sync for Salesforce. All three
are confirmed in code, with clear desired behavior. Scope for this round = **all three**.

1. **Fieldmap clone** — stop wiping mappings on object-type change; stop clones silently
   vanishing on save.
2. **Bulk pull** — correctly resolve the Salesforce object type when two SF objects map to
   the same WordPress post type.
3. **Chained/relational pull** — write ACF relationship fields the correct ACF way so the
   link actually sticks.

---

## Issue 1 — Fieldmap Clone

### 1a. Fields get wiped when you change the WordPress or Salesforce object

**Root cause:** `assets/js/src/02-load-field-options.js` (lines 55–74). On `change` of
`#wordpress_object` or `#salesforce_object`, after a 1s timeout it runs:

```js
$( 'table.fields tbody tr' ).not( '.fieldmap-template' ).remove();
```

This unconditionally destroys **every** mapped field row, regardless of whether the same
field still exists on the newly selected object. Cloning to a different post type that
shares the same fields therefore throws away all the mapping work.

**Desired behavior:** For whichever side changed (WordPress *or* Salesforce), check each
existing row's selected field against the new object's field list:
- If the field still exists on the new object → **keep** the row and its mapping.
- If the field does **not** exist → reset just that side's `<select>` to blank (leave the
  row so the user can see what needs remapping).
- Only the changed side is re-evaluated; the unchanged side's selections are preserved.

### 1b. Cloned fieldmap silently disappears on save

**Root cause (two compounding bugs):**
1. The clone form pre-fills the **exact same `label`** as the original
   (`class-object-sync-sf-admin.php` ~line 690, `$label = $map['label']`).
2. On save, `setup_fieldmap_data()` derives `name = sanitize_title( label )`, and the
   `object_sync_sf_field_map` table has a `UNIQUE KEY name (name)`. Identical label →
   identical `name` → `$wpdb->insert` fails → `create_fieldmap()` returns `false`.
3. The error redirect points at `...&method=clone` **without an `id`**, so the reload
   falls through the `isset($map['id'])` guard and bounces to the `missing_fieldmap`
   list view. Net result: no error message, clone gone.

**Desired behavior:**
- A cloned fieldmap saves successfully by default (no manual rename required).
- If a label/name collision is genuinely unavoidable, the user sees a **clear error** and
  keeps their in-progress mapping — it must never silently disappear.

**Decision:** Give clones a distinct default label/name on the clone screen (e.g.
`"<original> (copy)"`), *and* harden the save path so an insert failure surfaces a real
error with the submitted data preserved (never a silent bounce to the list).

---

## Issue 2 — Bulk Pull Resolves the Wrong Salesforce Object Type

**Root cause:** `class-object-sync-sf-bulk-sync.php`, `resolve_sf_type()`. When an SF ID's
post type has more than one fieldmap (e.g. Campaign **and** Job both map to the same post
type), it blindly returns `$fieldmaps[0]['salesforce_object']` — always the first fieldmap.
A batch of Job IDs then gets pulled as Campaigns → "object does not exist."

**Decision — Object-type selector in the bulk pull UI (primary):**
Because bulk pulls are always run one object type at a time, add a **Salesforce object
type selector** to the bulk pull section, populated from the configured/mapped SF object
types. The operator picks the type for that batch and every ID is pulled as that type —
deterministic, no guessing, and it directly fixes the Campaign/Job collision.

- Default the selector to a sensible value (e.g. first mapped type, or a required
  "— Select object type —" prompt so nothing is pulled by accident).
- Pass the chosen `salesforce_object` through the AJAX request; `process_pull()` /
  `resolve_sf_type()` uses it directly instead of inferring from `fieldmaps[0]`.

**Optional enhancement — SF ID key-prefix "Auto-detect":**
Every Salesforce ID begins with a 3-character key prefix unique per object type (`701` =
Campaign, `003` = Contact, custom prefixes like `a0X`). We *may* offer an "Auto-detect"
choice in the same selector that resolves each ID by its prefix (built from describe
metadata) — useful for mixed batches. Not required for the current workflow; planning can
decide whether to include it now or defer.

```
Selector: [ Campaign ▾ ]   ← operator picks; all IDs pulled as this type
(optional) [ Auto-detect ]  ← resolve by ID key-prefix: 701→Campaign, a0X→Job__c ...
```

**Why this approach:** Matches the actual workflow (one type per batch), needs no per-ID
API call, and is the simplest correct fix. Unresolvable IDs still report cleanly per-row.

---

## Issue 3 — Chained / Relational Pull Writes ACF Fields Incorrectly

**Context:** `Object_Sync_Sf_Relational_Sync` fires on `object_sync_for_salesforce_pull_success`,
pulls any missing related parent objects, and wires the child post's ACF relationship field.

**Root cause (reported):** The relationship write was setting **both** the value meta
(`field_name`) and the ACF reference meta (`_field_name`) to the *same value*. ACF's
convention is:
- `field_name` = the value (e.g. the related post ID array)
- `_field_name` = the field **key** (e.g. `field_abc123`)

With both set to the value, ACF can't resolve the field reference, so the relationship
doesn't read back correctly.

**Desired behavior:** Write ACF relationship fields the correct ACF way — value meta holds
the value, reference meta (`_field_name`) holds the field **key**.

**Fix approach (confirmed):** ACF's `update_field( $selector, $value, $post_id )` handles
the `_field_name` reference meta correctly **as long as you pass the field key**
(e.g. `field_abc123`) rather than the field **name**. So the fix is to ensure the
relational rule supplies/stores the ACF field **key** and that the code passes that key to
`update_field()`. If a rule currently holds a field name, resolve it to its key
(`acf_get_field()` / stored key) before writing.

**Link mode:** **Replace (overwrite)** — set the field to exactly the related post(s) for
that rule, clearing prior values. (Confirmed; current replace behavior is intended.)

---

## Why This Approach (overall)

- **Fix root causes, not symptoms.** Each issue traces to a specific, confirmed line of
  code with well-defined correct behavior — no speculative redesign needed.
- **YAGNI.** No new UI beyond a distinct default clone label; no schema changes; key-prefix
  resolution reuses describe data the plugin already fetches.
- **Preserve existing conventions.** Stay within the fork's existing classes
  (`bulk-sync`, `relational-sync`) and the standard OSF admin/JS structure.

---

## Key Decisions

| # | Decision |
|---|----------|
| 1 | Scope = all three issues this round. |
| 2 | Clone object-change: preserve rows whose field exists on the new object; blank only the missing side; only re-evaluate the changed side. |
| 3 | Clone save: give clones a distinct default label/name; surface insert failures as real errors that preserve in-progress data (never silent bounce). |
| 4 | Bulk pull: add an object-type selector (from mapped SF types) so the operator picks the type per batch; use it directly instead of `fieldmaps[0]`. Optional "Auto-detect" via SF ID key-prefix may be added or deferred. Unresolvable IDs report as per-row errors. |
| 5 | Chained pull: call ACF `update_field()` with the field **key** (not name) so `_field_name` reference meta is written correctly; resolve name→key if a rule stores a name. |
| 6 | Chained pull link mode = Replace (overwrite). |

---

## Resolved Questions

- **What's wrong with chained pull?** → ACF meta written incorrectly (both value and
  reference meta set to the value instead of value + field key). *Resolved.*
- **ACF link mode append vs replace?** → Replace. *Resolved.*
- **Bulk pull disambiguation strategy?** → Object-type selector in the bulk pull UI
  (operator picks the type per batch, since batches are single-type). Key-prefix
  auto-detect is an optional add-on. *Resolved.*
- **Unresolvable/unmatched ID reporting?** → Per-ID error row in the bulk-pull results
  table, consistent with existing error reporting; batch continues. *Resolved.*
- **Scope this round?** → All three. *Resolved.*

## Open Questions

- **Include "Auto-detect" (key-prefix) now or defer?** The explicit selector fully covers
  the current single-type workflow; the prefix-based auto-detect is only needed for mixed
  batches. Decide during `/ce:plan` whether to build it now or leave a hook for later.

---

## Next Step

Run `/ce:plan` to turn this into an implementation plan (the plan will pick up this
document automatically).
