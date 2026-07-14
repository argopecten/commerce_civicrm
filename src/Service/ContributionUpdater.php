<?php

namespace Drupal\commerce_civicrm\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Contribution lookups, payment info and cancellation for Commerce orders.
 *
 * Contribution creation goes through the CiviCRM Order API in
 * OrderCivicrmUpdater; this service provides the supporting pieces:
 * order/payment-scoped contribution lookups (idempotency), payment
 * instrument mapping, and cancellation.
 */
class ContributionUpdater {

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Constructs a ContributionUpdater object.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $logger_factory,
    protected readonly CivicrmHelper $civicrmHelper,
    protected readonly ConfigFactoryInterface $configFactory,
  ) {
    $this->logger = $logger_factory->get('commerce_civicrm');
  }

  /**
   * Finds an existing CiviCRM contribution for the given order.
   *
   * Looks up the Commerce_Order.commerce_order_id custom field, falling back
   * to an exact source match for legacy records (and backfilling the custom
   * field when the fallback hits).
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   *
   * @return int|null
   *   The existing contribution ID if found, NULL otherwise.
   */
  public function findExistingContributionForOrder(OrderInterface $order): ?int {
    try {
      if (!$this->civicrmHelper->initialize()) {
        return NULL;
      }

      $order_id = (int) $order->id();

      $result = \Civi\Api4\Contribution::get(FALSE)
        ->addSelect('id')
        ->addWhere('Commerce_Order.commerce_order_id', '=', $order_id)
        ->addOrderBy('id', 'ASC')
        ->setLimit(1)
        ->execute();

      if ($result->count() > 0) {
        return (int) $result->first()['id'];
      }

      // Fallback: exact source match for legacy contributions.
      $result = \Civi\Api4\Contribution::get(FALSE)
        ->addSelect('id')
        ->addWhere('source', '=', 'Commerce Order #' . $order_id)
        ->setLimit(1)
        ->execute();

      if ($result->count() > 0) {
        $contribution_id = (int) $result->first()['id'];
        $this->logger->info('Found contribution @cid via source fallback for order @oid — backfilling custom field', [
          '@cid' => $contribution_id,
          '@oid' => $order_id,
        ]);

        try {
          \Civi\Api4\Contribution::update(FALSE)
            ->addWhere('id', '=', $contribution_id)
            ->addValue('Commerce_Order.commerce_order_id', $order_id)
            ->execute();
        }
        catch (\CRM_Core_Exception $e) {
          $this->logger->warning('Could not backfill custom field on contribution @cid: @error', [
            '@cid' => $contribution_id,
            '@error' => $e->getMessage(),
          ]);
        }

        return $contribution_id;
      }
    }
    catch (\CRM_Core_Exception $e) {
      $this->logger->error('Error finding existing CiviCRM contribution: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Finds all contributions linked to an order (initial and renewals).
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   *
   * @return int[]
   *   The contribution IDs, oldest first.
   */
  public function findAllContributionsForOrder(OrderInterface $order): array {
    try {
      if (!$this->civicrmHelper->initialize()) {
        return [];
      }

      $result = \Civi\Api4\Contribution::get(FALSE)
        ->addSelect('id')
        ->addWhere('Commerce_Order.commerce_order_id', '=', (int) $order->id())
        ->addOrderBy('id', 'ASC')
        ->execute();

      return array_map('intval', $result->column('id'));
    }
    catch (\CRM_Core_Exception $e) {
      $this->logger->error('Error finding contributions for order @order_id: @error', [
        '@order_id' => $order->id(),
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Finds the contribution that records a specific Commerce payment.
   *
   * Matches the Commerce_Order.commerce_payment_id custom field, falling
   * back to the payment's remote transaction ID.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The Commerce payment.
   *
   * @return int|null
   *   The contribution ID if found, NULL otherwise.
   */
  public function findContributionForPayment(PaymentInterface $payment): ?int {
    try {
      if (!$this->civicrmHelper->initialize()) {
        return NULL;
      }

      $result = \Civi\Api4\Contribution::get(FALSE)
        ->addSelect('id')
        ->addWhere('Commerce_Order.commerce_payment_id', '=', (int) $payment->id())
        ->setLimit(1)
        ->execute();

      if ($result->count() > 0) {
        return (int) $result->first()['id'];
      }

      $remote_id = $payment->getRemoteId();
      if ($remote_id) {
        $result = \Civi\Api4\Contribution::get(FALSE)
          ->addSelect('id')
          ->addWhere('trxn_id', '=', $remote_id)
          ->setLimit(1)
          ->execute();
        if ($result->count() > 0) {
          return (int) $result->first()['id'];
        }
      }
    }
    catch (\CRM_Core_Exception $e) {
      $this->logger->error('Error finding contribution for payment @payment_id: @error', [
        '@payment_id' => $payment->id(),
        '@error' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Gets the most recent completed payment of an order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface|null
   *   The payment, or NULL when the order has no completed payment (e.g.
   *   manually completed bank-transfer orders).
   */
  public function getLatestCompletedPayment(OrderInterface $order): ?PaymentInterface {
    try {
      $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
      $payment_ids = $payment_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('order_id', $order->id())
        ->condition('state', 'completed')
        ->sort('payment_id', 'DESC')
        ->range(0, 1)
        ->execute();

      if ($payment_ids) {
        /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
        $payment = $payment_storage->load(reset($payment_ids));
        return $payment;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error loading payments of order @order_id: @error', [
        '@order_id' => $order->id(),
        '@error' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Maps a Commerce payment to a CiviCRM payment instrument ID.
   *
   * The commerce_civicrm.settings payment_instrument_map is consulted with
   * the payment gateway config entity ID first, then the gateway plugin ID;
   * unmatched gateways use payment_instrument_default.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The Commerce payment.
   *
   * @return int|null
   *   The payment instrument option value, or NULL if not resolvable.
   */
  public function getPaymentInstrumentId(PaymentInterface $payment): ?int {
    $settings = $this->configFactory->get('commerce_civicrm.settings');
    $map = $settings->get('contribution.payment_instrument_map') ?: [];

    $instrument_name = NULL;
    $gateway = $payment->getPaymentGateway();
    if ($gateway) {
      $instrument_name = $map[$gateway->id()] ?? $map[$gateway->getPluginId()] ?? NULL;
    }
    $instrument_name = $instrument_name ?? $settings->get('contribution.payment_instrument_default');

    if (!$instrument_name) {
      return NULL;
    }

    return $this->civicrmHelper->getOptionValue('payment_instrument', $instrument_name);
  }

  /**
   * Cancels the CiviCRM contributions linked to a cancelled order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   *
   * @return int[]
   *   The cancelled contribution IDs.
   */
  public function cancelContributionsFromOrder(OrderInterface $order): array {
    $cancelled = [];

    if (!$this->civicrmHelper->initialize()) {
      return $cancelled;
    }

    foreach ($this->findAllContributionsForOrder($order) as $contribution_id) {
      try {
        \Civi\Api4\Contribution::update(FALSE)
          ->addWhere('id', '=', $contribution_id)
          ->addValue('contribution_status_id:name', 'Cancelled')
          ->execute();
        $cancelled[] = $contribution_id;
        $this->logger->info('Cancelled CiviCRM contribution @contribution_id for order @order_id', [
          '@contribution_id' => $contribution_id,
          '@order_id' => $order->id(),
        ]);
      }
      catch (\CRM_Core_Exception $e) {
        $this->logger->error('Error cancelling contribution @contribution_id for order @order_id: @error', [
          '@contribution_id' => $contribution_id,
          '@order_id' => $order->id(),
          '@error' => $e->getMessage(),
        ]);
      }
    }

    return $cancelled;
  }

}
