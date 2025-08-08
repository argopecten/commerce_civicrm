# Troubleshooting

## Common Issues and Solutions

### Installation Issues

#### Field Not Added to Product Types
**Symptoms**: CiviCRM Integration section not visible on product edit forms

**Solutions**:
1. **Check Module Installation**:
   ```bash
   drush pm:list | grep commerce_civicrm
   ```

2. **Manually Add Fields**:
   ```php
   // Add field to all product types
   $results = commerce_civicrm_add_field_to_all_product_types();
   
   // Add field to specific product type
   $success = commerce_civicrm_add_field_to_product_type('my_product_type');
   ```

3. **Check Field Display**:
   - Go to product type manage display settings
   - Ensure CiviCRM field is not hidden

#### CiviCRM Not Available
**Symptoms**: "CiviCRM not available" errors in logs

**Solutions**:
1. **Check CiviCRM Status**: Visit `/admin/reports/status`
2. **Verify CiviCRM Module**: Ensure CiviCRM module is enabled
3. **Database Connectivity**: Check CiviCRM database connection
4. **Permissions**: Verify CiviCRM access permissions

### Configuration Issues

#### Entity Options Not Loading
**Symptoms**: Dropdown lists empty in product configuration

**Solutions**:
1. **CiviCRM Connectivity**: Verify CiviCRM is accessible
2. **API Permissions**: Check CiviCRM API permissions
3. **Entity Status**: Ensure entities are active in CiviCRM
4. **Clear Cache**: Clear Drupal and CiviCRM caches

#### Configuration Not Saving
**Symptoms**: Product configuration resets after saving

**Solutions**:
1. **Field Permissions**: Check field edit permissions
2. **Form Validation**: Look for validation errors
3. **JSON Format**: Verify configuration data is valid JSON
4. **Field Storage**: Check field storage configuration

### Order Processing Issues

#### Contacts Not Created
**Symptoms**: Orders complete but no CiviCRM contacts created

**Diagnostic Steps**:
1. **Check Billing Profile**: Ensure orders have complete billing profiles
2. **Review Logs**: Look for contact creation errors
3. **Verify Email**: Check that customer email addresses are valid
4. **Test API**: Test CiviCRM contact creation directly

**Solutions**:
```php
// Test contact creation
$contact_updater = \Drupal::service('commerce_civicrm.contact_updater');
$contact_id = $contact_updater->updateContactFromOrder($order);
```

#### Contributions Not Appearing
**Symptoms**: Orders process but no contributions in CiviCRM

**Diagnostic Steps**:
1. **Product Configuration**: Verify products have CiviCRM integration enabled
2. **Financial Types**: Check that financial type IDs are valid
3. **Order State**: Ensure orders reached "completed" state
4. **Duplicate Check**: Look for existing contributions

**Solutions**:
1. **Check Product Settings**:
   ```php
   $settings = commerce_civicrm_get_product_settings($product);
   var_dump($settings);
   ```

2. **Test Contribution Creation**:
   ```php
   $contribution_updater = \Drupal::service('commerce_civicrm.contribution_updater');
   $contribution_id = $contribution_updater->createContributionFromOrder($order);
   ```

#### Event Registrations Failing
**Symptoms**: Event products purchased but no CiviCRM registrations

**Diagnostic Steps**:
1. **Event Status**: Verify events are active and public in CiviCRM
2. **Event Dates**: Check that events haven't ended
3. **Participant Roles**: Verify participant role IDs are valid
4. **Duplicate Prevention**: Check for existing registrations

#### Mailing Subscriptions Not Working
**Symptoms**: Mailing products purchased but contacts not added to groups

**Diagnostic Steps**:
1. **Group Configuration**: Verify mailing groups exist and are active
2. **Group Type**: Ensure groups are configured as mailing lists
3. **Subscription Settings**: Check mailing preferences configuration
4. **Contact Existence**: Verify contacts exist before group addition

### Performance Issues

#### Slow Order Processing
**Symptoms**: Orders take long time to complete

**Diagnostic Steps**:
1. **CiviCRM Performance**: Check CiviCRM database performance
2. **API Calls**: Review number of CiviCRM API calls per order
3. **Network Latency**: Test CiviCRM connectivity speed
4. **Resource Usage**: Monitor server resources during processing

**Solutions**:
1. **Optimize API Calls**: Batch operations where possible
2. **Cache Results**: Cache frequently accessed CiviCRM data
3. **Queue Processing**: Consider moving processing to background queues
4. **Database Optimization**: Optimize CiviCRM database queries

### Logging and Debugging

#### Enable Debug Logging
```php
// In settings.php
$config['system.logging']['error_level'] = 'verbose';
```

#### View Commerce CiviCRM Logs
1. Go to `/admin/reports/dblog`
2. Filter by "commerce_civicrm" channel
3. Review error, warning, and info messages

#### Common Log Messages

**Successful Operations**:
```
INFO: Created CiviCRM contact 123 for order 456
INFO: Created contribution 789 for order 456
INFO: Registered participant 101 for event 5
```

**Warnings**:
```
WARNING: Billing profile incomplete for order 456
WARNING: Event 5 not found or inactive
WARNING: Contact already exists in mailing group 3
```

**Errors**:
```
ERROR: CiviCRM not available
ERROR: Failed to create contribution: Invalid financial type 999
ERROR: API error: Contact creation failed
```

### Diagnostic Tools

#### Test Contact Creation
```php
$order = \Drupal\commerce_order\Entity\Order::load($order_id);
$contact_updater = \Drupal::service('commerce_civicrm.contact_updater');
$contact_id = $contact_updater->updateContactFromOrder($order);
```

#### Test Full Order Processing
```php
$order = \Drupal\commerce_order\Entity\Order::load($order_id);
$order_updater = \Drupal::service('commerce_civicrm.order_civicrm_updater');
$results = $order_updater->processCompletedOrder($order);
```

#### Check CiviCRM Connectivity
```php
$helper = \Drupal::service('commerce_civicrm.civicrm_helper');
$available = $helper->isCivicrmAvailable();
```

### Getting Help

#### Before Seeking Support
1. **Check Logs**: Review all relevant log messages
2. **Test Configuration**: Verify all configuration settings
3. **Isolate Issue**: Test with minimal configuration
4. **Document Steps**: Record exact steps to reproduce the issue

#### Information to Provide
- Drupal and CiviCRM versions
- Module version
- Error messages from logs
- Steps to reproduce the issue
- Configuration details (sanitized)

#### Support Resources
- Module documentation
- Drupal.org issue queue
- CiviCRM community forums
- Local Drupal user groups

### Prevention Tips

#### Regular Maintenance
1. **Monitor Logs**: Regularly check for warning and error messages
2. **Test Configurations**: Verify product configurations after CiviCRM updates
3. **Backup Data**: Maintain backups of both Drupal and CiviCRM databases
4. **Update Modules**: Keep modules updated to latest stable versions

#### Best Practices
1. **Development Testing**: Always test in development environment first
2. **Documentation**: Document your CiviCRM entity IDs and configurations
3. **Monitoring**: Set up automated monitoring for critical operations
4. **Training**: Ensure staff understand the integration workflow
