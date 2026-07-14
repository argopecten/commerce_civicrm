<?php

namespace Drupal\commerce_civicrm\Service;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Psr\Log\LoggerInterface;

/**
 * Service for handling CiviCRM integration on product edit forms.
 */
class ProductFormHelper {

  use StringTranslationTrait;

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Creates a new ProductFormHelper instance.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   */
  public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    protected readonly CivicrmHelper $civicrmHelper,
    protected readonly MembershipUpdater $membershipUpdater,
    protected readonly ContactUpdater $contactUpdater,
  ) {
    $this->logger = $logger_factory->get('commerce_civicrm');
  }

  /**
   * Alters the product form to add CiviCRM settings.
   *
   * @param array $form
   *   The form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $form_id
   *   The form ID.
   */
  public function alterProductForm(array &$form, FormStateInterface $form_state, string $form_id): void {
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = $form_state->getFormObject()->getEntity();

    // Ensure the product has the required field before proceeding.
    if (!$product->hasField('field_civicrm')) {
      return;
    }

    // Check if CiviCRM is available before proceeding.
    try {
      if (!$this->civicrmHelper->isAvailable()) {
        return;
      }
    }
    catch (\CRM_Core_Exception $e) {
      $this->logger->error('CiviCRM helper service failed: @message', ['@message' => $e->getMessage()]);
      return;
    }

    // Get existing CiviCRM settings for this product.
    $civicrm_settings = $this->getProductSettings($product);

    // Build the CiviCRM integration form section.
    $form['civicrm'] = [
      '#type' => 'details',
      '#title' => $this->t('CiviCRM Integration'),
      '#group' => 'advanced',
      '#weight' => 90,
      '#open' => !empty($civicrm_settings['enabled']),
      '#access' => TRUE,
    ];
 
    $form['civicrm']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable CiviCRM Processing for this Product'),
      '#description' => $this->t('When enabled, purchasing this product will create records in CiviCRM.'),
      '#default_value' => $civicrm_settings['enabled'],
    ];

    $form['civicrm']['entity'] = [
      '#type' => 'select',
      '#title' => $this->t('CiviCRM Entity Type'),
      '#description' => $this->t('Choose what type of CiviCRM record to create when this product is purchased.'),
      '#options' => [
        'contribution' => $this->t('Contribution (Donation)'),
        'membership' => $this->t('Membership'),
        'event' => $this->t('Event Registration'),
        'mailing' => $this->t('Mailing List Subscription'),
      ],
      '#default_value' => $civicrm_settings['entity'],
      '#states' => [
        'visible' => [
          ':input[name="civicrm[enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Add membership type selection.
    $membership_options = $this->membershipUpdater->getMembershipTypes();
    $form['civicrm']['membership_type'] = [
      '#type' => 'select',
      '#title' => $this->t('CiviCRM Membership Type'),
      '#description' => $this->t('Select the membership type to create when this product is purchased.'),
      '#options' => $membership_options,
      '#default_value' => $civicrm_settings['membership_type'],
      '#states' => [
        'visible' => [
          ':input[name="civicrm[enabled]"]' => ['checked' => TRUE],
          ':input[name="civicrm[entity]"]' => ['value' => 'membership'],
        ],
      ],
    ];

    // Add financial type selection.
    $financial_options = $this->contactUpdater->getFinancialTypes();
    $form['civicrm']['contribution_type'] = [
      '#type' => 'select',
      '#title' => $this->t('CiviCRM Financial Type'),
      '#description' => $this->t('Select the financial type for contributions created when this product is purchased.'),
      '#options' => $financial_options,
      '#default_value' => $civicrm_settings['financial_type'],
      '#states' => [
        'visible' => [
          ':input[name="civicrm[enabled]"]' => ['checked' => TRUE],
          ':input[name="civicrm[entity]"]' => ['value' => 'contribution'],
        ],
      ],
    ];

    // Add event selection.
    $event_options = $this->contactUpdater->getEvents();
    $form['civicrm']['event_id'] = [
      '#type' => 'select',
      '#title' => $this->t('CiviCRM Event'),
      '#description' => $this->t('Select the event for which to register participants when this product is purchased.'),
      '#options' => $event_options,
      '#default_value' => $civicrm_settings['event_id'],
      '#states' => [
        'visible' => [
          ':input[name="civicrm[enabled]"]' => ['checked' => TRUE],
          ':input[name="civicrm[entity]"]' => ['value' => 'event'],
        ],
      ],
    ];

    // Add participant role selection for events.
    $participant_role_options = $this->contactUpdater->getParticipantRoles();
    $form['civicrm']['participant_role_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Participant Role'),
      '#description' => $this->t('Select the role for event participants.'),
      '#options' => $participant_role_options,
      '#default_value' => $civicrm_settings['participant_role_id'] ?? '',
      '#states' => [
        'visible' => [
          ':input[name="civicrm[enabled]"]' => ['checked' => TRUE],
          ':input[name="civicrm[entity]"]' => ['value' => 'event'],
        ],
      ],
    ];

    // Add mailing group selection.
    $mailing_options = $this->contactUpdater->getMailingGroups();
    $form['civicrm']['mailing_group_id'] = [
      '#type' => 'select',
      '#title' => $this->t('CiviCRM Mailing Group'),
      '#description' => $this->t('Select the mailing group to add contacts to when this product is purchased.'),
      '#options' => $mailing_options,
      '#default_value' => $civicrm_settings['group'],
      '#states' => [
        'visible' => [
          ':input[name="civicrm[enabled]"]' => ['checked' => TRUE],
          ':input[name="civicrm[entity]"]' => ['value' => 'mailing'],
        ],
      ],
    ];

    // Add mailing subscription preferences.
    $form['civicrm']['mailing_preferences'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Mailing Preferences'),
      '#description' => $this->t('Select the subscription preferences for mailing list subscribers.'),
      '#options' => [
        'double_opt_in' => $this->t('Require double opt-in confirmation'),
        'send_welcome' => $this->t('Send welcome message'),
        'update_existing' => $this->t('Update existing subscribers'),
      ],
      '#default_value' => $civicrm_settings['mailing_preferences'] ?? [],
      '#states' => [
        'visible' => [
          ':input[name="civicrm[enabled]"]' => ['checked' => TRUE],
          ':input[name="civicrm[entity]"]' => ['value' => 'mailing'],
        ],
      ],
    ];

    // Add custom submit handler to save the CiviCRM settings.
    array_unshift($form['actions']['submit']['#submit'], 'commerce_civicrm_product_form_submit');
  }

  /**
   * Submit handler for the product form.
   *
   * @param array $form
   *   The form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitProductForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = $form_state->getFormObject()->getEntity();

    if (!$product->hasField('field_civicrm')) {
      return;
    }

    $civicrm_enabled = !empty($values['civicrm']['enabled']);
    $civicrm_entity = $values['civicrm']['entity'] ?? 'contribution';

    // Save settings as a JSON string; type references are stored by name so
    // the configuration is portable across sites with differing CiviCRM IDs.
    $settings = [
      'enabled' => $civicrm_enabled,
      'entity' => $civicrm_entity,
    ];

    if ($civicrm_enabled) {
      switch ($civicrm_entity) {
        case 'membership':
          $settings['membership_type'] = $values['civicrm']['membership_type'] ?? NULL;
          break;

        case 'contribution':
          $settings['financial_type'] = $values['civicrm']['contribution_type'] ?? NULL;
          break;

        case 'event':
          $settings['event_id'] = $values['civicrm']['event_id'] ?? NULL;
          $settings['participant_role_id'] = $values['civicrm']['participant_role_id'] ?? NULL;
          break;

        case 'mailing':
          $settings['group'] = $values['civicrm']['mailing_group_id'] ?? NULL;
          $settings['mailing_preferences'] = array_filter($values['civicrm']['mailing_preferences'] ?? []);
          break;
      }
    }

    $product->set('field_civicrm', json_encode($settings));
    // The main entity form submit handler will save the product.
  }

  /**
   * Gets CiviCRM settings for a product.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The Commerce product entity.
   *
   * @return array
   *   Array of CiviCRM settings with defaults.
   */
  public function getProductSettings(ProductInterface $product): array {
    $defaults = [
      'enabled' => FALSE,
      'entity' => 'contribution',
      'membership_type' => NULL,
      'financial_type' => NULL,
      'event_id' => NULL,
      'participant_role_id' => NULL,
      'group' => NULL,
      'mailing_preferences' => [],
    ];

    if (!$product->hasField('field_civicrm') || $product->get('field_civicrm')->isEmpty()) {
      return $defaults;
    }

    $civicrm_settings_raw = $product->get('field_civicrm')->value;
    $civicrm_settings = json_decode($civicrm_settings_raw, TRUE);

    return is_array($civicrm_settings) ? array_merge($defaults, $civicrm_settings) : $defaults;
  }

}
