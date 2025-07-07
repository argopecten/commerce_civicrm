# Commerce CiviCRM - MembershipUpdater Service

## Overview

The **MembershipUpdater** service is a dedicated service class that handles all CiviCRM membership operations within the Commerce CiviCRM integration module. This service follows the same pattern as ContactUpdater and EventUpdater services.

## Features

### Core Membership Operations
- **Create Membership**: Creates new CiviCRM memberships from Commerce orders
- **Update Membership**: Updates existing memberships with new order information
- **Renew Membership**: Extends membership periods based on membership type rules
- **Cancel Membership**: Cancels memberships when orders are cancelled
- **Find Existing**: Locates existing memberships to prevent duplicates

### Membership Type Management
- **Get Membership Types**: Retrieves available CiviCRM membership types
- **Get Membership Statuses**: Retrieves available membership status options
- **Calculate Dates**: Automatically calculates membership start and end dates

### Smart Features
- **Duplicate Prevention**: Checks for existing memberships before creating new ones
- **Date Calculations**: Handles various membership period types (fixed, rolling, lifetime)
- **Status Management**: Maps Commerce order states to CiviCRM membership statuses
- **Custom Fields**: Framework for mapping custom fields from orders to memberships

## Service Integration

### Dependency Injection
The service is properly integrated into the Drupal service container:

```yaml
commerce_civicrm.membership_updater:
  class: Drupal\commerce_civicrm\Service\MembershipUpdater
  arguments:
    - '@entity_type.manager'
    - '@logger.factory'
    - '@commerce_civicrm.civicrm_helper'
```

### Dependencies
- **EntityTypeManagerInterface**: For handling Drupal entities
- **LoggerChannelFactoryInterface**: For comprehensive logging
- **CivicrmHelper**: For robust CiviCRM initialization and helper methods

## Usage in Order Processing

The MembershipUpdater is integrated into the OrderCivicrmUpdater service:

```php
case 'membership':
  $membership_id = $this->membershipUpdater->createMembershipFromOrder(
    $contact_id, 
    $settings['entity_id'], 
    $order, 
    $order_item
  );
  if ($membership_id) {
    $results['memberships'][] = $membership_id;
    // ... logging
  }
  break;
```

## API Methods

### Primary Methods
- `createMembershipFromOrder($contact_id, $membership_type_id, $order, $order_item)`
- `renewMembership($contact_id, $membership_type_id, $order)`
- `cancelMembership($contact_id, $membership_type_id, $order)`
- `getMembershipTypes()`
- `getMembershipStatuses()`

### Protected Helper Methods
- `findExistingMembership($contact_id, $membership_type_id)`
- `updateMembership($membership_id, $order, $order_item)`
- `getMembershipTypeDetails($membership_type_id)`
- `calculateMembershipDates($membership_type)`
- `addCustomFieldsToMembership($membership_data, $order, $order_item)`
- `updateMembershipStatus($membership_id, $order)`

## Error Handling

The service includes comprehensive error handling:
- CiviCRM initialization checks
- API operation validation
- Exception catching and logging
- Graceful degradation for missing data

## Integration Points

### Product Configuration
The service is integrated into product configuration forms through helper functions:
- `_commerce_civicrm_get_membership_types()` uses the service to populate form options

### Rules Integration
The CiviCrmAddMembership Rules action uses the service for membership creation:
- Updated to use MembershipUpdater instead of direct API calls
- Maintains backward compatibility with existing rules

### Order Processing
- Integrated into the main order processing workflow
- Handles membership creation when orders are completed
- Supports membership cancellation when orders are cancelled

## Status Mapping

The service maps Commerce order states to CiviCRM membership statuses:
- `completed` → `Current`
- `canceled` → `Cancelled`
- `draft`/`pending` → `Pending`
- Default → `New`

## Date Handling

The service automatically calculates membership dates based on membership type:
- **Rolling Memberships**: Start from join date
- **Fixed Memberships**: Align with calendar periods
- **Lifetime Memberships**: No end date
- **Custom Periods**: Support for days, months, years

## Future Enhancements

The service is designed to be extensible:
- Custom field mapping can be added to `addCustomFieldsToMembership()`
- Additional membership business logic can be added
- Integration with CiviCRM's membership renewal workflows
- Support for membership grace periods and payment plans

## Example Usage

```php
// Get the service
$membership_updater = \Drupal::service('commerce_civicrm.membership_updater');

// Create a membership from an order
$membership_id = $membership_updater->createMembershipFromOrder(
  $contact_id,
  $membership_type_id,
  $order,
  $order_item
);

// Renew an existing membership
$membership_id = $membership_updater->renewMembership(
  $contact_id,
  $membership_type_id,
  $order
);
```

This service completes the membership functionality in the Commerce CiviCRM module, providing a robust, well-integrated solution for membership management.
