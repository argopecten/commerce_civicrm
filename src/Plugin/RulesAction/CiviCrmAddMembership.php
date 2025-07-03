<?php

namespace Drupal\commerce_civicrm\Plugin\RulesAction;

use Drupal\rules\Core\RulesActionBase;
use Drupal\user\UserInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Add a CiviCRM Membership' action.
 *
 * @RulesAction(
 * id = "commerce_civicrm_add_membership",
 * label = @Translation("Add a CiviCRM Membership"),
 * category = @Translation("CiviCRM"),
 * context_definitions = {
 * "user" = @ContextDefinition("entity:user",
 * label = @Translation("User"),
 * description = @Translation("The user for whom to create the membership."),
 * assignment_restriction = "selector"
 * ),
 * "membership_type_id" = @ContextDefinition("integer",
 * label = @Translation("Membership Type ID"),
 * description = @Translation("The ID of the CiviCRM Membership Type to create or renew."),
 * assignment_restriction = "input"
 * ),
 * }
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
   * Constructs a CiviCrmAddMembership object.
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
   * @param int $membership_type_id
   * The CiviCRM membership type ID.
   */
  protected function doExecute(UserInterface $user, int $membership_type_id) {
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

    // Create the membership in CiviCRM.
    try {
      civicrm_api3('Membership', 'create', [
        'contact_id' => $contact_id,
        'membership_type_id' => $membership_type_id,
        'source' => $this->t('Drupal Commerce via Rules'),
        'join_date' => date('Y-m-d'),
        'status_id' => 'New', // This could be customized further if needed.
      ]);
      $this->logger->info('Successfully created/renewed CiviCRM membership for contact @cid.', ['@cid' => $contact_id]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create CiviCRM membership for contact @cid: @message', [
        '@cid' => $contact_id,
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
