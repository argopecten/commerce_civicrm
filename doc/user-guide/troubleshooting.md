# Troubleshooting

## Installation Issues

### CiviCRM Integration Section Not Visible on Product Forms

The `field_civicrm` field may be missing from the product type.

**Check**:

```bash
drush php:eval "echo \Drupal\field\Entity\FieldStorageConfig::loadByName('commerce_product', 'field_civicrm') ? 'storage exists' : 'storage missing';"
```

**Fix**:

```php
// Add to all product types:
commerce_civicrm_add_field_to_all_product_types();

// Or add to a specific product type:
commerce_civicrm_add_field_to_product_type('my_product_type');
```

Then clear caches: `drush cr`.

### CiviCRM Not Available

**Symptoms**: "CiviCRM is not available" errors in logs, warning on status page.

**Diagnostic steps**:

1. Check status page: `/admin/reports/status`
2. Verify CiviCRM module is enabled: `drush pm:list | grep civicrm`
3. Check if CiviCRM is in maintenance mode (upgrade active or environment set
   to `Maintenance`)
4. Test CiviCRM access directly: visit `/civicrm`

**Check programmatically**:

```php
$helper = \Drupal::service('commerce_civicrm.civicrm_helper');
var_dump($helper->isAvailable());           // Basic availability
var_dump($helper->isReadyForOperations());  // Availability + not in maintenance
```

## Configuration Issues

### Entity Option Dropdowns Are Empty

The product form dropdowns (membership types, financial types, events, mailing
groups) are populated live from CiviCRM.

**Possible causes**:

- CiviCRM is unavailable or in maintenance mode
- No active entities of that type exist in CiviCRM (e.g., no active events)
- CiviCRM API access issue

**Fix**: Verify CiviCRM connectivity first, then check that at least one entity
of the relevant type is active in CiviCRM.

### Product Settings Not Saving

**Check** that `field_civicrm` exists on the product type:

```php
$field = \Drupal\field\Entity\FieldConfig::loadByName('commerce_product', 'default', 'field_civicrm');
var_dump($field ? 'exists' : 'missing');
```

**Check** current stored settings:

```php
$product = \Drupal\commerce_product\Entity\Product::load($product_id);
$raw = $product->get('field_civicrm')->value;
echo $raw; // Should be a JSON string
```

## Order Processing Issues

### No CiviCRM Records Created After Order Completion

**Step 1 — Check logs**: `/admin/reports/dblog`, filter by `commerce_civicrm`.
Look for error or warning messages for the order ID.

**Step 2 — Verify product configuration**:

```php
$product = \Drupal\commerce_product\Entity\Product::load($product_id);
$settings = json_decode($product->get('field_civicrm')->value, TRUE);
var_dump($settings);
// Expected: ['enabled' => true, 'entity' => '...', 'entity_id' => ...]
```

If `enabled` is `false` or missing, the product is not configured for CiviCRM.

**Step 3 — Verify order reached the right state**:

```php
$order = \Drupal\commerce_order\Entity\Order::load($order_id);
echo $order->getState()->getId(); // Should be 'completed'
```

For the default workflow, only `draft → completed` triggers processing.

**Step 4 — Test processing manually**:

```php
$order = \Drupal\commerce_order\Entity\Order::load($order_id);
$updater = \Drupal::service('commerce_civicrm.order_civicrm_updater');
$results = $updater->processOrder($order);
var_dump($results);
```

### Contact Not Created in CiviCRM

**Possible causes**:

- Order has no customer (anonymous checkout)
- Customer has no email address
- CiviCRM deduplication rule matched multiple contacts ambiguously

**Check**:

```php
$order = \Drupal\commerce_order\Entity\Order::load($order_id);
$customer = $order->getCustomer();
echo $customer ? 'user ' . $customer->id() : 'no customer';

$updater = \Drupal::service('commerce_civicrm.contact_updater');
$contact_id = $updater->getContactIdByUser($customer);
echo $contact_id ? 'contact ' . $contact_id : 'no contact found';
```

### Contributions Created But No Membership

This happens when the product is configured with a financial type but no
membership type. For linked membership+contribution, the product must have
**both** `membership_type_id` (entity type = membership) and a financial type
configured.

### Event Products Not Creating Registrations

**This is expected** — event participant creation (`Participant::create()`) is
not yet implemented. The product form UI supports event configuration, but the
backend `processOrderItem()` has no `event` branch. See
[FMO #32](../fmo/32-advanced-civicrm-integration.md) §4.

### Mailing Subscriptions Not Reversed on Cancellation

**This is a known gap** — `processCancellation()` handles memberships and
contributions but does not call `MailingUpdater::removeContactFromMailingGroup()`
for mailing products. The contact remains in the group after order cancellation.

### Duplicate Contributions in CiviCRM

**Possible cause**: You are using a **fulfillment workflow** (`order_default_validation`).
Both `onOrderValidate()` and `onOrderFulfill()` call `processOrder()` without
state guards. The linked membership+contribution path has a duplicate guard, but
standalone contributions do not.

**Workaround**: Use the default workflow (`order_default`) which only fires
`onOrderPlace()` with a proper `draft → completed` guard.

See [todo #37](../development/todo.md) for the tracked bug.

## Logging and Debugging

### View Module Logs

1. Go to `/admin/reports/dblog`
2. Filter by type: `commerce_civicrm`
3. Review error, warning, and info messages

### Enable Verbose Logging

```php
// In settings.php:
$config['system.logging']['error_level'] = 'verbose';
```

This enables debug-level messages (per-item processing details, product settings).

### Common Log Messages

**Successful operations**:

```
INFO: Processing order 456 placement (draft → completed) in workflow order_default
INFO: Found 2 order items to process for order 456
INFO: Created membership 789 for order item 1 (order 456)
INFO: Created contribution 101 for order item 2 (order 456)
INFO: Completed processing order 456. Created records: {"memberships":[789],"contributions":[101]}
```

**Warnings**:

```
WARNING: Order 456 has no customer - skipping CiviCRM integration
WARNING: Could not find or create CiviCRM contact for user 12
WARNING: Failed to create membership for type 3
WARNING: CiviCRM integration not enabled for product 7 - skipping
```

**Errors**:

```
ERROR: CiviCRM is not available - skipping order 456 processing
ERROR: Error processing order item 2 for order 456: Invalid financial type
```

## Diagnostic Quick Reference

| Symptom | First Check | Service/Method |
|---------|------------|---------------|
| CiviCRM unavailable | `/admin/reports/status` | `CivicrmHelper::isAvailable()` |
| No records created | Product `field_civicrm` JSON | `OrderCivicrmUpdater::getCivicrmProductSettings()` |
| Contact not found | Customer has email? | `ContactUpdater::getContactIdByUser()` |
| Duplicate contributions | Which workflow? | Check `order.getState()` |
| Event not registered | Expected — not implemented | See FMO #32 §4 |
| Mailing not cancelled | Expected — not implemented | See FMO #31 §4 |

## Getting Help

### Before Seeking Support

1. **Check logs** at `/admin/reports/dblog` filtered by `commerce_civicrm`
2. **Verify product configuration** — inspect `field_civicrm` JSON
3. **Test with minimal setup** — single product, default workflow
4. **Note versions** — Drupal, Commerce, CiviCRM, PHP, and module version

### Information to Provide

- Drupal core version, Commerce version, CiviCRM version
- Commerce workflow in use (default vs fulfillment)
- Product `field_civicrm` JSON value
- Relevant log messages from `commerce_civicrm` channel
- Steps to reproduce

### Resources

- Module documentation: `doc/` directory
- Service reference: [Services Overview](../services/overview.md)
- Development backlog: [todo.md](../development/todo.md)
