<?php

namespace Drupal\commerce_civicrm\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Service for initializing CiviCRM and providing helper methods.
 */
class CivicrmHelper {

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Constructs a CivicrmHelper object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly ?object $civicrm = NULL,
  ) {
    $this->logger = $logger_factory->get('commerce_civicrm');
  }

  /**
   * Initializes CiviCRM for API operations.
   *
   * @return bool
   *   TRUE if CiviCRM is successfully initialized, FALSE otherwise.
   */
  public function initialize(): bool {
    try {
      // Check if CiviCRM module is enabled
      if (!$this->moduleHandler->moduleExists('civicrm')) {
        $this->logger->error('CiviCRM module not enabled');
        return FALSE;
      }
      
      // Check if CiviCRM service is available
      if ($this->civicrm === NULL) {
        $this->logger->error('CiviCRM service not available');
        return FALSE;
      }
      
      // Initialize CiviCRM bootstrap
      $this->civicrm->initialize();

      // Check if CiviCRM is in maintenance mode (upgrades, etc.).
      if ($this->isInMaintenanceMode()) {
        $this->logger->warning('CiviCRM is in maintenance mode — deferring API operations');
        return FALSE;
      }

      // $this->logger->info('CiviCRM initialized successfully');      
      return TRUE;
      
    } catch (\Exception $e) {
      // Catching \Exception broadly because CiviCRM initialization can fail
      // with various exception types depending on the installation state.
      $this->logger->error('Exception while initializing CiviCRM: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Checks if CiviCRM is available and properly configured.
   *
   * @return bool
   *   TRUE if CiviCRM is available, FALSE otherwise.
   */
  public function isAvailable(): bool {
    try {
      // Initialize CiviCRM
      if (!$this->initialize()) {
        return FALSE;
      }

      // Test with a simple API call to verify CiviCRM is working
      $result = \Civi\Api4\Contact::get(FALSE)
        ->setLimit(1)
        ->execute();
      
      return TRUE;
    } catch (\CRM_Core_Exception $e) {
      $this->logger->error('CiviCRM availability check failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Checks if CiviCRM is available and NOT in maintenance mode.
   *
   * This is a stronger check than isAvailable() — it also rejects
   * maintenance mode. Use this before performing write operations.
   *
   * @return bool
   *   TRUE if CiviCRM is available for write operations, FALSE otherwise.
   */
  public function isReadyForOperations(): bool {
    if (!$this->initialize()) {
      return FALSE;
    }
    return !$this->isInMaintenanceMode();
  }

  /**
   * Checks if CiviCRM is currently in maintenance mode.
   *
   * CiviCRM enters maintenance mode during database upgrades and
   * when the administrator explicitly enables it. During maintenance mode,
   * API calls may fail or produce inconsistent results.
   *
   * @return bool
   *   TRUE if CiviCRM is in maintenance mode, FALSE otherwise.
   */
  protected function isInMaintenanceMode(): bool {
    try {
      // Check the CiviCRM upgrade status.
      // CRM_Utils_System::isCiviUpgradeActive() returns TRUE during upgrades.
      if (defined('CIVICRM_UPGRADE_ACTIVE') && CIVICRM_UPGRADE_ACTIVE) {
        return TRUE;
      }

      // Check CiviCRM's environment setting.
      // In CiviCRM 6.1+, the 'environment' setting can be 'Maintenance'.
      $environment = \Civi::settings()->get('environment');
      if ($environment === 'Maintenance') {
        return TRUE;
      }

      return FALSE;
    } catch (\Exception $e) {
      // If we can't determine maintenance mode, assume it's not active.
      // This handles cases where CiviCRM is partially initialized.
      $this->logger->debug('Unable to check CiviCRM maintenance mode: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Static per-request cache for name-to-ID lookups.
   *
   * @var array
   */
  protected array $lookupCache = [];

  /**
   * Ensures the Commerce_Order custom group and its fields exist in CiviCRM.
   *
   * The group extends Contribution and carries two fields used for
   * idempotency: commerce_order_id (set on every contribution created from an
   * order) and commerce_payment_id (set when the contribution reflects a
   * specific Commerce payment, e.g. a renewal charge).
   *
   * @return bool
   *   TRUE if the custom fields exist or were created, FALSE on failure.
   */
  public function ensureCommerceOrderCustomFields(): bool {
    try {
      if (!$this->initialize()) {
        return FALSE;
      }

      $existing = \Civi\Api4\CustomGroup::get(FALSE)
        ->addWhere('name', '=', 'Commerce_Order')
        ->setLimit(1)
        ->execute();

      if ($existing->count() === 0) {
        $this->logger->info('Commerce_Order custom group not found — provisioning now.');
        \Civi\Api4\CustomGroup::create(FALSE)
          ->addValue('name', 'Commerce_Order')
          ->addValue('title', 'Commerce Order')
          ->addValue('extends', 'Contribution')
          ->addValue('style', 'Inline')
          ->addValue('is_active', TRUE)
          ->addValue('collapse_display', TRUE)
          ->execute();
      }

      $fields = \Civi\Api4\CustomField::get(FALSE)
        ->addSelect('name')
        ->addWhere('custom_group_id:name', '=', 'Commerce_Order')
        ->execute()
        ->column('name');

      foreach (['commerce_order_id' => 'Commerce Order ID', 'commerce_payment_id' => 'Commerce Payment ID'] as $name => $label) {
        if (in_array($name, $fields, TRUE)) {
          continue;
        }
        \Civi\Api4\CustomField::create(FALSE)
          ->addValue('custom_group_id:name', 'Commerce_Order')
          ->addValue('name', $name)
          ->addValue('label', $label)
          ->addValue('data_type', 'Int')
          ->addValue('html_type', 'Text')
          ->addValue('is_searchable', TRUE)
          ->addValue('is_active', TRUE)
          ->addValue('is_required', FALSE)
          ->addValue('is_view', TRUE)
          ->execute();
        $this->logger->info('Provisioned Commerce_Order.@field custom field.', ['@field' => $name]);
      }

      return TRUE;
    }
    catch (\CRM_Core_Exception $e) {
      $this->logger->error('Failed to ensure Commerce_Order custom fields exist: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Resolves a membership type reference (name or ID) to its ID.
   *
   * @param string|int|null $reference
   *   A membership type name or numeric ID.
   *
   * @return int|null
   *   The membership type ID, or NULL if not resolvable.
   */
  public function resolveMembershipTypeId(string|int|null $reference): ?int {
    return $this->resolveEntityId('MembershipType', $reference);
  }

  /**
   * Resolves a financial type reference (name or ID) to its ID.
   *
   * @param string|int|null $reference
   *   A financial type name or numeric ID.
   *
   * @return int|null
   *   The financial type ID, or NULL if not resolvable.
   */
  public function resolveFinancialTypeId(string|int|null $reference): ?int {
    return $this->resolveEntityId('FinancialType', $reference);
  }

  /**
   * Resolves a group reference (name, title or ID) to its ID.
   *
   * @param string|int|null $reference
   *   A group name, title or numeric ID.
   *
   * @return int|null
   *   The group ID, or NULL if not resolvable.
   */
  public function resolveGroupId(string|int|null $reference): ?int {
    return $this->resolveEntityId('Group', $reference);
  }

  /**
   * Resolves a CiviCRM entity reference (name or ID) to its ID.
   *
   * @param string $entity
   *   The API4 entity name (MembershipType, FinancialType, Group, ...).
   * @param string|int|null $reference
   *   The entity name (or title for groups) or numeric ID.
   *
   * @return int|null
   *   The entity ID, or NULL if not resolvable.
   */
  public function resolveEntityId(string $entity, string|int|null $reference): ?int {
    if ($reference === NULL || $reference === '') {
      return NULL;
    }
    if (is_numeric($reference)) {
      return (int) $reference;
    }

    $cache_key = $entity . ':' . $reference;
    if (array_key_exists($cache_key, $this->lookupCache)) {
      return $this->lookupCache[$cache_key];
    }

    $id = NULL;
    try {
      if (!$this->initialize()) {
        return NULL;
      }
      $class = '\\Civi\\Api4\\' . $entity;
      $result = $class::get(FALSE)
        ->addSelect('id')
        ->addWhere('name', '=', $reference)
        ->setLimit(1)
        ->execute();
      if ($result->count() > 0) {
        $id = (int) $result->first()['id'];
      }
      elseif ($entity === 'Group') {
        // Groups are commonly referenced by their human-readable title.
        $result = $class::get(FALSE)
          ->addSelect('id')
          ->addWhere('title', '=', $reference)
          ->setLimit(1)
          ->execute();
        if ($result->count() > 0) {
          $id = (int) $result->first()['id'];
        }
      }
      if ($id === NULL) {
        $this->logger->warning('Could not resolve CiviCRM @entity reference "@ref" to an ID.', [
          '@entity' => $entity,
          '@ref' => $reference,
        ]);
      }
    }
    catch (\CRM_Core_Exception $e) {
      $this->logger->error('Error resolving @entity "@ref": @error', [
        '@entity' => $entity,
        '@ref' => $reference,
        '@error' => $e->getMessage(),
      ]);
    }

    return $this->lookupCache[$cache_key] = $id;
  }

  /**
   * Gets an option value by option group and name (per-request cached).
   *
   * @param string $option_group
   *   The option group name (e.g. 'contribution_status',
   *   'payment_instrument').
   * @param string $name
   *   The option name.
   *
   * @return int|null
   *   The option value, or NULL if not found.
   */
  public function getOptionValue(string $option_group, string $name): ?int {
    $cache_key = 'option:' . $option_group . ':' . $name;
    if (array_key_exists($cache_key, $this->lookupCache)) {
      return $this->lookupCache[$cache_key];
    }

    $value = NULL;
    try {
      if (!$this->initialize()) {
        return NULL;
      }
      $result = \Civi\Api4\OptionValue::get(FALSE)
        ->addSelect('value')
        ->addWhere('option_group_id:name', '=', $option_group)
        ->addWhere('name', '=', $name)
        ->setLimit(1)
        ->execute();
      if ($result->count() > 0) {
        $value = (int) $result->first()['value'];
      }
    }
    catch (\CRM_Core_Exception $e) {
      $this->logger->error('Error looking up option @group/@name: @error', [
        '@group' => $option_group,
        '@name' => $name,
        '@error' => $e->getMessage(),
      ]);
    }

    return $this->lookupCache[$cache_key] = $value;
  }

  /**
   * Gets CiviCRM system information.
   *
   * @return array
   *   Array of system information or empty array if not available.
   */
  public function getSystemInfo(): array {
    try {
      // Initialize CiviCRM
      if (!$this->initialize()) {
        return [];
      }

      $result = \Civi\Api4\System::get(FALSE)->execute();
      return $result->getArrayCopy();
    } catch (\CRM_Core_Exception $e) {
      $this->logger->error('Failed to get CiviCRM system info: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

}
