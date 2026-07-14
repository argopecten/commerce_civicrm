<?php

namespace Drupal\commerce_civicrm\Service;

use Drupal\commerce_civicrm\Event\CommerceCivicrmEvents;
use Drupal\commerce_civicrm\Event\RenewalRecordedEvent;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Records additional (renewal) payments for already-processed orders.
 *
 * Recurring charges typically happen on the same Commerce order without a
 * workflow transition, so the transition-based pipeline never sees them.
 * Site code (e.g. a PaymentEvents subscriber) calls recordRenewalPayment()
 * with the new completed payment; this creates a new contribution through
 * the CiviCRM Order API whose membership line items reference the existing
 * memberships, which extends them.
 */
class RenewalProcessor {

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a RenewalProcessor object.
   */
  public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    protected readonly CivicrmHelper $civicrmHelper,
    protected readonly OrderCivicrmUpdater $orderCivicrmUpdater,
    protected readonly ContributionUpdater $contributionUpdater,
    protected readonly EventDispatcherInterface $eventDispatcher,
  ) {
    $this->logger = $logger_factory->get('commerce_civicrm');
  }

  /**
   * Records a renewal payment for an already-processed order.
   *
   * Idempotent per payment: if a contribution already references the payment
   * (via the Commerce_Order.commerce_payment_id custom field or its remote
   * transaction ID), nothing is created.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order the payment belongs to.
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The completed Commerce payment to record.
   *
   * @return array
   *   ['contribution' => ?int, 'memberships' => int[], 'skipped' => bool].
   */
  public function recordRenewalPayment(OrderInterface $order, PaymentInterface $payment): array {
    $result = ['contribution' => NULL, 'memberships' => [], 'skipped' => TRUE];

    if (!$this->civicrmHelper->isReadyForOperations()) {
      $this->logger->error('CiviCRM is not available - skipping renewal for order @order_id, payment @payment_id', [
        '@order_id' => $order->id(),
        '@payment_id' => $payment->id(),
      ]);
      return $result;
    }

    if ($payment->getState()->getId() !== 'completed') {
      $this->logger->warning('Payment @payment_id is not completed - not recording renewal', [
        '@payment_id' => $payment->id(),
      ]);
      return $result;
    }

    $contact_id = $this->orderCivicrmUpdater->resolveContactId($order);
    if (!$contact_id) {
      return $result;
    }

    $this->civicrmHelper->ensureCommerceOrderCustomFields();

    // The order must have been processed initially; without an initial
    // contribution this payment is not a renewal.
    $initial_contribution_id = $this->contributionUpdater->findExistingContributionForOrder($order);
    if (!$initial_contribution_id) {
      $this->logger->info('No initial contribution for order @order_id - payment @payment_id is not a renewal', [
        '@order_id' => $order->id(),
        '@payment_id' => $payment->id(),
      ]);
      return $result;
    }

    // Idempotency per payment.
    $existing = $this->contributionUpdater->findContributionForPayment($payment);
    if ($existing && $existing !== $initial_contribution_id) {
      $this->logger->info('Payment @payment_id already recorded as contribution @cid - skipping', [
        '@payment_id' => $payment->id(),
        '@cid' => $existing,
      ]);
      $result['contribution'] = $existing;
      return $result;
    }
    if ($existing === $initial_contribution_id) {
      $this->logger->info('Payment @payment_id belongs to the initial contribution @cid - not a renewal', [
        '@payment_id' => $payment->id(),
        '@cid' => $existing,
      ]);
      return $result;
    }

    $context = ['phase' => 'renewal', 'payment_id' => (int) $payment->id()];

    $directives = $this->orderCivicrmUpdater->buildOrderDirectives($order, $contact_id, $context);
    $financial = array_values(array_filter($directives, static fn (array $d) => in_array($d['type'] ?? NULL, ['membership', 'contribution', 'event'], TRUE)));
    if ($financial === []) {
      $this->logger->info('Order @order_id has no financial directives - nothing to renew', [
        '@order_id' => $order->id(),
      ]);
      return $result;
    }

    $records = $this->orderCivicrmUpdater->createCiviOrder(
      $order,
      $contact_id,
      $financial,
      $payment,
      $context,
      $payment->getAmount()?->getNumber()
    );

    if (empty($records['contributions'])) {
      return $result;
    }

    $result = [
      'contribution' => $records['contributions'][0],
      'memberships' => $records['memberships'] ?? [],
      'skipped' => FALSE,
    ];

    $this->logger->info('Recorded renewal payment @payment_id for order @order_id: contribution @cid, memberships @mids', [
      '@payment_id' => $payment->id(),
      '@order_id' => $order->id(),
      '@cid' => $result['contribution'],
      '@mids' => implode(', ', $result['memberships']),
    ]);

    $this->eventDispatcher->dispatch(
      new RenewalRecordedEvent($order, $payment, $records),
      CommerceCivicrmEvents::RENEWAL_RECORDED
    );

    return $result;
  }

}
