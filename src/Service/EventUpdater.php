<?php

namespace Drupal\commerce_civicrm\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\civicrm_tools\CivicrmToolsInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;

/**
 * Service for updating CiviCRM events based on Commerce Order data.
 */
class EventUpdater {

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
   * Constructs an EventUpdater object.
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
   * Creates CiviCRM event registrations based on Commerce Order data.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   * @param int $contact_id
   *   The CiviCRM contact ID.
   *
   * @return array
   *   Array of participant IDs created.
   */
  public function createEventRegistrationsFromOrder(OrderInterface $order, $contact_id) {
    $participant_ids = [];
    
    try {
      // Process each order item to check for event products
      foreach ($order->getItems() as $order_item) {
        $participant_id = $this->processOrderItemForEvent($order_item, $contact_id, $order);
        if ($participant_id) {
          $participant_ids[] = $participant_id;
        }
      }
    } catch (\Exception $e) {
      $this->logger->error('Error creating CiviCRM event registrations for order @order_id: @error', [
        '@order_id' => $order->id(),
        '@error' => $e->getMessage(),
      ]);
    }
    
    return $participant_ids;
  }

  /**
   * Processes an order item to check if it's an event product.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   The order item.
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   *
   * @return int|null
   *   The participant ID if created, NULL otherwise.
   */
  protected function processOrderItemForEvent(OrderItemInterface $order_item, $contact_id, OrderInterface $order) {
    try {
      // Get the purchased entity (product variation)
      $purchased_entity = $order_item->getPurchasedEntity();
      if (!$purchased_entity) {
        return NULL;
      }
      
      // Get the product from the variation
      $product = $purchased_entity->getProduct();
      if (!$product) {
        return NULL;
      }
      
      // Check if this product is mapped to a CiviCRM event
      $event_id = $this->getEventIdForProduct($product);
      if (!$event_id) {
        return NULL;
      }
      
      // Check if participant already exists
      $existing_participant_id = $this->findExistingParticipant($event_id, $contact_id, $order);
      if ($existing_participant_id) {
        $this->logger->info('Participant already exists for event @event_id, contact @contact_id: @participant_id', [
          '@event_id' => $event_id,
          '@contact_id' => $contact_id,
          '@participant_id' => $existing_participant_id,
        ]);
        return $existing_participant_id;
      }
      
      // Create event registration
      return $this->createEventRegistration($event_id, $contact_id, $order_item, $order);
    } catch (\Exception $e) {
      $this->logger->error('Error processing order item @item_id for event: @error', [
        '@item_id' => $order_item->id(),
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Gets the CiviCRM event ID for a Commerce product.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The Commerce product.
   *
   * @return int|null
   *   The CiviCRM event ID if found, NULL otherwise.
   */
  protected function getEventIdForProduct($product) {
    try {
      // Check if product has a field mapping to CiviCRM event
      // This assumes you have a field called 'field_civicrm_event_id' on your product
      if ($product->hasField('field_civicrm_event_id') && !$product->get('field_civicrm_event_id')->isEmpty()) {
        return $product->get('field_civicrm_event_id')->value;
      }
      
      // Alternative: Map by product title or SKU
      // You could implement a mapping table or configuration here
      $event_id = $this->findEventByTitle($product->getTitle());
      if ($event_id) {
        return $event_id;
      }
      
    } catch (\Exception $e) {
      $this->logger->error('Error getting event ID for product @product_id: @error', [
        '@product_id' => $product->id(),
        '@error' => $e->getMessage(),
      ]);
    }
    
    return NULL;
  }

  /**
   * Finds a CiviCRM event by title.
   *
   * @param string $title
   *   The event title to search for.
   *
   * @return int|null
   *   The event ID if found, NULL otherwise.
   */
  protected function findEventByTitle($title) {
    try {
      $api = $this->civicrmTools->getApi();
      
      $result = $api->Event->get(FALSE)
        ->addSelect('id')
        ->addWhere('title', '=', $title)
        ->addWhere('is_active', '=', TRUE)
        ->setLimit(1)
        ->execute();
      
      if ($result->count() > 0) {
        return $result->first()['id'];
      }
    } catch (\Exception $e) {
      $this->logger->error('Error finding event by title: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
    
    return NULL;
  }

  /**
   * Finds an existing participant for the given event, contact, and order.
   *
   * @param int $event_id
   *   The CiviCRM event ID.
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   *
   * @return int|null
   *   The existing participant ID if found, NULL otherwise.
   */
  protected function findExistingParticipant($event_id, $contact_id, OrderInterface $order) {
    try {
      $api = $this->civicrmTools->getApi();
      
      $result = $api->Participant->get(FALSE)
        ->addSelect('id')
        ->addWhere('event_id', '=', $event_id)
        ->addWhere('contact_id', '=', $contact_id)
        ->addWhere('source', 'LIKE', '%Order #' . $order->id() . '%')
        ->setLimit(1)
        ->execute();
      
      if ($result->count() > 0) {
        return $result->first()['id'];
      }
    } catch (\Exception $e) {
      $this->logger->error('Error finding existing participant: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
    
    return NULL;
  }

  /**
   * Creates a new event registration (participant).
   *
   * @param int $event_id
   *   The CiviCRM event ID.
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   The order item.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   *
   * @return int|null
   *   The new participant ID if successful, NULL otherwise.
   */
  protected function createEventRegistration($event_id, $contact_id, OrderItemInterface $order_item, OrderInterface $order) {
    try {
      $api = $this->civicrmTools->getApi();
      
      // Prepare participant data
      $participant_data = [
        'event_id' => $event_id,
        'contact_id' => $contact_id,
        'status_id' => $this->getParticipantStatusId('Registered'),
        'role_id' => $this->getParticipantRoleId('Attendee'),
        'register_date' => date('Y-m-d H:i:s'),
        'source' => 'Commerce Order #' . $order->id(),
        'fee_amount' => $order_item->getTotalPrice()->getNumber(),
        'fee_currency' => $order_item->getTotalPrice()->getCurrencyCode(),
      ];
      
      // Create the participant
      $result = $api->Participant->create(FALSE)
        ->setValues($participant_data)
        ->execute();
      
      if ($result->count() > 0) {
        $participant_id = $result->first()['id'];
        $this->logger->info('Created new CiviCRM participant @participant_id for event @event_id', [
          '@participant_id' => $participant_id,
          '@event_id' => $event_id,
        ]);
        return $participant_id;
      }
    } catch (\Exception $e) {
      $this->logger->error('Error creating CiviCRM participant: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
    
    return NULL;
  }

  /**
   * Gets the participant status ID by name.
   *
   * @param string $status_name
   *   The status name.
   *
   * @return int|null
   *   The status ID if found, NULL otherwise.
   */
  protected function getParticipantStatusId($status_name) {
    try {
      $api = $this->civicrmTools->getApi();
      
      $result = $api->OptionValue->get(FALSE)
        ->addSelect('value')
        ->addWhere('option_group_id:name', '=', 'participant_status')
        ->addWhere('name', '=', $status_name)
        ->setLimit(1)
        ->execute();
      
      if ($result->count() > 0) {
        return $result->first()['value'];
      }
    } catch (\Exception $e) {
      $this->logger->error('Error getting participant status ID: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
    
    return NULL;
  }

  /**
   * Gets the participant role ID by name.
   *
   * @param string $role_name
   *   The role name.
   *
   * @return int|null
   *   The role ID if found, NULL otherwise.
   */
  protected function getParticipantRoleId($role_name) {
    try {
      $api = $this->civicrmTools->getApi();
      
      $result = $api->OptionValue->get(FALSE)
        ->addSelect('value')
        ->addWhere('option_group_id:name', '=', 'participant_role')
        ->addWhere('name', '=', $role_name)
        ->setLimit(1)
        ->execute();
      
      if ($result->count() > 0) {
        return $result->first()['value'];
      }
    } catch (\Exception $e) {
      $this->logger->error('Error getting participant role ID: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
    
    return NULL;
  }

  /**
   * Updates an existing event registration.
   *
   * @param int $participant_id
   *   The participant ID.
   * @param array $participant_data
   *   The participant data to update.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function updateEventRegistration($participant_id, array $participant_data) {
    try {
      $api = $this->civicrmTools->getApi();
      
      $result = $api->Participant->update(FALSE)
        ->addValue('id', $participant_id)
        ->setValues($participant_data)
        ->execute();
      
      if ($result->count() > 0) {
        $this->logger->info('Updated CiviCRM participant @participant_id', [
          '@participant_id' => $participant_id,
        ]);
        return TRUE;
      }
    } catch (\Exception $e) {
      $this->logger->error('Error updating CiviCRM participant @participant_id: @error', [
        '@participant_id' => $participant_id,
        '@error' => $e->getMessage(),
      ]);
    }
    
    return FALSE;
  }

}
