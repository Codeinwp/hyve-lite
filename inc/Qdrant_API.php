<?php
/**
 * Qdrant_API class.
 * 
 * @package Codeinwp/HyveLite
 */

namespace ThemeIsle\HyveLite;

use ThemeIsle\HyveLite\Main;
use Qdrant\Qdrant;
use Qdrant\Config;
use Qdrant\Http\Builder;
use Qdrant\Endpoints\Collections;
use Qdrant\Models\PointsStruct;
use Qdrant\Models\PointStruct;
use Qdrant\Models\VectorStruct;
use Qdrant\Models\Filter\Filter;
use Qdrant\Models\Filter\Condition\MatchString;
use Qdrant\Models\Request\CreateCollection;
use Qdrant\Models\Request\SearchRequest;
use Qdrant\Models\Request\VectorParams;

/**
 * Qdrant_API class.
 */
class Qdrant_API {
	/**
	 * Collection name.
	 * 
	 * @var string
	 */
	const COLLECTION_NAME = 'hyve';

	/**
	 * Qdrant Client.
	 * 
	 * @var Qdrant
	 */
	public $client;

	/**
	 * The single instance of the class.
	 *
	 * @var Qdrant_API
	 */
	private static $instance = null;

	/**
	 * Ensures only one instance of the class is loaded.
	 *
	 * @return Qdrant_API An instance of the class.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 * 
	 * @param string $api_key  API Key.
	 * @param string $endpoint Endpoint.
	 */
	public function __construct( $api_key = '', $endpoint = '' ) {
		$settings = Main::get_settings();
		$api_key  = ! empty( $api_key ) ? $api_key : $settings['qdrant_api_key'];
		$endpoint = ! empty( $endpoint ) ? $endpoint : $settings['qdrant_endpoint'];

		if ( empty( $api_key ) || empty( $endpoint ) ) {
			return;
		}

		$config = new Config( $endpoint );
		$config->setApiKey( $api_key );

		$transport    = ( new Builder() )->build( $config );
		$this->client = new Qdrant( $transport );

		add_action( 'hyve_lite_migrate_data', array( $this, 'migrate_data' ) );
	}

	/**
	 * Initialize Qdrant Integration.
	 * 
	 * @return bool|\WP_Error
	 */
	public function init() {
		$collection_exists = $this->collection_exists();

		if ( is_wp_error( $collection_exists ) ) {
			return $collection_exists;
		}

		if ( ! $collection_exists ) {
			$create_collection = $this->create_collection();

			if ( is_wp_error( $create_collection ) ) {
				return $create_collection;
			}
		}

		update_option( 'hyve_qdrant_status', 'active' );

		wp_schedule_single_event( time(), 'hyve_lite_migrate_data' );

		return true;
	}

	/**
	 * Check if collection exists.
	 * 
	 * @return bool|\WP_Error
	 */ 
	public function collection_exists() {
		try {
			$collections = ( new Collections( $this->client ) )->setCollectionName( self::COLLECTION_NAME );
			$response    = $collections->exists();
			return $response['result']['exists'];
		} catch ( \Exception $e ) {
			if ( 403 === $e->getCode() ) {
				update_option( 'hyve_qdrant_status', 'inactive' );
			}

			return new \WP_Error( 'collection_error', $e->getMessage() );
		}
	}

	/**
	 * Create collection.
	 * 
	 * @return bool|\WP_Error
	 */
	public function create_collection() {
		try {
			$collection = new CreateCollection();
			$collection->addVector( new VectorParams( 1536, VectorParams::DISTANCE_COSINE ), 'embeddings' );
			$response = $this->client->collections( self::COLLECTION_NAME )->create( $collection );
			return $response['result'];
		} catch ( \Exception $e ) {
			if ( 403 === $e->getCode() ) {
				update_option( 'hyve_qdrant_status', 'inactive' );
			}

			return new \WP_Error( 'collection_error', $e->getMessage() );
		}
	}

	/**
	 * Add point to collection.
	 * 
	 * @param int   $id         ID.
	 * @param array $embeddings Embeddings.
	 * @param array $data       Data.
	 * 
	 * @return bool|\WP_Error
	 */
	public function add_point( $id, $embeddings, $data ) {
		try {
			$points = new PointsStruct();

			$points->addPoint(
				new PointStruct(
					(int) $id,
					new VectorStruct( $embeddings, 'embeddings' ),
					$data
				)
			);

			$response = $this->client->collections( self::COLLECTION_NAME )->points()->upsert( $points, array( 'wait' => 'true' ) );

			return 'completed' === $response['result']['status'];
		} catch ( \Exception $e ) {
			if ( 403 === $e->getCode() ) {
				update_option( 'hyve_qdrant_status', 'inactive' );
			}

			return new \WP_Error( 'collection_error', $e->getMessage() );
		}
	}


	/**
	 * Add point to collection.
	 * 
	 * @param array $points Points.
	 * 
	 * @return bool|\WP_Error
	 */
	public function add_points( $points ) {
		try {
			$points_struct = new PointsStruct();

			foreach ( $points as $point ) {
				$points_struct->addPoint(
					new PointStruct(
						(int) $point['id'],
						new VectorStruct( $point['embeddings'], 'embeddings' ),
						$point['data']
					)
				);
			}

			$response = $this->client->collections( self::COLLECTION_NAME )->points()->upsert( $points_struct, array( 'wait' => 'true' ) );

			return 'completed' === $response['result']['status'];
		} catch ( \Exception $e ) {
			if ( 403 === $e->getCode() ) {
				update_option( 'hyve_qdrant_status', 'inactive' );
			}

			return new \WP_Error( 'collection_error', $e->getMessage() );
		}
	}

	/**
	 * Delete point from collection.
	 * 
	 * @param int $id ID.
	 * 
	 * @return bool|\WP_Error
	 */
	public function delete_point( $id ) {
		try {
			$response = $this->client->collections( self::COLLECTION_NAME )->points()->deleteByFilter(
				( new Filter() )->addMust(
					new MatchString( 'post_id', $id )
				),
				array( 'wait' => 'true' )
			);

			return 'completed' === $response['result']['status'];
		} catch ( \Exception $e ) {
			if ( 403 === $e->getCode() ) {
				update_option( 'hyve_qdrant_status', 'inactive' );
			}

			return new \WP_Error( 'collection_error', $e->getMessage() );
		}
	}

	/**
	 * Search collection.
	 * 
	 * @param array $embeddings Embeddings.
	 * 
	 * @return array|\WP_Error
	 */
	public function search( $embeddings ) {
		try {
			$search = (
				new SearchRequest(
					new VectorStruct( $embeddings, 'embeddings' )
				)
				)
				->setLimit( 10 )
				->setWithPayload( true );

			$response = $this->client->collections( self::COLLECTION_NAME )->points()->search( $search );

			if ( empty( $response['result'] ) ) {
				return array();
			}

			$results = $response['result'];

			$payload = array_map(
				function ( $result ) {
					$payload          = $result['payload'];
					$payload['score'] = $result['score'];
					return $payload;
				},
				$results
			);

			return $payload;
		} catch ( \Exception $e ) {
			if ( 403 === $e->getCode() ) {
				update_option( 'hyve_qdrant_status', 'inactive' );
			}

			return new \WP_Error( 'collection_error', $e->getMessage() );
		}
	}

	/**
	 * Migrate Data to Qdrant.
	 * 
	 * @return void
	 */
	public static function migrate_data() {
		$db_table = DB_Table::instance();
		$posts    = $db_table->get_by_source( 'WordPress' );

		if ( empty( $posts ) ) {
			return;
		}

		$points = array();

		foreach ( $posts as $post ) {
			$points[] = array(
				'id'         => $post->id,
				'embeddings' => json_decode( $post->embeddings, true ),
				'data'       => array(
					'post_id'      => $post->post_id,
					'post_title'   => $post->post_title,
					'post_content' => $post->post_content,
					'token_count'  => $post->token_count,
				),
			);
		}

		$qdrant = self::instance();

		$success = $qdrant->add_points( $points );

		if ( is_wp_error( $success ) ) {
			return;
		}

		foreach ( $posts as $post ) {
			$db_table->update(
				$post->id,
				array(
					'source' => 'qdrant',
				) 
			);
		}

		if ( 100 === count( $posts ) ) {
			wp_schedule_single_event( time() + 30, 'hyve_lite_migrate_data' );
		}
	}

	/**
	 * Qdrant Status.
	 * 
	 * @since 1.3.0
	 * 
	 * @return bool
	 */
	public static function is_active() {
		return 'active' === get_option( 'hyve_qdrant_status', 'inactive' );
	}
}
