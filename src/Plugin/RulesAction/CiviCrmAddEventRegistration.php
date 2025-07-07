<?php

namespace Drupal\commerce_civicrm\Plugin\RulesAction;

use Drupal\Core\Form\FormStateInterface;
use Drupal\rules\Core\RulesActionBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\LoggerInterface;
use Drupal\user\UserInterface;
use Drupal\commerce_civicrm\Service\ContactUpdater;
use Drupal\commerce_civicrm\Service\EventUpdater;
use Drupal\commerce_civicrm\Service\CivicrmHelper;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Provides a 'Add a CiviCRM Event Registration' action.
 *
 * @RulesAction(
 *   id = "commerce_civicrm_add_event_registration",
 *   label = @Translation("Add a CiviCRM Event Registration"),
 *   category = @Translation("CiviCRM"),
 *   context_definitions = {
 *     "user" = @ContextDefinition("entity:user",
 *       label = @Translation("User"),
 *       description = @Translation("The user for whom to create the event registration."),
 *       assignment_restriction = "selector"
 *     ),
 *     "event_id" = @ContextDefinition("integer",
 *       label = @Translation("Event ID"),
 *       description = @Translation("The ID of the CiviCRM Event for registration."),
 *       assignment_restriction = "input"
 *     ),
 *     "participant_role_id" = @ContextDefinition("integer",
 *       label = @Translation("Participant Role ID"),
 *       description = @Translation("The ID of the CiviCRM Participant Role (optional)."),
 *       assignment_restriction = "input",
 *       required = FALSE
 *     ),
 *   }
 * )
 */
class CiviCrmAddEventRegistration extends RulesActionBase implements ContainerFactoryPluginInterface {

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
   * The event updater service.
   *
   * @var \Drupal\commerce_civicrm\Service\EventUpdater
   */
  protected $eventUpdater;

  /**
   * The CiviCRM helper service.
   *
   * @var \Drupal\commerce_civicrm\Service\CivicrmHelper
   */
  protected $civicrmHelper;

  /**
   * Constructs a CiviCrmAddEventRegistration object.
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
   * @param \Drupal\commerce_civicrm\Service\EventUpdater $event_updater
   *   The event updater service.
   * @param \Drupal\commerce_civicrm\Service\CivicrmHelper $civicrm_helper
   *   The CiviCRM helper service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerInterface $logger, ContactUpdater $contact_updater, EventUpdater $event_updater, CivicrmHelper $civicrm_helper) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $logger;
    $this->contactUpdater = $contact_updater;
    $this->eventUpdater = $event_updater;
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
      $container->get('commerce_civicrm.event_updater'),
      $container->get('commerce_civicrm.civicrm_helper')
    );
  }

  /**
   * Executes the action.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user.
   * @param int $event_id
   *   The CiviCRM event ID.
   * @param int|null $participant_role_id
   *   The CiviCRM participant role ID (optional).
   */
  protected function doExecute(UserInterface $user, int $event_id, $participant_role_id = NULL) {
    // Get the CiviCRM contact ID for the user
    $contact_id = $this->contactUpdater->getContactIdByUser($user);
    
    if (!$contact_id) {
      $this->logger->warning('Could not find CiviCRM contact for user @uid when creating event registration', [
        '@uid' => $user->id(),
      ]);
      return;
    }

    // Create the event registration using the EventUpdater service
    try {
      $participant_id = $this->eventUpdater->createEventRegistrationWithRole(
        $contact_id,
        $event_id,
        $participant_role_id
      );
      
      if ($participant_id) {
        $this->logger->info('Successfully created CiviCRM event registration @participant_id for contact @contact_id via Rules action', [
          '@participant_id' => $participant_id,
          '@contact_id' => $contact_id,
        ]);
      } else {
        $this->logger->error('Failed to create CiviCRM event registration for contact @contact_id via Rules action', [
          '@contact_id' => $contact_id,
        ]);
      }
    } catch (\Exception $e) {
      $this->logger->error('Failed to create CiviCRM event registration for contact @contact_id via Rules action: @message', [
        '@contact_id' => $contact_id,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    // Add form elements for event selection
    $form['event_help'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('This action will register the selected user for a CiviCRM event. You can specify the event ID and optionally a participant role.') . '</p>',
    ];

    $form['event_instructions'] = [
      '#type' => 'markup',
      '#markup' => '<p><strong>' . $this->t('Note:') . '</strong> ' . $this->t('Event ID and Participant Role ID must be valid CiviCRM IDs. You can find these in your CiviCRM installation under Events and Participant Roles respectively.') . '</p>',
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
    return $this->t('Register user for CiviCRM event');
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
