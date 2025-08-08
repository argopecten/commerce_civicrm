# API Reference

## Overview

This document provides a comprehensive reference for the CiviCRM API usage patterns within the Commerce CiviCRM module. The module uses CiviCRM API4 for all operations.

## API4 Patterns

### Basic Operations

#### Create Entity
```php
$result = \Civi\Api4\EntityName::create()
  ->addValue('field_name', $value)
  ->addValue('another_field', $another_value)
  ->execute();

$entity_id = $result->first()['id'];
```

#### Update Entity
```php
$result = \Civi\Api4\EntityName::update()
  ->addWhere('id', '=', $entity_id)
  ->addValue('field_name', $new_value)
  ->execute();
```

#### Get Entities
```php
$entities = \Civi\Api4\EntityName::get()
  ->addSelect('id', 'field1', 'field2')
  ->addWhere('status', '=', 'active')
  ->addOrderBy('created_date', 'DESC')
  ->setLimit(25)
  ->execute();

foreach ($entities as $entity) {
  // Process each entity
}
```

#### Delete Entity
```php
$result = \Civi\Api4\EntityName::delete()
  ->addWhere('id', '=', $entity_id)
  ->execute();
```

## Entity-Specific Operations

### Contact Operations

#### Create Contact
```php
$contact = \Civi\Api4\Contact::create()
  ->addValue('contact_type', 'Individual')
  ->addValue('first_name', $first_name)
  ->addValue('last_name', $last_name)
  ->addValue('source', 'Commerce Order #' . $order_number)
  ->execute();

$contact_id = $contact->first()['id'];
```

#### Find Contact by Email
```php
$contacts = \Civi\Api4\Contact::get()
  ->addSelect('id', 'first_name', 'last_name')
  ->addJoin('Email AS email', 'INNER')
  ->addWhere('email.email', '=', $email_address)
  ->addWhere('email.is_primary', '=', TRUE)
  ->addWhere('is_deleted', '=', FALSE)
  ->execute();

if ($contacts->count() > 0) {
  $contact_id = $contacts->first()['id'];
}
```

#### Update Contact
```php
$result = \Civi\Api4\Contact::update()
  ->addWhere('id', '=', $contact_id)
  ->addValue('first_name', $first_name)
  ->addValue('last_name', $last_name)
  ->addValue('modified_date', date('Y-m-d H:i:s'))
  ->execute();
```

### Email Operations

#### Create Primary Email
```php
$email = \Civi\Api4\Email::create()
  ->addValue('contact_id', $contact_id)
  ->addValue('email', $email_address)
  ->addValue('is_primary', 1)
  ->addValue('location_type_id', 1) // Home
  ->execute();
```

#### Check Existing Email
```php
$existing_emails = \Civi\Api4\Email::get()
  ->addWhere('contact_id', '=', $contact_id)
  ->addWhere('email', '=', $email_address)
  ->execute();

if ($existing_emails->count() == 0) {
  // Create new email
}
```

### Contribution Operations

#### Create Contribution
```php
$contribution = \Civi\Api4\Contribution::create()
  ->addValue('contact_id', $contact_id)
  ->addValue('financial_type_id', $financial_type_id)
  ->addValue('total_amount', $amount)
  ->addValue('currency', $currency)
  ->addValue('contribution_status_id', 1) // Completed
  ->addValue('receive_date', date('Y-m-d H:i:s'))
  ->addValue('source', 'Commerce Order #' . $order_number)
  ->execute();

$contribution_id = $contribution->first()['id'];
```

#### Check for Existing Contribution
```php
$existing = \Civi\Api4\Contribution::get()
  ->addWhere('contact_id', '=', $contact_id)
  ->addWhere('source', '=', $source)
  ->addWhere('total_amount', '=', $amount)
  ->execute();

if ($existing->count() > 0) {
  return $existing->first()['id'];
}
```

#### Get Financial Types
```php
$financial_types = \Civi\Api4\FinancialType::get()
  ->addSelect('id', 'name')
  ->addWhere('is_active', '=', TRUE)
  ->addOrderBy('name', 'ASC')
  ->execute();

$options = [];
foreach ($financial_types as $type) {
  $options[$type['id']] = $type['name'];
}
```

### Membership Operations

#### Create Membership
```php
$membership = \Civi\Api4\Membership::create()
  ->addValue('contact_id', $contact_id)
  ->addValue('membership_type_id', $membership_type_id)
  ->addValue('source', 'Commerce Order #' . $order_number)
  ->addValue('start_date', date('Y-m-d'))
  ->addValue('status_id', 1) // New
  ->execute();

$membership_id = $membership->first()['id'];
```

#### Get Membership Types
```php
$membership_types = \Civi\Api4\MembershipType::get()
  ->addSelect('id', 'name', 'minimum_fee', 'duration_interval', 'duration_unit')
  ->addWhere('is_active', '=', TRUE)
  ->addOrderBy('name', 'ASC')
  ->execute();

$options = [];
foreach ($membership_types as $type) {
  $fee_info = '';
  if ($type['minimum_fee']) {
    $fee_info = ' ($' . number_format($type['minimum_fee'], 2) . ')';
  }
  $options[$type['id']] = $type['name'] . $fee_info;
}
```

#### Find Existing Membership
```php
$existing = \Civi\Api4\Membership::get()
  ->addWhere('contact_id', '=', $contact_id)
  ->addWhere('membership_type_id', '=', $membership_type_id)
  ->addWhere('status_id', 'IN', [1, 2]) // New or Current
  ->addOrderBy('end_date', 'DESC')
  ->setLimit(1)
  ->execute();
```

### Event Operations

#### Get Events
```php
$events = \Civi\Api4\Event::get()
  ->addSelect('id', 'title', 'start_date', 'end_date', 'max_participants')
  ->addWhere('is_active', '=', TRUE)
  ->addWhere('is_public', '=', TRUE)
  ->addWhere('start_date', '>=', date('Y-m-d'))
  ->addOrderBy('start_date', 'ASC')
  ->execute();

$options = [];
foreach ($events as $event) {
  $date_info = '';
  if ($event['start_date']) {
    $date_info = ' (' . date('M j, Y', strtotime($event['start_date'])) . ')';
  }
  $options[$event['id']] = $event['title'] . $date_info;
}
```

#### Create Participant
```php
$participant = \Civi\Api4\Participant::create()
  ->addValue('contact_id', $contact_id)
  ->addValue('event_id', $event_id)
  ->addValue('role_id', $participant_role_id)
  ->addValue('status_id', 1) // Registered
  ->addValue('register_date', date('Y-m-d H:i:s'))
  ->addValue('source', 'Commerce Order #' . $order_number)
  ->execute();

$participant_id = $participant->first()['id'];
```

#### Check Existing Participant
```php
$existing = \Civi\Api4\Participant::get()
  ->addWhere('contact_id', '=', $contact_id)
  ->addWhere('event_id', '=', $event_id)
  ->addWhere('status_id', 'IN', [1, 2]) // Registered or Attended
  ->execute();

if ($existing->count() > 0) {
  return $existing->first()['id'];
}
```

#### Get Participant Roles
```php
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
```

### Group Operations

#### Get Mailing Groups
```php
$groups = \Civi\Api4\Group::get()
  ->addSelect('id', 'title', 'description')
  ->addWhere('is_active', '=', TRUE)
  ->addWhere('group_type', 'CONTAINS', 'Mailing List')
  ->addWhere('visibility', 'IN', ['Public Pages', 'User and User Admin Only'])
  ->addOrderBy('title', 'ASC')
  ->execute();

$options = [];
foreach ($groups as $group) {
  $options[$group['id']] = $group['title'];
}
```

#### Add Contact to Group
```php
$group_contact = \Civi\Api4\GroupContact::create()
  ->addValue('contact_id', $contact_id)
  ->addValue('group_id', $group_id)
  ->addValue('status', 'Added')
  ->execute();
```

#### Check Group Membership
```php
$existing = \Civi\Api4\GroupContact::get()
  ->addWhere('contact_id', '=', $contact_id)
  ->addWhere('group_id', '=', $group_id)
  ->addWhere('status', '=', 'Added')
  ->execute();

return $existing->count() > 0;
```

## Advanced Patterns

### Batch Operations

#### Create Multiple Entities
```php
$batch_data = [
  ['first_name' => 'John', 'last_name' => 'Doe'],
  ['first_name' => 'Jane', 'last_name' => 'Smith'],
];

$results = [];
foreach (array_chunk($batch_data, 25) as $chunk) {
  foreach ($chunk as $contact_data) {
    $contact = \Civi\Api4\Contact::create()
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', $contact_data['first_name'])
      ->addValue('last_name', $contact_data['last_name'])
      ->execute();
      
    $results[] = $contact->first()['id'];
  }
}
```

### Complex Queries

#### Join Operations
```php
$contacts_with_contributions = \Civi\Api4\Contact::get()
  ->addSelect('id', 'display_name')
  ->addSelect('SUM(contribution.total_amount) AS total_contributed')
  ->addJoin('Contribution AS contribution', 'LEFT')
  ->addWhere('contribution.contribution_status_id', '=', 1)
  ->addGroupBy('id')
  ->addHaving('total_contributed', '>', 100)
  ->addOrderBy('total_contributed', 'DESC')
  ->execute();
```

#### Custom Field Queries
```php
$contacts = \Civi\Api4\Contact::get()
  ->addSelect('id', 'first_name', 'last_name', 'custom_field_name.value')
  ->addWhere('custom_field_name.value', 'IS NOT NULL')
  ->execute();
```

### Error Handling Patterns

#### Standard Error Handling
```php
try {
  $result = \Civi\Api4\Contact::create()
    ->addValue('first_name', $first_name)
    ->addValue('last_name', $last_name)
    ->execute();
    
  return $result->first()['id'];
  
} catch (\API_Exception $e) {
  $this->logger->error('CiviCRM API error: @message', [
    '@message' => $e->getMessage(),
  ]);
  return NULL;
} catch (\Exception $e) {
  $this->logger->error('General error: @message', [
    '@message' => $e->getMessage(),
  ]);
  return NULL;
}
```

#### Validation Before API Calls
```php
if (!$this->civicrmHelper->isCivicrmAvailable()) {
  $this->logger->error('CiviCRM not available');
  return NULL;
}

if (empty($contact_data['first_name']) || empty($contact_data['last_name'])) {
  $this->logger->warning('Incomplete contact data provided');
  return NULL;
}

// Proceed with API call
```

## Performance Optimization

### Efficient Queries

#### Select Only Needed Fields
```php
// Good: Select only needed fields
$contacts = \Civi\Api4\Contact::get()
  ->addSelect('id', 'first_name', 'last_name')
  ->execute();

// Avoid: Selecting all fields (default behavior)
$contacts = \Civi\Api4\Contact::get()
  ->execute();
```

#### Use Appropriate Limits
```php
$contacts = \Civi\Api4\Contact::get()
  ->addSelect('id', 'display_name')
  ->setLimit(25)
  ->setOffset($offset)
  ->execute();
```

#### Optimize WHERE Clauses
```php
// Good: Use indexed fields in WHERE clauses
$contacts = \Civi\Api4\Contact::get()
  ->addWhere('id', 'IN', $contact_ids)
  ->execute();

// Avoid: Complex LIKE queries on large datasets
$contacts = \Civi\Api4\Contact::get()
  ->addWhere('display_name', 'LIKE', '%' . $search_term . '%')
  ->execute();
```

### Caching Strategies

#### Cache Static Data
```php
protected function getCachedFinancialTypes() {
  static $types = NULL;
  
  if ($types === NULL) {
    $types = \Civi\Api4\FinancialType::get()
      ->addSelect('id', 'name')
      ->addWhere('is_active', '=', TRUE)
      ->execute()
      ->indexBy('id');
  }
  
  return $types;
}
```

## Best Practices

### API Usage Guidelines

1. **Always Check CiviCRM Availability**
   ```php
   if (!$this->civicrmHelper->isCivicrmAvailable()) {
     return [];
   }
   ```

2. **Use Specific Field Selection**
   ```php
   ->addSelect('id', 'name', 'status')  // Good
   // Avoid selecting all fields unless necessary
   ```

3. **Implement Proper Error Handling**
   ```php
   try {
     // API operation
   } catch (\Exception $e) {
     $this->logger->error('API error: @message', ['@message' => $e->getMessage()]);
     return NULL;
   }
   ```

4. **Validate Input Data**
   ```php
   if (empty($required_field)) {
     $this->logger->warning('Required field missing');
     return NULL;
   }
   ```

5. **Use Appropriate Log Levels**
   ```php
   $this->logger->info('Entity created successfully');     // Success
   $this->logger->warning('Non-critical issue occurred');  // Warning
   $this->logger->error('Operation failed');               // Error
   $this->logger->debug('Detailed debug information');     // Debug
   ```

This API reference provides the foundation for working with CiviCRM entities within the Commerce CiviCRM module. Always refer to the official CiviCRM API documentation for the most current information on available entities and operations.
