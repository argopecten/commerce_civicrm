# ContributionUpdater Service

## Overview

The **ContributionUpdater** service handles all CiviCRM contribution operations within the Commerce CiviCRM integration module. This service manages contribution creation, financial type handling, and payment method mapping.

## Primary Responsibilities

### Contribution Management
- **Create Contributions**: Creates CiviCRM contributions from Commerce orders
- **Financial Type Mapping**: Maps products to CiviCRM financial types
- **Payment Processing**: Handles payment method and status mapping
- **Duplicate Prevention**: Prevents duplicate contributions for the same order

### Financial Integration
- **Revenue Tracking**: Records revenue in CiviCRM financial system
- **Currency Handling**: Manages multiple currency support
- **Tax Integration**: Handles tax amounts and reporting
- **Refund Processing**: Framework for handling refunds and adjustments

## Core Methods

### Primary Operations

#### `createContributionFromOrder(OrderInterface $order)`
**Purpose**: Creates CiviCRM contributions from order data

**Process**:
1. Validates order and contact information
2. Extracts contribution data from order
3. Maps payment methods to CiviCRM payment instruments
4. Creates contribution record in CiviCRM
5. Returns contribution ID

**Returns**: `int|null` - CiviCRM contribution ID or NULL on failure

```php
$contribution_updater = \Drupal::service('commerce_civicrm.contribution_updater');
$contribution_id = $contribution_updater->createContributionFromOrder($order);
```

#### `createContributionFromOrderWithFinancialType(OrderInterface $order, $financial_type_id)`
**Purpose**: Creates contributions with specific financial type (used by Rules actions)

**Parameters**:
- `$order` - Commerce order entity
- `$financial_type_id` - Specific CiviCRM Financial Type ID

**Returns**: `int|null` - CiviCRM contribution ID or NULL on failure

```php
$contribution_id = $contribution_updater->createContributionFromOrderWithFinancialType(
  $order, 
  $financial_type_id
);
```

## Contribution Data Processing

### Order Data Extraction
The service extracts contribution information from Commerce orders:

```php
$contribution_data = [
  'contact_id' => $contact_id,
  'financial_type_id' => $financial_type_id,
  'total_amount' => $order->getTotalPrice()->getNumber(),
  'currency' => $order->getTotalPrice()->getCurrencyCode(),
  'source' => 'Commerce Order #' . $order->getOrderNumber(),
  'contribution_status_id' => $this->mapOrderStatusToContribution($order->getState()->value),
  'receive_date' => $order->getCompletedTime() ?: time(),
];
```

### Financial Type Mapping
Maps products to appropriate CiviCRM financial types:

1. **Product Configuration**: Uses financial type from product CiviCRM settings
2. **Default Mapping**: Falls back to default financial type if not configured
3. **Rules Override**: Allows Rules actions to specify different financial types

### Payment Method Mapping
Maps Commerce payment methods to CiviCRM payment instruments:

```php
protected function mapPaymentMethod($payment_method) {
  $mapping = [
    'credit_card' => 1,     // Credit Card
    'paypal' => 2,          // PayPal
    'bank_transfer' => 3,   // Bank Transfer
    'check' => 4,           // Check
    'cash' => 5,            // Cash
  ];
  
  return $mapping[$payment_method] ?? 1; // Default to Credit Card
}
```

### Status Mapping
Maps Commerce order states to CiviCRM contribution statuses:

```php
protected function mapOrderStatusToContribution($order_status) {
  $mapping = [
    'completed' => 1,       // Completed
    'pending' => 2,         // Pending
    'canceled' => 3,        // Cancelled
    'refunded' => 4,        // Refunded
  ];
  
  return $mapping[$order_status] ?? 2; // Default to Pending
}
```

## CiviCRM API Integration

### Contribution Creation
Uses CiviCRM API4 for contribution operations:

```php
$contribution = \Civi\Api4\Contribution::create()
  ->addValue('contact_id', $contact_id)
  ->addValue('financial_type_id', $financial_type_id)
  ->addValue('total_amount', $amount)
  ->addValue('currency', $currency)
  ->addValue('source', $source)
  ->addValue('contribution_status_id', $status_id)
  ->addValue('receive_date', $receive_date)
  ->execute();
```

### Duplicate Prevention
Checks for existing contributions before creating new ones:

```php
$existing = \Civi\Api4\Contribution::get()
  ->addWhere('contact_id', '=', $contact_id)
  ->addWhere('source', '=', $source)
  ->addWhere('total_amount', '=', $amount)
  ->execute();

if ($existing->count() > 0) {
  $this->logger->warning('Contribution already exists for order @order_id', [
    '@order_id' => $order->id(),
  ]);
  return $existing->first()['id'];
}
```

## Integration Points

### Order Processing Integration
The ContributionUpdater is called for products configured as contributions:

```php
// In OrderCivicrmUpdater
case 'contribution':
  $contribution_id = $this->contributionUpdater->createContributionFromOrder($order);
  if ($contribution_id) {
    $results['contributions'][] = $contribution_id;
    $this->logger->info('Created contribution @id for order @order_id', [
      '@id' => $contribution_id,
      '@order_id' => $order->id(),
    ]);
  }
  break;
```

### Rules Action Integration
Rules actions use ContributionUpdater for custom contribution creation:

```php
// In CiviCrmAddContribution Rules action
public function doExecute(UserInterface $user, OrderInterface $order, $financial_type_id) {
  $contact_id = $this->contactUpdater->getContactIdByUser($user);
  if (!$contact_id) {
    return;
  }
  
  $contribution_id = $this->contributionUpdater->createContributionFromOrderWithFinancialType(
    $order, 
    $financial_type_id
  );
}
```

## Error Handling

### Common Error Scenarios
- **Invalid Financial Type**: Non-existent financial type ID
- **Missing Contact**: No associated CiviCRM contact
- **Currency Issues**: Unsupported currency codes
- **API Failures**: CiviCRM API errors during creation

### Error Recovery
```php
try {
  $contribution = \Civi\Api4\Contribution::create()
    ->addValue('contact_id', $contact_id)
    ->addValue('financial_type_id', $financial_type_id)
    // ... other values
    ->execute();
    
} catch (\Exception $e) {
  $this->logger->error('Failed to create contribution: @message', [
    '@message' => $e->getMessage(),
    'order_id' => $order->id(),
    'contact_id' => $contact_id,
  ]);
  
  return NULL;
}
```

## Financial Type Management

### Available Financial Types
Retrieves financial types for configuration:

```php
public function getFinancialTypes() {
  try {
    if (!$this->civicrmHelper->isCivicrmAvailable()) {
      return [];
    }
    
    $financial_types = \Civi\Api4\FinancialType::get()
      ->addSelect('id', 'name')
      ->addWhere('is_active', '=', TRUE)
      ->execute();
      
    $options = [];
    foreach ($financial_types as $type) {
      $options[$type['id']] = $type['name'];
    }
    
    return $options;
  } catch (\Exception $e) {
    $this->logger->error('Failed to get financial types: @message', [
      '@message' => $e->getMessage(),
    ]);
    return [];
  }
}
```

## Advanced Features

### Multi-Currency Support
Handles orders in different currencies:

```php
$contribution_data['currency'] = $order->getTotalPrice()->getCurrencyCode();
$contribution_data['total_amount'] = $order->getTotalPrice()->getNumber();

// Handle currency conversion if needed
if ($contribution_data['currency'] !== $default_currency) {
  $this->logger->info('Processing contribution in currency @currency', [
    '@currency' => $contribution_data['currency'],
  ]);
}
```

### Tax Handling
Framework for handling tax amounts:

```php
// Calculate tax amount if needed
$tax_amount = 0;
foreach ($order->getAdjustments() as $adjustment) {
  if ($adjustment->getType() === 'tax') {
    $tax_amount += $adjustment->getAmount()->getNumber();
  }
}

if ($tax_amount > 0) {
  $contribution_data['tax_amount'] = $tax_amount;
}
```

### Custom Field Support
Framework for adding custom fields to contributions:

```php
protected function addCustomFieldsToContribution($contribution_data, $order) {
  // Add custom fields based on order data
  if ($order->hasField('field_project_code')) {
    $project_code = $order->get('field_project_code')->value;
    if ($project_code) {
      $contribution_data['custom_project_code'] = $project_code;
    }
  }
  
  return $contribution_data;
}
```

## Performance Optimization

### Batch Processing
For multiple contributions:

```php
public function createMultipleContributions($contributions_data) {
  $results = [];
  
  foreach (array_chunk($contributions_data, 50) as $batch) {
    $batch_results = $this->processBatch($batch);
    $results = array_merge($results, $batch_results);
  }
  
  return $results;
}
```

### Caching
Cache financial types and payment instruments:

```php
protected function getCachedFinancialTypes() {
  $cache_key = 'commerce_civicrm:financial_types';
  $cached = \Drupal::cache()->get($cache_key);
  
  if ($cached && $cached->valid) {
    return $cached->data;
  }
  
  $financial_types = $this->getFinancialTypes();
  \Drupal::cache()->set($cache_key, $financial_types, time() + 3600);
  
  return $financial_types;
}
```

## Testing and Debugging

### Unit Testing
```php
public function testContributionCreation() {
  $order = $this->createTestOrder();
  $contribution_id = $this->contributionUpdater->createContributionFromOrder($order);
  $this->assertIsInt($contribution_id);
  
  // Verify in CiviCRM
  $contribution = \Civi\Api4\Contribution::get()
    ->addWhere('id', '=', $contribution_id)
    ->execute()
    ->first();
    
  $this->assertEquals($order->getTotalPrice()->getNumber(), $contribution['total_amount']);
}
```

### Debug Information
Enable detailed logging for contribution processing:

```php
$this->logger->debug('Creating contribution: @data', [
  '@data' => json_encode($contribution_data),
]);
```

## Best Practices

### Data Validation
- Validate financial type IDs before API calls
- Ensure contact exists before creating contributions
- Verify currency codes are supported

### Error Handling
- Provide detailed error messages with context
- Use appropriate log levels for different scenarios
- Implement graceful degradation for API failures

### Performance
- Cache frequently accessed financial type data
- Use batch processing for bulk operations
- Minimize API calls through efficient queries

The ContributionUpdater service provides comprehensive contribution management capabilities, supporting both automatic order-based contribution creation and custom Rules-driven scenarios with robust error handling and performance optimization.
