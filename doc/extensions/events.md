# Event Registration Integration

## Overview

The Event Registration feature allows products to automatically register customers for CiviCRM events when orders are completed. This is ideal for conference registrations, workshop enrollments, and training sessions.

## Features

### Event Selection
- **Active Events Only**: Choose from currently active CiviCRM events
- **Public Events**: Only public events are available for selection
- **Event Information**: Display event titles, dates, and descriptions
- **Multiple Events**: Different products can register for different events

### Participant Management
- **Role Assignment**: Assign specific participant roles to registrants
- **Status Tracking**: Automatic participant status management
- **Duplicate Prevention**: Prevents duplicate registrations for the same contact/event
- **Registration History**: Track all registrations through CiviCRM

## Configuration

### Product Setup

1. **Edit Product**: Go to the product edit form
2. **Enable Integration**: Check "Enable CiviCRM Integration"
3. **Select Entity Type**: Choose "Event Registration"
4. **Choose Event**: Select from available active events
5. **Set Participant Role**: Choose the appropriate participant role
6. **Save Configuration**: Save the product

### Example Configuration
```json
{
  "enabled": true,
  "entity": "event",
  "entity_id": "5",
  "participant_role_id": "1"
}
```

## Event Management in CiviCRM

### Event Requirements
Events must meet these criteria to be available:
- **Active Status**: Event must be active in CiviCRM
- **Public Events**: Event must be marked as public
- **Valid Dates**: Event should have proper start/end dates

### Participant Roles
Common participant roles include:
- **Attendee**: Standard event participant
- **Volunteer**: Event volunteer
- **Host**: Event organizer or host
- **Speaker**: Event presenter

## Processing Workflow

### Order Completion
When an order containing event products is completed:

1. **Contact Verification**: Ensure customer contact exists in CiviCRM
2. **Event Validation**: Verify event is still active and available
3. **Duplicate Check**: Check for existing participant record
4. **Registration Creation**: Create participant record with specified role
5. **Status Update**: Set participant status to "Registered"
6. **Logging**: Record operation in logs

### API Operations
The system uses CiviCRM API4 for event operations:

```php
// Get available events
$events = \Civi\Api4\Event::get()
  ->addSelect('id', 'title', 'start_date', 'end_date')
  ->addWhere('is_active', '=', TRUE)
  ->addWhere('is_public', '=', TRUE)
  ->execute();

// Create participant record
$participant = \Civi\Api4\Participant::create()
  ->addValue('contact_id', $contact_id)
  ->addValue('event_id', $event_id)
  ->addValue('role_id', $participant_role_id)
  ->addValue('status_id', 1) // Registered
  ->execute();
```

## Use Cases

### Conference Registration
**Scenario**: Annual conference with multiple session types

**Setup**:
- Create products for different conference passes
- Link each product to the corresponding event
- Assign appropriate participant roles (Attendee, Speaker, VIP)

**Result**: Customers automatically registered when they purchase tickets

### Workshop Series
**Scenario**: Multi-part workshop series

**Setup**:
- Create separate products for each workshop session
- Link products to individual workshop events
- Use consistent participant roles across series

**Result**: Customers can register for individual sessions or complete series

### Training Programs
**Scenario**: Professional certification training

**Setup**:
- Create product bundles for certification levels
- Link to training event sequences
- Assign role based on certification level

**Result**: Automatic enrollment in appropriate training tracks

## Error Handling

### Common Scenarios
- **Event Inactive**: Warning logged, registration skipped
- **Duplicate Registration**: Warning logged, existing record preserved
- **Invalid Participant Role**: Error logged, default role used
- **Contact Missing**: Error logged, registration fails

### Error Recovery
- **Graceful Degradation**: Other order processing continues
- **Detailed Logging**: Specific error information recorded
- **Retry Logic**: Failed registrations can be manually retried

## Monitoring and Reporting

### Log Messages
```
INFO: Registered participant 123 for event 5 (role: 1)
WARNING: Participant already registered for event 5
ERROR: Event 5 not found or inactive
DEBUG: Processing event registration for order 456
```

### CiviCRM Reports
Standard CiviCRM reports show:
- Event participant lists
- Registration statistics
- Revenue by event
- Participant role breakdowns

## Advanced Configuration

### Rules Integration
Use Rules actions for complex scenarios:

```yaml
Rule: "VIP Event Registration"
Events:
  - commerce_order.place.post_transition
Conditions:
  - Order total > $500
  - Order contains premium product
Actions:
  - Add a CiviCRM Event Registration
    User: [commerce_order:customer]
    Event ID: 10
    Participant Role ID: 3  # VIP role
```

### Multiple Event Products
Single orders can contain multiple event products:
- Each product processes independently
- Different events and roles per product
- Comprehensive logging for all registrations

### Custom Participant Roles
Create custom participant roles in CiviCRM:
1. Go to CiviCRM admin
2. Navigate to participant roles
3. Create new roles as needed
4. Use new role IDs in product configuration

## Best Practices

### Event Planning
- **Advance Setup**: Configure products before event promotion
- **Capacity Management**: Monitor registrations vs. event capacity
- **Role Planning**: Define participant roles before product creation

### Product Organization
- **Clear Naming**: Use descriptive product names
- **Event Grouping**: Group related event products
- **Documentation**: Maintain records of event/product mappings

### Testing
- **Development Testing**: Test registrations in development environment
- **Event Validation**: Verify events exist and are properly configured
- **Role Testing**: Test all participant role assignments

## Troubleshooting

### Registration Not Created
1. **Check Event Status**: Verify event is active and public
2. **Validate Product Config**: Ensure correct event and role IDs
3. **Review Logs**: Look for specific error messages
4. **Test Contact**: Verify customer contact exists

### Wrong Participant Role
1. **Check Role ID**: Verify participant role ID is correct
2. **Review CiviCRM Setup**: Ensure role exists and is active
3. **Update Configuration**: Correct role ID in product settings

### Duplicate Registrations
- **Expected Behavior**: System prevents duplicates by design
- **Check Existing**: Review existing participant records
- **Update if Needed**: Manually update registration details in CiviCRM

## Integration with Other Features

### Combined with Contributions
Products can create both contributions and event registrations:
- Contribution tracks payment
- Participant record tracks attendance

### Mailing List Integration
Event participants can be automatically added to event mailing lists:
- Use Rules to add registrants to event-specific groups
- Send pre-event information and updates
- Manage post-event follow-up

### Membership Integration
Event registration can be combined with membership:
- Member discounts on events
- Automatic member event access
- Integrated member/participant tracking

The Event Registration feature provides comprehensive event management integration, supporting both simple registration scenarios and complex event series workflows.
