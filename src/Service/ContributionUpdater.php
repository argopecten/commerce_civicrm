<?php

namespace Drupal\commerce_civicrm\Service;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_civicrm\Service\CivicrmHelper;

/**
 * Service for updating CiviCRM contributions based on Commerce Order data.
 */
class ContributionUpdater {

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Constructs a ContributionUpdater object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $logger_factory,
    protected readonly CivicrmHelper $civicrmHelper,
  ) {
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
  public function createContributionFromOrder(OrderInterface $order, $contact_id): ?int {
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
    } catch (\CRM_Core_Exception $e) {
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
  protected function extractContributionData(OrderInterface $order, $contact_id): array {
    $contribution_data = [];
    
    // Basic contribution data
    $contribution_data['contact_id'] = $contact_id;
    $contribution_data['total_amount'] = $order->getTotalPrice()->getNumber();
    $contribution_data['currency'] = $order->getTotalPrice()->getCurrencyCode();
    $timestamp = $order->getCompletedTime() ?: \Drupal::time()->getRequestTime();
    $contribution_data['receive_date'] = DrupalDateTime::createFromTimestamp($timestamp)
      ->format('Y-m-d H:i:s');
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
    $contribution_data['Commerce_Order.commerce_order_id'] = (int) $order->id();
    
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
  protected function findExistingContribution(OrderInterface $order): ?int {
    try {
      // Initialize CiviCRM
      if (!$this->civicrmHelper->initialize()) {
        return NULL;
      }

      $order_id = (int) $order->id();

      // Primary lookup: exact match on custom field.
      $result = \Civi\Api4\Contribution::get(FALSE)
        ->addSelect('id')
        ->addWhere('Commerce_Order.commerce_order_id', '=', $order_id)
        ->setLimit(1)
        ->execute();

      if ($result->count() > 0) {
        return $result->first()['id'];
      }

      // Fallback: exact source match for legacy contributions.
      $result = \Civi\Api4\Contribution::get(FALSE)
        ->addSelect('id')
        ->addWhere('source', '=', 'Commerce Order #' . $order_id)
        ->setLimit(1)
        ->execute();

      if ($result->count() > 0) {
        $contribution_id = $result->first()['id'];
        $this->logger->info('Found contribution @cid via source fallback for order @oid — backfilling custom field', [
          '@cid' => $contribution_id,
          '@oid' => $order_id,
        ]);

        try {
          \Civi\Api4\Contribution::update(FALSE)
            ->addWhere('id', '=', $contribution_id)
            ->addValue('Commerce_Order.commerce_order_id', $order_id)
            ->execute();
        } catch (\CRM_Core_Exception $e) {
          $this->logger->warning('Could not backfill custom field on contribution @cid: @error', [
            '@cid' => $contribution_id,
            '@error' => $e->getMessage(),
          ]);
        }

        return $contribution_id;
      }
    } catch (\CRM_Core_Exception $e) {
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
  protected function createContribution(array $contribution_data): ?int {
    try {
      // Initialize CiviCRM
      if (!$this->civicrmHelper->initialize()) {
        return NULL;
      }

      $this->ensureCustomFieldExists();

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
    } catch (\CRM_Core_Exception $e) {
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
  protected function getFinancialTypeId(): ?int {
    try {
      // Initialize CiviCRM
      if (!$this->civicrmHelper->initialize()) {
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
    } catch (\CRM_Core_Exception $e) {
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
  protected function getContributionStatusId(OrderInterface $order): ?int {
    try {
      // Initialize CiviCRM
      if (!$this->civicrmHelper->initialize()) {
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
    } catch (\CRM_Core_Exception $e) {
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
  protected function getPaymentInfo(OrderInterface $order): array {
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
    } catch (\Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException $e) {
      $this->logger->error('Error getting payment info: @error', [
        '@error' => $e->getMessage(),
      ]);
    } catch (\Drupal\Component\Plugin\Exception\PluginNotFoundException $e) {
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
  protected function getPaymentInstrumentId($gateway_plugin_id): ?int {
    try {
      // Initialize CiviCRM
      if (!$this->civicrmHelper->initialize()) {
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
    } catch (\CRM_Core_Exception $e) {
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
  public function createContributionFromOrderWithFinancialType(OrderInterface $order, $contact_id, $financial_type_id, $contribution_status = 'Completed'): ?int {
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
        'source' => 'Commerce Order #' . $order->id(),
        'contribution_status_id' => $this->getContributionStatusIdByName($contribution_status),
        'receive_date' => (new DrupalDateTime())->format('Y-m-d H:i:s'),
        'non_deductible_amount' => 0,
        'fee_amount' => 0,
        'net_amount' => $total_price->getNumber(),
        'Commerce_Order.commerce_order_id' => (int) $order->id(),
      ];

      // Create the contribution
      return $this->createContribution($contribution_data);
    } catch (\CRM_Core_Exception $e) {
      $this->logger->error('Error creating CiviCRM contribution for order @order_id: @error', [
        '@order_id' => $order->id(),
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Cancels a CiviCRM contribution from a cancelled order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   * @param int $contact_id
   *   The CiviCRM contact ID.
   *
   * @return int|null
   *   The cancelled contribution ID if successful, NULL otherwise.
   */
  public function cancelContributionFromOrder(OrderInterface $order, $contact_id): ?int {
    try {
      $this->logger->info('Cancelling CiviCRM contribution for order @order_id, contact @contact_id', [
        '@order_id' => $order->id(),
        '@contact_id' => $contact_id,
      ]);

      // Find existing contribution for this order
      $existing_contribution_id = $this->findExistingContribution($order);
      if (!$existing_contribution_id) {
        $this->logger->warning('No existing contribution found for order @order_id to cancel', [
          '@order_id' => $order->id(),
        ]);
        return NULL;
      }

      // Initialize CiviCRM
      if (!$this->civicrmHelper->initialize()) {
        return NULL;
      }

      // Get cancelled status ID
      $cancelled_status_id = $this->getContributionStatusIdByName('Cancelled');
      if (!$cancelled_status_id) {
        $this->logger->error('Could not find Cancelled status for contributions');
        return NULL;
      }

      // Update contribution status to cancelled
      $result = \Civi\Api4\Contribution::update(FALSE)
        ->addWhere('id', '=', $existing_contribution_id)
        ->addValue('contribution_status_id', $cancelled_status_id)
        ->addValue('source', 'Commerce Order #' . $order->id() . ' (Cancelled)')
        ->execute();

      if ($result->count() > 0) {
        $this->logger->info('Cancelled CiviCRM contribution @contribution_id for order @order_id', [
          '@contribution_id' => $existing_contribution_id,
          '@order_id' => $order->id(),
        ]);
        return $existing_contribution_id;
      }

      $this->logger->error('Failed to cancel contribution @contribution_id for order @order_id', [
        '@contribution_id' => $existing_contribution_id,
        '@order_id' => $order->id(),
      ]);
      return NULL;

    } catch (\CRM_Core_Exception $e) {
      $this->logger->error('Error cancelling CiviCRM contribution for order @order_id: @error', [
        '@order_id' => $order->id(),
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Gets contribution status ID by name.
   *
   * @param string $status_name
   *   The status name (e.g., 'Cancelled', 'Completed', 'Pending').
   *
   * @return int|null
   *   The status ID if found, NULL otherwise.
   */
  protected function getContributionStatusIdByName($status_name): ?int {
    try {
      // Initialize CiviCRM
      if (!$this->civicrmHelper->initialize()) {
        return NULL;
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
    } catch (\CRM_Core_Exception $e) {
      $this->logger->error('Error getting contribution status ID for @status: @error', [
        '@status' => $status_name,
        '@error' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Ensures the Commerce_Order custom group and field exist in CiviCRM.
   *
   * @return bool
   *   TRUE if the custom field exists or was created, FALSE on failure.
   */
  protected function ensureCustomFieldExists(): bool {
    try {
      $existing = \Civi\Api4\CustomGroup::get(FALSE)
        ->addWhere('name', '=', 'Commerce_Order')
        ->setLimit(1)
        ->execute();

      if ($existing->count() > 0) {
        return TRUE;
      }

      $this->logger->info('Commerce_Order custom group not found — provisioning now.');

      \Civi\Api4\CustomGroup::create(FALSE)
        ->addValue('name', 'Commerce_Order')
        ->addValue('title', 'Commerce Order')
        ->addValue('extends', 'Contribution')
        ->addValue('style', 'Inline')
        ->addValue('is_active', TRUE)
        ->addValue('collapse_display', TRUE)
        ->execute();

      \Civi\Api4\CustomField::create(FALSE)
        ->addValue('custom_group_id:name', 'Commerce_Order')
        ->addValue('name', 'commerce_order_id')
        ->addValue('label', 'Commerce Order ID')
        ->addValue('data_type', 'Int')
        ->addValue('html_type', 'Text')
        ->addValue('is_searchable', TRUE)
        ->addValue('is_active', TRUE)
        ->addValue('is_required', FALSE)
        ->addValue('is_view', TRUE)
        ->execute();

      $this->logger->info('Commerce_Order custom group and field provisioned successfully.');
      return TRUE;
    } catch (\CRM_Core_Exception $e) {
      $this->logger->error('Failed to ensure Commerce_Order custom field exists: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

}
