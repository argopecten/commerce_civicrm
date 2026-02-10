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
