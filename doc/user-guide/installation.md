# Installation & Configuration

## Overview

The Commerce CiviCRM module provides seamless integration between Drupal Commerce and CiviCRM, automatically creating and updating CiviCRM records when customers complete orders.

## Requirements

- Drupal 10.3+ or 11.x
- CiviCRM module (civicrm:civicrm)
- Drupal Commerce (commerce_product, commerce_order, commerce_payment)
- Profile module (for customer data)
- State Machine module (for order state transitions)
- Rules module (optional, for advanced automation)

## Installation

1. Download and install the module in your Drupal site
2. Enable the module: `drush en commerce_civicrm`
3. The module will automatically:
   - Create the required `field_civicrm` field on all existing product types
   - Add the field to any new product types created after installation
4. Configure CiviCRM integration on individual products

## Automatic Field Management

The module automatically handles the required CiviCRM integration field:

- **During Installation**: The `field_civicrm` field is automatically added to all existing Commerce product types
- **For New Product Types**: When you create new product types after installation, the field is automatically added via `hook_commerce_product_type_insert()`
- **Manual Management**: Helper functions are available for troubleshooting or manual field management:
  - `commerce_civicrm_add_field_to_product_type($bundle)` - Add field to specific product type
  - `commerce_civicrm_add_field_to_all_product_types()` - Add field to all existing product types

The field is hidden by default in form and view displays to keep the product interface clean, but administrators can show it through the standard Drupal field UI if needed.

## Initial Configuration

After installation:

1. **Verify CiviCRM Connection**: Check that CiviCRM is properly configured and accessible
2. **Test Field Addition**: Confirm that existing product types have the CiviCRM field
3. **Create Test Product**: Set up a test product with CiviCRM integration enabled
4. **Process Test Order**: Complete a test order and verify CiviCRM records are created
5. **Review Logs**: Check logs at `/admin/reports/dblog` for any issues

## Permissions

The module uses existing Commerce and CiviCRM permissions. Ensure users have appropriate permissions for:
- Commerce order processing
- CiviCRM contact access
- Product configuration (for administrators)

## Next Steps

- [Product Configuration](product-configuration.md) - Configure products for CiviCRM integration
- [Order Processing](order-processing.md) - Understand how orders are processed
- [Extensions Overview](../extensions/overview.md) - Learn about advanced features
