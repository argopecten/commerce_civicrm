# Troubleshooting

## Installation Issues

### CiviCRM Integration Section Not Visible on Product Forms

Two possible causes: the `field_civicrm` field is missing from the product
type, or CiviCRM is unavailable (the section is skipped entirely then).

**Check the field**:

```bash
drush php:eval "echo \Drupal\field\Entity\FieldStorageConfig::loadByName('commerce_product', 'field_civicrm') ? 'storage exists' : 'storage missing';"
```

**Fix** (adds the field where missing, then clear caches):

```php
// Add to all product types:
commerce_civicrm_add_field_to_all_product_types();

// Or add to a specific product type:
commerce_civicrm_add_field_to_product_type('my_product_type');
```

### CiviCRM Not Available

**Symptoms**: "CiviCRM is not available" errors in logs, warning on the
status page, missing CiviCRM section on product forms.

**Diagnostic steps**:

1. Check the status page: `/admin/reports/status`
2. Verify the CiviCRM module is enabled: `drush pm:list | grep civicrm`
3. Check whether CiviCRM is in maintenance mode (upgrade active, or the
   `environment` setting is `Maintenance`)
4. Test CiviCRM access directly: visit `/civicrm`

**Check programmatically**:

```php
$helper = \Drupal::service('commerce_civicrm.civicrm_helper');
var_dump($helper->isAvailable());           // Basic availability
var_dump($helper->isReadyForOperations());  // Availability + not in maintenance
```

## Configuration Issues

### Entity Option Dropdowns Are Empty

The product form dropdowns (membership types, financial types, events,
mailing groups) are populated live from CiviCRM.

**Possible causes**:

- CiviCRM is unavailable or in maintenance mode
- No active entities of that type exist in CiviCRM (e.g. no active events;
  mailing groups must have group type *Mailing List*)

### Product Settings Not Saving

**Check** that `field_civicrm` exists on the product type:

```php
$field = \Drupal\field\Entity\FieldConfig::loadByName('commerce_product', 'default', 'field_civicrm');
var_dump($field ? 'exists' : 'missing');
```

**Check** the stored settings:

```php
$product = \Drupal\commerce_product\Entity\Product::load($product_id);
echo $product->get('field_civicrm')->value; // Should be a JSON string
```

## Order Processing Issues

### No CiviCRM Records Created After an Order

**Step 1 — Check the log** (`commerce_civicrm` channel) for the order ID.
The messages distinguish the causes precisely:

| Log message | Cause / fix |
|---|---|
| *(nothing logged at all)* | The transition that fired is not in `order.create_transitions` — see below |
| `CiviCRM is not available - skipping order …` | CiviCRM down or in maintenance — replay later with drush |
| `Could not resolve a CiviCRM contact for order … (fallback: none)` | Customer has no UFMatch link; consider `contact.fallback: match_or_create` |
| `Order … has no CiviCRM-enabled items - nothing to do` | No product in the order has `enabled: true` |
| `Contribution already exists for order …: … - skipping` | Already processed (this is idempotency, not an error) |
| `Cannot resolve membership type "…" / financial type "…"` | The CiviCRM type was renamed/deleted — re-save the product or restore the name |

**Step 2 — Verify the transition configuration.** The transition your
workflow actually fires must be listed:

```bash
drush config:get commerce_civicrm.settings order
```

Remember: when one order save chains several transitions, state machine only
fires the **last** one — list every transition ID that can end a save (e.g.
both `paid` and `completed`). See [Configuration](configuration.md).

**Step 3 — Verify the product configuration**:

```php
$product = \Drupal\commerce_product\Entity\Product::load($product_id);
var_dump(json_decode($product->get('field_civicrm')->value, TRUE));
// Expected: ['enabled' => true, 'entity' => 'membership', 'membership_type' => '…']
```

**Step 4 — Replay manually** (idempotent, logs every decision):

```bash
drush commerce-civicrm:process-order <order_id>
```

### Contact Not Created in CiviCRM

**Possible causes**:

- The customer has no UFMatch link and `contact.fallback` is `none`
  (the default) — only linked users are processed
- With `match_or_create`: the order has no billing profile, or the dedupe
  check matched **multiple** contacts (ambiguity is skipped on purpose,
  with a warning in the log)

**Check**:

```php
$order = \Drupal\commerce_order\Entity\Order::load($order_id);
$updater = \Drupal::service('commerce_civicrm.contact_updater');
var_dump($updater->getContactIdByUser($order->getCustomer()));
```

### Contribution Stays "Pending"

The contribution is created as Pending and completed by a CiviCRM Payment
**only when the order is paid** (completed/shipped/paid state, or zero
balance). Check the order's payments in Commerce; once the order is paid,
subsequent processing runs will not touch the existing contribution — record
the payment on the CiviCRM side or reprocess after deleting the Pending
contribution.

### Membership Not Renewed / Duplicated

A membership is renewed (not duplicated) when the contact has an existing
membership of the **same type** with status **New, Current or Grace**.
Expired or cancelled memberships don't match — a new membership is created
instead. Check the existing membership's type and status in CiviCRM.

### Wrong Membership Dates

Membership dates are governed by `membership.date_mode`
([Configuration](configuration.md)):

- `civicrm` — CiviCRM computes dates from the membership type period
  settings; check those in CiviCRM
- `dispatch` — a site event subscriber supplies dates; check that
  subscriber's source data

### Renewal Payment Not Recorded

`RenewalProcessor::recordRenewalPayment()` refuses (with an info/warning log)
when:

- the payment is not in `completed` state,
- the order has **no initial contribution** yet (the initial processing must
  have run first),
- the payment is already recorded (idempotency), or
- it belongs to the initial contribution itself.

### Duplicate Contributions

Should not occur for the same order: processing is idempotent via the
`Commerce_Order.commerce_order_id` custom field, and renewals are idempotent
per payment. If you see duplicates, check whether the custom field group
exists in CiviCRM (*Administer → Customize Data and Screens → Custom
Fields → Commerce Order*) — the module recreates it automatically before
processing, so its absence points to a CiviCRM-side problem.

## Logging and Debugging

All messages use the `commerce_civicrm` logger channel. Where they end up
depends on the site's logging setup (dblog: `/admin/reports/dblog`; syslog:
grep the system log).

```bash
drush ws --count=50 --filter=commerce_civicrm   # dblog sites
```

### Common Log Messages

**Successful processing**:

```
INFO: Processing order 456 transition paid (pending → paid, workflow magyar_hang_workflow) for CiviCRM record creation
INFO: Completed processing order 456. Created records: {"contributions":[101],"memberships":[789]}
```

**Idempotent skip (not an error)**:

```
INFO: Contribution already exists for order 456: 101 - skipping
```

**Configuration problems**:

```
WARNING: Cannot resolve membership type "Old Name" (order 456) - skipping directive
WARNING: Could not resolve a CiviCRM contact for order 456 (customer 12, fallback: none)
```

**Availability problems**:

```
ERROR: CiviCRM is not available - skipping order 456 processing
WARNING: CiviCRM is in maintenance mode — deferring API operations
```

## Diagnostic Quick Reference

| Symptom | First check |
|---------|------------|
| CiviCRM unavailable | `/admin/reports/status`; `CivicrmHelper::isAvailable()` |
| Nothing logged for an order | `order.create_transitions` vs. the workflow's actual transitions |
| No records created | Order log messages (table above) |
| Contact not found | UFMatch link; `contact.fallback` setting |
| Contribution stays Pending | Order payments in Commerce |
| Membership duplicated | Existing membership's type + status in CiviCRM |
| Renewal not recorded | Payment state; initial contribution exists? |

## Getting Help

### Before Seeking Support

1. **Check the log** (`commerce_civicrm` channel) for the affected order
2. **Verify the settings** — `drush config:get commerce_civicrm.settings`
3. **Verify the product configuration** — inspect the `field_civicrm` JSON
4. **Try a replay** — `drush commerce-civicrm:process-order <id>` and read
   the resulting log lines

### Information to Provide

- Drupal core, Commerce, CiviCRM, PHP and module versions
- The order workflow and its transition IDs
- `commerce_civicrm.settings` content
- The product's `field_civicrm` JSON value
- Relevant log messages from the `commerce_civicrm` channel
- Steps to reproduce

### Resources

- Module documentation: `doc/` directory
- Service reference: [Services](../development/services.md)
- Development backlog: [todo.md](../development/todo.md)
