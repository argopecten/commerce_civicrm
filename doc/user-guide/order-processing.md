# Order Processing

## Overview

When customers complete orders, the module automatically processes CiviCRM integration based on the products in their cart. The processing happens through Drupal's event system and follows a specific workflow.

## Processing Workflow

### Event Triggers
The module processes orders when they transition to specific states:
- **Order Placed**: `commerce_order.place.post_transition`
- **Order Fulfilled**: `commerce_order.fulfill.post_transition`

### Processing Steps

1. **Contact Processing**: Creates or updates CiviCRM contact from billing profile
2. **Product Analysis**: Reviews each order item for CiviCRM configuration
3. **Entity Creation**: Creates appropriate CiviCRM records based on product settings
4. **Logging**: Records all operations for audit and debugging

## Detailed Processing

### 1. Contact Management
- Extracts customer data from the order's billing profile
- Searches for existing CiviCRM contact by email address
- Creates new contact if none exists
- Updates existing contact with current information

### 2. Product Processing
For each order item with CiviCRM integration enabled:

#### Contributions
- Creates a CiviCRM contribution record
- Uses the configured Financial Type
- Records the order amount and currency
- Links to the customer's contact

#### Memberships
- Creates a new membership or renews existing one
- Uses the configured Membership Type
- Calculates start and end dates based on membership rules
- Sets appropriate membership status

#### Event Registration
- Registers the customer for the specified event
- Assigns the configured participant role
- Sets participant status to "Registered"
- Prevents duplicate registrations

#### Mailing List Subscription
- Adds the customer to the specified mailing group
- Applies configured subscription preferences
- Handles double opt-in if enabled
- Sends welcome messages if configured

### 3. Error Handling
- Gracefully handles CiviCRM connectivity issues
- Prevents duplicate record creation
- Logs all errors with context
- Continues processing other items if one fails

## Service Architecture

The processing uses a service-based architecture:

### OrderCivicrmUpdater
Main orchestration service that:
- Coordinates the entire processing workflow
- Manages the processing sequence
- Collects and reports results

### Specialized Services
- **ContactUpdater**: Handles contact creation and updates
- **ContributionUpdater**: Manages contribution records
- **MembershipUpdater**: Handles membership operations
- **EventUpdater**: Manages event registrations
- **MailingUpdater**: Handles mailing group subscriptions

## Processing Results

The system tracks processing results:

```php
$results = [
  'contact_id' => 123,
  'contributions' => [456],
  'memberships' => [789],
  'events' => [101],
  'mailings' => [202]
];
```

## Logging and Monitoring

### Log Categories
- **Info**: Successful operations and status updates
- **Warning**: Non-critical issues (missing profiles, etc.)
- **Error**: Failed operations and exceptions
- **Debug**: Detailed operation information

### Log Locations
- Drupal logs: `/admin/reports/dblog`
- Log channel: `commerce_civicrm`

### Sample Log Messages
```
INFO: Created CiviCRM contact 123 for order 456
INFO: Created contribution 789 for order 456
WARNING: Billing profile incomplete for order 456
ERROR: Failed to create membership: Invalid membership type
```

## Duplicate Prevention

The system prevents duplicate records through:

### Contact Matching
- Matches contacts by email address
- Updates existing contacts instead of creating duplicates

### Contribution Checking
- Checks for existing contributions from the same order
- Prevents multiple contributions for the same order item

### Event Registration
- Verifies existing participant records
- Prevents duplicate event registrations

### Mailing Group Membership
- Checks existing group memberships
- Updates preferences for existing members

## Performance Considerations

### Efficient Processing
- Batches CiviCRM API calls where possible
- Uses caching for frequently accessed data
- Processes items in optimal order

### Error Recovery
- Failed items don't block processing of other items
- Comprehensive error logging for troubleshooting
- Graceful degradation when CiviCRM is unavailable

## Monitoring Order Processing

### Check Processing Status
1. Review order completion logs
2. Verify CiviCRM records were created
3. Check for any error messages
4. Validate customer data accuracy

### Common Processing Issues
- Incomplete billing profiles
- Invalid CiviCRM entity IDs
- CiviCRM connectivity problems
- Duplicate prevention conflicts

## Next Steps

- [Troubleshooting](troubleshooting.md) - Resolve common processing issues
- [Extensions Overview](../extensions/overview.md) - Learn about advanced features
- [Service Architecture](../services/overview.md) - Understand the technical implementation
