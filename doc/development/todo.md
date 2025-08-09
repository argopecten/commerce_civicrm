# TODO Items and Development Roadmap

This document tracks all TODO items and pending development tasks in the Commerce CiviCRM module. Items are organized by priority and component.

## High Priority Items

### 1. Order Cancellation Functionality

#### Contribution Status Updates
**Location**: `src/Service/OrderCivicrmUpdater.php:369`  
**Method**: `updateContributionStatus()`

**Current State**: Placeholder implementation that only logs the action  
**Required Implementation**:
- Find existing contributions linked to the cancelled order
- Update contribution status to "Cancelled" in CiviCRM
- Handle edge cases where contributions may not exist
- Implement proper error handling and rollback

**Technical Requirements**:
```php
// Pseudo-code for implementation:
1. Query CiviCRM for contributions linked to order (via custom field or external ID)
2. Use CiviCRM API4 to update contribution status
3. Handle multiple contributions per order
4. Log success/failure for each contribution
5. Return status array for result tracking
```

**Dependencies**: 
- CiviCRM API4 access
- Method to link orders to contributions (external_identifier or custom field)
- Contribution status mapping (Drupal status → CiviCRM status)

#### Participant Status Updates  
**Location**: `src/Service/OrderCivicrmUpdater.php:392`  
**Method**: `updateParticipantStatuses()`

**Current State**: Placeholder implementation that only logs the action  
**Required Implementation**:
- Find existing participant records linked to the cancelled order
- Update participant status to "Cancelled" in CiviCRM
- Handle multiple participants per order
- Implement proper error handling

**Technical Requirements**:
```php
// Pseudo-code for implementation:
1. Query CiviCRM for participants linked to order
2. Use CiviCRM API4 to update participant status
3. Handle event-specific cancellation policies
4. Log participant cancellation details
5. Return array of updated participant IDs
```

**Dependencies**:
- CiviCRM API4 access
- Method to link orders to participants
- Participant status mapping
- Event-specific cancellation rules

## Medium Priority Items

### 2. Mailing List Enhanced Functionality

#### Double Opt-in Implementation
**Location**: `src/Service/MailingUpdater.php:279`  
**Method**: `sendDoubleOptInEmail()`

**Current State**: Logs action but doesn't send actual emails  
**Required Implementation**:
- Generate unique confirmation tokens
- Send double opt-in emails using CiviCRM's mailing system
- Create confirmation landing page/endpoint
- Handle confirmation responses
- Update subscription status based on confirmation

**Technical Requirements**:
```php
// Implementation components:
1. Token generation and storage
2. Email template creation in CiviCRM
3. Drupal route for confirmation handling
4. Token validation and expiration
5. Subscription status update workflow
```

**Dependencies**:
- CiviCRM Mailing component
- Drupal routing system
- Token storage mechanism
- Email template configuration

#### Welcome Message Functionality
**Location**: `src/Service/MailingUpdater.php:314`  
**Method**: `sendWelcomeMessage()`

**Current State**: Logs action but doesn't send actual messages  
**Required Implementation**:
- Send welcome emails to new mailing list subscribers
- Support templated welcome messages
- Handle different welcome messages per mailing group
- Integrate with CiviCRM's messaging system

**Technical Requirements**:
```php
// Implementation components:
1. Welcome email template management
2. Group-specific welcome message configuration
3. CiviCRM MessageTemplate integration
4. Subscriber preference handling
5. Delivery tracking and logging
```

**Dependencies**:
- CiviCRM MessageTemplate API
- Group-specific configuration storage
- Email delivery tracking

## Future Enhancement Items

### 3. OrderCompleteSubscriber Extensibility

#### Custom Cancel Handler Logic
**Location**: Event subscriber extensibility framework  
**Context**: Mentioned in documentation as TODO sections

**Suggested Implementations**:
- Remove contacts from specific mailing lists upon cancellation
- Update custom field values for cancelled orders
- Trigger custom workflows for order cancellation
- Send cancellation notifications to administrators
- Integration with external systems (payment processors, inventory, etc.)

#### Custom Fulfill Handler Logic  
**Location**: Event subscriber extensibility framework  
**Context**: Mentioned in documentation as TODO sections

**Suggested Implementations**:
- Activate membership statuses upon fulfillment
- Send completion/welcome emails
- Trigger post-purchase workflows
- Update contact preferences based on purchased products
- Integration with shipping/delivery systems

### 4. Advanced CiviCRM Integration

#### Enhanced Contribution Tracking
**Priority**: Low  
**Description**: Implement advanced contribution tracking and reporting

**Features**:
- Contribution line item details from order items
- Tax handling and reporting
- Multi-currency support
- Refund processing integration
- Recurring contribution setup

#### Membership Lifecycle Management
**Priority**: Low  
**Description**: Advanced membership features

**Features**:
- Automatic membership renewals
- Membership upgrade/downgrade workflows
- Family/organization membership handling
- Membership benefit tracking
- Integration with membership communications

#### Event Registration Enhancements
**Priority**: Low  
**Description**: Advanced event integration features

**Features**:
- Event capacity management
- Waitlist functionality
- Session/track registration
- Custom participant data collection
- Event-specific pricing integration

## Implementation Guidelines

### Development Standards
1. **API Usage**: All CiviCRM interactions must use API4
2. **Error Handling**: Comprehensive try/catch blocks with detailed logging
3. **Testing**: Unit tests for all new functionality
4. **Documentation**: Update service documentation for all changes
5. **Configuration**: Make features configurable where possible

### Testing Requirements
1. **Unit Tests**: Test individual service methods
2. **Integration Tests**: Test order workflow scenarios
3. **CiviCRM Tests**: Test with actual CiviCRM instance
4. **Edge Cases**: Test error conditions and edge cases

### Documentation Updates
When implementing TODO items, update:
1. Service documentation in `/doc/services/`
2. User guides in `/doc/user-guide/`
3. This TODO file to mark items as completed
4. Add new configuration documentation if needed

## Completed Items
*(Items will be moved here as they are implemented)*

None yet - this is the initial TODO documentation.

## Notes for Developers

### CiviCRM API4 Resources
- [CiviCRM API4 Documentation](https://docs.civicrm.org/dev/en/latest/api/v4/)
- [API4 Explorer](https://your-civicrm-site/civicrm/api4#/explorer)
- [API4 Examples](https://docs.civicrm.org/dev/en/latest/api/v4/examples/)

### Drupal Commerce Resources
- [Commerce Order Events](https://docs.drupalcommerce.org/commerce2/developer-guide/orders/order-events)
- [State Machine Workflows](https://docs.drupalcommerce.org/commerce2/developer-guide/orders/order-workflows)

### Module-Specific Patterns
- Follow existing service patterns in the module
- Use the logger factory consistently
- Maintain result array structures for consistency
- Follow defensive programming practices (especially for CiviCRM connectivity)

## Priority Assessment Criteria

**High Priority**: Core functionality that affects user workflows or data integrity  
**Medium Priority**: User experience improvements and common use cases  
**Low Priority**: Advanced features and optimizations

Items can be re-prioritized based on:
- User feedback and requirements
- Community contributions
- Integration needs with other modules
- Performance considerations
