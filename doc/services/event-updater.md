# EventUpdater Service

## Overview

The **EventUpdater** service handles all CiviCRM event registration operations within the Commerce CiviCRM integration module. This service manages event participant creation, role assignment, and registration status tracking.

## Primary Responsibilities

### Event Registration Management
- **Create Participants**: Creates CiviCRM participant records for event registration
- **Role Assignment**: Assigns specific participant roles to registrants
- **Status Tracking**: Manages participant registration status
- **Duplicate Prevention**: Prevents duplicate registrations for the same contact/event

### Event Information
- **Event Retrieval**: Gets available CiviCRM events for configuration
- **Role Management**: Retrieves available participant roles
- **Event Validation**: Ensures events are active and available for registration

## Core Methods

### Primary Operations

#### `createEventRegistrationFromOrder(OrderInterface $order)`
**Purpose**: Creates event registrations based on order products configured for events

**Process**:
1. Processes each order item with event configuration
2. Validates event availability and status
3. Checks for existing participant records
4. Creates participant with specified role
5. Sets registration status

**Returns**: `array` - Array of created participant IDs

```php
$event_updater = \Drupal::service('commerce_civicrm.event_updater');
$participant_ids = $event_updater->createEventRegistrationFromOrder($order);
```

#### `createEventRegistrationWithRole($contact_id, $event_id, $participant_role_id = NULL)`
**Purpose**: Creates event registration with specific role (used by Rules actions)

**Parameters**:
- `$contact_id` - CiviCRM contact ID
- `$event_id` - CiviCRM event ID  
- `$participant_role_id` - Optional participant role ID

**Returns**: `int|null` - Participant ID or NULL on failure

```php
$participant_id = $event_updater->createEventRegistrationWithRole(
  $contact_id, 
  $event_id, 
  $participant_role_id
);
```

### Information Retrieval Methods

#### `getEvents()`
**Purpose**: Retrieves available CiviCRM events for configuration

**Returns**: `array` - Associative array of event options with dates

```php
$events = $event_updater->getEvents();
// Returns: ['5' => 'Annual Conference (2024-06-15)', '6' => 'Workshop Series (2024-07-01)']
```

#### `getParticipantRoles()`
**Purpose**: Retrieves available CiviCRM participant roles

**Returns**: `array` - Associative array of participant role options

```php
$roles = $event_updater->getParticipantRoles();
// Returns: ['1' => 'Attendee', '2' => 'Volunteer', '3' => 'Speaker']
```

## Event Data Processing

### Event Validation
Ensures events meet requirements for registration:

```php
protected function validateEvent($event_id) {
  $event = \Civi\Api4\Event::get()
    ->addSelect('id', 'title', 'is_active', 'is_public', 'start_date', 'end_date')
    ->addWhere('id', '=', $event_id)
    ->addWhere('is_active', '=', TRUE)
    ->addWhere('is_public', '=', TRUE)
    ->execute()
    ->first();
    
  if (!$event) {
    return FALSE;
  }
  
  // Check if event is in the future or currently active
  $now = new \DateTime();
  $start_date = new \DateTime($event['start_date']);
  
  return $start_date >= $now || $this->isEventCurrentlyActive($event);
}
```

### Participant Data Creation
Constructs participant record data:

```php
$participant_data = [
  'contact_id' => $contact_id,
  'event_id' => $event_id,
  'status_id' => 1, // Registered
  'role_id' => $participant_role_id ?: 1, // Default to Attendee
  'register_date' => date('Y-m-d H:i:s'),
  'source' => 'Commerce Order #' . $order->getOrderNumber(),
];
```

### Duplicate Prevention
Checks for existing participant records:

```php
protected function checkExistingParticipant($contact_id, $event_id) {
  $existing = \Civi\Api4\Participant::get()
    ->addWhere('contact_id', '=', $contact_id)
    ->addWhere('event_id', '=', $event_id)
    ->addWhere('status_id', 'IN', [1, 2]) // Registered or Attended
    ->execute();
    
  return $existing->count() > 0;
}
```

## CiviCRM API Integration

### Event Retrieval
Gets available events for configuration:

```php
public function getEvents() {
  try {
    if (!$this->civicrmHelper->isCivicrmAvailable()) {
      return [];
    }
    
    $events = \Civi\Api4\Event::get()
      ->addSelect('id', 'title', 'start_date', 'end_date')
      ->addWhere('is_active', '=', TRUE)
      ->addWhere('is_public', '=', TRUE)
      ->addOrderBy('start_date', 'ASC')
      ->execute();
      
    $options = [];
    foreach ($events as $event) {
      $date_info = '';
      if (!empty($event['start_date'])) {
        $date_info = ' (' . date('Y-m-d', strtotime($event['start_date'])) . ')';
      }
      $options[$event['id']] = $event['title'] . $date_info;
    }
    
    return $options;
  } catch (\Exception $e) {
    $this->logger->error('Failed to get events: @message', [
      '@message' => $e->getMessage(),
    ]);
    return [];
  }
}
```

### Participant Creation
Creates participant records using CiviCRM API4:

```php
$participant = \Civi\Api4\Participant::create()
  ->addValue('contact_id', $contact_id)
  ->addValue('event_id', $event_id)
  ->addValue('role_id', $participant_role_id)
  ->addValue('status_id', 1) // Registered
  ->addValue('register_date', date('Y-m-d H:i:s'))
  ->addValue('source', $source)
  ->execute();
```

### Participant Role Retrieval
Gets available participant roles:

```php
public function getParticipantRoles() {
  try {
    $roles = \Civi\Api4\OptionValue::get()
      ->addSelect('value', 'label')
      ->addWhere('option_group_id:name', '=', 'participant_role')
      ->addWhere('is_active', '=', TRUE)
      ->addOrderBy('weight', 'ASC')
      ->execute();
      
    $options = [];
    foreach ($roles as $role) {
      $options[$role['value']] = $role['label'];
    }
    
    return $options;
  } catch (\Exception $e) {
    $this->logger->error('Failed to get participant roles: @message', [
      '@message' => $e->getMessage(),
    ]);
    return [];
  }
}
```

## Integration Points

### Order Processing Integration
The EventUpdater is called for products configured for event registration:

```php
// In OrderCivicrmUpdater
case 'event':
  $participant_role_id = $settings['participant_role_id'] ?? 1;
  $participant_id = $this->eventUpdater->createEventRegistrationWithRole(
    $contact_id, 
    $settings['entity_id'], 
    $participant_role_id
  );
  if ($participant_id) {
    $results['events'][] = $participant_id;
    $this->logger->info('Registered participant @id for event @event_id', [
      '@id' => $participant_id,
      '@event_id' => $settings['entity_id'],
    ]);
  }
  break;
```

### Rules Action Integration
Rules actions use EventUpdater for custom event registration:

```php
// In CiviCrmAddEventRegistration Rules action
public function doExecute(UserInterface $user, $event_id, $participant_role_id = NULL) {
  $contact_id = $this->contactUpdater->getContactIdByUser($user);
  if (!$contact_id) {
    return;
  }
  
  $participant_id = $this->eventUpdater->createEventRegistrationWithRole(
    $contact_id, 
    $event_id, 
    $participant_role_id
  );
}
```

### Form Integration
Product configuration forms use EventUpdater for event options:

```php
// In product form
$form['event_id'] = [
  '#type' => 'select',
  '#title' => $this->t('Event'),
  '#options' => $this->eventUpdater->getEvents(),
  '#states' => [
    'visible' => [
      ':input[name="field_civicrm[0][entity]"]' => ['value' => 'event'],
    ],
  ],
];
```

## Error Handling

### Common Error Scenarios
- **Event Not Found**: Non-existent or inactive event
- **Event Ended**: Registration for past events
- **Duplicate Registration**: Contact already registered for event
- **Invalid Role**: Non-existent participant role

### Error Recovery
```php
try {
  // Validate event first
  if (!$this->validateEvent($event_id)) {
    $this->logger->warning('Event @event_id not available for registration', [
      '@event_id' => $event_id,
    ]);
    return NULL;
  }
  
  // Check for existing registration
  if ($this->checkExistingParticipant($contact_id, $event_id)) {
    $this->logger->info('Contact @contact_id already registered for event @event_id', [
      '@contact_id' => $contact_id,
      '@event_id' => $event_id,
    ]);
    return $this->getExistingParticipantId($contact_id, $event_id);
  }
  
  // Create new registration
  $participant = \Civi\Api4\Participant::create()
    // ... participant data
    ->execute();
    
} catch (\Exception $e) {
  $this->logger->error('Failed to create event registration: @message', [
    '@message' => $e->getMessage(),
    'contact_id' => $contact_id,
    'event_id' => $event_id,
  ]);
  
  return NULL;
}
```

## Advanced Features

### Event Capacity Management
Framework for checking event capacity:

```php
protected function checkEventCapacity($event_id) {
  $event = \Civi\Api4\Event::get()
    ->addSelect('max_participants')
    ->addWhere('id', '=', $event_id)
    ->execute()
    ->first();
    
  if (!$event['max_participants']) {
    return TRUE; // No capacity limit
  }
  
  $registered_count = \Civi\Api4\Participant::get()
    ->addWhere('event_id', '=', $event_id)
    ->addWhere('status_id', 'IN', [1, 2]) // Registered or Attended
    ->execute()
    ->count();
    
  return $registered_count < $event['max_participants'];
}
```

### Multiple Event Registration
Support for registering for multiple events in one order:

```php
public function createMultipleEventRegistrations($contact_id, $event_registrations) {
  $results = [];
  
  foreach ($event_registrations as $registration) {
    $participant_id = $this->createEventRegistrationWithRole(
      $contact_id,
      $registration['event_id'],
      $registration['role_id'] ?? NULL
    );
    
    if ($participant_id) {
      $results[] = $participant_id;
    }
  }
  
  return $results;
}
```

### Custom Field Support
Framework for adding custom fields to participant records:

```php
protected function addCustomFieldsToParticipant($participant_data, $order, $order_item) {
  // Add special instructions from order
  if ($order->hasField('field_special_instructions')) {
    $instructions = $order->get('field_special_instructions')->value;
    if ($instructions) {
      $participant_data['custom_instructions'] = $instructions;
    }
  }
  
  // Add dietary requirements from order item
  if ($order_item->hasField('field_dietary_requirements')) {
    $dietary = $order_item->get('field_dietary_requirements')->value;
    if ($dietary) {
      $participant_data['custom_dietary'] = $dietary;
    }
  }
  
  return $participant_data;
}
```

## Performance Optimization

### Batch Registration
For bulk event registrations:

```php
public function createBatchEventRegistrations($registrations_data) {
  $results = [];
  
  foreach (array_chunk($registrations_data, 25) as $batch) {
    $batch_results = $this->processBatch($batch);
    $results = array_merge($results, $batch_results);
  }
  
  return $results;
}
```

### Caching
Cache event and role data:

```php
protected function getCachedEvents() {
  $cache_key = 'commerce_civicrm:events';
  $cached = \Drupal::cache()->get($cache_key);
  
  if ($cached && $cached->valid) {
    return $cached->data;
  }
  
  $events = $this->getEvents();
  \Drupal::cache()->set($cache_key, $events, time() + 1800); // 30 minutes
  
  return $events;
}
```

## Testing and Debugging

### Unit Testing
```php
public function testEventRegistration() {
  $contact_id = 123;
  $event_id = 5;
  $role_id = 1;
  
  $participant_id = $this->eventUpdater->createEventRegistrationWithRole(
    $contact_id, 
    $event_id, 
    $role_id
  );
  
  $this->assertIsInt($participant_id);
  
  // Verify in CiviCRM
  $participant = \Civi\Api4\Participant::get()
    ->addWhere('id', '=', $participant_id)
    ->execute()
    ->first();
    
  $this->assertEquals($contact_id, $participant['contact_id']);
  $this->assertEquals($event_id, $participant['event_id']);
}
```

### Debug Information
Enable detailed logging for event registration:

```php
$this->logger->debug('Creating event registration: contact=@contact_id, event=@event_id, role=@role_id', [
  '@contact_id' => $contact_id,
  '@event_id' => $event_id,
  '@role_id' => $participant_role_id,
]);
```

## Best Practices

### Event Management
- Verify events are active and public before offering registration
- Check event dates to prevent registration for past events
- Consider event capacity when allowing registration

### Role Assignment
- Use meaningful participant roles
- Provide default role fallback
- Document role meanings for administrators

### Error Handling
- Provide clear error messages for registration failures
- Handle duplicate registrations gracefully
- Log sufficient detail for troubleshooting

### Performance
- Cache event and role data appropriately
- Use batch processing for multiple registrations
- Optimize API queries for large event lists

The EventUpdater service provides comprehensive event registration management, supporting both automatic product-based registration and custom Rules-driven scenarios with robust validation and error handling.
