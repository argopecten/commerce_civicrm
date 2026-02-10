<?php

namespace Drupal\commerce_civicrm\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\commerce_civicrm\Service\CivicrmHelper;
use Drupal\commerce_civicrm\Service\ContactUpdater;
use Drupal\commerce_civicrm\Service\ContributionUpdater;
use Drupal\commerce_civicrm\Service\MembershipUpdater;
use Drupal\commerce_civicrm\Service\MailingUpdater;
use Psr\Log\LoggerInterface;

/**
 * Service for processing Commerce orders and creating CiviCRM records.
 * 
 * This service provides Commerce-style direct integration by:
 * 1. Processing order items and their product configurations
 * 2. Creating appropriate CiviCRM records based on product settings
 * 3. Supporting multiple CiviCRM record types per order
 */
class OrderCivicrmUpdater {

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs an OrderCivicrmUpdater object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    protected readonly CivicrmHelper $civicrmHelper,
    protected readonly ContactUpdater $contactUpdater,
    protected readonly ContributionUpdater $contributionUpdater,
    protected readonly MembershipUpdater $membershipUpdater,
    protected readonly MailingUpdater $mailingUpdater,
  ) {
    $this->logger = $logger_factory->get('commerce_civicrm');
  }

  /**
   * Process an order and create appropriate CiviCRM records.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The commerce order to process.
   *
   * @return array
   *   Array of created CiviCRM record IDs keyed by type.
   */
  public function processOrder(OrderInterface $order): array {
    $this->logger->info('Processing order @order_id for CiviCRM integration', [
      '@order_id' => $order->id(),
    ]);

    // Check if CiviCRM is available
    if (!$this->civicrmHelper->isAvailable()) {
      $this->logger->error('CiviCRM is not available - skipping order @order_id processing', [
        '@order_id' => $order->id(),
      ]);
      return [];
    }

    // Get customer
    $customer = $order->getCustomer();
    if (!$customer) {
      $this->logger->warning('Order @order_id has no customer', [
        '@order_id' => $order->id(),
      ]);
      return [];
    }

    // Get or create CiviCRM contact
    $contact_id = $this->contactUpdater->getContactIdByUser($customer);
    if (!$contact_id) {
      $this->logger->warning('Could not find or create CiviCRM contact for user @uid', [
        '@uid' => $customer->id(),
      ]);
      return [];
    }

    $created_records = [];

    // Process each order item
    $order_items = $order->getItems();
    $this->logger->info('Found @count order items to process for order @order_id', [
      '@count' => count($order_items),
      '@order_id' => $order->id(),
    ]);

    foreach ($order_items as $index => $order_item) {
      $this->logger->debug('Processing order item @index of @total for order @order_id', [
        '@index' => $index + 1,
        '@total' => count($order_items),
        '@order_id' => $order->id(),
      ]);

      try {
        $item_records = $this->processOrderItem($order_item, $contact_id, $order);
        
        if (empty($item_records)) {
          $this->logger->warning('Order item @index returned no records for order @order_id', [
            '@index' => $index + 1,
            '@order_id' => $order->id(),
          ]);
        }
        
        $created_records = array_merge_recursive($created_records, $item_records);

        $this->logger->debug('Completed processing order item @index, records created: @records', [
          '@index' => $index + 1,
          '@records' => json_encode($item_records),
        ]);
      } catch (\CRM_Core_Exception $e) {
        $this->logger->error('Error processing order item @index for order @order_id: @error', [
          '@index' => $index + 1,
          '@order_id' => $order->id(),
          '@error' => $e->getMessage(),
        ]);
      }
    }

    $this->logger->info('Completed processing order @order_id. Created records: @records', [
      '@order_id' => $order->id(),
      '@records' => json_encode($created_records),
    ]);

    return $created_records;
  }

  /**
   * Process an order item and create appropriate CiviCRM records.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   The order item to process.
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The parent order.
   *
   * @return array
   *   Array of created CiviCRM record IDs.
   */
  protected function processOrderItem(OrderItemInterface $order_item, $contact_id, OrderInterface $order): array {
    $created_records = [];

    /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $variation */
    $variation = $order_item->getPurchasedEntity();
    if (!$variation) {
      $this->logger->warning('Order item has no purchased entity (variation) - skipping');
      return $created_records;
    }

    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = $variation->getProduct();
    if (!$product) {
      $this->logger->warning('Order item variation has no product - skipping');
      return $created_records;
    }

    // Get the proper order item entity ID
    $order_item_id = $order_item->id();

    $this->logger->info('Processing order item @item_id (product @product_id) for order @order_id', [
      '@item_id' => $order_item_id,
      '@product_id' => $product->id(),
      '@order_id' => $order->id(),
    ]);

    // Get CiviCRM settings from the existing field_civicrm JSON configuration
    $civicrm_settings = $this->getCivicrmProductSettings($product);
    
    $this->logger->debug('Product @product_id CiviCRM settings: @settings', [
      '@product_id' => $product->id(),
      '@settings' => json_encode($civicrm_settings),
    ]);

    // Check if CiviCRM integration is enabled for this product
    if (empty($civicrm_settings['enabled'])) {
      $this->logger->info('CiviCRM integration not enabled for product @product_id - skipping', [
        '@product_id' => $product->id(),
      ]);
      return $created_records;
    }

    $has_membership = !empty($civicrm_settings['membership_type_id']);
    $has_contribution = !empty($civicrm_settings['financial_type_id']);

    if ($has_membership && $has_contribution) {
      $membership_type_id = $civicrm_settings['membership_type_id'];
      $financial_type_id = $civicrm_settings['financial_type_id'];

      $this->logger->info('Creating linked membership+contribution for order item @item_id', [
        '@item_id' => $order_item_id,
      ]);

      $created_records = $this->createLinkedMembershipContribution(
        $contact_id,
        $order,
        $order_item,
        $membership_type_id,
        $financial_type_id
      );
    }
    elseif ($has_membership) {
      $membership_type_id = $civicrm_settings['membership_type_id'];
      $this->logger->debug('Product @product_id membership type ID: @type_id', [
        '@product_id' => $product->id(),
        '@type_id' => $membership_type_id,
      ]);

      $this->logger->info('Creating membership for type @type_id', [
        '@type_id' => $membership_type_id,
      ]);

      // Use the correct method signature from MembershipUpdater
      $membership_id = $this->membershipUpdater->createMembershipFromOrder($contact_id, $membership_type_id, $order, $order_item);
      if ($membership_id) {
        $created_records['memberships'][] = $membership_id;
        $this->logger->info('Created membership @membership_id for order item @item_id (order @order_id)', [
          '@membership_id' => $membership_id,
          '@item_id' => $order_item_id,
          '@order_id' => $order->id(),
        ]);
      } else {
        $this->logger->warning('Failed to create membership for type @type_id', [
          '@type_id' => $membership_type_id,
        ]);
      }
    }

    if (!$has_membership && $has_contribution) {
      $financial_type_id = $civicrm_settings['financial_type_id'];
      $this->logger->debug('Product @product_id financial type ID: @type_id', [
        '@product_id' => $product->id(),
        '@type_id' => $financial_type_id,
      ]);

      $this->logger->info('Creating contribution for financial type @type_id', [
        '@type_id' => $financial_type_id,
      ]);

      // Use the correct method signature from ContributionUpdater
      $contribution_id = $this->contributionUpdater->createContributionFromOrderWithFinancialType($order, $contact_id, $financial_type_id);
      if ($contribution_id) {
        $created_records['contributions'][] = $contribution_id;
        $this->logger->info('Created contribution @contribution_id for order item @item_id (order @order_id)', [
          '@contribution_id' => $contribution_id,
          '@item_id' => $order_item_id,
          '@order_id' => $order->id(),
        ]);
      } else {
        $this->logger->warning('Failed to create contribution for financial type @type_id', [
          '@type_id' => $financial_type_id,
        ]);
      }
    }

    if (!empty($civicrm_settings['entity']) && $civicrm_settings['entity'] === 'mailing' && !empty($civicrm_settings['entity_id'])) {
      $mailing_group_id = $civicrm_settings['entity_id'];
      $preferences = $civicrm_settings['mailing_preferences'] ?? [];

      $this->logger->info('Creating mailing subscription for group @group_id', [
        '@group_id' => $mailing_group_id,
      ]);

      $success = $this->mailingUpdater->processMailingSubscriptionFromOrder(
        $contact_id,
        $mailing_group_id,
        $order,
        $preferences
      );

      if ($success) {
        $created_records['mailings'][] = $mailing_group_id;
      } else {
        $this->logger->warning('Failed to create mailing subscription for group @group_id', [
          '@group_id' => $mailing_group_id,
        ]);
      }
    }

    if (empty($created_records)) {
      $this->logger->info('No CiviCRM records created for order item @item_id - product @product_id has no CiviCRM configuration', [
        '@item_id' => $order_item_id,
        '@product_id' => $product->id(),
      ]);
    }

    return $created_records;
  }

  /**
   * Creates a linked contribution + membership using the CiviCRM Order API.
   *
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The parent order.
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   The order item being processed.
   * @param int $membership_type_id
   *   The CiviCRM membership type ID.
   * @param int $financial_type_id
   *   The CiviCRM financial type ID.
   *
   * @return array
   *   Array of created CiviCRM record IDs.
   */
  protected function createLinkedMembershipContribution($contact_id, OrderInterface $order, OrderItemInterface $order_item, $membership_type_id, $financial_type_id): array {
    $created_records = [];

    if (!$this->civicrmHelper->initialize()) {
      $this->logger->error('Failed to initialize CiviCRM for linked membership/contribution creation');
      return $created_records;
    }

    $this->ensureCommerceOrderCustomFieldExists();

    $existing_contribution_id = $this->findExistingContributionForOrder($order);
    if ($existing_contribution_id) {
      $this->logger->info('Contribution already exists for order @order_id: @contribution_id', [
        '@order_id' => $order->id(),
        '@contribution_id' => $existing_contribution_id,
      ]);
      $created_records['contributions'][] = $existing_contribution_id;

      $existing_membership_id = $this->findMembershipByOrderSource($contact_id, $membership_type_id, $order);
      if ($existing_membership_id) {
        $created_records['memberships'][] = $existing_membership_id;
      }

      return $created_records;
    }

    $total_price = $order_item->getTotalPrice();
    if (!$total_price) {
      $this->logger->warning('No total price found for order item @item_id', [
        '@item_id' => $order_item->id(),
      ]);
      return $created_records;
    }

    $existing_membership = $this->findExistingMembershipForRenewal($contact_id, $membership_type_id);

    try {
      $result = \Civi\Api4\Order::create(FALSE)
        ->setContributionValues([
          'contact_id' => $contact_id,
          'financial_type_id' => $financial_type_id,
          'total_amount' => $total_price->getNumber(),
          'currency' => $total_price->getCurrencyCode(),
          'receive_date' => DrupalDateTime::createFromTimestamp(
            $order->getCompletedTime() ?: \Drupal::time()->getRequestTime()
          )->format('Y-m-d H:i:s'),
          'source' => 'Commerce Order #' . $order->id(),
          'contribution_status_id:name' => 'Completed',
          'Commerce_Order.commerce_order_id' => (int) $order->id(),
        ])
        ->addLineItem([
          'line_item' => [
            'entity_table' => 'civicrm_membership',
            'financial_type_id' => $financial_type_id,
            'label' => $order_item->getTitle(),
            'qty' => (int) $order_item->getQuantity(),
            'unit_price' => $order_item->getUnitPrice()->getNumber(),
            'line_total' => $total_price->getNumber(),
          ],
          'params' => array_filter([
            'membership_type_id' => $membership_type_id,
            'membership_id' => $existing_membership['id'] ?? NULL,
            'contact_id' => $contact_id,
            'source' => 'Commerce Order #' . $order->id(),
          ]),
        ])
        ->execute();

      if ($result->count() === 0) {
        $this->logger->warning('Linked Order::create returned no results for order @order_id', [
          '@order_id' => $order->id(),
        ]);
        return $created_records;
      }

      $created = $result->first();
      $contribution_id = NULL;
      $membership_id = NULL;

      if (is_array($created)) {
        $contribution_id = $created['contribution_id'] ?? $created['id'] ?? NULL;

        if (!empty($created['membership_id'])) {
          $membership_id = $created['membership_id'];
        }

        if (!$membership_id && !empty($created['line_items']) && is_array($created['line_items'])) {
          foreach ($created['line_items'] as $line_item) {
            if (!is_array($line_item)) {
              continue;
            }
            if (($line_item['entity_table'] ?? NULL) === 'civicrm_membership') {
              $membership_id = $line_item['entity_id'] ?? $line_item['membership_id'] ?? NULL;
              if ($membership_id) {
                break;
              }
            }
          }
        }

        if (!$membership_id && !empty($created['line_item']) && is_array($created['line_item'])) {
          $line_item = $created['line_item'];
          if (($line_item['entity_table'] ?? NULL) === 'civicrm_membership') {
            $membership_id = $line_item['entity_id'] ?? $line_item['membership_id'] ?? NULL;
          }
        }
      }

      if (!$membership_id) {
        $membership_id = $this->findMembershipByOrderSource($contact_id, $membership_type_id, $order);
      }

      if ($contribution_id) {
        $created_records['contributions'][] = $contribution_id;
      }

      if ($membership_id) {
        $created_records['memberships'][] = $membership_id;
      }

      $this->logger->info('Created linked membership/contribution for order @order_id', [
        '@order_id' => $order->id(),
      ]);

      return $created_records;
    } catch (\CRM_Core_Exception $e) {
      $this->logger->error('Error creating linked membership/contribution: @error', [
        '@error' => $e->getMessage(),
      ]);
      return $created_records;
    }
  }

  /**
   * Finds an existing contribution for the given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The commerce order.
   *
   * @return int|null
   *   The contribution ID if found.
   */
  protected function findExistingContributionForOrder(OrderInterface $order): ?int {
    try {
      $order_id = (int) $order->id();

      $result = \Civi\Api4\Contribution::get(FALSE)
        ->addSelect('id')
        ->addWhere('Commerce_Order.commerce_order_id', '=', $order_id)
        ->setLimit(1)
        ->execute();

      if ($result->count() > 0) {
        return $result->first()['id'];
      }

      $result = \Civi\Api4\Contribution::get(FALSE)
        ->addSelect('id')
        ->addWhere('source', '=', 'Commerce Order #' . $order_id)
        ->setLimit(1)
        ->execute();

      if ($result->count() > 0) {
        $contribution_id = $result->first()['id'];
        try {
          \Civi\Api4\Contribution::update(FALSE)
            ->addWhere('id', '=', $contribution_id)
            ->addValue('Commerce_Order.commerce_order_id', $order_id)
            ->execute();
        } catch (\CRM_Core_Exception $e) {
          $this->logger->warning('Could not backfill Commerce_Order custom field on contribution @cid: @error', [
            '@cid' => $contribution_id,
            '@error' => $e->getMessage(),
          ]);
        }
        return $contribution_id;
      }
    } catch (\CRM_Core_Exception $e) {
      $this->logger->error('Error finding existing contribution: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Finds an existing active membership for renewal.
   *
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param int $membership_type_id
   *   The membership type ID.
   *
   * @return array|null
   *   The membership data if found.
   */
  protected function findExistingMembershipForRenewal($contact_id, $membership_type_id): ?array {
    try {
      $result = \Civi\Api4\Membership::get(FALSE)
        ->addSelect('id')
        ->addWhere('contact_id', '=', $contact_id)
        ->addWhere('membership_type_id', '=', $membership_type_id)
        ->addWhere('status_id:name', 'IN', ['New', 'Current', 'Grace'])
        ->setLimit(1)
        ->execute();

      return $result->count() > 0 ? $result->first() : NULL;
    } catch (\CRM_Core_Exception $e) {
      $this->logger->error('Error finding existing membership for renewal: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Finds a membership created from a given order source.
   *
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param int $membership_type_id
   *   The membership type ID.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The commerce order.
   *
   * @return int|null
   *   The membership ID if found.
   */
  protected function findMembershipByOrderSource($contact_id, $membership_type_id, OrderInterface $order): ?int {
    try {
      $result = \Civi\Api4\Membership::get(FALSE)
        ->addSelect('id')
        ->addWhere('contact_id', '=', $contact_id)
        ->addWhere('membership_type_id', '=', $membership_type_id)
        ->addWhere('source', '=', 'Commerce Order #' . $order->id())
        ->setLimit(1)
        ->execute();

      return $result->count() > 0 ? $result->first()['id'] : NULL;
    } catch (\CRM_Core_Exception $e) {
      $this->logger->error('Error finding membership by order source: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Ensures the Commerce_Order custom group and field exist in CiviCRM.
   *
   * @return bool
   *   TRUE if the custom field exists or was created, FALSE on failure.
   */
  protected function ensureCommerceOrderCustomFieldExists(): bool {
    try {
      $existing = \Civi\Api4\CustomGroup::get(FALSE)
        ->addWhere('name', '=', 'Commerce_Order')
        ->setLimit(1)
        ->execute();

      if ($existing->count() > 0) {
        return TRUE;
      }

      $this->logger->info('Commerce_Order custom group not found - provisioning now.');

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

  /**
   * Get CiviCRM settings from product's field_civicrm field.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product entity.
   *
   * @return array
   *   Array of CiviCRM settings with normalized keys.
   */
  protected function getCivicrmProductSettings(ProductInterface $product): array {
    $defaults = [
      'enabled' => FALSE,
      'entity' => 'contribution',
      'entity_id' => NULL,
      'membership_type_id' => NULL,
      'financial_type_id' => NULL,
    ];

    if (!$product->hasField('field_civicrm') || $product->get('field_civicrm')->isEmpty()) {
      return $defaults;
    }

    $civicrm_settings_raw = $product->get('field_civicrm')->value;
    $civicrm_settings = json_decode($civicrm_settings_raw, TRUE);

    if (!is_array($civicrm_settings)) {
      return $defaults;
    }

    // Merge with defaults
    $settings = array_merge($defaults, $civicrm_settings);
    
    // Normalize the settings based on entity type for backward compatibility
    if (!empty($settings['enabled']) && !empty($settings['entity']) && !empty($settings['entity_id'])) {
      switch ($settings['entity']) {
        case 'membership':
          $settings['membership_type_id'] = $settings['entity_id'];
          break;
          
        case 'contribution':
          $settings['financial_type_id'] = $settings['entity_id'];
          break;
      }
    }

    return $settings;
  }

  /**
   * Process order cancellation and update CiviCRM records.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The cancelled order.
   *
   * @return array
   *   Array of cancelled CiviCRM record IDs keyed by type.
   */
  public function processCancellation(OrderInterface $order): array {
    $this->logger->info('Processing cancellation for order @order_id', [
      '@order_id' => $order->id(),
    ]);

    // Check if CiviCRM is available
    if (!$this->civicrmHelper->isAvailable()) {
      $this->logger->error('CiviCRM is not available - skipping cancellation for order @order_id', [
        '@order_id' => $order->id(),
      ]);
      return [];
    }

    // Get customer
    $customer = $order->getCustomer();
    if (!$customer) {
      $this->logger->warning('Order @order_id has no customer - skipping cancellation', [
        '@order_id' => $order->id(),
      ]);
      return [];
    }

    // Get CiviCRM contact
    $contact_id = $this->contactUpdater->getContactIdByUser($customer);
    if (!$contact_id) {
      $this->logger->warning('Could not find CiviCRM contact for user @uid - skipping cancellation', [
        '@uid' => $customer->id(),
      ]);
      return [];
    }

    $cancelled_records = [];
    $contribution_cancelled = FALSE;

    // Process each order item
    $order_items = $order->getItems();
    $this->logger->info('Found @count order items to process for cancellation of order @order_id', [
      '@count' => count($order_items),
      '@order_id' => $order->id(),
    ]);

    foreach ($order_items as $index => $order_item) {
      $this->logger->debug('Processing cancellation for order item @index of @total for order @order_id', [
        '@index' => $index + 1,
        '@total' => count($order_items),
        '@order_id' => $order->id(),
      ]);

      /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $variation */
      $variation = $order_item->getPurchasedEntity();
      if (!$variation) {
        $this->logger->warning('Order item has no purchased entity (variation) - skipping cancellation for this item');
        continue;
      }

      /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
      $product = $variation->getProduct();
      if (!$product) {
        $this->logger->warning('Order item variation has no product - skipping cancellation for this item');
        continue;
      }

      // Get CiviCRM settings from the existing field_civicrm JSON configuration
      $civicrm_settings = $this->getCivicrmProductSettings($product);

      $this->logger->debug('Product @product_id CiviCRM settings (cancellation): @settings', [
        '@product_id' => $product->id(),
        '@settings' => json_encode($civicrm_settings),
      ]);

      // Check if CiviCRM integration is enabled for this product
      if (empty($civicrm_settings['enabled'])) {
        $this->logger->info('CiviCRM integration not enabled for product @product_id - skipping cancellation for this item', [
          '@product_id' => $product->id(),
        ]);
        continue;
      }

      // Cancel membership if configured.
      if (!empty($civicrm_settings['membership_type_id'])) {
        $membership_type_id = $civicrm_settings['membership_type_id'];
        try {
          $membership_id = $this->membershipUpdater->cancelMembershipFromOrder($contact_id, $membership_type_id, $order, $order_item);
          if ($membership_id) {
            $cancelled_records['memberships'][] = $membership_id;
            $this->logger->info('Cancelled membership @membership_id for order item @item_id (order @order_id)', [
              '@membership_id' => $membership_id,
              '@item_id' => $order_item->id(),
              '@order_id' => $order->id(),
            ]);
          } else {
            $this->logger->warning('Failed to cancel membership for type @type_id', [
              '@type_id' => $membership_type_id,
            ]);
          }
        } catch (\CRM_Core_Exception $e) {
          $this->logger->error('Error cancelling membership for order @order_id: @error', [
            '@order_id' => $order->id(),
            '@error' => $e->getMessage(),
          ]);
        }
      }

      // Cancel contribution if configured (only once per order).
      if (!empty($civicrm_settings['financial_type_id']) && !$contribution_cancelled) {
        try {
          $contribution_id = $this->contributionUpdater->cancelContributionFromOrder($order, $contact_id);
          $contribution_cancelled = TRUE;
          if ($contribution_id) {
            $cancelled_records['contributions'][] = $contribution_id;
            $this->logger->info('Cancelled contribution @contribution_id for order @order_id', [
              '@contribution_id' => $contribution_id,
              '@order_id' => $order->id(),
            ]);
          } else {
            $this->logger->warning('Failed to cancel contribution for order @order_id', [
              '@order_id' => $order->id(),
            ]);
          }
        } catch (\CRM_Core_Exception $e) {
          $this->logger->error('Error cancelling contribution for order @order_id: @error', [
            '@order_id' => $order->id(),
            '@error' => $e->getMessage(),
          ]);
        }
      }
    }

    $this->logger->info('Completed cancellation processing for order @order_id. Cancelled records: @records', [
      '@order_id' => $order->id(),
      '@records' => json_encode($cancelled_records),
    ]);

    return $cancelled_records;
  }

}
