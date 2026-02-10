# Agent Prompt: Implement Todo #22 — Fix Views Configuration (D7 Artifacts)

## Objective

Fix the views configuration YAML to remove D7 artifacts, standardize the view ID, and fix the mismatched config name in the uninstall hook. Breaking changes are allowed. No update hooks are needed.

## Repository

`/Users/petergerner/Documents/GitHub/commerce_civicrm/`

## Problem

Three issues with the views configuration:

### 1. View ID uses hyphens instead of underscores

The view ID is `my-orders` (hyphenated). Drupal convention for machine names is underscores. The config file is named `views.view.my-orders.yml` with `id: my-orders`. Meanwhile, the uninstall hook tries to delete `views.view.commerce_civicrm_orders_by_user` — a completely different name.

### 2. `entity_type: null` throughout the YAML

The exported YAML has `entity_type: null` on multiple field definitions (approximately at L30 and repeated for every field). This is a D7-era export artifact. In D10/D11, this field is unnecessary when null — it should be removed or left as-is if the view still functions. However, Drupal 11 views exports do not include `entity_type: null` — they omit the key entirely.

### 3. Uninstall hook config name mismatch

In `commerce_civicrm.install`, `hook_uninstall()` tries to delete:
```php
'views.view.commerce_civicrm_orders_by_user',
```

But the actual view config object is `views.view.my-orders`. This means the view is **never cleaned up** on uninstall.

### 4. Access control is `none`

The view has `access: type: none` — meaning no access control. For a "My Orders" view showing a user's orders, this should be restricted to authenticated users at minimum.

## What to implement

### Step 1: Rename the view config file

Rename `config/install/views.view.my-orders.yml` to `config/install/views.view.commerce_civicrm_my_orders.yml`.

The new view ID should be `commerce_civicrm_my_orders` — namespaced with the module name and using underscores.

### Step 2: Update the view ID inside the YAML

**Before:**
```yaml
id: my-orders
```

**After:**
```yaml
id: commerce_civicrm_my_orders
```

### Step 3: Remove all `entity_type: null` entries

Search the YAML file for all occurrences of `entity_type: null` and remove those lines entirely. These appear in each field definition. There may be 5-10 occurrences.

**Before:**
```yaml
        order_number:
          id: order_number
          table: commerce_order
          field: order_number
          relationship: none
          group_type: group
          admin_label: ''
          entity_type: null
          entity_field: order_number
```

**After:**
```yaml
        order_number:
          id: order_number
          table: commerce_order
          field: order_number
          relationship: none
          group_type: group
          admin_label: ''
          entity_field: order_number
```

### Step 4: Add access control

Find the access section in the view's display options and change from `none` to `perm`:

**Before:**
```yaml
      access:
        type: none
        options: {  }
```

**After:**
```yaml
      access:
        type: perm
        options:
          perm: 'view own commerce_order'
```

The permission `view own commerce_order` is provided by Commerce and is appropriate for a "My Orders" page.

### Step 5: Update `commerce_civicrm.info.yml`

**Before:**
```yaml
config:
  install:
    - views.view.my-orders
```

**After:**
```yaml
config:
  install:
    - views.view.commerce_civicrm_my_orders
```

### Step 6: Update `commerce_civicrm.install` uninstall config

**Before:**
```php
$config_names = [
    'commerce_civicrm.settings',
    'views.view.commerce_civicrm_orders_by_user',
];
```

**After:**
```php
$config_names = [
    'commerce_civicrm.settings',
    'views.view.commerce_civicrm_my_orders',
];
```

## Files to create (via rename)

1. **`config/install/views.view.commerce_civicrm_my_orders.yml`** — Renamed from `views.view.my-orders.yml`

## Files to delete

1. **`config/install/views.view.my-orders.yml`** — After creating the renamed copy

## Files to modify

1. **`config/install/views.view.commerce_civicrm_my_orders.yml`** — Update ID, remove `entity_type: null`, fix access
2. **`commerce_civicrm.info.yml`** — Update config install reference
3. **`commerce_civicrm.install`** — Fix uninstall config name

## Constraints

- **Breaking changes are allowed**
- **No update hooks needed**
- The view path can remain `my-orders` (that's a URL path, not a machine name — doesn't need changing)
- The view label can remain "My orders"
- All `entity_type: null` lines must be removed from the YAML
- The access permission `view own commerce_order` must be used (it's a Commerce core permission)
- The new view ID must be `commerce_civicrm_my_orders` (module-namespaced, underscores)

## Verification

After implementation, confirm:
1. `config/install/views.view.commerce_civicrm_my_orders.yml` exists with `id: commerce_civicrm_my_orders`
2. `config/install/views.view.my-orders.yml` does NOT exist (deleted)
3. Zero occurrences of `entity_type: null` in the views YAML
4. The access type is `perm` with `view own commerce_order`
5. `commerce_civicrm.info.yml` references `views.view.commerce_civicrm_my_orders`
6. `commerce_civicrm.install` uninstall references `views.view.commerce_civicrm_my_orders`
7. YAML is valid (no syntax errors)
8. Run `grep -r 'my-orders' .` — should only appear in the view's URL path configuration, not in IDs or config names
