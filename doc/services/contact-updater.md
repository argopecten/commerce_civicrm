# ContactUpdater Service

## Overview

The **ContactUpdater** service is the foundational service that handles all CiviCRM contact operations within the Commerce CiviCRM integration module. This service manages contact creation, updates, and provides helper methods for other services.

## Primary Responsibilities

### Contact Management
- **Create Contacts**: Creates new CiviCRM contacts from Drupal user data
- **Update Contacts**: Updates existing CiviCRM contacts with current information
- **Find Contacts**: Locates existing contacts by email or user ID
- **Contact Validation**: Ensures contact data integrity and completeness

### Data Integration
- **Profile Extraction**: Extracts contact data from Commerce billing profiles
- **User Mapping**: Maps Drupal users to CiviCRM contacts
- **Email Management**: Handles primary email address management
- **Data Synchronization**: Keeps contact data synchronized between systems

## Core Methods

### Primary Operations

#### `updateContactFromOrder(OrderInterface $order)`
**Purpose**: Main method for processing customer data from orders

**Process**:
1. Extracts contact data from billing profile
2. Searches for existing contact by email
3. Creates new contact if none exists
4. Updates existing contact with current data
5. Returns CiviCRM contact ID

**Returns**: `int|null` - CiviCRM contact ID or NULL on failure

```php
$contact_updater = \Drupal::service('commerce_civicrm.contact_updater');
$contact_id = $contact_updater->updateContactFromOrder($order);
```

#### `getContactIdByUser(UserInterface $user)`
**Purpose**: Finds CiviCRM contact ID for a Drupal user

**Process**:
1. Checks UFMatch table for existing mapping
2. Searches by email if no direct mapping
3. Returns contact ID if found

**Returns**: `int|null` - CiviCRM contact ID or NULL if not found

```php
$contact_id = $contact_updater->getContactIdByUser($user);
```

### Helper Methods

#### `getMembershipTypes()`
**Purpose**: Retrieves available CiviCRM membership types for form options

**Returns**: `array` - Associative array of membership type options

```php
$membership_types = $contact_updater->getMembershipTypes();
// Returns: ['1' => 'Annual Membership', '2' => 'Student Membership']
```

#### `getFinancialTypes()`
**Purpose**: Retrieves available CiviCRM financial types for contribution configuration

**Returns**: `array` - Associative array of financial type options

```php
$financial_types = $contact_updater->getFinancialTypes();
// Returns: ['1' => 'Donation', '2' => 'Member Dues', '3' => 'Event Fee']
```

#### `getProductSettings($product)`
**Purpose**: Extracts and validates CiviCRM configuration from product field

**Parameters**: 
- `$product` - Commerce product entity

**Returns**: `array` - Configuration array with defaults

```php
$settings = $contact_updater->getProductSettings($product);
// Returns: ['enabled' => true, 'entity' => 'contribution', 'entity_id' => '1']
```

## Contact Data Processing

### Data Extraction
The service extracts contact information from various sources:

#### From Billing Profiles
```php
$billing_profile = $order->getBillingProfile();
if ($billing_profile && $billing_profile->hasField('address')) {
  $address = $billing_profile->get('address')->first();
  $contact_data = [
    'first_name' => $address->getGivenName(),
    'last_name' => $address->getFamilyName(),
    'email' => $order->getEmail(),
  ];
}
```

#### From User Accounts
```php
$user = $order->getCustomer();
if ($user && !$user->isAnonymous()) {
  $contact_data['email'] = $user->getEmail();
  // Additional user-specific data
}
```

### Contact Matching
The service uses multiple strategies to find existing contacts:

1. **UFMatch Table**: Direct Drupal user to CiviCRM contact mapping
2. **Email Matching**: Find contacts by primary email address
3. **Name + Email**: Fallback matching using name and email combination

### Contact Creation
When creating new contacts:

```php
$contact = \Civi\Api4\Contact::create()
  ->addValue('contact_type', 'Individual')
  ->addValue('first_name', $contact_data['first_name'])
  ->addValue('last_name', $contact_data['last_name'])
  ->addValue('source', 'Commerce Order #' . $order->getOrderNumber())
  ->execute();
```

### Contact Updates
When updating existing contacts:

```php
$contact = \Civi\Api4\Contact::update()
  ->addWhere('id', '=', $contact_id)
  ->addValue('first_name', $contact_data['first_name'])
  ->addValue('last_name', $contact_data['last_name'])
  ->addValue('modified_date', date('Y-m-d H:i:s'))
  ->execute();
```

## Email Management

### Primary Email Handling
The service manages primary email addresses for contacts:

```php
// Find existing email
$existing_emails = \Civi\Api4\Email::get()
  ->addWhere('contact_id', '=', $contact_id)
  ->addWhere('is_primary', '=', 1)
  ->execute();

// Create or update primary email
if ($existing_emails->count() == 0) {
  \Civi\Api4\Email::create()
    ->addValue('contact_id', $contact_id)
    ->addValue('email', $email_address)
    ->addValue('is_primary', 1)
    ->execute();
}
```

### Email Validation
- Validates email format before processing
- Handles cases where orders have no email address
- Logs warnings for invalid or missing emails

## Integration Points

### Order Processing Integration
The ContactUpdater is the first service called in order processing:

```php
// In OrderCivicrmUpdater
$contact_id = $this->contactUpdater->updateContactFromOrder($order);
if (!$contact_id) {
  $this->logger->error('Could not create or find contact for order @order_id', [
    '@order_id' => $order->id(),
  ]);
  return;
}
```

### Rules Action Integration
Rules actions use ContactUpdater for contact management:

```php
// In Rules actions
$contact_id = $this->contactUpdater->getContactIdByUser($user);
if (!$contact_id) {
  $this->logger->warning('Could not find CiviCRM contact for user @uid', [
    '@uid' => $user->id(),
  ]);
  return;
}
```

### Form Integration
Product configuration forms use helper methods:

```php
// In form build
$form['financial_type'] = [
  '#type' => 'select',
  '#title' => $this->t('Financial Type'),
  '#options' => $this->contactUpdater->getFinancialTypes(),
];
```

## Error Handling

### Common Error Scenarios
- **CiviCRM Unavailable**: Service unavailable or misconfigured
- **Missing Profile Data**: Orders without complete billing profiles
- **Invalid Email**: Orders with malformed email addresses
- **API Failures**: CiviCRM API errors during contact operations

### Error Recovery
```php
try {
  $contact_id = $this->createContact($contact_data);
} catch (\Exception $e) {
  $this->logger->error('Failed to create contact: @message', [
    '@message' => $e->getMessage(),
  ]);
  
  // Attempt to find existing contact as fallback
  if (!empty($contact_data['email'])) {
    $contact_id = $this->findContactByEmail($contact_data['email']);
  }
}
```

## Performance Optimization

### Caching Strategies
- Cache CiviCRM entity lists (membership types, financial types)
- Cache contact lookups within request scope
- Use static variables for repeated operations

### API Efficiency
- Batch contact operations where possible
- Minimize API calls through smart caching
- Use efficient query patterns

### Memory Management
- Release large data structures after use
- Avoid loading unnecessary contact data
- Process contacts in batches for bulk operations

## Data Quality

### Validation Rules
- Require valid email addresses
- Validate name fields for minimum length
- Check for required profile fields

### Data Cleaning
- Trim whitespace from all text fields
- Standardize name capitalization
- Format phone numbers consistently

### Duplicate Prevention
- Use email-based matching to prevent duplicates
- Check UFMatch table before creating contacts
- Log when potential duplicates are found

## Testing and Debugging

### Unit Testing
```php
public function testContactCreation() {
  $order = $this->createTestOrder();
  $contact_id = $this->contactUpdater->updateContactFromOrder($order);
  $this->assertIsInt($contact_id);
}
```

### Debug Information
Enable detailed logging to see contact processing:

```php
$this->logger->debug('Processing contact for order @order_id: @data', [
  '@order_id' => $order->id(),
  '@data' => json_encode($contact_data),
]);
```

## Best Practices

### Contact Data Handling
- Always validate data before API calls
- Handle missing profile gracefully
- Log sufficient detail for troubleshooting

### Performance Considerations
- Cache frequently accessed data
- Minimize API round trips
- Use batch operations for multiple contacts

### Error Management
- Provide detailed error messages
- Use appropriate log levels
- Implement graceful degradation

The ContactUpdater service provides the foundational contact management capabilities that all other CiviCRM integration services depend on, ensuring consistent and reliable contact handling across the entire module.
