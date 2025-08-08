# Product Configuration

## Overview

Products can be configured to automatically trigger CiviCRM operations when purchased. The module supports four types of CiviCRM integration:

1. **Contributions** - Creates financial records
2. **Memberships** - Creates or renews memberships
3. **Events** - Registers customers for events
4. **Mailing Lists** - Subscribes customers to mailing groups

## Configuration Steps

### Basic Configuration

1. Edit any Commerce product
2. Scroll to the "CiviCRM Integration" section
3. Enable CiviCRM integration
4. Choose the integration type

### Integration Types

#### Contribution Integration
- **Purpose**: Creates a CiviCRM contribution when the product is purchased
- **Configuration**: Select the CiviCRM Financial Type
- **Use Case**: Donations, membership fees, event fees

#### Membership Integration
- **Purpose**: Creates or renews a CiviCRM membership
- **Configuration**: Select the CiviCRM Membership Type
- **Use Case**: Annual memberships, subscription services

#### Event Registration
- **Purpose**: Registers the customer for a CiviCRM event
- **Configuration**: 
  - Select the target CiviCRM event
  - Choose the participant role
- **Use Case**: Conference registration, workshop enrollment

#### Mailing List Subscription
- **Purpose**: Adds the customer to a CiviCRM mailing group
- **Configuration**:
  - Select the target mailing group
  - Configure subscription preferences:
    - **Double Opt-in**: Require email confirmation
    - **Welcome Message**: Send welcome email to new subscribers
    - **Update Existing**: Update existing subscriber information
- **Use Case**: Newsletter subscriptions, marketing lists

## Field Structure

The CiviCRM configuration is stored in a JSON field with this structure:

```json
{
  "enabled": true,
  "entity": "contribution",
  "entity_id": "1"
}
```

### Event Registration Example
```json
{
  "enabled": true,
  "entity": "event",
  "entity_id": "5",
  "participant_role_id": "1"
}
```

### Mailing Subscription Example
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

## Best Practices

### Product Organization
- Use clear product names that indicate CiviCRM integration
- Group related products (e.g., different membership levels)
- Document your CiviCRM entity IDs for reference

### Testing
- Always test new product configurations in a development environment
- Verify CiviCRM records are created correctly
- Check that customer data flows properly

### Maintenance
- Keep CiviCRM entity IDs updated if they change
- Monitor logs for configuration issues
- Review and update mailing preferences as needed

## Troubleshooting

### Common Issues

1. **CiviCRM Integration section not visible**
   - Verify the field was added during installation
   - Check field permissions and display settings

2. **Entity options not loading**
   - Verify CiviCRM is properly configured
   - Check CiviCRM database connectivity
   - Review error logs

3. **Records not created on purchase**
   - Verify product configuration is saved
   - Check order completion workflow
   - Review processing logs

### Validation

To verify configuration:
1. Save the product and reload the edit form
2. Confirm the settings are preserved
3. Process a test order
4. Check CiviCRM for the created records

## Next Steps

- [Order Processing](order-processing.md) - Understand how configured products are processed
- [Extensions Overview](../extensions/overview.md) - Learn about advanced features
- [Rules Integration](../extensions/rules.md) - Set up advanced workflows
