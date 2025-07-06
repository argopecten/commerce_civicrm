<?php

namespace Drupal\commerce_civicrm\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;

/**
 * Service for updating CiviCRM contributions based on Commerce Order data.
 */
class ContributionUpdater {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a ContributionUpdater object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('commerce_civicrm');
  }

  /**
   * Creates a CiviCRM contribution based on Commerce Order data.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   * @param int $contact_id
   *   The CiviCRM contact ID.
   *
   * @return int|null
   *   The CiviCRM contribution ID if successful, NULL otherwise.
   */
  public function createContributionFromOrder(OrderInterface $order, $contact_id) {
    try {
      // Check if contribution already exists for this order
      $existing_contribution_id = $this->findExistingContribution($order);
      if ($existing_contribution_id) {
        $this->logger->info('Contribution already exists for order @order_id: @contribution_id', [
          '@order_id' => $order->id(),
          '@contribution_id' => $existing_contribution_id,
        ]);
        return $existing_contribution_id;
      }

      // Get contribution data from order
      $contribution_data = $this->extractContributionData($order, $contact_id);
      
      if (empty($contribution_data)) {
        $this->logger->warning('No contribution data found for order @order_id', [
          '@order_id' => $order->id(),
        ]);
        return NULL;
      }

      // Create the contribution
      return $this->createContribution($contribution_data);
    } catch (\Exception $e) {
      $this->logger->error('Error creating CiviCRM contribution for order @order_id: @error', [
        '@order_id' => $order->id(),
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Extracts contribution data from the order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   * @param int $contact_id
   *   The CiviCRM contact ID.
   *
   * @return array
   *   Array of contribution data.
   */
  protected function extractContributionData(OrderInterface $order, $contact_id) {
    $contribution_data = [];
    
    // Basic contribution data
    $contribution_data['contact_id'] = $contact_id;
    $contribution_data['total_amount'] = $order->getTotalPrice()->getNumber();
    $contribution_data['currency'] = $order->getTotalPrice()->getCurrencyCode();
    $contribution_data['receive_date'] = date('Y-m-d H:i:s', $order->getCompletedTime() ?: time());
    $contribution_data['source'] = 'Commerce Order #' . $order->id();
    
    // Get financial type ID (you may need to adjust this based on your CiviCRM setup)
    $financial_type_id = $this->getFinancialTypeId();
    if ($financial_type_id) {
      $contribution_data['financial_type_id'] = $financial_type_id;
    }
    
    // Set contribution status based on order state
    $contribution_status_id = $this->getContributionStatusId($order);
    if ($contribution_status_id) {
      $contribution_data['contribution_status_id'] = $contribution_status_id;
    }
    
    // Add payment information if available
    $payment_info = $this->getPaymentInfo($order);
    if ($payment_info) {
      $contribution_data = array_merge($contribution_data, $payment_info);
    }
    
    // Add custom fields for order reference
    $contribution_data['custom_commerce_order_id'] = $order->id();
    
    return $contribution_data;
  }

  /**
   * Finds an existing CiviCRM contribution for the given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   *
   * @return int|null
   *   The existing contribution ID if found, NULL otherwise.
   */
  protected function findExistingContribution(OrderInterface $order) {
    try {
      // Initialize CiviCRM first
      if (!\Drupal::hasService('civicrm')) {
        return NULL;
      }
      
      $civicrm = \Drupal::service('civicrm');
      if (!$civicrm->initialize()) {
        return NULL;
      }
      
      // Search by source field containing the order ID
      $result = \Civi\Api4\Contribution::get(FALSE)
        ->addSelect('id')
        ->addWhere('source', 'LIKE', '%Order #' . $order->id() . '%')
        ->setLimit(1)
        ->execute();
      
      if ($result->count() > 0) {
        return $result->first()['id'];
      }
    } catch (\Exception $e) {
      $this->logger->error('Error finding existing CiviCRM contribution: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
    
    return NULL;
  }

  /**
   * Creates a new CiviCRM contribution.
   *
   * @param array $contribution_data
   *   The contribution data array.
   *
   * @return int|null
   *   The new contribution ID if successful, NULL otherwise.
   */
  protected function createContribution(array $contribution_data) {
    try {
      // Initialize CiviCRM first
      if (!\Drupal::hasService('civicrm')) {
        return NULL;
      }
      
      $civicrm = \Drupal::service('civicrm');
      if (!$civicrm->initialize()) {
        return NULL;
      }
      
      $result = \Civi\Api4\Contribution::create(FALSE)
        ->setValues($contribution_data)
        ->execute();
      
      if ($result->count() > 0) {
        $contribution_id = $result->first()['id'];
        $this->logger->info('Created new CiviCRM contribution @contribution_id', [
          '@contribution_id' => $contribution_id,
        ]);
        return $contribution_id;
      }
    } catch (\Exception $e) {
      $this->logger->error('Error creating CiviCRM contribution: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
    
    return NULL;
  }

  /**
   * Gets the financial type ID for contributions.
   *
   * @return int|null
   *   The financial type ID or NULL if not found.
   */
  protected function getFinancialTypeId() {
    try {
      // Initialize CiviCRM first
      if (!\Drupal::hasService('civicrm')) {
        return NULL;
      }
      
      $civicrm = \Drupal::service('civicrm');
      if (!$civicrm->initialize()) {
        return NULL;
      }
      
      // Try to get "Donation" financial type first, fallback to first available
      $result = \Civi\Api4\FinancialType::get(FALSE)
        ->addSelect('id')
        ->addWhere('name', '=', 'Donation')
        ->setLimit(1)
        ->execute();
      
      if ($result->count() > 0) {
        return $result->first()['id'];
      }
      
      // Fallback to first available financial type
      $result = \Civi\Api4\FinancialType::get(FALSE)
        ->addSelect('id')
        ->addWhere('is_active', '=', TRUE)
        ->setLimit(1)
        ->execute();
      
      if ($result->count() > 0) {
        return $result->first()['id'];
      }
    } catch (\Exception $e) {
      $this->logger->error('Error getting financial type ID: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
    
    return NULL;
  }

  /**
   * Gets the contribution status ID based on order state.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   *
   * @return int|null
   *   The contribution status ID or NULL if not found.
   */
  protected function getContributionStatusId(OrderInterface $order) {
    try {
      // Initialize CiviCRM first
      if (!\Drupal::hasService('civicrm')) {
        return NULL;
      }
      
      $civicrm = \Drupal::service('civicrm');
      if (!$civicrm->initialize()) {
        return NULL;
      }
      
      // Map order states to contribution statuses
      $order_state = $order->getState()->getId();
      $status_name = 'Pending';
      
      switch ($order_state) {
        case 'completed':
          $status_name = 'Completed';
          break;
        case 'canceled':
          $status_name = 'Cancelled';
          break;
        case 'draft':
        case 'validation':
          $status_name = 'Pending';
          break;
      }
      
      $result = \Civi\Api4\OptionValue::get(FALSE)
        ->addSelect('value')
        ->addWhere('option_group_id:name', '=', 'contribution_status')
        ->addWhere('name', '=', $status_name)
        ->setLimit(1)
        ->execute();
      
      if ($result->count() > 0) {
        return $result->first()['value'];
      }
    } catch (\Exception $e) {
      $this->logger->error('Error getting contribution status ID: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
    
    return NULL;
  }

  /**
   * Gets payment information from the order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   *
   * @return array
   *   Array of payment information.
   */
  protected function getPaymentInfo(OrderInterface $order) {
    $payment_info = [];
    
    try {
      // Get the most recent payment for this order
      $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
      $payments = $payment_storage->loadByProperties([
        'order_id' => $order->id(),
      ]);
      
      if (!empty($payments)) {
        /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
        $payment = reset($payments);
        
        // Add payment method information
        $payment_gateway = $payment->getPaymentGateway();
        if ($payment_gateway) {
          $payment_info['payment_instrument_id'] = $this->getPaymentInstrumentId($payment_gateway->getPluginId());
        }
        
        // Add transaction reference if available
        if ($payment->getRemoteId()) {
          $payment_info['trxn_id'] = $payment->getRemoteId();
        }
      }
    } catch (\Exception $e) {
      $this->logger->error('Error getting payment info: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
    
    return $payment_info;
  }

  /**
   * Gets the payment instrument ID based on payment gateway.
   *
   * @param string $gateway_plugin_id
   *   The payment gateway plugin ID.
   *
   * @return int|null
   *   The payment instrument ID or NULL if not found.
   */
  protected function getPaymentInstrumentId($gateway_plugin_id) {
    try {
      // Initialize CiviCRM first
      if (!\Drupal::hasService('civicrm')) {
        return NULL;
      }
      
      $civicrm = \Drupal::service('civicrm');
      if (!$civicrm->initialize()) {
        return NULL;
      }
      
      // Map common payment gateways to CiviCRM payment instruments
      $instrument_mapping = [
        'paypal' => 'PayPal',
        'stripe' => 'Credit Card',
        'square' => 'Credit Card',
        'authorize_net' => 'Credit Card',
        'manual' => 'Cash',
      ];
      
      $instrument_name = $instrument_mapping[$gateway_plugin_id] ?? 'Credit Card';
      
      $result = \Civi\Api4\OptionValue::get(FALSE)
        ->addSelect('value')
        ->addWhere('option_group_id:name', '=', 'payment_instrument')
        ->addWhere('name', '=', $instrument_name)
        ->setLimit(1)
        ->execute();
      
      if ($result->count() > 0) {
        return $result->first()['value'];
      }
    } catch (\Exception $e) {
      $this->logger->error('Error getting payment instrument ID: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
    
    return NULL;
  }

  /**
   * Creates a CiviCRM contribution from an order with a custom financial type.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The commerce order.
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param int $financial_type_id
   *   The CiviCRM financial type ID.
   * @param string $contribution_status
   *   The contribution status (defaults to 'Completed').
   *
   * @return int|null
   *   The CiviCRM contribution ID if successful, NULL otherwise.
   */
  public function createContributionFromOrderWithFinancialType(OrderInterface $order, $contact_id, $financial_type_id, $contribution_status = 'Completed') {
    try {
      // Check if contribution already exists for this order
      $existing_contribution_id = $this->findExistingContribution($order);
      if ($existing_contribution_id) {
        $this->logger->info('Contribution already exists for order @order_id: @contribution_id', [
          '@order_id' => $order->id(),
          '@contribution_id' => $existing_contribution_id,
        ]);
        return $existing_contribution_id;
      }

      // Get the order total
      $total_price = $order->getTotalPrice();
      if (!$total_price) {
        $this->logger->warning('No total price found for order @order_id', [
          '@order_id' => $order->id(),
        ]);
        return NULL;
      }

      // Prepare contribution data
      $contribution_data = [
        'contact_id' => $contact_id,
        'financial_type_id' => $financial_type_id,
        'total_amount' => $total_price->getNumber(),
        'currency' => $total_price->getCurrencyCode(),
        'source' => 'Drupal Commerce Order ' . $order->id(),
        'contribution_status_id' => $contribution_status,
        'receive_date' => date('YmdHis'),
        'non_deductible_amount' => 0,
        'fee_amount' => 0,
        'net_amount' => $total_price->getNumber(),
      ];

      // Create the contribution
      return $this->createContribution($contribution_data);
    } catch (\Exception $e) {
      $this->logger->error('Error creating CiviCRM contribution for order @order_id: @error', [
        '@order_id' => $order->id(),
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

}
