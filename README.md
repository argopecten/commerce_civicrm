# Commerce CiviCRM Integration Module

A Drupal module that provides seamless integration between Drupal Commerce and CiviCRM, automatically creating and updating CiviCRM records when customers complete orders.

## Overview

The Commerce CiviCRM module bridges the gap between your e-commerce platform and CRM system by automatically synchronizing customer data, creating contributions, managing memberships, and tracking event participation based on Commerce orders.

## Key Features

- **Automatic Contact Synchronization**: Creates and updates CiviCRM contacts from Commerce customer profiles
- **Contribution Tracking**: Automatically creates CiviCRM contributions when orders are completed
- **Membership Management**: Handles membership creation and renewals
- **Event Registration**: Tracks event participation from product purchases
- **Rules Integration**: Provides Rules actions for advanced workflow automation
- **Service-Based Architecture**: Modern, maintainable code using Drupal's service container
- **Comprehensive Logging**: Detailed logging for debugging and audit trails

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
3. The module will automatically create the required `field_civicrm` field on all product types
4. Configure CiviCRM integration on individual products

## Configuration

### Product Configuration

1. Edit any Commerce product
2. Scroll to the "CiviCRM Integration" section
3. Enable CiviCRM integration
4. Choose the integration type:
   - **Contribution**: Creates a CiviCRM contribution when purchased
   - **Membership**: Creates or renews a CiviCRM membership
   - **Event**: Registers the customer for a CiviCRM event
5. Select the appropriate CiviCRM entity (Financial Type, Membership Type, or Event)

### Order Processing

The module automatically processes orders when they transition to the "completed" state:

1. **Contact Processing**: Creates or updates CiviCRM contact from billing profile
2. **Contribution Creation**: Creates contributions for products configured as contributions
3. **Membership Processing**: Creates or renews memberships for membership products
4. **Event Registration**: Registers customers for events

## Main Service Classes

### ContactUpdater Service

Handles all CiviCRM contact operations:

#### Main Methods:

- **`updateContactFromOrder(OrderInterface $order)`**
  - Primary method for processing customer data from orders
  - Extracts contact data from billing profile
  - Creates or updates CiviCRM contacts
  - Returns the CiviCRM contact ID

- **`getContactIdByUser(UserInterface $user)`**
  - Finds the CiviCRM contact ID for a Drupal user
  - Uses UFMatch table for user-contact mapping
  - Returns contact ID or NULL if not found

- **`getMembershipTypes()`**
  - Retrieves available CiviCRM membership types
  - Returns array of options for form configuration
  - Handles CiviCRM availability gracefully

- **`getFinancialTypes()`**
  - Retrieves available CiviCRM financial types
  - Returns array of options for form configuration
  - Used for contribution configuration

- **`getProductSettings($product)`**
  - Extracts CiviCRM configuration from product field
  - Returns array with enabled status and entity configuration
  - Provides sensible defaults for missing configuration

### ContributionUpdater Service

Manages CiviCRM contributions:

#### Main Methods:

- **`createContributionFromOrder(OrderInterface $order)`**
  - Creates CiviCRM contributions from order data
  - Handles payment method mapping
  - Prevents duplicate contributions
  - Returns contribution ID or NULL

- **`createContributionFromOrderWithFinancialType(OrderInterface $order, $financial_type_id)`**
  - Creates contributions with specific financial type
  - Used by Rules actions for custom workflows
  - Supports override of default financial type

### EventUpdater Service

Handles CiviCRM event registrations:

#### Main Methods:

- **`createEventRegistrationFromOrder(OrderInterface $order)`**
  - Registers customers for CiviCRM events
  - Handles event participant creation
  - Manages participant status and roles
  - Returns participant ID or NULL

### OrderCivicrmUpdater Service

Orchestrates the complete order processing:

#### Main Methods:

- **`processCompletedOrder(OrderInterface $order)`**
  - Main entry point for order processing
  - Coordinates contact, contribution, and event processing
  - Returns comprehensive results array
  - Handles all CiviCRM integrations for an order

- **`processPlacedOrder(OrderInterface $order)`**
  - Processes orders when first placed
  - Similar to completed order but different logging
  - Allows for different processing logic if needed

## Event System

The module uses Drupal's event system to automatically process orders:

### OrderCompleteSubscriber

- **Event**: `commerce_order.place.post_transition` and `commerce_order.fulfill.post_transition`
- **Action**: Processes CiviCRM integration for completed orders
- **Service**: Uses `OrderCivicrmUpdater` to handle all CiviCRM operations

## Rules Integration

The module provides Rules actions for advanced automation:

### Available Actions:

1. **Add CiviCRM Contribution**
   - Creates a contribution for a user/order combination
   - Allows custom financial type selection
   - Useful for complex contribution workflows

2. **Add CiviCRM Membership**
   - Creates a membership for a user
   - Supports custom membership type selection
   - Handles membership duration and status

## Field Structure

### field_civicrm (Product Field)

JSON field storing CiviCRM configuration:

```json
{
  "enabled": true,
  "entity": "contribution",
  "entity_id": "1"
}
```

**Fields:**
- `enabled`: Boolean indicating if CiviCRM integration is active
- `entity`: Type of CiviCRM entity (contribution, membership, event)
- `entity_id`: ID of the specific CiviCRM entity

## API Usage

The module uses CiviCRM API4 for all operations:

### Contact Operations:
- `\Civi\Api4\Contact::get()` - Find existing contacts
- `\Civi\Api4\Contact::create()` - Create new contacts
- `\Civi\Api4\Contact::update()` - Update existing contacts
- `\Civi\Api4\Email::get()` - Find contact emails
- `\Civi\Api4\Email::create()` - Create contact emails

### Contribution Operations:
- `\Civi\Api4\Contribution::get()` - Find existing contributions
- `\Civi\Api4\Contribution::create()` - Create new contributions
- `\Civi\Api4\FinancialType::get()` - Get financial types

### Event Operations:
- `\Civi\Api4\Event::get()` - Find events
- `\Civi\Api4\Participant::create()` - Create event participants
- `\Civi\Api4\Participant::update()` - Update participant records

## Logging

The module provides comprehensive logging for debugging and audit purposes:

### Log Categories:
- **Info**: Successful operations and status updates
- **Warning**: Non-critical issues (missing profiles, etc.)
- **Error**: Failed operations and exceptions
- **Debug**: Detailed operation information

### Log Locations:
- Drupal logs: `/admin/reports/dblog`
- Log channel: `commerce_civicrm`

## Error Handling

The module gracefully handles various error conditions:

- **CiviCRM Unavailable**: Logs errors and continues without breaking
- **Missing Contact Data**: Warns about incomplete profiles
- **API Failures**: Comprehensive error logging with context
- **Duplicate Prevention**: Checks for existing records before creating

## Troubleshooting

### Common Issues:

1. **CiviCRM Not Available**
   - Check Status Report: `/admin/reports/status`
   - Verify CiviCRM module is enabled
   - Ensure CiviCRM database is accessible

2. **Contacts Not Created**
   - Check billing profile completeness
   - Verify customer email addresses
   - Review logs for specific errors

3. **Contributions Not Appearing**
   - Verify product has CiviCRM integration enabled
   - Check financial type configuration
   - Ensure order completed successfully

### Debug Mode:

Enable debug logging to see detailed operation information:

```php
// In settings.php
$config['system.logging']['error_level'] = 'verbose';
```

## Views Integration

The module includes a "My CiviCRM Orders" view at `/user/orders/civicrm` that displays:

- Order numbers with links to order details
- Order totals and status
- Creation dates
- Responsive table design for mobile devices

## Developer Information

### Service Container:

```php
// Access services in custom code
$contact_updater = \Drupal::service('commerce_civicrm.contact_updater');
$contribution_updater = \Drupal::service('commerce_civicrm.contribution_updater');
$event_updater = \Drupal::service('commerce_civicrm.event_updater');
```

### Extending the Module:

The service-based architecture makes it easy to extend:

1. Create custom services that depend on existing services
2. Use event subscribers to add custom processing
3. Implement additional Rules actions as needed

## Support

For issues and feature requests, please use the project's issue queue on Drupal.org.

## License

This module is licensed under the GPL v2 or later.