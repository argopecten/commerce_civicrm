# Commerce CiviCRM Integration

This documentation provides comprehensive information about the Commerce CiviCRM Integration Module, a Commerce-style direct integration system for Drupal Commerce and CiviCRM.

## Overview

The Commerce CiviCRM module uses **direct Commerce-style integration** instead of Rules. When an order is placed, the system automatically creates CiviCRM records based on product field configurations.

## Quick Start

1. **Install the module**: `drush en commerce_civicrm`
2. **Clear cache**: `drush cr`
3. **Add CiviCRM fields** to your product types (membership type, financial type)
4. **Configure products** with CiviCRM field values
5. **Place test orders** to verify automatic CiviCRM record creation
6. **Monitor logs** and verify CiviCRM records

## Architecture Overview

The module follows a **Commerce-native architecture** with direct service integration:

### Event Flow
1. **Commerce Order Events** - Workflow transitions like `order.place`
2. **OrderCompleteSubscriber** - Catches Commerce `WorkflowTransitionEvent` directly
3. **OrderCivicrmUpdater** - Processes order items and product configurations
4. **CiviCRM Services** - Create appropriate CiviCRM records automatically

### Core Components
- **Event Subscriber** - Listens to Commerce workflow transition events
- **Order Processor** - Main service that coordinates CiviCRM record creation
- **CiviCRM Services** - Handle all CiviCRM API operations
- **Product Fields** - Configure CiviCRM behavior through product field settings

## Product Configuration

To enable automatic CiviCRM integration, add the `field_civicrm` field to your Commerce Product types. The module can provision this field automatically via `hook_commerce_product_type_insert()`, or you can add it manually.

### field_civicrm
- **Type**: `text_long` (plain text)
- **Storage**: JSON blob containing CiviCRM settings
- **Key properties**:
  - `membership_type_id` — CiviCRM membership type ID
  - `financial_type_id` — CiviCRM financial type ID
  - `is_membership` — Whether this product creates a membership

See [Product Field Schema](development/product-field-schema.md) for the full JSON schema and defaults.

## Testing the Integration

### 1. Enable Module
```bash
drush en commerce_civicrm
drush cr
```

### 2. Check Services are Registered
```bash
drush devel:services | grep commerce_civicrm
```

Should show:
- `commerce_civicrm.order_civicrm_updater`
- `commerce_civicrm.order_complete_subscriber`

### 3. Create Test Product
1. Create a Commerce Product type (the `field_civicrm` field is added automatically)
2. Create a product and configure its `field_civicrm` JSON values
3. Set appropriate CiviCRM IDs (`membership_type_id`, `financial_type_id`)

### 4. Place Test Order
1. Add product to cart
2. Complete checkout process
3. Check logs for integration activity:

```bash
drush ws --tail --filter="commerce_civicrm"
```

### 5. Verify CiviCRM Records
Check your CiviCRM instance for:
- New/updated contact record
- Membership record (if product has `membership_type_id`)
- Contribution record (if product has `financial_type_id`)

See [Testing](development/testing.md) for the full manual test checklist.

## Integration Features

The module supports flexible product-based configuration:

- **🎯 Automatic Processing** - CiviCRM records created automatically based on product fields
- **📦 Product Configuration** - Configure behavior through standard Drupal fields
- **🔍 Monitoring** - Comprehensive logging and debugging capabilities
- **⚡ High Performance** - Direct service integration without Rules overhead

## Log Messages

The integration provides detailed logging. Look for these messages:

### Success Messages
- `Processing order @order_id for CiviCRM integration`
- `Processing order item @item_id for product @product_id`
- `Created membership @membership_id for order item @item_id`
- `Created contribution @contribution_id for order item @item_id`

### Warning Messages
- `Order @order_id has no customer`
- `Could not find or create CiviCRM contact for user @uid`
- `CiviCRM is not available - skipping order @order_id processing`

## Troubleshooting

### No CiviCRM Records Created
1. Check CiviCRM is properly initialized
2. Verify product has required fields
3. Check user has valid email/contact info
4. Review logs for error messages

### Service Not Found Errors
1. Clear Drupal cache: `drush cr`
2. Check `commerce_civicrm.services.yml` syntax
3. Verify all service dependencies exist

### Event Not Firing
1. Confirm Commerce workflow transitions are working
2. Check event subscriber registration in services.yml
3. Verify order state changes trigger events

## Customization

### Extending the Module
- **Decorate services** — Override `OrderCivicrmUpdater`, `ContactUpdater`, etc. via Drupal's service decoration
- **Event subscribers** — Subscribe to Commerce `WorkflowTransitionEvent` for custom logic
- **hook_form_alter** — Customise the `field_civicrm` widget on product edit forms
- **Extend `field_civicrm` JSON** — Add new keys to the JSON schema for additional CiviCRM record types

See [Developer Guide](development/developer-guide.md) and [Hooks & Events](development/hooks-and-events.md) for details.

## Benefits of Direct Integration

Compared to the previous Rules-based approach:

✅ **Reliable**: No dependency on Rules service availability  
✅ **Performance**: Direct service calls, no event dispatch overhead  
✅ **Maintainable**: Clear code flow, easier debugging  
✅ **Flexible**: Easy to customize and extend  
✅ **Commerce-Style**: Follows Commerce core module patterns  

## Documentation Structure

### User Guide
- **[Installation & Configuration](user-guide/installation.md)** - Setup and basic configuration
- **[Product Configuration](user-guide/product-configuration.md)** - Setting up CiviCRM fields on products
- **[Order Processing](user-guide/order-processing.md)** - How automatic processing works
- **[Troubleshooting](user-guide/troubleshooting.md)** - Common issues and solutions

### Development
- **[Developer Guide](development/developer-guide.md)** - Architecture overview, extension points, and documentation index
- **[Architecture](development/architecture.md)** - Module structure, service graph, and data flow
- **[Services](development/services.md)** - Detailed service reference with public API
- **[API Reference](development/api-reference.md)** - CiviCRM API4 patterns used in the module
- **[Hooks & Events](development/hooks-and-events.md)** - Drupal hooks, event subscribers, and extension points
- **[Product Field Schema](development/product-field-schema.md)** - `field_civicrm` JSON schema and product configuration
- **[Field Automation](development/field-automation.md)** - Automatic `field_civicrm` provisioning to product types
- **[Testing](development/testing.md)** - Testing strategy and manual test procedures
- **[TODO / Backlog](development/todo.md)** - Open issues and development backlog

## Support

For detailed information on specific topics, navigate to the appropriate documentation section above.
