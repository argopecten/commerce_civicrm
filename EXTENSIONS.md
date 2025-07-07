# Commerce CiviCRM Integration Module - Events & Mailing Extensions

## New Features Added

The Commerce CiviCRM module has been successfully extended to include **Event Registration** and **Mailing List Subscription** functionality.

### Event Registration

When customers purchase products configured for event registration:

- **Event Selection**: Choose from active, public CiviCRM events
- **Participant Roles**: Assign specific roles to event participants
- **Automatic Registration**: Customers are automatically registered for events when orders complete
- **Duplicate Prevention**: Prevents duplicate registrations for the same contact/event

#### Configuration:
1. Edit a Commerce product
2. Enable CiviCRM integration
3. Select "Event Registration" as the entity type
4. Choose the target event and participant role
5. Save the product

### Mailing List Subscription

When customers purchase products configured for mailing subscriptions:

- **Mailing Group Selection**: Choose from active CiviCRM mailing groups
- **Subscription Preferences**: Configure double opt-in, welcome messages, and update settings
- **Automatic Subscription**: Customers are automatically added to mailing groups when orders complete
- **Duplicate Prevention**: Prevents duplicate subscriptions for existing members

#### Configuration:
1. Edit a Commerce product
2. Enable CiviCRM integration
3. Select "Mailing List Subscription" as the entity type
4. Choose the target mailing group
5. Configure mailing preferences:
   - **Double Opt-in**: Require email confirmation
   - **Welcome Message**: Send welcome email to new subscribers
   - **Update Existing**: Update existing subscriber information
6. Save the product

## Rules Integration

The module provides comprehensive Rules actions for advanced workflow automation:

### Available Rules Actions:
1. **Add a CiviCRM Contribution** - Create contributions with custom financial types
2. **Add a CiviCRM Membership** - Create or renew memberships with specific types
3. **Add a CiviCRM Event Registration** - Register users for events with participant roles
4. **Add to CiviCRM Mailing Group** - Subscribe users to mailing groups with preferences

### Features:
- Automatic contact management and creation
- Comprehensive error handling and logging
- Service-based architecture with dependency injection
- Support for all CiviCRM entity types
- Flexible parameter configuration

See `RULES_ACTIONS.md` for detailed documentation and examples.

### New Service Methods

**ContactUpdater Service:**
- `updateContactFromOrder()` - Creates or updates CiviCRM contacts
- `getFinancialTypes()` - Retrieves available CiviCRM financial types
- `getContactIdByUser()` - Gets CiviCRM contact ID for Drupal users

**ContributionUpdater Service:**
- `createContributionFromOrder()` - Creates CiviCRM contributions from orders
- Enhanced error handling and logging

**MembershipUpdater Service:** ✨ NEW
- `createMembershipFromOrder()` - Creates CiviCRM memberships from orders
- `getMembershipTypes()` - Retrieves available CiviCRM membership types
- `renewMembership()` - Renews existing memberships
- `cancelMembership()` - Cancels memberships

**EventUpdater Service:**
- `getEvents()` - Retrieves available CiviCRM events with dates
- `getParticipantRoles()` - Retrieves available participant roles
- `createEventRegistrationWithRole()` - Creates event registrations with specific roles

**MailingUpdater Service:** ✨ NEW
- `addContactToMailingGroup()` - Adds contacts to mailing groups with preferences
- `getMailingGroups()` - Retrieves available CiviCRM mailing groups
- `processMailingSubscriptionFromOrder()` - Processes order-based subscriptions
- `sendDoubleOptInEmail()` - Framework for double opt-in functionality
- `sendWelcomeMessage()` - Framework for welcome messages
- `bulkAddContactsToMailingGroup()` - Bulk subscription operations

### Form Extensions

The product configuration form now includes:

- **Entity Type Selection**: Four options (Contribution, Membership, Event, Mailing)
- **Event Configuration**: Event selection and participant role assignment
- **Mailing Configuration**: Mailing group selection and preference settings
- **Conditional Visibility**: Form fields appear based on selected entity type

### Order Processing

The `OrderCivicrmUpdater` service now processes all four entity types:

1. **Contributions**: Creates financial records with specified types
2. **Memberships**: Creates membership records (existing functionality)
3. **Events**: Registers participants with specified roles
4. **Mailings**: Adds contacts to mailing groups with preferences

### API Integration

Uses CiviCRM API4 for all operations:

- `\Civi\Api4\Event::get()` - Retrieve events
- `\Civi\Api4\Participant::create()` - Create event registrations
- `\Civi\Api4\Group::get()` - Retrieve mailing groups
- `\Civi\Api4\GroupContact::create()` - Add contacts to groups
- `\Civi\Api4\OptionValue::get()` - Retrieve participant roles

## Usage Examples

### Event Registration Product
```json
{
  "enabled": true,
  "entity": "event",
  "entity_id": "5",
  "participant_role_id": "1"
}
```

### Mailing Subscription Product
```json
{
  "enabled": true,
  "entity": "mailing",
  "entity_id": "3",
  "mailing_preferences": {
    "double_opt_in": "double_opt_in",
    "send_welcome": "send_welcome"
  }
}
```

## Benefits

1. **Comprehensive Integration**: Four types of CiviCRM integration in one module
2. **Flexible Configuration**: Per-product configuration with specific settings
3. **Automatic Processing**: No manual intervention required
4. **Error Handling**: Comprehensive logging and graceful error handling
5. **Scalable Architecture**: Easy to extend with additional entity types
6. **User Experience**: Seamless customer experience with automatic CiviCRM updates

## Logging & Debugging

All operations are logged with the 'commerce_civicrm' channel:

- **Info**: Successful operations
- **Warning**: Non-critical issues
- **Error**: Failed operations
- **Debug**: Detailed operation information

Check logs at: `/admin/reports/dblog`

## Future Enhancements

The framework supports easy extension for:

- Custom CiviCRM entities
- Additional mailing preferences
- Complex event registration workflows
- Integration with CiviCRM's native mailing system
- Custom field mapping
- Conditional logic based on order data

## Support

The extended module maintains backward compatibility with existing installations while adding powerful new functionality for event management and mailing list automation.
