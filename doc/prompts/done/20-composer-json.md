# Agent Prompt: Implement Todo #20 — Fix `composer.json` Inconsistencies

## Objective

Fix the remaining placeholder and inconsistency issues in `composer.json`. Several issues from the original backlog were already resolved by prior prompts — this prompt addresses only the remaining problems. Breaking changes are allowed. No update hooks are needed.

## Repository

`/Users/petergerner/Documents/GitHub/commerce_civicrm/`

## Already fixed (do NOT re-change these)

The following `composer.json` fields were already corrected by earlier prompts:
- ✅ `description` — already updated (no longer says "Rules-based")
- ✅ `require.drupal/commerce` — already `^3.0`
- ✅ `require.php` — already `>=8.3`
- ✅ `require.drupal/core` — already `^11.0`
- ✅ `require-dev.phpunit/phpunit` — already `^10.5`
- ✅ `require.drupal/rules` — already removed

## Still broken

### 1. Placeholder author information

```json
"authors": [
    {
        "name": "Your Name",
        "email": "your.email@example.com",
        "homepage": "https://example.com",
        "role": "Maintainer"
    }
],
```

This is a template placeholder. Replace with the module's community maintainer info. Since this is an open-source Drupal community project:

```json
"authors": [
    {
        "name": "Commerce CiviCRM Contributors",
        "homepage": "https://www.drupal.org/project/commerce_civicrm",
        "role": "Maintainer"
    }
],
```

Remove the `email` field (no single person's email should be there for a community project) and update `name` and `homepage`.

### 2. Trim inflated keywords

The `keywords` array has 11 entries including generic ones like "automation" and "workflow" that don't describe what the module does. Trim to the most relevant:

**Before:**
```json
"keywords": [
    "drupal",
    "commerce",
    "civicrm",
    "crm",
    "ecommerce",
    "integration",
    "automation",
    "workflow",
    "membership",
    "contribution",
    "event"
],
```

**After:**
```json
"keywords": [
    "drupal",
    "commerce",
    "civicrm",
    "crm",
    "integration",
    "membership",
    "contribution"
],
```

Remove: `ecommerce` (redundant with `commerce`), `automation`, `workflow` (too generic), `event` (ambiguous — could mean CiviCRM events or Drupal events).

## Files to modify

1. **`composer.json`** — Fix authors and keywords

## Files NOT to modify

- All other files

## Constraints

- **Breaking changes are allowed**
- **No update hooks needed**
- Preserve JSON formatting (4-space indentation, consistent style)
- Do NOT modify `require`, `require-dev`, `description`, or any other fields — only `authors` and `keywords`
- Ensure valid JSON after editing

## Verification

After implementation, confirm:
1. `authors[0].name` is no longer "Your Name"
2. `authors[0].email` field is removed
3. `keywords` array has 7 or fewer entries, all relevant
4. JSON is valid (run `python3 -c "import json; json.load(open('composer.json'))"` or similar)
5. No other fields were modified
