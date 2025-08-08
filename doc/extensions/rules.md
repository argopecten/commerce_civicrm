# Commerce CiviCRM - Rules Actions Documentation

## Overview

The Commerce CiviCRM module provides comprehensive Rules integration with four dedicated Rules actions for CiviCRM operations. These actions enable advanced workflow automation beyond the standard product-based integration.

## Available Rules Actions

### 1. **Add a CiviCRM Contribution**
- **Plugin ID**: `commerce_civicrm_add_contribution`
- **Service**: ContributionUpdater
- **Purpose**: Create CiviCRM contributions with custom financial types

**Context Parameters:**
- `user` (entity:user) - The user for whom to create the contribution
- `order` (entity:commerce_order) - The commerce order (for amount/currency)
- `financial_type_id` (integer) - The CiviCRM Financial Type ID

**Usage Example:**
```
When: User account is created
Do: Add a CiviCRM Contribution
  User: [user]
  Order: [order]
  Financial Type ID: 1
```

### 2. **Add a CiviCRM Membership**
- **Plugin ID**: `commerce_civicrm_add_membership`
- **Service**: MembershipUpdater
- **Purpose**: Create or renew CiviCRM memberships with specific types

**Context Parameters:**
- `user` (entity:user) - The user for whom to create the membership
- `membership_type_id` (integer) - The CiviCRM Membership Type ID

**Usage Example:**
```
When: Order is completed
Do: Add a CiviCRM Membership
  User: [order:customer]
  Membership Type ID: 2
```

### 3. **Add a CiviCRM Event Registration** ✨ NEW
- **Plugin ID**: `commerce_civicrm_add_event_registration`
- **Service**: EventUpdater
- **Purpose**: Register users for CiviCRM events with participant roles

**Context Parameters:**
- `user` (entity:user) - The user to register for the event
- `event_id` (integer) - The CiviCRM Event ID
- `participant_role_id` (integer, optional) - The CiviCRM Participant Role ID

**Usage Example:**
```
When: User profile is updated
Do: Add a CiviCRM Event Registration
  User: [user]
  Event ID: 5
  Participant Role ID: 1
```

### 4. **Add to CiviCRM Mailing Group** ✨ NEW
- **Plugin ID**: `commerce_civicrm_add_mailing_subscription`
- **Service**: ContactUpdater
- **Purpose**: Subscribe users to CiviCRM mailing groups with preferences

**Context Parameters:**
- `user` (entity:user) - The user to add to the mailing group
- `mailing_group_id` (integer) - The CiviCRM Mailing Group ID
- `double_opt_in` (boolean, optional) - Whether to require double opt-in confirmation
- `send_welcome` (boolean, optional) - Whether to send a welcome message

**Usage Example:**
```
When: User registers for newsletter
Do: Add to CiviCRM Mailing Group
  User: [user]
  Mailing Group ID: 3
  Double Opt-in: Yes
  Send Welcome Message: Yes
```

## Rules Integration Features

### Automatic Contact Management
All Rules actions automatically:
- Find or create CiviCRM contacts based on Drupal user accounts
- Use the ContactUpdater service for consistent contact handling
- Log all operations for debugging and audit purposes

### Error Handling
Each Rules action includes:
- Comprehensive error logging
- Graceful failure handling
- Detailed status messages
- CiviCRM availability checks

### Service Dependencies
All Rules actions use dependency injection for:
- Logger services for comprehensive logging
- ContactUpdater for contact management
- Specific updater services (ContributionUpdater, MembershipUpdater, EventUpdater)
- CivicrmHelper for robust CiviCRM connectivity and helper methods

## Configuration Examples

### Example 1: Membership on Order Completion
```yaml
# Create a membership when an order is completed
Rule: "Create membership on order completion"
Events:
  - commerce_order.place.post_transition
Conditions:
  - Order has specific product type
Actions:
  - Add a CiviCRM Membership
    User: [commerce_order:customer]
    Membership Type ID: 2
```

### Example 2: Event Registration on Profile Update
```yaml
# Register for event when user updates their profile
Rule: "Auto-register for conference"
Events:
  - entity:user.update
Conditions:
  - User has specific role
  - Profile field contains "conference"
Actions:
  - Add a CiviCRM Event Registration
    User: [user]
    Event ID: 10
    Participant Role ID: 1
```

### Example 3: Mailing List Subscription
```yaml
# Subscribe to newsletter on registration
Rule: "Newsletter subscription"
Events:
  - user.register
Conditions:
  - User email domain is not empty
Actions:
  - Add to CiviCRM Mailing Group
    User: [user]
    Mailing Group ID: 5
    Double Opt-in: True
    Send Welcome Message: True
```

### Example 4: Complex Workflow
```yaml
# Multi-step workflow on order completion
Rule: "Complete CiviCRM integration"
Events:
  - commerce_order.place.post_transition
Conditions:
  - Order total > $100
  - Order contains membership product
Actions:
  - Add a CiviCRM Contribution
    User: [commerce_order:customer]
    Order: [commerce_order]
    Financial Type ID: 1
  - Add a CiviCRM Membership
    User: [commerce_order:customer]
    Membership Type ID: 3
  - Add to CiviCRM Mailing Group
    User: [commerce_order:customer]
    Mailing Group ID: 2
    Send Welcome Message: True
```

## Best Practices

### 1. **ID Management**
- Use valid CiviCRM entity IDs (found in CiviCRM admin interface)
- Test IDs in a development environment first
- Document your ID mappings for maintenance

### 2. **Error Monitoring**
- Monitor logs at `/admin/reports/dblog`
- Filter by "commerce_civicrm" for relevant messages
- Set up log monitoring for production environments

### 3. **Performance Considerations**
- Avoid creating too many Rules actions per event
- Use conditions to limit when actions fire
- Consider using queues for high-volume operations

### 4. **Contact Management**
- The ContactUpdater service handles contact creation/updates automatically
- Users are matched to CiviCRM contacts by email address
- Ensure user profiles have valid email addresses

## Troubleshooting

### Common Issues
1. **"Could not find CiviCRM contact"**
   - Ensure the user has a valid email address
   - Check CiviCRM connectivity
   - Verify ContactUpdater is working

2. **"Invalid entity ID"**
   - Verify the ID exists in CiviCRM
   - Check entity is active/enabled
   - Ensure proper permissions

3. **"CiviCRM not available"**
   - Check CiviCRM module status
   - Verify CiviCRM database connectivity
   - Review CiviCRM configuration

### Debug Steps
1. Enable verbose logging for commerce_civicrm
2. Test Rules actions individually
3. Check CiviCRM directly for created records
4. Review Rules event firing with Rules debug module

## Integration with Product-Based Workflow

These Rules actions complement the product-based CiviCRM integration:

- **Product Integration**: Automatic processing when products are purchased
- **Rules Actions**: Manual or event-driven processing for custom workflows
- **Combined Use**: Use both approaches for comprehensive CiviCRM integration

Both approaches share the same underlying services and provide consistent CiviCRM integration patterns.
