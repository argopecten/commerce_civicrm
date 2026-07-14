<?php

namespace Drupal\commerce_civicrm\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Service for CiviCRM participant records created from Commerce orders.
 *
 * Participant creation happens through the CiviCRM Order API (participant
 * line items built by OrderCivicrmUpdater); this service covers the
 * operations outside that call, currently cancellation.
 */
class ParticipantUpdater {

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Constructs a ParticipantUpdater object.
   */
  public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    protected readonly CivicrmHelper $civicrmHelper,
  ) {
    $this->logger = $logger_factory->get('commerce_civicrm');
  }

  /**
   * Cancels a participant registration.
   *
   * @param int $participant_id
   *   The CiviCRM participant ID.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order the cancellation originates from (for the source note).
   *
   * @return bool
   *   TRUE on success.
   */
  public function cancelParticipant(int $participant_id, OrderInterface $order): bool {
    if (!$this->civicrmHelper->initialize()) {
      return FALSE;
    }

    try {
      $result = \Civi\Api4\Participant::update(FALSE)
        ->addWhere('id', '=', $participant_id)
        ->addValue('status_id:name', 'Cancelled')
        ->addValue('source', 'Commerce Order #' . $order->id() . ' (Cancelled)')
        ->execute();

      if ($result->count() > 0) {
        $this->logger->info('Cancelled CiviCRM participant @participant_id for order @order_id', [
          '@participant_id' => $participant_id,
          '@order_id' => $order->id(),
        ]);
        return TRUE;
      }
      return FALSE;
    }
    catch (\CRM_Core_Exception $e) {
      $this->logger->error('Error cancelling participant @participant_id: @error', [
        '@participant_id' => $participant_id,
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

}
