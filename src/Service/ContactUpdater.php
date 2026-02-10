<?php

namespace Drupal\commerce_civicrm\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\profile\Entity\ProfileInterface;
use Drupal\user\UserInterface;
use Drupal\commerce_civicrm\Service\CivicrmHelper;

/**
 * Service for updating CiviCRM contacts based on Commerce Order data.
 */
class ContactUpdater {

  use StringTranslationTrait;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Constructs a ContactUpdater object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $logger_factory,
    protected readonly CivicrmHelper $civicrmHelper,
  ) {
    $this->logger = $logger_factory->get('commerce_civicrm');
  }

  /**
   * Updates or creates a CiviCRM contact based on Commerce Order data.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   *
   * @return int|null
   *   The CiviCRM contact ID if successful, NULL otherwise.
   */
  public function updateContactFromOrder(OrderInterface $order): ?int {
    try {
      // Get the customer profile from the order
      $billing_profile = $order->getBillingProfile();
      if (!$billing_profile instanceof ProfileInterface) {
        $this->logger->warning('No billing profile found for order @order_id', [
          '@order_id' => $order->id(),
        ]);
        return NULL;
      }

      // Logging the billing profile ID for debugging
      $this->logger->debug('Updating CiviCRM contact for order @order_id with billing profile @profile_id', [
        '@order_id' => $order->id(),
        '@profile_id' => $billing_profile->id(),
      ]);

      // Extract contact data from the billing profile
      $contact_data = $this->extractContactData($billing_profile, $order);
      if (empty($contact_data)) {
        $this->logger->warning('No valid contact data extracted for order @order_id', [
          '@order_id' => $order->id(),
        ]);
        return NULL;
      }

      // Check if contact already exists
      $existing_contact_id = $this->findExistingContact($contact_data);
      if ($existing_contact_id === NULL) {
        $this->logger->info('No existing CiviCRM contact found for order @order_id, creating new contact', [
          '@order_id' => $order->id(),
        ]);
      } else {
        $this->logger->info('Found existing CiviCRM contact @contact_id for order @order_id', [
          '@contact_id' => $existing_contact_id,
          '@order_id' => $order->id(),
        ]);
      }
      
      if ($existing_contact_id) {
        // Update existing contact
        $this->logger->debug('Updating existing CiviCRM contact @contact_id for order @order_id', [
          '@contact_id' => $existing_contact_id,
          '@order_id' => $order->id(),
        ]);
        return $this->updateExistingContact($existing_contact_id, $contact_data);
      } else {
        // Create new contact
        $this->logger->debug('Creating new CiviCRM contact for order @order_id', [
          '@order_id' => $order->id(),
        ]);
        return $this->createNewContact($contact_data);
      }
    } catch (\CRM_Core_Exception $e) {
      $this->logger->error('Error updating CiviCRM contact for order @order_id: @error', [
        '@order_id' => $order->id(),
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Extracts contact data from billing profile and order.
   *
   * @param \Drupal\profile\Entity\ProfileInterface $profile
   *   The billing profile.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   *
   * @return array
   *   Array of contact data.
   */
  protected function extractContactData(ProfileInterface $profile, OrderInterface $order): array {
    $contact_data = [];
    $address_data = [];
    
    // Get address field if it exists
    if ($profile->hasField('address') && !$profile->get('address')->isEmpty()) {
      $address = $profile->get('address')->first()->getValue();
      
      $contact_data['first_name'] = $address['given_name'] ?? '';
      $contact_data['last_name'] = $address['family_name'] ?? '';

      $address_data['address_primary.street_address'] = $address['address_line1'] ?? '';
      $address_data['address_primary.supplemental_address_1'] = $address['address_line2'] ?? '';
      $address_data['address_primary.city'] = $address['locality'] ?? '';
      $address_data['address_primary.postal_code'] = $address['postal_code'] ?? '';

      if (!empty($address['administrative_area'])) {
        $address_data['address_primary.state_province_id:abbr'] = $address['administrative_area'];
      }
      if (!empty($address['country_code'])) {
        $address_data['address_primary.country_id:name'] = $address['country_code'];
      }
    }
    
    // Get email from order customer
    $customer = $order->getCustomer();
    if ($customer && $customer->getEmail()) {
      $contact_data['email'] = $customer->getEmail();
    }
    
    // Set contact type to Individual
    $contact_data['contact_type'] = 'Individual';

    $result = array_merge($contact_data, $address_data);

    // Logging contact data for debugging
    $this->logger->debug('Extracted contact data from order @order_id: @contact_data', [
      '@order_id' => $order->id(),
      '@contact_data' => json_encode($result),
    ]);
    
    return array_filter($result); // Remove empty values
  }

  /**
   * Finds an existing CiviCRM contact by email or name.
   *
   * @param array $contact_data
   *   The contact data array.
   *
   * @return int|null
   *   The existing contact ID if found, NULL otherwise.
   */
  protected function findExistingContact(array $contact_data): ?int {
    // Logging the contact data being searched
    $this->logger->debug('Searching for existing CiviCRM contact with data: @contact_data', [
      '@contact_data' => json_encode($contact_data),
    ]);

    try {
      // Initialize CiviCRM first
      if (!$this->civicrmHelper->initialize()) {
        return NULL;
      }
      
      // First try to find by email using Email entity directly
      if (!empty($contact_data['email'])) {
        // Logging the email search result
        $this->logger->debug('Searching for existing contact by email: @email', [
          '@email' => $contact_data['email'],
        ]);
        $email_result = \Civi\Api4\Email::get(FALSE)
          ->addSelect('contact_id')
          ->addWhere('email', '=', $contact_data['email'])
          // ->addWhere('is_primary', '=', TRUE)
          ->setLimit(1)
          ->execute();
        
        if ($email_result->count() > 0) {
          $this->logger->info('Found existing CiviCRM contact by email: @contact_id', [
            '@contact_id' => $email_result->first()['contact_id'],
          ]);

          // Logging the found contact ID
          $this->logger->debug('Found existing contact ID by email: @contact_id', [
            '@contact_id' => $email_result->first()['contact_id'],
          ]);
          return $email_result->first()['contact_id'];
        } else {
          $this->logger->debug('No existing contact found by email: @email', [
            '@email' => $contact_data['email'],
          ]);
        }
      }
      
      // If no email match, use dedupe rules or a safer name-based fallback.
      if (!empty($contact_data['first_name']) && !empty($contact_data['last_name'])) {
        // Prefer CiviCRM dedupe rules to avoid unsafe name-only matches.
        try {
          $dedupe_values = [
            'first_name' => $contact_data['first_name'],
            'last_name' => $contact_data['last_name'],
          ];
          if (!empty($contact_data['email'])) {
            $dedupe_values['email'] = $contact_data['email'];
          }

          $dedupe_result = \Civi\Api4\Contact::getDuplicates(FALSE)
            ->setValues($dedupe_values)
            ->setDedupeRule('Individual.Supervised')
            ->execute();

          if ($dedupe_result->count() === 1) {
            $contact_id = $dedupe_result->first()['id'];
            $this->logger->info('Found duplicate CiviCRM contact via dedupe rules: @contact_id', [
              '@contact_id' => $contact_id,
            ]);
            return $contact_id;
          }

          if ($dedupe_result->count() > 1) {
            $this->logger->warning('Multiple CiviCRM contacts match dedupe rules for @first @last — skipping to avoid ambiguity', [
              '@first' => $contact_data['first_name'],
              '@last' => $contact_data['last_name'],
            ]);
            return NULL;
          }
        } catch (\CRM_Core_Exception $e) {
          $this->logger->warning('CiviCRM dedupe check failed, falling back to restricted name lookup: @error', [
            '@error' => $e->getMessage(),
          ]);
        }

        // Safer fallback: restrict to Individuals and require a unique match.
        $query = \Civi\Api4\Contact::get(FALSE)
          ->addSelect('id')
          ->addWhere('contact_type', '=', 'Individual')
          ->addWhere('first_name', '=', $contact_data['first_name'])
          ->addWhere('last_name', '=', $contact_data['last_name']);

        if (!empty($contact_data['email'])) {
          $query->addWhere('email_primary.email', '=', $contact_data['email']);
        }

        $result = $query->setLimit(2)->execute();

        if ($result->count() === 1) {
          $this->logger->info('Found unique CiviCRM contact by name: @contact_id', [
            '@contact_id' => $result->first()['id'],
          ]);
          return $result->first()['id'];
        }

        if ($result->count() > 1) {
          $this->logger->warning('Multiple CiviCRM contacts match name @first @last — creating new contact to avoid merge error', [
            '@first' => $contact_data['first_name'],
            '@last' => $contact_data['last_name'],
          ]);
          return NULL;
        }
      }

      // Logging if no existing contact found
      $this->logger->info('No existing CiviCRM contact found for provided data: @contact_data', [
        '@contact_data' => json_encode($contact_data),
      ]);

    } catch (\CRM_Core_Exception $e) {
      $this->logger->error('Error finding existing CiviCRM contact: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
    
    return NULL;
  }

  /**
   * Updates an existing CiviCRM contact.
   *
   * @param int $contact_id
   *   The contact ID to update.
   * @param array $contact_data
   *   The contact data array.
   *
   * @return int|null
   *   The contact ID if successful, NULL otherwise.
   */
  protected function updateExistingContact($contact_id, array $contact_data): ?int {
    try {
      // Initialize CiviCRM first
      if (!$this->civicrmHelper->initialize()) {
        return NULL;
      }
      
      // Remove email from contact data as it's handled separately
      $email = $contact_data['email'] ?? NULL;
      unset($contact_data['email']);
      unset($contact_data['contact_type']);
      
      // Add the ID to the contact data for the update
      $contact_data['id'] = $contact_id;
      
      // Update the contact
      $result = \Civi\Api4\Contact::update(FALSE)
        ->setValues($contact_data)
        ->execute();
      
      if ($result->count() > 0) {
        $this->logger->info('Updated CiviCRM contact @contact_id', [
          '@contact_id' => $contact_id,
        ]);
        
        // Update email if provided
        if ($email) {
          $this->updateContactEmail($contact_id, $email);
        }
        
        return $contact_id;
      }
    } catch (\CRM_Core_Exception $e) {
      $this->logger->error('Error updating CiviCRM contact @contact_id: @error', [
        '@contact_id' => $contact_id,
        '@error' => $e->getMessage(),
      ]);
    }
    
    return NULL;
  }

  /**
   * Creates a new CiviCRM contact.
   *
   * @param array $contact_data
   *   The contact data array.
   *
   * @return int|null
   *   The new contact ID if successful, NULL otherwise.
   */
  protected function createNewContact(array $contact_data): ?int {
    try {
      // Initialize CiviCRM first
      if (!$this->civicrmHelper->initialize()) {
        return NULL;
      }
      
      // Remove email from contact data as it's handled separately
      $email = $contact_data['email'] ?? NULL;
      unset($contact_data['email']);
      
      // Create the contact
      $result = \Civi\Api4\Contact::create(FALSE)
        ->setValues($contact_data)
        ->execute();
      
      if ($result->count() > 0) {
        $contact_id = $result->first()['id'];
        $this->logger->info('Created new CiviCRM contact @contact_id', [
          '@contact_id' => $contact_id,
        ]);
        
        // Add email if provided
        if ($email) {
          $this->updateContactEmail($contact_id, $email);
        }
        
        return $contact_id;
      }
    } catch (\CRM_Core_Exception $e) {
      $this->logger->error('Error creating CiviCRM contact: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
    
    return NULL;
  }

  /**
   * Updates or creates an email for a contact.
   *
   * @param int $contact_id
   *   The contact ID.
   * @param string $email
   *   The email address.
   *
   * @return void
   */
  protected function updateContactEmail($contact_id, $email): void {
    try {
      // Initialize CiviCRM first
      if (!$this->civicrmHelper->initialize()) {
        return;
      }
      
      // Check if email already exists for this contact
      $existing_email = \Civi\Api4\Email::get(FALSE)
        ->addSelect('id')
        ->addWhere('contact_id', '=', $contact_id)
        ->addWhere('is_primary', '=', TRUE)
        ->setLimit(1)
        ->execute();
      
      if ($existing_email->count() > 0) {
        // Update existing email
        \Civi\Api4\Email::update(FALSE)
          ->addValue('id', $existing_email->first()['id'])
          ->addValue('email', $email)
          ->execute();
      } else {
        // Create new email
        \Civi\Api4\Email::create(FALSE)
          ->addValue('contact_id', $contact_id)
          ->addValue('email', $email)
          ->addValue('is_primary', TRUE)
          ->execute();
      }
    } catch (\CRM_Core_Exception $e) {
      $this->logger->error('Error updating email for contact @contact_id: @error', [
        '@contact_id' => $contact_id,
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Gets the CiviCRM contact ID for a Drupal user.
   *
   * @param \Drupal\user\UserInterface $user
   *   The Drupal user.
   *
   * @return int|null
   *   The CiviCRM contact ID if found, NULL otherwise.
   */
  public function getContactIdByUser(UserInterface $user): ?int {
    try {
      // Initialize CiviCRM
      if (!$this->civicrmHelper->initialize()) {
        return NULL;
      }

      $result = \Civi\Api4\UFMatch::get(FALSE)
        ->addWhere('uf_id', '=', $user->id())
        ->execute();
      
      if ($result->count() > 0) {
        return $result->first()['contact_id'];
      }
      
      $this->logger->warning('No CiviCRM contact found for user @uid', [
        '@uid' => $user->id(),
      ]);
      return NULL;
    } catch (\CRM_Core_Exception $e) {
      $this->logger->error('Error finding CiviCRM contact for user @uid: @error', [
        '@uid' => $user->id(),
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Gets available CiviCRM financial types.
   *
   * @return array
   *   Array of financial type options keyed by ID.
   */
  public function getFinancialTypes(): array {
    $options = [];

    try {
      // Initialize CiviCRM
      if (!$this->civicrmHelper->initialize()) {
        return ['' => $this->t('CiviCRM not available')];
      }

      $result = \Civi\Api4\FinancialType::get(FALSE)
        ->addSelect('id', 'name', 'label')
        ->addWhere('is_active', '=', TRUE)
        ->addOrderBy('label', 'ASC')
        ->setLimit(0)
        ->execute();

      foreach ($result as $type) {
        $options[$type['id']] = $type['label'];
      }

      if (empty($options)) {
        $options[''] = $this->t('No active financial types found');
      }
    } catch (\CRM_Core_Exception $e) {
      $this->logger->error('Failed to retrieve CiviCRM Financial Types: @error', [
        '@error' => $e->getMessage(),
      ]);
      $options[''] = $this->t('Error loading financial types');
    }

    return $options;
  }

  /**
   * Gets available CiviCRM events.
   *
   * @return array
   *   Array of event options keyed by ID.
   */
  public function getEvents(): array {
    $options = ['' => $this->t('- Select an event -')];

    try {
      // Initialize CiviCRM
      if (!$this->civicrmHelper->initialize()) {
        return ['' => $this->t('CiviCRM not available')];
      }

      $result = \Civi\Api4\Event::get(FALSE)
        ->addSelect('id', 'title', 'start_date')
        ->addWhere('is_active', '=', TRUE)
        ->addOrderBy('start_date', 'DESC')
        ->setLimit(0)
        ->execute();

      foreach ($result as $event) {
        $options[$event['id']] = $event['title'];
      }
    } catch (\CRM_Core_Exception $e) {
      $this->logger->error('Failed to retrieve CiviCRM Events: @error', [
        '@error' => $e->getMessage(),
      ]);
      return ['' => $this->t('Error loading events')];
    }

    return $options;
  }

  /**
   * Gets available CiviCRM participant roles.
   *
   * @return array
   *   Array of participant role options keyed by value.
   */
  public function getParticipantRoles(): array {
    $options = ['' => $this->t('- Select a participant role -')];

    try {
      // Initialize CiviCRM
      if (!$this->civicrmHelper->initialize()) {
        return ['' => $this->t('CiviCRM not available')];
      }

      $result = \Civi\Api4\OptionValue::get(FALSE)
        ->addSelect('value', 'label')
        ->addWhere('option_group_id:name', '=', 'participant_role')
        ->addWhere('is_active', '=', TRUE)
        ->addOrderBy('weight', 'ASC')
        ->setLimit(0)
        ->execute();

      foreach ($result as $role) {
        $options[$role['value']] = $role['label'];
      }
    } catch (\CRM_Core_Exception $e) {
      $this->logger->error('Failed to retrieve CiviCRM Participant Roles: @error', [
        '@error' => $e->getMessage(),
      ]);
      return ['' => $this->t('Error loading participant roles')];
    }

    return $options;
  }

  /**
   * Gets available CiviCRM mailing groups.
   *
   * @return array
   *   Array of mailing group options keyed by ID.
   */
  public function getMailingGroups(): array {
    $options = ['' => $this->t('- Select a mailing group -')];

    try {
      // Initialize CiviCRM
      if (!$this->civicrmHelper->initialize()) {
        return ['' => $this->t('CiviCRM not available')];
      }

      $result = \Civi\Api4\Group::get(FALSE)
        ->addSelect('id', 'title')
        ->addWhere('is_active', '=', TRUE)
        ->addWhere('group_type:name', 'CONTAINS', 'Mailing List')
        ->addOrderBy('title', 'ASC')
        ->setLimit(0)
        ->execute();

      foreach ($result as $group) {
        $options[$group['id']] = $group['title'];
      }
    } catch (\CRM_Core_Exception $e) {
      $this->logger->error('Failed to retrieve CiviCRM Mailing Groups: @error', [
        '@error' => $e->getMessage(),
      ]);
      return ['' => $this->t('Error loading mailing groups')];
    }

    return $options;
  }

}
