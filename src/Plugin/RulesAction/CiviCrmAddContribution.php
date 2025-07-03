<?php

namespace Drupal\commerce_civicrm\Plugin\RulesAction;

use Drupal\rules\Core\RulesActionBase;
use Drupal\user\UserInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Add a CiviCRM Contribution' action.
 *
 * @RulesAction(
 * id = "commerce_civicrm_add_contribution",
 * label = @Translation("Add a CiviCRM Contribution"),
 * category = @Translation("CiviCRM"),
 * context_definitions = {
 * "user" = @ContextDefinition("entity:user",
 * label = @Translation("User"),
 * description = @Translation("The user for whom to create the contribution."),
 * assignment_restriction = "selector"
 * ),
 * "order" = @ContextDefinition("entity:commerce_order",
 * label = @Translation("Order"),
 * description = @Translation("The commerce order from which to create the contribution. The total amount and currency will be used."),
 * assignment_restriction = "selector"
 * ),
 * "financial_type_id" = @ContextDefinition("integer",
 * label = @Translation("Financial Type ID"),
 * description = @Translation("The ID of the CiviCRM Financial Type for this contribution."),
 * assignment_restriction = "input"
 * ),
 * }
 * )
 */
class CiviCrmAddContribution extends RulesActionBase implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a CiviCrmAddContribution object.
   *
   * @param array $configuration
   * A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   * The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   * The plugin implementation definition.
   * @param \Psr\Log\LoggerInterface $logger
   * The logger service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory')->get('commerce_civicrm')
    );
  }

  /**
   * Executes the action.
   *
   * @param \Drupal\user\UserInterface $user
   * The user.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   * The order.
   * @param int $financial_type_id
   * The CiviCRM financial type ID.
   */
  protected function doExecute(UserInterface $user, OrderInterface $order, int $financial_type_id) {
    // Ensure CiviCRM is initialized.
    \Drupal::service('civicrm')->initialize();

    // Find the CiviCRM contact ID for the Drupal user.
    try {
      $uf_match = civicrm_api3('UFMatch', 'get', [
        'sequential' => 1,
        'uf_id' => $user->id(),
      ]);
      if (empty($uf_match['id'])) {
        $this->logger->warning('Could not find CiviCRM contact for user ID @uid.', ['@uid' => $user->id()]);
        return;
      }
      $contact_id = $uf_match['values'][0]['contact_id'];
    }
    catch (\Exception $e) {
      $this->logger->error('Error finding CiviCRM contact for user @uid: @message', [
        '@uid' => $user->id(),
        '@message' => $e->getMessage(),
      ]);
      return;
    }

    // Create the contribution in CiviCRM.
    try {
      civicrm_api3('Contribution', 'create', [
        'contact_id' => $contact_id,
        'financial_type_id' => $financial_type_id,
        'total_amount' => $order->getTotalPrice()->getNumber(),
        'currency' => $order->getTotalPrice()->getCurrencyCode(),
        'source' => $this->t('Drupal Commerce Order @order_id', ['@order_id' => $order->id()]),
        'contribution_status_id' => 'Completed',
      ]);
      $this->logger->info('Successfully created CiviCRM contribution for contact @cid from order @oid.', [
        '@cid' => $contact_id,
        '@oid' => $order->id(),
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create CiviCRM contribution for contact @cid: @message', [
        '@cid' => $contact_id,
        '@message' => $e->getMessage(),
      ]);
    }
  }

}