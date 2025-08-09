# OrderCivicrmUpdater Service

The `OrderCivicrmUpdater` is the main orchestration service that processes completed Commerce orders and coordinates CiviCRM integration across multiple specialized updater services.

## Purpose

This service acts as the central coordinator for processing Commerce orders with CiviCRM integration, delegating specific tasks to specialized updater services based on the purchased products' configuration.

## Key Features

- **Order Processing Orchestration**: Coordinates multiple CiviCRM operations for a single order
- **Contact Management**: Creates or updates CiviCRM contacts based on order customer data
- **Product-Based Integration**: Processes different CiviCRM operations based on product configuration
- **Comprehensive Result Tracking**: Returns detailed results including contact IDs and processing status
- **Error Aggregation**: Collects and reports errors from all sub-operations

## Dependencies

- `ContactUpdater` - Creates/updates CiviCRM contacts
- `ContributionUpdater` - Manages CiviCRM contributions
- `MembershipUpdater` - Handles CiviCRM memberships
- `EventUpdater` - Manages CiviCRM event registrations
- `MailingUpdater` - Handles mailing list subscriptions
- `LoggerChannelFactoryInterface` - For logging operations

## Main Methods

### `processCompletedOrder(OrderInterface $order)`

Main method that processes a completed order:
- Extracts customer information from the order
- Creates or updates CiviCRM contact
- Processes each order item based on product configuration
- Coordinates all CiviCRM operations
- Returns comprehensive results

**Return Value:**
```php
[
    'contact_id' => int,     // CiviCRM contact ID
    'success' => bool,       // Overall success status
    'errors' => array,       // Collection of any errors
    'results' => array       // Detailed results from each operation
]
```

## Processing Flow

1. **Contact Processing**: 
   - Extract customer data from order
   - Create or update CiviCRM contact using `ContactUpdater`
   - Return contact ID for subsequent operations

2. **Order Item Processing**:
   - Iterate through each order item
   - Check product for `field_civicrm` configuration
   - Process enabled CiviCRM operations based on configuration

3. **CiviCRM Operations**:
   - **Contributions**: Create financial contributions via `ContributionUpdater`
   - **Memberships**: Add or update memberships via `MembershipUpdater`
   - **Event Registrations**: Register for events via `EventUpdater`
   - **Mailing Lists**: Subscribe to lists via `MailingUpdater`

## Product Configuration

Products are configured via the `field_civicrm` field containing JSON:

```json
{
    "enabled": true,
    "contribution": {
        "enabled": true,
        "financial_type_id": 1,
        "campaign_id": 5
    },
    "membership": {
        "enabled": true,
        "membership_type_id": 2,
        "start_date": "order_date"
    },
    "event": {
        "enabled": true,
        "event_id": 10,
        "role_id": 1
    },
    "mailing": {
        "enabled": true,
        "group_ids": [5, 8, 12]
    }
}
```

## Usage Example

```php
// Inject the service
$orderCivicrmUpdater = \Drupal::service('commerce_civicrm.order_civicrm_updater');

// Process a completed order
$results = $orderCivicrmUpdater->processCompletedOrder($order);

// Check results
if (!empty($results['contact_id'])) {
    // Processing succeeded
    $contact_id = $results['contact_id'];
    \Drupal::logger('commerce_civicrm')->info('Order processed successfully. Contact ID: @contact_id', [
        '@contact_id' => $contact_id
    ]);
} else {
    // Processing failed
    $errors = $results['errors'] ?? ['Unknown error'];
    \Drupal::logger('commerce_civicrm')->error('Order processing failed: @errors', [
        '@errors' => implode(', ', $errors)
    ]);
}
```

## Error Handling

The service implements comprehensive error handling:

- **Non-blocking Errors**: Individual operation failures don't stop overall processing
- **Error Aggregation**: Collects errors from all sub-operations
- **Detailed Logging**: Logs both successes and failures with context
- **Graceful Degradation**: Returns partial results even if some operations fail

## Integration Points

### OrderCompleteSubscriber
The service is typically called by `OrderCompleteSubscriber` during order workflow transitions:

```php
// Called during order placement (draft → completed)
$results = $this->orderCivicrmUpdater->processCompletedOrder($order);

// Called during order validation (validation → completed)
$results = $this->orderCivicrmUpdater->processCompletedOrder($order);
```

### Rules Integration
Can also be called from Rules actions for custom workflow processing.

## Performance Considerations

- **Batch Processing**: Processes all order items in a single operation
- **API Efficiency**: Minimizes CiviCRM API calls through service coordination
- **Caching**: Reuses contact information across multiple operations
- **Error Recovery**: Continues processing even if individual operations fail

## Related Services

- [`ContactUpdater`](contact-updater.md) - Creates/updates CiviCRM contacts
- [`ContributionUpdater`](contribution-updater.md) - Handles financial contributions
- [`MembershipUpdater`](membership-updater.md) - Manages memberships
- [`EventUpdater`](event-updater.md) - Handles event registrations
- [`MailingUpdater`](mailing-updater.md) - Manages mailing subscriptions
- [`OrderCompleteSubscriber`](order-complete-subscriber.md) - Event subscriber that calls this service
