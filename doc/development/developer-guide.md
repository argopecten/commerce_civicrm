# Developer Guide

## Overview

This guide provides technical information for developers who want to extend or customize the Commerce CiviCRM module. The module follows modern Drupal development practices and provides multiple extension points.

## Architecture

### Service-Based Design
The module uses a service-based architecture with dependency injection:

```php
// Service definition in commerce_civicrm.services.yml
services:
  commerce_civicrm.contact_updater:
    class: Drupal\commerce_civicrm\Service\ContactUpdater
    arguments: ['@entity_type.manager', '@logger.factory', '@commerce_civicrm.civicrm_helper']
```

### Core Services
- **ContactUpdater**: Contact management and creation
- **ContributionUpdater**: Financial record management  
- **MembershipUpdater**: Membership lifecycle management
- **EventUpdater**: Event registration handling
- **MailingUpdater**: Mailing group subscription management
- **OrderCivicrmUpdater**: Orchestration and coordination
- **CivicrmHelper**: CiviCRM connectivity utilities

## Extension Points

### Creating Custom Services

Create a new service that extends the existing functionality:

```php
namespace Drupal\my_module\Service;

use Drupal\commerce_civicrm\Service\ContactUpdater;

class EnhancedContactUpdater extends ContactUpdater {
  
  public function updateContactFromOrder(OrderInterface $order) {
    // Call parent method
    $contact_id = parent::updateContactFromOrder($order);
    
    // Add custom functionality
    if ($contact_id) {
      $this->addCustomFields($contact_id, $order);
    }
    
    return $contact_id;
  }
  
  protected function addCustomFields($contact_id, $order) {
    // Custom field processing
  }
}
```

### Service Decoration
Extend services without modifying the original:

```yaml
# my_module.services.yml
services:
  my_module.enhanced_contact_updater:
    class: Drupal\my_module\Service\EnhancedContactUpdater
    decorates: commerce_civicrm.contact_updater
    arguments: ['@my_module.enhanced_contact_updater.inner', '@my_custom_service']
```

### Event Subscribers

Create event subscribers to extend order processing:

```php
namespace Drupal\my_module\EventSubscriber;

use Drupal\commerce_order\Event\OrderEvent;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CustomOrderSubscriber implements EventSubscriberInterface {
  
  public static function getSubscribedEvents() {
    return [
      'commerce_order.place.post_transition' => ['onOrderPlace', -10],
    ];
  }
  
  public function onOrderPlace(WorkflowTransitionEvent $event) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();
    
    // Custom processing after order is placed
    $this->processCustomCivicrmIntegration($order);
  }
  
  protected function processCustomCivicrmIntegration($order) {
    // Custom integration logic
  }
}
```

### Custom Field Integration

Add custom fields to CiviCRM entities:

```php
class CustomContactUpdater extends ContactUpdater {
  
  protected function addCustomFieldsToContact($contact_data, $order) {
    // Add custom fields from order
    if ($order->hasField('field_marketing_source')) {
      $source = $order->get('field_marketing_source')->value;
      if ($source) {
        $contact_data['custom_marketing_source'] = $source;
      }
    }
    
    return $contact_data;
  }
}
```

## CiviCRM API Integration

### API4 Usage Patterns

The module uses CiviCRM API4 for all operations:

```php
// Create entity
$result = \Civi\Api4\Contact::create()
  ->addValue('first_name', $first_name)
  ->addValue('last_name', $last_name)
  ->addValue('email', $email)
  ->execute();

// Update entity
$result = \Civi\Api4\Contact::update()
  ->addWhere('id', '=', $contact_id)
  ->addValue('first_name', $first_name)
  ->execute();

// Get entities with conditions
$contacts = \Civi\Api4\Contact::get()
  ->addSelect('id', 'first_name', 'last_name')
  ->addWhere('email', '=', $email)
  ->addWhere('is_deleted', '=', FALSE)
  ->execute();
```

### Error Handling Best Practices

Always wrap CiviCRM API calls in try-catch blocks:

```php
try {
  if (!$this->civicrmHelper->isCivicrmAvailable()) {
    $this->logger->error('CiviCRM not available');
    return NULL;
  }
  
  $result = \Civi\Api4\Contact::create()
    ->addValue('first_name', $first_name)
    ->execute();
    
  return $result->first()['id'];
  
} catch (\Exception $e) {
  $this->logger->error('Failed to create contact: @message', [
    '@message' => $e->getMessage(),
  ]);
  return NULL;
}
```

## Testing

### Unit Testing Services

Test services in isolation:

```php
namespace Drupal\Tests\commerce_civicrm\Unit\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\commerce_civicrm\Service\ContactUpdater;

class ContactUpdaterTest extends UnitTestCase {
  
  protected $contactUpdater;
  
  protected function setUp(): void {
    parent::setUp();
    
    // Mock dependencies
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $civicrm_helper = $this->createMock(CivicrmHelper::class);
    
    $this->contactUpdater = new ContactUpdater(
      $entity_type_manager,
      $logger_factory,
      $civicrm_helper
    );
  }
  
  public function testContactCreation() {
    // Test contact creation logic
  }
}
```

### Integration Testing

Test the complete workflow:

```php
namespace Drupal\Tests\commerce_civicrm\Kernel;

use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;

class OrderProcessingTest extends CommerceKernelTestBase {
  
  public function testOrderProcessing() {
    // Create test order
    $order = $this->createOrder();
    
    // Process order
    $order_updater = \Drupal::service('commerce_civicrm.order_civicrm_updater');
    $results = $order_updater->processCompletedOrder($order);
    
    // Assert results
    $this->assertArrayHasKey('contact_id', $results);
    $this->assertNotEmpty($results['contact_id']);
  }
}
```

## Configuration Management

### Module Configuration

Store module configuration in configuration entities:

```yaml
# config/install/commerce_civicrm.settings.yml
default_financial_type: 1
enable_debug_logging: false
contact_matching_strategy: 'email'
```

### Product Configuration

Extend the product configuration form:

```php
function my_module_form_commerce_product_form_alter(&$form, FormStateInterface $form_state) {
  $form['my_custom_civicrm_settings'] = [
    '#type' => 'fieldset',
    '#title' => t('Custom CiviCRM Settings'),
    '#weight' => 50,
  ];
  
  $form['my_custom_civicrm_settings']['custom_field'] = [
    '#type' => 'textfield',
    '#title' => t('Custom Field'),
    '#default_value' => $form_state->getFormObject()->getEntity()->get('field_custom')->value,
  ];
}
```

## Debugging

### Enable Debug Logging

Add debug logging to your custom services:

```php
class CustomService {
  
  public function processOrder($order) {
    $this->logger->debug('Processing order @order_id with custom logic', [
      '@order_id' => $order->id(),
    ]);
    
    // Processing logic
    
    $this->logger->info('Custom processing completed for order @order_id', [
      '@order_id' => $order->id(),
    ]);
  }
}
```

### Debug CiviCRM Connectivity

Test CiviCRM availability:

```php
$helper = \Drupal::service('commerce_civicrm.civicrm_helper');
if (!$helper->isCivicrmAvailable()) {
  \Drupal::logger('my_module')->error('CiviCRM not available');
  return;
}

// Test API access
try {
  $result = \Civi\Api4\Contact::get()
    ->addSelect('id')
    ->setLimit(1)
    ->execute();
    
  \Drupal::logger('my_module')->info('CiviCRM API test successful');
} catch (\Exception $e) {
  \Drupal::logger('my_module')->error('CiviCRM API test failed: @message', [
    '@message' => $e->getMessage(),
  ]);
}
```

## Performance Optimization

### Caching Strategies

Cache CiviCRM data appropriately:

```php
class OptimizedService {
  
  protected function getCachedFinancialTypes() {
    $cache_key = 'my_module:financial_types';
    $cached = \Drupal::cache()->get($cache_key);
    
    if ($cached && $cached->valid) {
      return $cached->data;
    }
    
    // Fetch from CiviCRM
    $types = $this->fetchFinancialTypesFromCivicrm();
    
    // Cache for 1 hour
    \Drupal::cache()->set($cache_key, $types, time() + 3600);
    
    return $types;
  }
}
```

### Batch Processing

Process large datasets efficiently:

```php
class BatchProcessor {
  
  public function processBulkOrders($order_ids) {
    $batch = [
      'title' => t('Processing orders'),
      'operations' => [],
      'finished' => [static::class, 'batchFinished'],
    ];
    
    foreach (array_chunk($order_ids, 10) as $chunk) {
      $batch['operations'][] = [
        [static::class, 'processBatch'],
        [$chunk],
      ];
    }
    
    batch_set($batch);
  }
  
  public static function processBatch($order_ids, &$context) {
    foreach ($order_ids as $order_id) {
      // Process individual order
      $context['results'][] = $order_id;
    }
  }
}
```

## Best Practices

### Code Organization
- Use services for business logic
- Keep controllers thin
- Follow Drupal coding standards
- Use dependency injection

### Error Handling
- Always check CiviCRM availability
- Provide meaningful error messages
- Log errors with sufficient context
- Implement graceful degradation

### Testing
- Write unit tests for services
- Create integration tests for workflows
- Mock external dependencies
- Test error conditions

### Performance
- Cache frequently accessed data
- Use batch processing for bulk operations
- Minimize API calls
- Profile and optimize slow operations

### Security
- Validate all input data
- Use parameterized queries
- Implement proper access controls
- Sanitize output data

## Contributing

When contributing to the module:

1. **Follow Standards**: Use Drupal coding standards
2. **Write Tests**: Include unit and integration tests
3. **Document Changes**: Update documentation
4. **Consider Backwards Compatibility**: Avoid breaking changes
5. **Use Services**: Implement functionality as services

## Resources

### Documentation
- [Service Architecture](../services/overview.md)
- [Extensions Overview](../extensions/overview.md)
- [CiviCRM API Documentation](https://docs.civicrm.org/dev/en/latest/api/)

### Code Examples
- Check existing services for patterns
- Review service implementations for best practices
- Study event subscribers for workflow integration

The Commerce CiviCRM module provides a robust foundation for CiviCRM integration that can be extended to meet specific business requirements while maintaining code quality and performance.
