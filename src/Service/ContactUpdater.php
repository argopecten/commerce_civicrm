<?php

namespace Drupal\commerce_civicrm\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\civicrm_tools\CivicrmToolsInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\profile\Entity\ProfileInterface;
use Drupal\user\UserInterface;

/**
 * Service for updating CiviCRM contacts based on Commerce Order data.
 */
class ContactUpdater {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The CiviCRM tools service.
   *
   * @var \Drupal\civicrm_tools\CivicrmToolsInterface
   */
  protected $civicrmTools;

  /**
   * Constructs a ContactUpdater object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\civicrm_tools\CivicrmToolsInterface $civicrm_tools
   *   The CiviCRM tools service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger_factory, CivicrmToolsInterface $civicrm_tools) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('commerce_civicrm');
    $this->civicrmTools = $civicrm_tools;
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
  public function updateContactFromOrder(OrderInterface $order) {
    try {
      // Get the customer profile from the order
      $billing_profile = $order->getBillingProfile();
      if (!$billing_profile instanceof ProfileInterface) {
        $this->logger->warning('No billing profile found for order @order_id', [
          '@order_id' => $order->id(),
        ]);
        return NULL;
      }

      // Extract contact data from the billing profile
      $contact_data = $this->extractContactData($billing_profile, $order);
      
      // Check if contact already exists
      $existing_contact_id = $this->findExistingContact($contact_data);
      
      if ($existing_contact_id) {
        // Update existing contact
        return $this->updateExistingContact($existing_contact_id, $contact_data);
      } else {
        // Create new contact
        return $this->createNewContact($contact_data);
      }
    } catch (\Exception $e) {
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
  protected function extractContactData(ProfileInterface $profile, OrderInterface $order) {
    $contact_data = [];
    
    // Get address field if it exists
    if ($profile->hasField('address') && !$profile->get('address')->isEmpty()) {
      $address = $profile->get('address')->first()->getValue();
      
      $contact_data['first_name'] = $address['given_name'] ?? '';
      $contact_data['last_name'] = $address['family_name'] ?? '';
      $contact_data['street_address'] = $address['address_line1'] ?? '';
      $contact_data['supplemental_address_1'] = $address['address_line2'] ?? '';
      $contact_data['city'] = $address['locality'] ?? '';
      $contact_data['postal_code'] = $address['postal_code'] ?? '';
      $contact_data['state_province'] = $address['administrative_area'] ?? '';
      $contact_data['country'] = $address['country_code'] ?? '';
    }
    
    // Get email from order customer
    $customer = $order->getCustomer();
    if ($customer && $customer->getEmail()) {
      $contact_data['email'] = $customer->getEmail();
    }
    
    // Set contact type to Individual
    $contact_data['contact_type'] = 'Individual';
    
    return array_filter($contact_data); // Remove empty values
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
  protected function findExistingContact(array $contact_data) {
    try {
      $api = $this->civicrmTools->getApi();
      
      // First try to find by email
      if (!empty($contact_data['email'])) {
        $result = $api->Contact->get(FALSE)
          ->addSelect('id')
          ->addWhere('email_primary.email', '=', $contact_data['email'])
          ->setLimit(1)
          ->execute();
        
        if ($result->count() > 0) {
          return $result->first()['id'];
        }
      }
      
      // If no email match, try by first and last name
      if (!empty($contact_data['first_name']) && !empty($contact_data['last_name'])) {
        $result = $api->Contact->get(FALSE)
          ->addSelect('id')
          ->addWhere('first_name', '=', $contact_data['first_name'])
          ->addWhere('last_name', '=', $contact_data['last_name'])
          ->setLimit(1)
          ->execute();
        
        if ($result->count() > 0) {
          return $result->first()['id'];
        }
      }
    } catch (\Exception $e) {
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
  protected function updateExistingContact($contact_id, array $contact_data) {
    try {
      $api = $this->civicrmTools->getApi();
      
      // Remove email from contact data as it's handled separately
      $email = $contact_data['email'] ?? NULL;
      unset($contact_data['email']);
      
      // Update the contact
      $result = $api->Contact->update(FALSE)
        ->addValue('id', $contact_id)
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
    } catch (\Exception $e) {
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
  protected function createNewContact(array $contact_data) {
    try {
      $api = $this->civicrmTools->getApi();
      
      // Remove email from contact data as it's handled separately
      $email = $contact_data['email'] ?? NULL;
      unset($contact_data['email']);
      
      // Create the contact
      $result = $api->Contact->create(FALSE)
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
    } catch (\Exception $e) {
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
   */
  protected function updateContactEmail($contact_id, $email) {
    try {
      $api = $this->civicrmTools->getApi();
      
      // Check if email already exists for this contact
      $existing_email = $api->Email->get(FALSE)
        ->addSelect('id')
        ->addWhere('contact_id', '=', $contact_id)
        ->addWhere('is_primary', '=', TRUE)
        ->setLimit(1)
        ->execute();
      
      if ($existing_email->count() > 0) {
        // Update existing email
        $api->Email->update(FALSE)
          ->addValue('id', $existing_email->first()['id'])
          ->addValue('email', $email)
          ->execute();
      } else {
        // Create new email
        $api->Email->create(FALSE)
          ->addValue('contact_id', $contact_id)
          ->addValue('email', $email)
          ->addValue('is_primary', TRUE)
          ->execute();
      }
    } catch (\Exception $e) {
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
  public function getContactIdByUser(UserInterface $user) {
    try {
      $api = $this->civicrmTools->getApi();
      
      $result = $api->UFMatch->get(FALSE)
        ->addWhere('uf_id', '=', $user->id())
        ->execute();
      
      if ($result->count() > 0) {
        return $result->first()['contact_id'];
      }
      
      $this->logger->warning('No CiviCRM contact found for user @uid', [
        '@uid' => $user->id(),
      ]);
      return NULL;
    } catch (\Exception $e) {
      $this->logger->error('Error finding CiviCRM contact for user @uid: @error', [
        '@uid' => $user->id(),
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Gets available CiviCRM membership types.
   *
   * @return array
   *   Array of membership type options keyed by ID.
   */
  public function getMembershipTypes() {
    $options = [];

    try {
      $api = $this->civicrmTools->getApi();
      
      $result = $api->MembershipType->get(FALSE)
        ->addWhere('is_active', '=', TRUE)
        ->addOrderBy('name', 'ASC')
        ->setLimit(0)
        ->execute();

      foreach ($result as $type) {
        $options[$type['id']] = $type['name'];
      }

      if (empty($options)) {
        $options[''] = t('No active membership types found');
      }
    } catch (\Exception $e) {
      $this->logger->error('Failed to retrieve CiviCRM Membership Types: @error', [
        '@error' => $e->getMessage(),
      ]);
      $options[''] = t('Error loading membership types');
    }

    return $options;
  }

  /**
   * Gets available CiviCRM financial types.
   *
   * @return array
   *   Array of financial type options keyed by ID.
   */
  public function getFinancialTypes() {
    $options = [];

    try {
      $api = $this->civicrmTools->getApi();
      
      $result = $api->FinancialType->get(FALSE)
        ->addWhere('is_active', '=', TRUE)
        ->addOrderBy('name', 'ASC')
        ->setLimit(0)
        ->execute();

      foreach ($result as $type) {
        $options[$type['id']] = $type['name'];
      }

      if (empty($options)) {
        $options[''] = t('No active financial types found');
      }
    } catch (\Exception $e) {
      $this->logger->error('Failed to retrieve CiviCRM Financial Types: @error', [
        '@error' => $e->getMessage(),
      ]);
      $options[''] = t('Error loading financial types');
    }

    return $options;
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
  public function getProductSettings($product) {
    $defaults = [
      'enabled' => FALSE,
      'entity' => 'contribution',
      'entity_id' => NULL,
    ];

    if (!$product->hasField('field_civicrm') || $product->get('field_civicrm')->isEmpty()) {
      return $defaults;
    }

    $civicrm_settings_raw = $product->get('field_civicrm')->value;
    $civicrm_settings = json_decode($civicrm_settings_raw, TRUE);

    return is_array($civicrm_settings) ? array_merge($defaults, $civicrm_settings) : $defaults;
  }

  /**
   * Checks if a product has CiviCRM integration enabled.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The Commerce product entity.
   *
   * @return bool
   *   TRUE if CiviCRM integration is enabled for this product.
   */
  public function isProductCivicrmEnabled($product) {
    $settings = $this->getProductSettings($product);
    return !empty($settings['enabled']);
  }

}
