<?php

namespace Drupal\commerce_civicrm\Plugin\RulesAction;

use Drupal\rules\Core\RulesActionBase;
use Drupal\user\UserInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\commerce_civicrm\Service\ContributionUpdater;
use Drupal\commerce_civicrm\Service\ContactUpdater;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Add a CiviCRM Contribution' action.
 *
 * @RulesAction(
 *   id = "commerce_civicrm_add_contribution",
 *   label = @Translation("Add a CiviCRM Contribution"),
 *   category = @Translation("CiviCRM"),
 *   context_definitions = {
 *     "user" = @ContextDefinition("entity:user",
 *       label = @Translation("User"),
 *       description = @Translation("The user for whom to create the contribution."),
 *       assignment_restriction = "selector"
 *     ),
 *     "order" = @ContextDefinition("entity:commerce_order",
 *       label = @Translation("Order"),
 *       description = @Translation("The commerce order from which to create the contribution. The total amount and currency will be used."),
 *       assignment_restriction = "selector"
 *     ),
 *     "financial_type_id" = @ContextDefinition("integer",
 *       label = @Translation("Financial Type ID"),
 *       description = @Translation("The ID of the CiviCRM Financial Type for this contribution."),
 *       assignment_restriction = "input"
 *     ),
 *   }
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
   * The contribution updater service.
   *
   * @var \Drupal\commerce_civicrm\Service\ContributionUpdater
   */
  protected $contributionUpdater;

  /**
   * The contact updater service.
   *
   * @var \Drupal\commerce_civicrm\Service\ContactUpdater
   */
  protected $contactUpdater;

  /**
   * Constructs a CiviCrmAddContribution object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\commerce_civicrm\Service\ContributionUpdater $contribution_updater
   *   The contribution updater service.
   * @param \Drupal\commerce_civicrm\Service\ContactUpdater $contact_updater
   *   The contact updater service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerInterface $logger, ContributionUpdater $contribution_updater, ContactUpdater $contact_updater) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $logger;
    $this->contributionUpdater = $contribution_updater;
    $this->contactUpdater = $contact_updater;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory')->get('commerce_civicrm'),
      $container->get('commerce_civicrm.contribution_updater'),
      $container->get('commerce_civicrm.contact_updater')
    );
  }

  /**
   * Executes the action.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param int $financial_type_id
   *   The CiviCRM financial type ID.
   */
  protected function doExecute(UserInterface $user, OrderInterface $order, int $financial_type_id) {
    // Get the CiviCRM contact ID for the user
    $contact_id = $this->contactUpdater->getContactIdByUser($user);
    
    if (!$contact_id) {
      $this->logger->warning('Could not find CiviCRM contact for user @uid (order @order_id)', [
        '@uid' => $user->id(),
        '@order_id' => $order->id(),
      ]);
      return;
    }

    // Create the contribution using the service
    $contribution_id = $this->contributionUpdater->createContributionFromOrderWithFinancialType(
      $order,
      $contact_id,
      $financial_type_id
    );

    if ($contribution_id) {
      $this->logger->info('Successfully created CiviCRM contribution @contribution_id for contact @contact_id from order @order_id', [
        '@contribution_id' => $contribution_id,
        '@contact_id' => $contact_id,
        '@order_id' => $order->id(),
      ]);
    } else {
      $this->logger->error('Failed to create CiviCRM contribution for contact @contact_id from order @order_id', [
        '@contact_id' => $contact_id,
        '@order_id' => $order->id(),
      ]);
    }
  }

}