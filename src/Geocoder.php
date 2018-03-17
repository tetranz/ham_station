<?php

namespace Drupal\ham_station;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\ham_station\Entity\HamStation;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Class to geocode addresses.
 */
class Geocoder {

  /**
   * Number of times to retry a bad http response.
   */
  const GEOCODE_MAX_RETRIES = 5;

  /**
   * The ham_station settings
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $settings;

  /**
   * Guzzle client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  private $httpClient;

  /**
   * @var \Drupal\Core\Entity\ContentEntityStorageInterface
   */
  private $hamStationStorage;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private $logger;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  private $dbConnection;

  /**
   * Geocoder constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactory $config_factory
   *   The config factory.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   Guzzle http client.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Database $db_connection
   *   The database connection.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    ConfigFactory $config_factory,
    ClientInterface $http_client,
    EntityTypeManagerInterface $entity_type_manager,
    Connection $db_connection,
    LoggerInterface $logger
  ) {
    $this->settings = $config_factory->get('ham_station.settings');
    $this->httpClient = $http_client;
    $this->hamStationStorage = $entity_type_manager->getStorage('ham_station');
    $this->dbConnection = $db_connection;
    $this->logger = $logger;
  }

  /**
   * Geocode a batch of addresses.
   *
   * @param callable $callback
   *   Optional callable used to report progress.
   */
  public function geoCode(callable $callback = NULL) {
    $google_key = $this->settings->get('google_geocode_key');

    if (empty($google_key)) {
      throw new \Exception('Google geocode key is not set.');
    }

    $batch_size = $this->settings->get('geocode_batch_size');

    if (!is_numeric($batch_size)) {
      throw new \Exception('Geocode batch size is not set.');
    }

    // Get a batch of entities with pending geocode status.
    // Where there are multiple stations at the same address, get only one.
    // Don't get stations where we have already successfully geocoded the same
    // address. We will fill these in with another query.
    $query = $this->dbConnection->select('ham_station', 'hs')
      ->fields('hs', ['id'])
      ->condition('hs.geocode_status', HamStation::GEOCODE_STATUS_PENDING)
      ->where('hs.id = (SELECT MIN(hs2.id) FROM {ham_station} hs2 WHERE hs2.address_hash = hs.address_hash)')
      ->where('NOT EXISTS (SELECT * FROM {ham_station} hs3 WHERE hs3.address_hash = hs.address_hash AND hs3.geocode_status = :success_status)', [
        ':success_status' => HamStation::GEOCODE_STATUS_SUCCESS
      ])
      ->range(0, $batch_size);

    // Using this mostly to get started with experimental queries.
    $extra_where = $this->settings->get('extra_batch_query_where');
    if (!empty($extra_where)) {
      $query->where($extra_where);
    }

    $entity_rows = $query->execute();

    $success_count = 0;
    $not_found_count = 0;
    $error_count = 0;
    
    foreach ($entity_rows as $entity_row) {
      $entity = HamStation::load($entity_row->id);
      $url = $this->getGeoCodeUrl($entity, $google_key);

      $retries = 0;

      do {
        $response = NULL;
        $request_success = TRUE;

        try {
          $response = $this->httpClient->request('GET', $url);
        }
        catch (GuzzleException $ex) {
          $request_success = FALSE;
          $this->logger->warning(sprintf(
            "Http exception while geocoding %s %s",
            $entity->getCallsign(),
            $ex->getMessage()
          ));
        }

        if ($request_success && $response->getStatusCode() != 200) {
          $request_success = FALSE;
          $this->logger->warning(sprintf(
            'Status code %s while geocoding %s',
            $response->getReasonPhrase(),
            $entity->getCallsign()
          ));
        }

      } while (!$request_success && ++$retries < static::GEOCODE_MAX_RETRIES);

      if (!$request_success) {
        $msg = sprintf('Excessive http errors while geocoding %s.', $entity->getCallsign());
        $this->logger->error($msg);
        $this->printFeedback($msg, $callback);
        // Not much point in continuing.
        break;
      }

      $response_json = (string) $response->getBody();
      $response_data = Json::decode($response_json);

      $status = $response_data['status'];

      if ($status === 'OVER_QUERY_LIMIT') {
        $this->logger->info('Geocoding query limit exceeded');
        $this->printFeedback($status, $callback);
        // Try again tomorrow.
        break;
      }

      if ($status === 'REQUEST_DENIED') {
        // Not sure what this really means. Let's stop and investigate.
        $msg = sprintf('Geocoding request denied for %s.', $entity->getCallsign());
        $this->logger->error($msg);
        $this->printFeedback($msg, $callback);
        $error_count++;
        break;
      }

      if ($status === 'INVALID_REQUEST') {
        // This should never happen. Let's stop and investigate.
        $msg = sprintf('Invalid geocoding request for %s.', $entity->getCallsign());
        $this->logger->error($msg);
        $this->printFeedback($msg, $callback);
        $error_count++;
        break;
      }

      // Looking good so far. We didn't get an "abandon ship" error.

      switch ($status) {
        case 'OK':
          $location = $response_data['results'][0]['geometry']['location'];
          $entity->field_location->lat = $location['lat'];
          $entity->field_location->lng = $location['lng'];
          $entity->geocode_status = HamStation::GEOCODE_STATUS_SUCCESS;
          $success_count++;
          break;

        case 'ZERO_RESULTS';
          $entity->geocode_status = HamStation::GEOCODE_STATUS_NOT_FOUND;
          $not_found_count++;
          break;

        case 'UNKNOWN_ERROR':
          // Probably a server error. Let's log it and move on for now.
          $this->logger->error(sprintf('Unknown error was geocoding %s.', $entity->getCallsign()));
          $error_count++;
          break;

        default:
          // This will only happpen if Google has a new response. Let's log it
          // and move on for now.
          $this->logger->error(sprintf('New response status %s while geocoding %s.',
            $status,
            $entity->getCallsign()
          ));
          $error_count++;
          break;
      }

      // Save the response for debugging.
      $entity->geocode_response = $response_json;
      $entity->save();
    }
    
    $msg = sprintf(
      'Geocode results: Success: %s | Not found: %s | Errors: %s',
      $success_count,
      $not_found_count,
      $error_count
    );

    $this->logger->info($msg);
    $this->printFeedback($msg, $callback);
  }

  /**
   * Generate the URL for geocoding a station.\
   *
   * @param \Drupal\ham_station\Entity\HamStation $entity
   *   The entity.
   * @param string $google_key
   *   Google Geocode API key.
   *
   * @return string
   *   The URL.
   */
  private function getGeoCodeUrl(HamStation $entity, $google_key) {
    $address = $entity->address;

    // See https://developers.google.com/maps/documentation/geocoding/start
    // This seems to be the correct format. i.e., postal code is not included
    // in the address. Adding it as a component filter seems to give a more
    // accurate response if the street address is not perfect.
    $url = Url::fromUri(
      'https://maps.googleapis.com/maps/api/geocode/json', [
        'query' => [
          'address' => sprintf('%s,%s,%s',
            $address->address_line1,
            $address->locality,
            $address->administrative_area
          ),
          'components' => sprintf('postal_code:%s|country:%s',
              $address->postalCode,
              $address->country_code
          ),
          'key' => $google_key,
        ],
      ]
    );

    return $url->toString();
  }

  /**
   * Copy successful geocode results to other licenses at the same address.
   *
   * @param callable $callback
   *   Optional callable used to report progress.
   */
  public function copyGeocodeForDuplicates(callable $callback) {
    // This avoids wasting our Google query quota on duplicates.
    $query = $this->dbConnection->select('ham_station', 'hs1');
    $query->addField('hs1', 'id', 'success_id');
    $query->addField('hs2', 'id', 'other_id');

    $query->innerJoin(
      'ham_station',
      'hs2',
      'hs2.address_hash = hs1.address_hash AND hs2.geocode_status <> :success_status',
      [':success_status' => HamStation::GEOCODE_STATUS_SUCCESS]
    );

    $rows = $query->condition('hs1.geocode_status', HamStation::GEOCODE_STATUS_SUCCESS)
      ->orderBy('hs1.id')
      ->execute();

    /** @var HamStation $success_entity */
    $success_entity = NULL;
    $update_count = 0;

    foreach ($rows as $row) {
      if (empty($success_entity) || $success_entity->id() != $row->success_id) {
        $success_entity = HamStation::load($row->success_id);
      }

      /** @var HamStation $other_entity */
      $other_entity = HamStation::load($row->other_id);

      $other_entity->field_location->lat = $success_entity->field_location->lat;
      $other_entity->field_location->lng = $success_entity->field_location->lng;
      $other_entity->geocode_response = $success_entity->geocode_response;
      $other_entity->geocode_status = $success_entity->geocode_status;
      $other_entity->save();
      $update_count++;
    }

    $msg = sprintf(
      '%s geocode results copied to duplicate addresses',
      $update_count
    );

    $this->logger->info($msg);
    $this->printFeedback($msg, $callback);
  }

  /**
   * Print feedback if a callback from supplied. Use for drush commands.
   * 
   * @param string $message
   *   The message to print.
   * @param callable|NULL $callback
   *   Callback.
   */
  private function printFeedback($message, callable $callback = NULL) {
    if ($callback !== NULL) {
      $callback($message);
    }
  }
}
