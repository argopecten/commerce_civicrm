<?php

namespace Drupal\commerce_civicrm\Event;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\Component\EventDispatcher\Event;

/**
 * Event fired immediately before the CiviCRM Order API call.
 *
 * Subscribers may alter the contribution values and the line items that will
 * be passed to \Civi\Api4\Order::create().
 */
class ContributionParamsEvent extends Event {

  /**
   * Constructs the event.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order being processed.
   * @param array $contributionValues
   *   The contribution values for Order::create()->setContributionValues().
   * @param array $lineItems
   *   The line items: flat LineItem arrays as passed to
   *   Order::create()->addLineItem(), with related-entity values as
   *   entity_id.FIELD keys.
   * @param \Drupal\commerce_payment\Entity\PaymentInterface|null $payment
   *   The Commerce payment this contribution reflects, if any.
   * @param array $context
   *   Processing context (see OrderItemDirectivesEvent).
   */
  public function __construct(
    protected readonly OrderInterface $order,
    protected array $contributionValues,
    protected array $lineItems,
    protected readonly ?PaymentInterface $payment,
    protected readonly array $context = [],
  ) {}

  /**
   * Gets the order.
   */
  public function getOrder(): OrderInterface {
    return $this->order;
  }

  /**
   * Gets the contribution values.
   */
  public function getContributionValues(): array {
    return $this->contributionValues;
  }

  /**
   * Replaces the contribution values.
   */
  public function setContributionValues(array $values): void {
    $this->contributionValues = $values;
  }

  /**
   * Gets the line items.
   */
  public function getLineItems(): array {
    return $this->lineItems;
  }

  /**
   * Replaces the line items.
   */
  public function setLineItems(array $lineItems): void {
    $this->lineItems = $lineItems;
  }

  /**
   * Gets the Commerce payment, if any.
   */
  public function getPayment(): ?PaymentInterface {
    return $this->payment;
  }

  /**
   * Gets the processing context.
   */
  public function getContext(): array {
    return $this->context;
  }

}
