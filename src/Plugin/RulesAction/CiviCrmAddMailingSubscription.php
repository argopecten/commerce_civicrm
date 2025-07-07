<?php

namespace Drupal\commerce_civicrm\Plugin\RulesAction;

use Drupal\Core\Form\FormStateInterface;
use Drupal\rules\Core\RulesActionBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\LoggerInterface;
use Drupal\user\UserInterface;
use Drupal\commerce_civicrm\Service\ContactUpdater;
use Drupal\commerce_civicrm\Service\MailingUpdater;
use Drupal\commerce_civicrm\Service\CivicrmHelper;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Provides a 'Add to CiviCRM Mailing Group' action.
 *
 * @RulesAction(
 *   id = "commerce_civicrm_add_mailing_subscription",
 *   label = @Translation("Add to CiviCRM Mailing Group"),
 *   category = @Translation("CiviCRM"),
 *   context_definitions = {
 *     "user" = @ContextDefinition("entity:user",
 *       label = @Translation("User"),
 *       description = @Translation("The user to add to the mailing group."),
 *       assignment_restriction = "selector"
 *     ),
 *     "mailing_group_id" = @ContextDefinition("integer",
 *       label = @Translation("Mailing Group ID"),
 *       description = @Translation("The ID of the CiviCRM Mailing Group."),
 *       assignment_restriction = "input"
 *     ),
 *     "double_opt_in" = @ContextDefinition("boolean",
 *       label = @Translation("Double Opt-in"),
 *       description = @Translation("Whether to require double opt-in confirmation."),
 *       assignment_restriction = "input",
 *       required = FALSE,
 *       default_value = FALSE
 *     ),
 *     "send_welcome" = @ContextDefinition("boolean",
 *       label = @Translation("Send Welcome Message"),
 *       description = @Translation("Whether to send a welcome message."),
 *       assignment_restriction = "input",
 *       required = FALSE,
 *       default_value = FALSE
 *     ),
 *   }
 * )
 */
class CiviCrmAddMailingSubscription extends RulesActionBase implements ContainerFactoryPluginInterface {

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
   * The mailing updater service.
   *
   * @var \Drupal\commerce_civicrm\Service\MailingUpdater
   */
  protected $mailingUpdater;

  /**
   * The CiviCRM helper service.
   *
   * @var \Drupal\commerce_civicrm\Service\CivicrmHelper
   */
  protected $civicrmHelper;

  /**
   * Constructs a CiviCrmAddMailingSubscription object.
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
   * @param \Drupal\commerce_civicrm\Service\MailingUpdater $mailing_updater
   *   The mailing updater service.
   * @param \Drupal\commerce_civicrm\Service\CivicrmHelper $civicrm_helper
   *   The CiviCRM helper service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerInterface $logger, ContactUpdater $contact_updater, MailingUpdater $mailing_updater, CivicrmHelper $civicrm_helper) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $logger;
    $this->contactUpdater = $contact_updater;
    $this->mailingUpdater = $mailing_updater;
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
      $container->get('commerce_civicrm.mailing_updater'),
      $container->get('commerce_civicrm.civicrm_helper')
    );
  }

  /**
   * Executes the action.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user.
   * @param int $mailing_group_id
   *   The CiviCRM mailing group ID.
   * @param bool $double_opt_in
   *   Whether to require double opt-in confirmation.
   * @param bool $send_welcome
   *   Whether to send a welcome message.
   */
  protected function doExecute(UserInterface $user, int $mailing_group_id, bool $double_opt_in = FALSE, bool $send_welcome = FALSE) {
    // Get the CiviCRM contact ID for the user
    $contact_id = $this->contactUpdater->getContactIdByUser($user);
    
    if (!$contact_id) {
      $this->logger->warning('Could not find CiviCRM contact for user @uid when adding to mailing group', [
        '@uid' => $user->id(),
      ]);
      return;
    }

    // Prepare mailing preferences
    $mailing_preferences = [];
    if ($double_opt_in) {
      $mailing_preferences['double_opt_in'] = TRUE;
    }
    if ($send_welcome) {
      $mailing_preferences['send_welcome'] = TRUE;
    }
    $mailing_preferences['update_existing'] = TRUE;

    // Add the contact to the mailing group
    try {
      $success = $this->mailingUpdater->addContactToMailingGroup(
        $contact_id,
        $mailing_group_id,
        $mailing_preferences
      );
      
      if ($success) {
        $this->logger->info('Successfully added contact @contact_id to mailing group @group_id via Rules action', [
          '@contact_id' => $contact_id,
          '@group_id' => $mailing_group_id,
        ]);
      } else {
        $this->logger->error('Failed to add contact @contact_id to mailing group @group_id via Rules action', [
          '@contact_id' => $contact_id,
          '@group_id' => $mailing_group_id,
        ]);
      }
    } catch (\Exception $e) {
      $this->logger->error('Failed to add contact @contact_id to mailing group @group_id via Rules action: @message', [
        '@contact_id' => $contact_id,
        '@group_id' => $mailing_group_id,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    // Add form elements for mailing group selection
    $form['mailing_help'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('This action will add the selected user to a CiviCRM mailing group. You can specify subscription preferences like double opt-in and welcome messages.') . '</p>',
    ];

    $form['mailing_instructions'] = [
      '#type' => 'markup',
      '#markup' => '<p><strong>' . $this->t('Note:') . '</strong> ' . $this->t('Mailing Group ID must be a valid CiviCRM Group ID. You can find these in your CiviCRM installation under Contacts > Manage Groups.') . '</p>',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    // No additional configuration needed as all parameters are provided via context
  }

  /**
   * {@inheritdoc}
   */
  public function summary() {
    return $this->t('Add user to CiviCRM mailing group');
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
