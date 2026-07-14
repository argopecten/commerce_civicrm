<?php

namespace Drupal\commerce_civicrm\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Commerce CiviCRM.
 */
final class CommerceCivicrmCommands extends DrushCommands {

  /**
   * Processes (or replays) an order into CiviCRM records.
   *
   * Idempotent: orders that already have a linked contribution are skipped,
   * so replaying is safe. Useful for orders paid while the module was
   * disabled or during a deployment window.
   */
  #[CLI\Command(name: 'commerce-civicrm:process-order', aliases: ['ccv-process'])]
  #[CLI\Argument(name: 'order_ids', description: 'One or more commerce order IDs, comma-separated.')]
  #[CLI\Usage(name: 'commerce-civicrm:process-order 128', description: 'Process order 128 into CiviCRM.')]
  #[CLI\Usage(name: 'commerce-civicrm:process-order 128,129,130', description: 'Process several orders.')]
  public function processOrder(string $order_ids): void {
    $storage = \Drupal::entityTypeManager()->getStorage('commerce_order');
    /** @var \Drupal\commerce_civicrm\Service\OrderCivicrmUpdater $updater */
    $updater = \Drupal::service('commerce_civicrm.order_civicrm_updater');

    foreach (array_filter(array_map('trim', explode(',', $order_ids))) as $order_id) {
      /** @var \Drupal\commerce_order\Entity\OrderInterface|null $order */
      $order = $storage->load($order_id);
      if (!$order) {
        $this->logger()->error("Order $order_id not found.");
        continue;
      }

      $records = $updater->processOrder($order, ['phase' => 'create', 'trigger' => 'drush']);
      if ($records === []) {
        $this->logger()->warning("Order $order_id: no CiviCRM records created (see the commerce_civicrm log channel).");
      }
      else {
        $this->logger()->success("Order $order_id: " . json_encode($records));
      }
    }
  }

}
