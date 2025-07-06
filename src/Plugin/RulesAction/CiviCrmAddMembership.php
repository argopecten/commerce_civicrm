<?php

namespace Drupal\commerce_civicrm\Plugin\RulesAction;

use Drupal\rules\Core\RulesActionBase;
use Drupal\user\UserInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\commerce_civicrm\Service\ContactUpdater;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerInterface $logger, ContactUpdater $contact_updater) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $logger;
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
      $container->get('commerce_civicrm.contact_updater')
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

    // Create the membership using CiviCRM API
    try {
      if (!class_exists('\Civi\Api4\Membership')) {
        \Drupal::service('civicrm')->initialize();
      }
      
      $result = \Civi\Api4\Membership::create(FALSE)
        ->setValues([
          'contact_id' => $contact_id,
          'membership_type_id' => $membership_type_id,
          'source' => 'Drupal Commerce via Rules',
          'join_date' => date('Y-m-d'),
          'status_id' => 'New',
        ])
        ->execute();
      
      if ($result->count() > 0) {
        $membership_id = $result->first()['id'];
        $this->logger->info('Successfully created/renewed CiviCRM membership @membership_id for contact @contact_id', [
          '@membership_id' => $membership_id,
          '@contact_id' => $contact_id,
        ]);
      } else {
        $this->logger->error('Failed to create CiviCRM membership for contact @contact_id: No result returned', [
          '@contact_id' => $contact_id,
        ]);
      }
    } catch (\Exception $e) {
      $this->logger->error('Failed to create CiviCRM membership for contact @contact_id: @message', [
        '@contact_id' => $contact_id,
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
