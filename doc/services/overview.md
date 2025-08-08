# Service Architecture Overview

## Introduction

The Commerce CiviCRM module follows modern Drupal best practices with a service-based architecture. This design provides modularity, testability, and maintainability while supporting complex CiviCRM integration scenarios.

## Service Architecture Principles

### Separation of Concerns
Each service has a specific responsibility:
- **ContactUpdater**: Contact management and creation
- **ContributionUpdater**: Financial record management
- **MembershipUpdater**: Membership lifecycle management
- **EventUpdater**: Event registration handling
- **MailingUpdater**: Mailing group subscription management
- **OrderCivicrmUpdater**: Orchestration and coordination

### Dependency Injection
All services use Drupal's dependency injection container:
- **EntityTypeManager**: For Drupal entity operations
- **LoggerChannelFactory**: For comprehensive logging
- **CivicrmHelper**: For CiviCRM connectivity and utilities

### Service Container Configuration
Services are defined in `commerce_civicrm.services.yml`:

```yaml
services:
  commerce_civicrm.contact_updater:
    class: Drupal\commerce_civicrm\Service\ContactUpdater
    arguments: ['@entity_type.manager', '@logger.factory', '@commerce_civicrm.civicrm_helper']
    
  commerce_civicrm.contribution_updater:
    class: Drupal\commerce_civicrm\Service\ContributionUpdater
    arguments: ['@entity_type.manager', '@logger.factory', '@commerce_civicrm.civicrm_helper']
    
  commerce_civicrm.membership_updater:
    class: Drupal\commerce_civicrm\Service\MembershipUpdater
    arguments: ['@entity_type.manager', '@logger.factory', '@commerce_civicrm.civicrm_helper']
    
  commerce_civicrm.event_updater:
    class: Drupal\commerce_civicrm\Service\EventUpdater
    arguments: ['@entity_type.manager', '@logger.factory', '@commerce_civicrm.civicrm_helper']
    
  commerce_civicrm.mailing_updater:
    class: Drupal\commerce_civicrm\Service\MailingUpdater
    arguments: ['@entity_type.manager', '@logger.factory', '@commerce_civicrm.civicrm_helper']
    
  commerce_civicrm.order_civicrm_updater:
    class: Drupal\commerce_civicrm\Service\OrderCivicrmUpdater
    arguments: 
      - '@entity_type.manager'
      - '@logger.factory'
      - '@commerce_civicrm.contact_updater'
      - '@commerce_civicrm.contribution_updater'
      - '@commerce_civicrm.membership_updater'
      - '@commerce_civicrm.event_updater'
      - '@commerce_civicrm.mailing_updater'
```

## Service Relationships

### Core Service Dependencies
```
OrderCivicrmUpdater
├── ContactUpdater
├── ContributionUpdater
├── MembershipUpdater  
├── EventUpdater
└── MailingUpdater

All services depend on:
├── EntityTypeManager
├── LoggerChannelFactory
└── CivicrmHelper
```

### Data Flow
1. **Order Processing**: OrderCivicrmUpdater receives order events
2. **Contact Management**: ContactUpdater creates/updates contacts
3. **Entity Processing**: Specialized services handle specific CiviCRM entities
4. **Result Aggregation**: OrderCivicrmUpdater collects and reports results

## Common Service Patterns

### Initialization Pattern
All services follow a consistent initialization pattern:

```php
public function __construct(
  EntityTypeManagerInterface $entity_type_manager,
  LoggerChannelFactoryInterface $logger_factory,
  CivicrmHelper $civicrm_helper
) {
  $this->entityTypeManager = $entity_type_manager;
  $this->logger = $logger_factory->get('commerce_civicrm');
  $this->civicrmHelper = $civicrm_helper;
}
```

### Error Handling Pattern
Consistent error handling across all services:

```php
try {
  if (!$this->civicrmHelper->isCivicrmAvailable()) {
    $this->logger->error('CiviCRM not available');
    return NULL;
  }
  
  // Service-specific logic
  
} catch (\Exception $e) {
  $this->logger->error('Operation failed: @message', [
    '@message' => $e->getMessage(),
  ]);
  return NULL;
}
```

### Logging Pattern
Standardized logging for operations:

```php
$this->logger->info('Created @entity @id for contact @contact_id', [
  '@entity' => 'contribution',
  '@id' => $result['id'],
  '@contact_id' => $contact_id,
]);
```

## Service Usage

### In Order Processing
```php
// Get the main orchestration service
$order_updater = \Drupal::service('commerce_civicrm.order_civicrm_updater');
$results = $order_updater->processCompletedOrder($order);
```

### In Rules Actions
```php
// Get specific services for Rules actions
$contact_updater = \Drupal::service('commerce_civicrm.contact_updater');
$membership_updater = \Drupal::service('commerce_civicrm.membership_updater');

$contact_id = $contact_updater->getContactIdByUser($user);
$membership_id = $membership_updater->createMembershipFromOrder(
  $contact_id, $membership_type_id, $order, $order_item
);
```

### In Custom Code
```php
// Access services in custom modules
$event_updater = \Drupal::service('commerce_civicrm.event_updater');
$events = $event_updater->getEvents();
```

## Service Benefits

### Modularity
- **Single Responsibility**: Each service handles one aspect of CiviCRM integration
- **Loose Coupling**: Services can be used independently
- **Easy Testing**: Individual services can be unit tested

### Extensibility
- **Service Decoration**: Services can be decorated to extend functionality
- **Custom Services**: New services can depend on existing ones
- **Plugin System**: Services support plugin-based extensions

### Maintainability
- **Clear Boundaries**: Well-defined service responsibilities
- **Dependency Management**: Explicit dependency declarations
- **Code Reuse**: Services can be reused across different contexts

## Testing Services

### Unit Testing
Each service can be unit tested independently:

```php
public function testContactCreation() {
  $contact_updater = new ContactUpdater(
    $this->entityTypeManager,
    $this->loggerFactory,
    $this->civicrmHelper
  );
  
  $contact_id = $contact_updater->updateContactFromOrder($order);
  $this->assertNotNull($contact_id);
}
```

### Integration Testing
Services can be tested together:

```php
public function testOrderProcessing() {
  $order_updater = \Drupal::service('commerce_civicrm.order_civicrm_updater');
  $results = $order_updater->processCompletedOrder($test_order);
  
  $this->assertArrayHasKey('contact_id', $results);
  $this->assertArrayHasKey('contributions', $results);
}
```

## Performance Considerations

### Service Instantiation
- Services are instantiated only when needed
- Dependency injection container manages service lifecycle
- Services can be cached for repeated use

### CiviCRM Connections
- CivicrmHelper manages CiviCRM connectivity efficiently
- Connection state is shared across services
- Proper error handling for connectivity issues

### Memory Management
- Services release resources appropriately
- Large data sets are processed in chunks
- Garbage collection is considered in long-running processes

## Future Extensibility

### New Entity Types
Adding support for new CiviCRM entities:

1. **Create Service**: Implement new updater service
2. **Register Service**: Add to services.yml
3. **Update Orchestrator**: Modify OrderCivicrmUpdater
4. **Add Rules Action**: Create corresponding Rules action

### Service Decoration
Extend existing services without modification:

```yaml
services:
  commerce_civicrm.enhanced_contact_updater:
    class: Drupal\my_module\Service\EnhancedContactUpdater
    decorates: commerce_civicrm.contact_updater
    arguments: ['@commerce_civicrm.enhanced_contact_updater.inner', '@my_custom_service']
```

### Plugin Integration
Services support plugin-based extensions:

```php
// In service constructor
$this->pluginManager = $plugin_manager;

// In service method
$plugins = $this->pluginManager->getDefinitions();
foreach ($plugins as $plugin) {
  $plugin->process($data);
}
```

## Service Documentation

Individual service documentation:
- **[ContactUpdater](contact-updater.md)** - Contact management service
- **[ContributionUpdater](contribution-updater.md)** - Contribution management service  
- **[MembershipUpdater](membership-updater.md)** - Membership management service
- **[EventUpdater](event-updater.md)** - Event registration service
- **[MailingUpdater](mailing-updater.md)** - Mailing group management service

The service architecture provides a robust, extensible foundation for CiviCRM integration that follows Drupal best practices and supports complex integration scenarios.
