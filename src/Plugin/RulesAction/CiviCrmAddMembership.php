<?php

namespace Drupal\commerce_civicrm\Plugin\RulesAction;

use Drupal\Core\Form\FormStateInterface;
use Drupal\rules\Core\RulesActionBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\LoggerInterface;
use Drupal\user\UserInterface;
use Drupal\commerce_civicrm\Service\ContactUpdater;
use Drupal\commerce_civicrm\Service\MembershipUpdater;
use Drupal\commerce_civicrm\Service\CivicrmHelper;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Provides a 'Add a CiviCRM Membership' action.
 *
 * @RulesAction(
 *   id = "commerce_civicrm_add_membership",
 *   label = @Translation("Add a CiviCRM Membership"),
 *   category = @Translation("CiviCRM"),
 *   context_definitions = {
 *     "user" = @ContextDefinition("entity:user",
 *       label = @Translation("User"),
 *       description = @Translation("The user for whom to create the membership."),
 *       assignment_restriction = "selector"
 *     ),
 *     "membership_type_id" = @ContextDefinition("integer",
 *       label = @Translation("Membership Type ID"),
 *       description = @Translation("The ID of the CiviCRM Membership Type to create or renew."),
 *       assignment_restriction = "input"
 *     ),
 *   }
 * )
 */
class CiviCrmAddMembership extends RulesActionBase implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The contact updater service.
   *
   * @var \Drupal\commerce_civicrm\Service\ContactUpdater
   */
  protected $contactUpdater;

  /**
   * The membership updater service.
   *
   * @var \Drupal\commerce_civicrm\Service\MembershipUpdater
   */
  protected $membershipUpdater;

  /**
   * The CiviCRM helper service.
   *
   * @var \Drupal\commerce_civicrm\Service\CivicrmHelper
   */
  protected $civicrmHelper;

  /**
   * Constructs a CiviCrmAddMembership object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\commerce_civicrm\Service\ContactUpdater $contact_updater
   *   The contact updater service.
   * @param \Drupal\commerce_civicrm\Service\MembershipUpdater $membership_updater
   *   The membership updater service.
   * @param \Drupal\commerce_civicrm\Service\CivicrmHelper $civicrm_helper
   *   The CiviCRM helper service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerInterface $logger, ContactUpdater $contact_updater, MembershipUpdater $membership_updater, CivicrmHelper $civicrm_helper) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $logger;
    $this->contactUpdater = $contact_updater;
    $this->membershipUpdater = $membership_updater;
    $this->civicrmHelper = $civicrm_helper;
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
      $container->get('commerce_civicrm.contact_updater'),
      $container->get('commerce_civicrm.membership_updater'),
      $container->get('commerce_civicrm.civicrm_helper')
    );
  }

  /**
   * Executes the action.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user.
   * @param int $membership_type_id
   *   The CiviCRM membership type ID.
   */
  protected function doExecute(UserInterface $user, int $membership_type_id) {
    // Get the CiviCRM contact ID for the user
    $contact_id = $this->contactUpdater->getContactIdByUser($user);
    
    if (!$contact_id) {
      $this->logger->warning('Could not find CiviCRM contact for user @uid when creating membership', [
        '@uid' => $user->id(),
      ]);
      return;
    }

    // Create a dummy order for the membership updater
    // In a real scenario, this would be called from an order context
    $order = new class {
      public function id() { return 'rules-action'; }
      public function getState() { 
        return new class {
          public function getId() { return 'completed'; }
        };
      }
    };

    $order_item = new class {
      public function id() { return 'rules-action-item'; }
    };

    // Create the membership using the MembershipUpdater service
    try {
      $membership_id = $this->membershipUpdater->createMembershipFromOrder(
        $contact_id,
        $membership_type_id,
        $order,
        $order_item
      );
      
      if ($membership_id) {
        $this->logger->info('Successfully created/renewed CiviCRM membership @membership_id for contact @contact_id via Rules action', [
          '@membership_id' => $membership_id,
          '@contact_id' => $contact_id,
        ]);
      } else {
        $this->logger->error('Failed to create CiviCRM membership for contact @contact_id via Rules action', [
          '@contact_id' => $contact_id,
        ]);
      }
    } catch (\Exception $e) {
      $this->logger->error('Failed to create CiviCRM membership for contact @contact_id via Rules action: @message', [
        '@contact_id' => $contact_id,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Initializes CiviCRM for API operations.
   *
   * @return bool
   *   TRUE if CiviCRM is successfully initialized, FALSE otherwise.
   */
  private function initializeCivicrm() {
    return $this->civicrmHelper->initialize();
  }

}
