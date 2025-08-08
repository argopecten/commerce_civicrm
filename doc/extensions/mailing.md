# Mailing List Integration

## Overview

The Mailing List Subscription feature automatically adds customers to CiviCRM mailing groups when they purchase configured products. This enables automated newsletter subscriptions, marketing list management, and customer communication workflows.

## Features

### Mailing Group Management
- **Group Selection**: Choose from active CiviCRM mailing groups
- **Smart Filtering**: Only mailing-enabled groups are available
- **Multiple Groups**: Different products can subscribe to different groups
- **Group Status**: Automatic status management (Added, Pending, Removed)

### Subscription Preferences
- **Double Opt-in**: Require email confirmation before activation
- **Welcome Messages**: Send welcome emails to new subscribers
- **Update Existing**: Update information for existing subscribers
- **Source Tracking**: Track subscription source from commerce orders

### Advanced Features
- **Duplicate Prevention**: Prevents duplicate subscriptions
- **Bulk Operations**: Add multiple contacts to groups efficiently
- **Status Management**: Handle various subscription states
- **Integration Framework**: Ready for advanced mailing workflows

## Configuration

### Product Setup

1. **Edit Product**: Go to the product edit form
2. **Enable Integration**: Check "Enable CiviCRM Integration"
3. **Select Entity Type**: Choose "Mailing List Subscription"
4. **Choose Mailing Group**: Select from available groups
5. **Configure Preferences**: Set subscription options
6. **Save Configuration**: Save the product

### Example Configuration
```json
{
  "enabled": true,
  "entity": "mailing",
  "entity_id": "3",
  "mailing_preferences": {
    "double_opt_in": "double_opt_in",
    "send_welcome": "send_welcome",
    "update_existing": "update_existing"
  }
}
```

### Preference Options

#### Double Opt-in
- **Purpose**: Requires email confirmation before adding to group
- **Status**: Sets initial status to "Pending" until confirmed
- **Compliance**: Helps with email marketing compliance (GDPR, CAN-SPAM)

#### Welcome Messages
- **Purpose**: Sends welcome email to new subscribers
- **Framework**: Basic framework provided for customization
- **Integration**: Can integrate with CiviCRM's native mailing system

#### Update Existing
- **Purpose**: Updates existing subscriber information
- **Behavior**: Refreshes contact data if already subscribed
- **Default**: Enabled by default to keep data current

## Processing Workflow

### Order Completion
When an order containing mailing products is completed:

1. **Contact Verification**: Ensure customer contact exists in CiviCRM
2. **Group Validation**: Verify mailing group exists and is active
3. **Membership Check**: Check for existing group membership
4. **Subscription Processing**: Add contact to group with preferences
5. **Status Setting**: Set appropriate membership status
6. **Logging**: Record operation in logs

### API Operations
The system uses CiviCRM API4 for mailing operations:

```php
// Get available mailing groups
$groups = \Civi\Api4\Group::get()
  ->addSelect('id', 'title', 'description')
  ->addWhere('is_active', '=', TRUE)
  ->addWhere('group_type', 'CONTAINS', 'Mailing List')
  ->execute();

// Add contact to group
$group_contact = \Civi\Api4\GroupContact::create()
  ->addValue('contact_id', $contact_id)
  ->addValue('group_id', $group_id)
  ->addValue('status', 'Added')
  ->execute();
```

## Use Cases

### Newsletter Subscriptions
**Scenario**: Automatic newsletter signup with product purchases

**Setup**:
- Create "Newsletter Subscription" product
- Link to newsletter mailing group
- Enable welcome message
- Optionally enable double opt-in

**Result**: Customers automatically subscribed when purchasing any product

### Product-Specific Lists
**Scenario**: Different mailing lists for different product categories

**Setup**:
- Create category-specific mailing groups in CiviCRM
- Link products to appropriate mailing groups
- Configure different preferences per category

**Result**: Customers receive relevant communications based on purchases

### Premium Customer Lists
**Scenario**: VIP mailing list for high-value customers

**Setup**:
- Create premium mailing group
- Link to high-value products only
- Use Rules for additional qualification logic

**Result**: Automatic VIP list management based on purchase behavior

## Mailing Group Setup in CiviCRM

### Group Requirements
Groups must meet these criteria to be available:
- **Active Status**: Group must be active in CiviCRM
- **Mailing Type**: Group must include "Mailing List" in group types
- **Visibility**: Group should be configured for appropriate visibility

### Group Configuration
1. **Create Group**: Go to CiviCRM Contacts → Manage Groups
2. **Set Title**: Use descriptive group name
3. **Configure Type**: Select "Mailing List" type
4. **Set Permissions**: Configure group visibility and permissions
5. **Save Group**: Save and note the group ID

## Subscription Status Management

### Status Types
- **Added**: Active subscription, receives mailings
- **Pending**: Awaiting confirmation (double opt-in)
- **Removed**: Previously subscribed but removed

### Status Transitions
- **New Subscription**: Direct to "Added" or "Pending" based on preferences
- **Existing Member**: Update contact information, maintain status
- **Removed Member**: Can be re-added with new subscription

## Error Handling

### Common Scenarios
- **Group Not Found**: Warning logged, subscription skipped
- **Already Subscribed**: Info logged, existing subscription preserved
- **Invalid Group Type**: Error logged, subscription fails
- **Contact Missing**: Error logged, subscription fails

### Recovery Options
- **Manual Processing**: Failed subscriptions can be manually processed
- **Retry Logic**: Orders can be reprocessed for failed subscriptions
- **Bulk Import**: Use CiviCRM import tools for large-scale corrections

## Monitoring and Reporting

### Log Messages
```
INFO: Added contact 123 to mailing group 3
INFO: Contact 123 already in mailing group 3, updated information
WARNING: Mailing group 5 not found or inactive
DEBUG: Processing mailing subscription for order 456
```

### CiviCRM Reports
Standard CiviCRM reports show:
- Group membership lists
- Subscription statistics
- Group growth over time
- Contact source tracking

## Advanced Configuration

### Rules Integration
Use Rules actions for complex subscription logic:

```yaml
Rule: "Premium Newsletter Subscription"
Events:
  - commerce_order.place.post_transition
Conditions:
  - Order total > $100
  - Customer is returning customer
Actions:
  - Add to CiviCRM Mailing Group
    User: [commerce_order:customer]
    Mailing Group ID: 5
    Double Opt-in: No
    Send Welcome Message: Yes
```

### Multiple Group Subscriptions
Single products can subscribe to multiple groups using Rules:
- Primary product configuration for main group
- Additional Rules actions for secondary groups
- Different preferences for different groups

### Conditional Subscriptions
Use Rules conditions for smart subscription logic:
- Geographic targeting
- Customer history
- Product combinations
- Purchase amounts

## Integration with Email Marketing

### CiviCRM Mailing
- **Native Integration**: Works with CiviCRM's built-in mailing system
- **Template Support**: Use CiviCRM mailing templates
- **Tracking**: Built-in open/click tracking
- **Compliance**: GDPR and CAN-SPAM compliance features

### External Services
Framework supports integration with external email services:
- **API Webhooks**: Send subscription data to external services
- **Data Export**: Export group membership for external systems
- **Sync Services**: Two-way synchronization with email platforms

## Best Practices

### Group Organization
- **Clear Naming**: Use descriptive group names
- **Logical Grouping**: Organize groups by purpose/audience
- **Documentation**: Maintain records of group purposes and sources

### Preference Management
- **Compliance First**: Enable double opt-in for compliance
- **Welcome Messages**: Provide clear welcome information
- **Unsubscribe Options**: Ensure easy unsubscribe processes

### Data Quality
- **Regular Cleanup**: Remove inactive or bouncing contacts
- **Data Validation**: Ensure accurate contact information
- **Segmentation**: Use groups for effective audience segmentation

## Troubleshooting

### Subscription Not Created
1. **Check Group Status**: Verify group is active and mailing-enabled
2. **Validate Product Config**: Ensure correct group ID
3. **Review Logs**: Look for specific error messages
4. **Test Contact**: Verify customer contact exists

### Wrong Group Assignment
1. **Check Group ID**: Verify mailing group ID is correct
2. **Review CiviCRM Setup**: Ensure group exists and is active
3. **Update Configuration**: Correct group ID in product settings

### Double Opt-in Not Working
1. **Email Configuration**: Check CiviCRM email system configuration
2. **Template Setup**: Verify double opt-in templates exist
3. **Framework Extension**: May require additional development for full functionality

## Integration with Other Features

### Combined with Events
Mailing subscriptions can complement event registration:
- Event-specific mailing lists
- Pre-event communications
- Post-event follow-up

### Membership Integration
Combine mailing with membership management:
- Member-only mailing lists
- Membership tier communications
- Renewal reminders

### Contribution Tracking
Link mailing subscriptions with financial tracking:
- Donor-specific communications
- Fundraising campaign lists
- Thank you message workflows

The Mailing List Integration feature provides comprehensive email marketing automation, supporting both simple subscription scenarios and complex, multi-tier communication strategies.
