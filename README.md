# Commerce CiviCRM Integration Module

A comprehensive Drupal module that provides seamless integration between Drupal Commerce and CiviCRM, featuring automatic contact synchronization, contribution tracking, membership management, event registration, and mailing list subscriptions.

## Key Features

### Core Integration Types
- **🏦 Contribution Tracking**: Automatically creates CiviCRM contributions for financial records
- **👥 Membership Management**: Handles membership creation, renewals, and lifecycle management
- **📅 Event Registration**: Registers customers for CiviCRM events with role assignments
- **📧 Mailing List Subscriptions**: Adds customers to mailing groups with advanced preferences

### Advanced Capabilities
- **🔄 Automatic Contact Synchronization**: Creates and updates CiviCRM contacts from Commerce profiles
- **⚡ Direct Event Integration**: Native Commerce workflow integration without external dependencies
- **🏗️ Service-Based Architecture**: Modern, maintainable code using Drupal's service container
- **📊 Comprehensive Logging**: Detailed logging for debugging and audit trails
- **🛡️ Error Handling**: Graceful error handling with duplicate prevention

## Quick Start

1. **Install**: `drush en commerce_civicrm`
2. **Configure**: Set up CiviCRM integration on products
3. **Test**: Process a sample order
4. **Monitor**: Review logs and CiviCRM records

## Documentation

### 📚 [Complete Documentation](doc/README.md)
Comprehensive documentation organized by topic and audience.

### 🚀 Quick Links
- **[Installation Guide](doc/user-guide/installation.md)** - Get started with setup and configuration
- **[Product Configuration](doc/user-guide/product-configuration.md)** - Configure products for CiviCRM integration
- **[Extensions Overview](doc/extensions/overview.md)** - Learn about advanced features
- **[Developer Guide](doc/development/developer-guide.md)** - Extend and customize the module

## System Requirements

- **Drupal**: 10.3+ or 11.x
- **CiviCRM**: Latest stable version
- **Commerce**: Latest stable version
- **Dependencies**: Profile, State Machine

## Integration Types

| Type | Purpose | Configuration |
|------|---------|---------------|
| 🏦 **Contributions** | Financial tracking | Select Financial Type |
| 👥 **Memberships** | Membership management | Select Membership Type |
| 📅 **Events** | Event registration | Choose Event + Role |
| 📧 **Mailing Lists** | Email subscriptions | Select Group + Preferences |

## Architecture

The module uses a **service-based architecture** with these core services:

- **ContactUpdater** - Contact management and synchronization
- **ContributionUpdater** - Financial record handling
- **MembershipUpdater** - Membership lifecycle management
- **EventUpdater** - Event registration processing
- **MailingUpdater** - Mailing group subscriptions
- **OrderCivicrmUpdater** - Workflow orchestration

## Automatic Processing

When orders are completed, the module automatically:

1. **👤 Contact Processing** - Creates/updates CiviCRM contacts
2. **💰 Contribution Creation** - Records financial transactions
3. **🎫 Membership Processing** - Handles membership creation/renewal
4. **📅 Event Registration** - Registers for events
5. **📧 Mailing Subscriptions** - Adds to mailing groups

## Getting Started

1. Install and configure CiviCRM
2. Install and enable Commerce CiviCRM module
3. Configure products with CiviCRM integration settings
4. Process orders to automatically create CiviCRM records

## Error Handling & Monitoring

- **🔍 Comprehensive Logging** - Detailed operation tracking
- **🛡️ Graceful Degradation** - Continues processing if issues occur
- **🚫 Duplicate Prevention** - Prevents duplicate records
- **📊 Monitoring Tools** - Built-in debugging and audit capabilities

## Support & Community

- **📖 Documentation** - [Complete documentation](doc/README.md)
- **🐛 Issue Queue** - Report bugs and request features
- **💬 Community** - Join the Drupal CiviCRM community
- **🔧 Professional Support** - Available from certified developers