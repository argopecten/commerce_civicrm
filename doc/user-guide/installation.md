# Installation & Configuration

## Overview

The Commerce CiviCRM module integrates Drupal Commerce with CiviCRM: when an
order passes a configured workflow transition, CiviCRM records
(contributions, memberships, event participants, mailing subscriptions) are
created from the products' CiviCRM configuration. Products are configured
through a built-in UI that stores settings as JSON in a single
`field_civicrm` field.

## Requirements

| Package | Version |
|---------|---------|
| **PHP** | >= 8.3 |
| **Drupal core** | ^11.0 |
| **Drupal Commerce** | ^3.0 |
| **CiviCRM** (`civicrm/civicrm-core` + `civicrm/civicrm-drupal-8`) | ^6.16 |
| **Profile** | ^1.2 |
| **State Machine** | ^1.5 |

The module also depends on `commerce_product`, `commerce_order`,
`commerce_payment`, and `user` (declared in `commerce_civicrm.info.yml`).
CiviCRM 6.16 is a hard floor: the module uses the current CiviCRM Order and
Payment API behaviour.

## Installation

### Step 1 — Install the Module

```bash
composer require drupal/commerce_civicrm
drush en commerce_civicrm
```

### Step 2 — Automatic Setup

On installation the module automatically:

1. **Adds `field_civicrm`** (a `text_long` JSON field) to all existing
   Commerce product types. The field is hidden from form and view displays —
   configuration happens through the dedicated *CiviCRM Integration* section
   on product edit forms. New product types receive the field automatically.
2. **Provisions CiviCRM custom fields** — creates a `Commerce_Order` custom
   group on the Contribution entity with `commerce_order_id` and
   `commerce_payment_id` integer fields, used to link contributions to
   Commerce orders/payments and to keep processing idempotent.
3. **Installs default settings** (`commerce_civicrm.settings`): process
   orders on the `place` transition, cancel on `cancel`.
4. **Installs the "My CiviCRM Orders" view**
   (`commerce_civicrm_my_orders`).

### Step 3 — Configure Transitions (if needed)

If your order workflow doesn't use the stock `place` / `cancel` transitions
(custom workflows, paid/completed flows), set the transition IDs that should
trigger processing — see [Configuration](configuration.md).

### Step 4 — Verify

1. **Check the status page** (`/admin/reports/status`) — the module reports
   CiviCRM availability.
2. **Edit any product** — a "CiviCRM Integration" section should appear in
   the Advanced sidebar.
3. **Review logs** on the `commerce_civicrm` channel.

## Post-Install Notes

### CiviCRM availability

The module checks CiviCRM availability at runtime. If CiviCRM is unavailable
or in maintenance mode (upgrade active, or environment set to
`Maintenance`), the module logs the problem and skips processing — **orders
always complete normally in Commerce**. Skipped orders can be replayed later:

```bash
drush commerce-civicrm:process-order 128,129
```

(Idempotent: orders that already have a linked contribution are skipped.)

### Permissions

The module uses existing Commerce and CiviCRM permissions:

- **Commerce order processing** — standard Commerce roles
- **Product configuration** — users who can edit products see the CiviCRM
  settings
- **CiviCRM API access** — the module passes `checkPermissions = FALSE` on
  all API4 calls, so no additional CiviCRM permissions are needed for the
  integration itself

## Uninstallation

```bash
drush pm:uninstall commerce_civicrm
```

On uninstall the module:

1. Removes `field_civicrm` field instances and storage from all product types
2. Deletes the CiviCRM `Commerce_Order` custom group and its fields
3. Removes module configuration and the `commerce_civicrm_my_orders` view
4. Clears relevant caches

CiviCRM records created by the module (contacts, contributions, memberships,
participants, group memberships) are left in place.

## Next Steps

- [Configuration](configuration.md) — transitions, contact fallback, payment instruments, membership dates
- [Product Configuration](product-configuration.md) — configure CiviCRM settings on products
- [Order Processing](order-processing.md) — understand the automatic processing workflow
