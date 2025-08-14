# Rules Integration (Optional)

This module includes optional Rules integration for advanced workflow automation. The Rules actions are available when the Rules module is installed but are not required for core functionality.

## Available Rules Actions

The following Rules actions are available in `src/Plugin/RulesAction/`:

1. **CiviCrmAddContribution** - Create contributions with custom financial types
2. **CiviCrmAddMembership** - Create or renew memberships with specific types  
3. **CiviCrmAddEventRegistration** - Register users for events with participant roles
4. **CiviCrmAddMailingSubscription** - Subscribe users to mailing groups with preferences

## Core vs Rules Integration

- **Core Integration**: Works automatically without Rules, processes orders based on product configuration
- **Rules Integration**: Provides additional flexibility for custom workflows and complex automation scenarios

## Usage

If you install the Rules module, these actions become available in the Rules interface for creating custom workflows beyond the automatic order processing.

## Migration Note

This module was originally designed with Rules as a primary integration method. Version 2.0+ provides direct Commerce integration that works without Rules while maintaining backward compatibility for existing Rules-based workflows.
