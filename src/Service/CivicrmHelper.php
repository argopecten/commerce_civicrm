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
   * The CiviCRM initializer service.
   *
   * @var \Drupal\commerce_civicrm\Service\CivicrmInitializer
   */
  protected $civicrmInitializer;

  /**
   * Constructs a CivicrmHelper object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\commerce_civicrm\Service\CivicrmInitializer $civicrm_initializer
   *   The CiviCRM initializer service.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory, CivicrmInitializer $civicrm_initializer) {
    $this->logger = $logger_factory->get('commerce_civicrm');
    $this->civicrmInitializer = $civicrm_initializer;
  }

  /**
   * Checks if CiviCRM is available and properly configured.
   *
   * @return bool
   *   TRUE if CiviCRM is available, FALSE otherwise.
   */
  public function isAvailable() {
    return $this->civicrmInitializer->isAvailable();
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
      if (!$this->civicrmInitializer->initialize()) {
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
