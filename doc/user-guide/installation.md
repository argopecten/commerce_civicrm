# Installation & Configuration

## Overview

The Commerce CiviCRM module integrates Drupal Commerce with CiviCRM, enabling
automatic CiviCRM record creation when customers complete orders. Products are
configured through a built-in UI that stores CiviCRM settings as JSON in a
single `field_civicrm` field.

## Requirements

| Package | Version |
|---------|---------|
| **PHP** | >= 8.3 |
| **Drupal core** | ^11.0 |
| **Drupal Commerce** | ^3.0 |
| **CiviCRM** | ^6.1 (tested with 6.1.0–6.2.0) |
| **Profile** | ^1.2 |
| **State Machine** | ^1.5 |

The module also depends on `commerce_product`, `commerce_order`,
`commerce_payment`, and `user` (declared in `commerce_civicrm.info.yml`).

## Installation

### Step 1 — Install the Module

```bash
composer require drupal/commerce_civicrm
drush en commerce_civicrm
```

### Step 2 — Automatic Setup

On installation the module automatically:

1. **Adds `field_civicrm`** (a `text_long` JSON field) to all existing Commerce
   product types. The field is hidden from form and view displays — configuration
   happens through the dedicated CiviCRM UI section on product edit forms.
2. **Provisions CiviCRM custom fields** — creates a `Commerce_Order` custom group
   on the `Contribution` entity with a `commerce_order_id` integer field, used for
   order-to-contribution cross-referencing.
3. **Registers event subscribers** for Commerce order workflow transitions
   (`place`, `validate`, `fulfill`, `cancel`).

New product types created after installation automatically receive the
`field_civicrm` field via `hook_commerce_product_type_insert()`.

### Step 3 — Verify

1. **Check status page** (`/admin/reports/status`) — the module reports CiviCRM
   availability and maintenance mode status.
2. **Edit any product** — a "CiviCRM Integration" section should appear in the
   Advanced sidebar.
3. **Review logs** at `/admin/reports/dblog` (filter by `commerce_civicrm`).

## Post-Install Configuration

### Verify CiviCRM Connection

The module checks CiviCRM availability at runtime via `CivicrmHelper::isAvailable()`.
If CiviCRM is in maintenance mode (upgrade active or environment set to
`Maintenance`), the module logs a warning and skips processing — orders still
complete normally in Commerce.

### Permissions

The module uses existing Commerce and CiviCRM permissions:

- **Commerce order processing** — standard Commerce roles
- **Product configuration** — users who can edit products see the CiviCRM settings
- **CiviCRM API access** — the module uses `checkPermissions(FALSE)` on all API4
  calls, so no additional CiviCRM permissions are needed for the integration itself

## Uninstallation

```bash
drush pm:uninstall commerce_civicrm
```

On uninstall the module:

1. Removes `field_civicrm` field instances and storage from all product types
2. Deletes the CiviCRM `Commerce_Order` custom group and its fields
3. Removes module configuration and the `commerce_civicrm_my_orders` view
4. Clears relevant caches

## Next Steps

- [Product Configuration](product-configuration.md) — configure CiviCRM settings on products
- [Order Processing](order-processing.md) — understand the automatic processing workflow
- [Services Overview](../services/overview.md) — technical service architecture
