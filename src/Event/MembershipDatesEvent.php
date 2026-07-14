<?php

namespace Drupal\commerce_civicrm\Event;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Component\EventDispatcher\Event;

/**
 * Event fired to determine membership dates when date_mode is 'dispatch'.
 *
 * Subscribers may set any of join_date, start_date, end_date (Y-m-d strings).
 * A NULL date is omitted from the API call so CiviCRM applies its own logic
 * (for end_date that means computing it from the membership type duration).
 */
class MembershipDatesEvent extends Event {

  /**
   * The dates to apply, keyed by join_date / start_date / end_date.
   */
  protected array $dates;

  /**
   * Constructs the event.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order being processed.
   * @param array $directive
   *   The membership directive being processed.
   * @param array $membershipType
   *   The CiviCRM membership type record.
   * @param array|null $existingMembership
   *   The existing membership when this is a renewal, NULL otherwise.
   * @param bool $isRenewal
   *   TRUE when an existing membership is being renewed/extended.
   */
  public function __construct(
    protected readonly OrderInterface $order,
    protected readonly array $directive,
    protected readonly array $membershipType,
    protected readonly ?array $existingMembership,
    protected readonly bool $isRenewal,
  ) {
    $today = (new \DateTimeImmutable())->format('Y-m-d');
    $this->dates = [
      'join_date' => $isRenewal ? NULL : $today,
      'start_date' => $isRenewal ? NULL : $today,
      'end_date' => NULL,
    ];
  }

  /**
   * Gets the order.
   */
  public function getOrder(): OrderInterface {
    return $this->order;
  }

  /**
   * Gets the membership directive.
   */
  public function getDirective(): array {
    return $this->directive;
  }

  /**
   * Gets the CiviCRM membership type record.
   */
  public function getMembershipType(): array {
    return $this->membershipType;
  }

  /**
   * Gets the existing membership for renewals.
   */
  public function getExistingMembership(): ?array {
    return $this->existingMembership;
  }

  /**
   * Whether this is a renewal of an existing membership.
   */
  public function isRenewal(): bool {
    return $this->isRenewal;
  }

  /**
   * Gets the dates (join_date, start_date, end_date; NULL values are omitted).
   */
  public function getDates(): array {
    return $this->dates;
  }

  /**
   * Sets one or more dates. Keys not passed keep their current value.
   */
  public function setDates(array $dates): void {
    $this->dates = array_merge($this->dates, array_intersect_key($dates, $this->dates));
  }

}
