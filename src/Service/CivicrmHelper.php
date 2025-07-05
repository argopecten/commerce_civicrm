<?php

namespace Drupal\commerce_civicrm\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\civicrm_tools\CivicrmToolsInterface;

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
   * The CiviCRM tools service.
   *
   * @var \Drupal\civicrm_tools\CivicrmToolsInterface
   */
  protected $civicrmTools;

  /**
   * Constructs a CivicrmHelper object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\civicrm_tools\CivicrmToolsInterface $civicrm_tools
   *   The CiviCRM tools service.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory, CivicrmToolsInterface $civicrm_tools) {
    $this->logger = $logger_factory->get('commerce_civicrm');
    $this->civicrmTools = $civicrm_tools;
  }

  /**
   * Checks if CiviCRM is available and properly configured.
   *
   * @return bool
   *   TRUE if CiviCRM is available, FALSE otherwise.
   */
  public function isAvailable() {
    try {
      $api = $this->civicrmTools->getApi();
      // Test with a simple API call to verify CiviCRM is working
      $result = $api->Contact->get(FALSE)
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
      $api = $this->civicrmTools->getApi();
      $result = $api->System->get(FALSE)->execute();
      return $result->getArrayCopy();
    } catch (\Exception $e) {
      $this->logger->error('Failed to get CiviCRM system info: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

}
