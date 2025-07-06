<?php

namespace Drupal\commerce_civicrm\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for checking CiviCRM availability and providing helper methods.
 */
class CivicrmHelper {

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a CivicrmHelper object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory) {
    $this->logger = $logger_factory->get('commerce_civicrm');
  }

  /**
   * Checks if CiviCRM is available and properly configured.
   *
   * @return bool
   *   TRUE if CiviCRM is available, FALSE otherwise.
   */
  public function isAvailable() {
    try {
      // Initialize CiviCRM
      if (!\Drupal::hasService('civicrm')) {
        return FALSE;
      }
      
      $civicrm = \Drupal::service('civicrm');
      if (!$civicrm->initialize()) {
        return FALSE;
      }

      // Test with a simple API call to verify CiviCRM is working
      $result = \Civi\Api4\Contact::get(FALSE)
        ->setLimit(1)
        ->execute();
      
      return TRUE;
    } catch (\Exception $e) {
      $this->logger->error('CiviCRM availability check failed: @message', [
        '@message' => $e->getMessage(),
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
  public function getSystemInfo() {
    try {
      // Initialize CiviCRM
      if (!\Drupal::hasService('civicrm')) {
        return [];
      }
      
      $civicrm = \Drupal::service('civicrm');
      if (!$civicrm->initialize()) {
        return [];
      }

      $result = \Civi\Api4\System::get(FALSE)->execute();
      return $result->getArrayCopy();
    } catch (\Exception $e) {
      $this->logger->error('Failed to get CiviCRM system info: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

}
