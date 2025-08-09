# OrderCompleteSubscriber Service

The `OrderCompleteSubscriber` is an event subscriber service that listens for multiple Drupal Commerce order workflow transition events and triggers CiviCRM integration workflows at the appropriate stages.

## Purpose

This service automatically processes orders during key workflow transitions, creating or updating CiviCRM contacts, contributions, memberships, and other records based on the purchased products' configuration and specific order states.

## Key Features

- **Multi-Event Processing**: Handles 4 different order workflow transitions
- **State Validation**: Validates from/to states for each transition
- **CiviCRM Integration Detection**: Checks if order items have CiviCRM integration enabled
- **Comprehensive Logging**: Logs all processing steps for debugging and monitoring
- **Extensible Design**: Includes TODO sections for custom business logic

## Events Subscribed

The service subscribes to these Commerce order workflow transition events:

```php
commerce_order.place.post_transition    // Draft → Any (when order is placed)
commerce_order.cancel.post_transition   // Any → Canceled (when order is canceled)
commerce_order.validate.post_transition // Validation → Any (when order validates from validation state)
commerce_order.fulfill.post_transition  // Fulfillment → Any (when order is fulfilled from fulfillment state)
```

Each event handler includes state validation to ensure it only processes the intended transitions.

## Dependencies

- `LoggerChannelFactoryInterface` - For logging order processing
- `EntityTypeManagerInterface` - For loading Commerce entities
- `OrderCivicrmUpdater` - Main service for processing CiviCRM integration

## Methods

### `onOrderPlace(WorkflowTransitionEvent $event)`

Handles order placement (draft → completed):
- Validates transition is from 'draft' state
- Logs the order placement
- Processes CiviCRM integration if enabled
- Calls `OrderCivicrmUpdater::processPlacedOrder()` for initial order processing
- Focuses on contact creation, contribution recording, and event registrations

### `onOrderCancel(WorkflowTransitionEvent $event)`

Handles order cancellation (any → canceled):
- Validates transition is to 'canceled' state
- Logs the order cancellation
- Processes cancellation-specific CiviCRM updates
- Calls `OrderCivicrmUpdater::processCancelledOrder()` for cancellation handling
- Updates contribution status and participant statuses to cancelled

### `onOrderValidate(WorkflowTransitionEvent $event)`

Handles order validation (validation → any state):
- Validates transition is from 'validation' state
- Logs the order validation with destination state
- Processes comprehensive CiviCRM integration if enabled
- Calls `OrderCivicrmUpdater::processCompletedOrder()` for full processing
- Handles all entity types: contacts, contributions, memberships, events, and mailings
- Supports transitions to any state (completed, fulfilled, etc.)

### `onOrderFulfill(WorkflowTransitionEvent $event)`

Handles order fulfillment (fulfillment → any state):
- Validates transition is from 'fulfillment' state
- Logs the order fulfillment with destination state
- Processes final CiviCRM updates if enabled
- Calls `OrderCivicrmUpdater::processCompletedOrder()` for comprehensive processing
- Handles any remaining updates like membership activations or final mailings
- Supports transitions to any state from fulfillment

### `hasCivicrmIntegration(OrderInterface $order)`

Private method that checks if any order items have CiviCRM integration enabled:
- Iterates through all order items
- Checks each product for `field_civicrm` configuration
- Returns `TRUE` if any product has CiviCRM integration enabled

## Configuration Requirements

For the subscriber to process an order:

1. **Product Configuration**: Products must have the `field_civicrm` field configured
2. **CiviCRM Settings**: The field must contain valid JSON with `enabled: true`
3. **Order Workflow**: Order must transition through the monitored workflow states

## Workflow States

The service monitors these order workflow transitions:

| Event | From State | To State | Purpose |
|-------|------------|----------|---------|
| place | draft | completed | Initial order processing |
| cancel | any | canceled | Handle cancellations |
| validate | validation | any | Post-validation processing |
| fulfill | fulfillment | any | Fulfillment handling |

## Usage Example

The service works automatically once registered. During order workflow transitions:

```php
// Order place event triggers (draft → completed)
$subscriber->onOrderPlace($event);

// Order validation event triggers (validation → any state)
$subscriber->onOrderValidate($event);

// Both check for CiviCRM integration and process accordingly
if ($subscriber->hasCivicrmIntegration($order)) {
    $results = $orderCivicrmUpdater->processCompletedOrder($order);
}
```

## Logging

All processing steps are logged to the `commerce_civicrm` channel:

- Order transition notifications with state information
- CiviCRM integration status for each transition
- Processing success/failure with contact IDs
- Error messages for debugging
- TODO reminders for unimplemented features

## Error Handling

The service includes comprehensive error handling:

- State validation prevents inappropriate processing
- Graceful handling of missing CiviCRM configuration
- Detailed error logging for debugging
- Continues processing even if individual items fail
- No exceptions thrown to avoid breaking order workflow

## Extensibility

The service includes TODO sections for custom business logic:

### Cancel Handler
- Update contribution status to canceled
- Remove contacts from mailing lists
- Reverse membership assignments
- Send cancellation notifications

### Fulfill Handler
- Update membership status to active
- Send completion/welcome emails
- Trigger post-purchase workflows
- Update contact preferences

## Related Services

- [`OrderCivicrmUpdater`](order-civicrm-updater.md) - Main processing service
- [`ContributionUpdater`](contribution-updater.md) - Handles CiviCRM contributions
- [`ContactUpdater`](contact-updater.md) - Manages CiviCRM contacts
