# Commerce CiviCRM - MailingUpdater Service

## Overview

The **MailingUpdater** service is a dedicated service class that handles all CiviCRM mailing group operations within the Commerce CiviCRM integration module. This service follows the same pattern as other updater services and provides comprehensive mailing group management functionality.

## Features

### Core Mailing Operations
- **Add to Mailing Group**: Adds contacts to CiviCRM mailing groups with preferences
- **Remove from Mailing Group**: Removes contacts from mailing groups
- **Check Group Membership**: Checks if contacts are already in groups (prevents duplicates)
- **Update Membership**: Updates existing group memberships
- **Bulk Operations**: Adds multiple contacts to groups at once

### Mailing Group Management
- **Get Mailing Groups**: Retrieves available CiviCRM mailing groups
- **Get All Groups**: Retrieves all CiviCRM groups (not just mailing groups)
- **Group Statistics**: Gets membership statistics for groups
- **Status Management**: Handles group membership statuses (Added, Pending, Removed)

### Advanced Features
- **Double Opt-in Support**: Framework for double opt-in confirmation emails
- **Welcome Messages**: Framework for welcome message functionality
- **Order Integration**: Specialized method for order-based subscriptions
- **Preference Handling**: Supports various subscription preferences

## Service Integration

### Dependency Injection
The service is properly integrated into the Drupal service container:

```yaml
commerce_civicrm.mailing_updater:
  class: Drupal\commerce_civicrm\Service\MailingUpdater
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

The MailingUpdater is integrated into the OrderCivicrmUpdater service:

```php
case 'mailing':
  $mailing_preferences = $settings['mailing_preferences'] ?? [];
  $success = $this->mailingUpdater->processMailingSubscriptionFromOrder(
    $contact_id, 
    $settings['entity_id'], 
    $order,
    $mailing_preferences
  );
  if ($success) {
    $results['mailings'][] = $settings['entity_id'];
    // ... logging
  }
  break;
```

## API Methods

### Primary Methods
- `addContactToMailingGroup($contact_id, $mailing_group_id, $preferences = [])`
- `removeContactFromMailingGroup($contact_id, $mailing_group_id)`
- `processMailingSubscriptionFromOrder($contact_id, $mailing_group_id, $order, $preferences = [])`
- `getMailingGroups()`
- `getAllGroups()`
- `bulkAddContactsToMailingGroup($contact_ids, $mailing_group_id, $preferences = [])`

### Helper Methods
- `checkGroupMembership($contact_id, $mailing_group_id)`
- `updateGroupMembership($contact_id, $mailing_group_id, $preferences)`
- `sendDoubleOptInEmail($contact_id, $mailing_group_id)`
- `sendWelcomeMessage($contact_id, $mailing_group_id)`
- `getGroupMembershipStatuses()`
- `getMailingGroupStatistics($mailing_group_id)`

## Mailing Preferences

The service supports various mailing preferences:

```php
$preferences = [
  'double_opt_in' => TRUE,        // Require email confirmation
  'send_welcome' => TRUE,         // Send welcome message
  'update_existing' => TRUE,      // Update existing memberships
  'source' => 'Commerce Order #123', // Source reference
];
```

## Error Handling

The service includes comprehensive error handling:
- CiviCRM initialization checks
- API operation validation
- Exception catching and logging
- Graceful degradation for missing data
- Duplicate membership prevention

## Integration Points

### Product Configuration
The service is integrated into product configuration forms:
- `_commerce_civicrm_get_mailing_groups()` uses the service to populate form options

### Rules Integration
The CiviCrmAddMailingSubscription Rules action uses the service:
- Updated to use MailingUpdater instead of ContactUpdater
- Supports all mailing preferences

### Order Processing
- Integrated into the main order processing workflow
- Handles mailing subscriptions when orders are completed
- Supports subscription preferences from product configuration

## Group Types

The service handles different types of CiviCRM groups:

### Mailing Groups
- Groups specifically configured for mailing lists
- Retrieved via `getMailingGroups()`
- Filtered by group type "Mailing List"

### All Groups
- Any CiviCRM group (access control, mailing, etc.)
- Retrieved via `getAllGroups()`
- Useful for broader group management

## Membership Statuses

The service manages group membership statuses:
- **Added**: Active group membership
- **Pending**: Awaiting confirmation (double opt-in)
- **Removed**: Previously removed from group

## Future Enhancements

The service is designed to be extensible:
- Double opt-in email functionality can be fully implemented
- Welcome message system can be enhanced
- Integration with CiviCRM's mailing workflows
- Advanced segmentation and targeting features
- Automated unsubscribe handling

## Example Usage

```php
// Get the service
$mailing_updater = \Drupal::service('commerce_civicrm.mailing_updater');

// Add contact to mailing group with preferences
$success = $mailing_updater->addContactToMailingGroup(
  $contact_id,
  $mailing_group_id,
  [
    'double_opt_in' => TRUE,
    'send_welcome' => TRUE,
    'update_existing' => TRUE,
  ]
);

// Process subscription from order
$success = $mailing_updater->processMailingSubscriptionFromOrder(
  $contact_id,
  $mailing_group_id,
  $order,
  $preferences
);

// Bulk add multiple contacts
$results = $mailing_updater->bulkAddContactsToMailingGroup(
  [$contact_id1, $contact_id2, $contact_id3],
  $mailing_group_id,
  ['send_welcome' => TRUE]
);
```

## Separation from ContactUpdater

The MailingUpdater was created as a separate service to:
- **Single Responsibility**: Focus specifically on mailing group operations
- **Code Organization**: Keep contact management and mailing management separate
- **Extensibility**: Allow for specialized mailing features without cluttering ContactUpdater
- **Maintainability**: Easier to maintain and extend mailing-specific functionality

This service completes the specialized service architecture for CiviCRM integration, providing dedicated mailing group management alongside the other updater services.
