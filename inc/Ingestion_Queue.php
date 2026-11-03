<?php
/**
 * Resumable self-hosted knowledge ingestion.
 *
 * @package Codeinwp/HyveLite
 */

namespace ThemeIsle\HyveLite;

/**
 * Large self-hosted imports run in the background, one bounded step at a
 * time, so a rate limit or an interrupted request never restarts the whole
 * document. Chunks wait in the knowledge table as `staging` rows, hidden
 * from retrieval and counts, until every one is moderated and embedded.
 */
class Ingestion_Queue {
	const HOOK = 'hyve_ingest_document';
	const JOB  = '_hyve_ingest_job';

	/**
	 * The submitted document, written once; the job stays small.
	 */
	const SOURCE = '_hyve_ingest_source';

	/**
	 * Chunks embedded per request.
	 */
	const EMBEDDING_BATCH = 10;

	/**
	 * Seconds allowed for one batched provider request.
	 */
	const REQUEST_TIMEOUT = 30;

	/**
	 * Consecutive rate-limit waits without progress before giving up: an
	 * account with no credits answers 429 forever.
	 */
	const MAX_RATE_WAITS = 30;

	/**
	 * Points uploaded to Qdrant per request.
	 */
	const REMOTE_BATCH = 20;

	/**
	 * Failures that retrying cannot fix.
	 *
	 * @var string[]
	 */
	const TERMINAL_CODES = [ 'content_failed_moderation', 'storage_error', 'invalid_source' ];

	/**
	 * Registered queue.
	 *
	 * @var self|null
	 */
	private static $instance;

	/**
	 * Register hooks once.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register processing, recovery and deletion callbacks.
	 */
	private function __construct() {
		add_action( self::HOOK, [ $this, 'run' ] );
		add_action( 'hyve_update_posts', [ $this, 'watchdog' ], 5 );
		add_action( 'before_delete_post', [ $this, 'cancel' ], 5 );
	}

	/**
	 * Whether an import owns the source already.
	 *
	 * @param int $post_id Source ID.
	 * @return bool
	 * @phpstan-impure
	 */
	public function is_active( $post_id ) {
		return $post_id > 0 && is_array( get_post_meta( $post_id, self::JOB, true ) );
	}

	/**
	 * Reserve a visible source and stage its chunks before scheduling work.
	 *
	 * @param array<string, mixed>        $doc Source content.
	 * @param array<string, mixed>        $args Ingestion options.
	 * @param array<array<string, mixed>> $chunks Tokenized content.
	 * @return true|\WP_Error
	 */
	public function enqueue( $doc, $args, $chunks ) {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( ! $post_id && ! empty( $args['create'] ) ) {
			$created = wp_insert_post(
				[
					'post_title'   => $doc['title'],
					'post_content' => $doc['content'],
					'post_type'    => $args['post_type'] ?? 'hyve_docs',
					'post_status'  => 'publish',
				],
				true
			);
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			$post_id = $created;
		}
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new \WP_Error( 'invalid_source', __( 'The content source could not be found.', 'hyve-lite' ) );
		}
		$lease = $this->lock( $post_id );
		if ( false === $lease ) {
			return new \WP_Error( 'ingestion_in_progress', __( 'This source is already being indexed. Wait for it to finish before replacing it.', 'hyve-lite' ) );
		}
		try {
			if ( $this->is_active( $post_id ) ) {
				$existing = get_post_meta( $post_id, self::JOB, true );
				if ( $existing['doc_hash'] !== $this->doc_hash( $doc ) ) {
					return new \WP_Error( 'ingestion_in_progress', __( 'This source is already being indexed. Wait for it to finish before replacing it.', 'hyve-lite' ) );
				}
				return true;
			}
			$table   = DB_Table::instance();
			$row_ids = [];
			foreach ( $chunks as $chunk ) {
				$id = $table->insert(
					[
						'post_id'      => $post_id,
						'post_title'   => $chunk['post_title'] ?? $doc['title'],
						'post_content' => $chunk['post_content'],
						'token_count'  => (int) ( $chunk['token_count'] ?? 0 ),
						'post_status'  => 'staging',
					]
				);
				if ( ! $id ) {
					$this->delete_staging( $post_id );
					$error = new \WP_Error( 'storage_error', __( 'The indexed content could not be saved.', 'hyve-lite' ) );
					// A source this call created would otherwise be invisible:
					// the caller has no post ID to record the failure on.
					if ( isset( $created ) ) {
						update_post_meta( $post_id, '_hyve_added', 1 );
						update_post_meta( $post_id, '_hyve_processing_error', $error->get_error_message() . ' ' . __( 'Fix the reported issue and re-add this content.', 'hyve-lite' ) );
					}
					return $error;
				}
				$row_ids[] = $id;
			}
			$job = [
				'id'          => wp_generate_uuid4(),
				'doc_hash'    => $this->doc_hash( $doc ),
				'args'        => $args,
				'phase'       => 'moderation',
				'offset'      => 0,
				'total'       => count( $row_ids ),
				'attempts'    => 0,
				'retry_at'    => 0,
				'row_ids'     => $row_ids,
				'source_hash' => $this->source_hash( $post_id ),
			];
			foreach ( $args['meta'] ?? [] as $key => $value ) {
				update_post_meta( $post_id, $key, $value );
			}
			update_post_meta( $post_id, '_hyve_added', 1 );
			// Only WordPress posts are re-indexed by the update cron; a managed
			// source would keep a Needs Attention item nothing ever clears.
			if ( empty( $args['create'] ) ) {
				update_post_meta( $post_id, '_hyve_needs_update', 1 );
			}
			delete_post_meta( $post_id, '_hyve_processing_error' );
			delete_post_meta( $post_id, '_hyve_moderation_failed' );
			delete_post_meta( $post_id, '_hyve_moderation_review' );
			// Meta writes unslash; slash so the document round-trips exactly.
			delete_post_meta( $post_id, self::SOURCE );
			add_post_meta( $post_id, self::SOURCE, wp_slash( $doc ), true );
			add_post_meta( $post_id, self::JOB, wp_slash( $job ), true );
			$this->save( $post_id, $job, 'queued' );
			$this->schedule( $post_id, time() );
			return true;
		} finally {
			$this->unlock( $post_id, $lease );
		}
	}

	/**
	 * Recover events lost to PHP termination or cron failures.
	 *
	 * @return void
	 */
	public function watchdog() {
		$connect = Hyve_Connect::is_active();
		foreach ( $this->ids_with_meta( self::JOB, [ 'publish', 'private' ] ) as $id ) {
			$job = get_post_meta( $id, self::JOB, true );
			if ( ! is_array( $job ) ) {
				continue;
			}
			// The platform owns indexing now; a self-hosted job can never finish.
			if ( $connect ) {
				$this->cancel( $id );
				continue;
			}
			$this->schedule( $id, max( time(), (int) $job['retry_at'] ) );
		}
		if ( ! $connect ) {
			$this->retry_prunes();
		}
	}

	/**
	 * Every source carrying a meta key, read a page at a time and collected
	 * before any is changed, so acting on one cannot shift the pages.
	 *
	 * @param string          $key Meta key.
	 * @param string|string[] $status Post statuses.
	 * @return int[]
	 */
	private function ids_with_meta( $key, $status ) {
		$per_page = 100;
		$ids      = [];
		for ( $page = 1; ; $page++ ) {
			$batch = get_posts(
				[
					'post_type'      => array_values( get_post_types() ),
					'post_status'    => $status,
					'fields'         => 'ids',
					'posts_per_page' => $per_page,
					'paged'          => $page,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'no_found_rows'  => true,
					'meta_key'       => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only sources the queue owns.
				]
			);
			$ids   = array_merge( $ids, array_map( 'intval', $batch ) );
			if ( count( $batch ) < $per_page ) {
				break;
			}
		}
		return $ids;
	}

	/**
	 * Keep pruning generations an import had to leave behind in Qdrant.
	 * Search hides them meanwhile (see Qdrant_API::search()).
	 *
	 * @return void
	 */
	private function retry_prunes() {
		if ( ! Qdrant_API::is_active() ) {
			return;
		}
		foreach ( array_keys( Qdrant_API::pending_prunes() ) as $id ) {
			// A running import owns the source's points: pruning around an
			// older generation would delete the newer one it is uploading.
			if ( $this->is_active( $id ) ) {
				continue;
			}
			$lease = $this->lock( $id );
			if ( false === $lease ) {
				continue;
			}
			try {
				// Read again under the lease: an import may have finished and
				// moved or cleared the marker since the list was read.
				$generation = Qdrant_API::current_generation( $id );
				if ( '' !== $generation && ! $this->is_active( $id ) && true === Qdrant_API::instance()->delete_ingestion_points( $id, $generation, true ) ) {
					Qdrant_API::set_current_generation( $id, null );
				}
			} finally {
				$this->unlock( $id, $lease );
			}
		}
	}

	/**
	 * Advance one job for as long as the time budget and the provider's rate
	 * limits allow. WP-Cron fires at most once a minute and only runs the
	 * events ready when it starts, so each run must do more than one step.
	 * A lease prevents concurrent executions.
	 *
	 * @param int $post_id Source ID.
	 * @return void
	 */
	public function run( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! $this->is_active( $post_id ) ) {
			return;
		}
		if ( Hyve_Connect::is_active() ) {
			$this->cancel( $post_id );
			return;
		}
		$lease = $this->lock( $post_id );
		if ( false === $lease ) {
			$this->schedule( $post_id, time() + 30 );
			return;
		}
		$openai = OpenAI::instance();
		try {
			$job = get_post_meta( $post_id, self::JOB, true );
			if ( ! is_array( $job ) || ! get_post( $post_id ) || ! get_post_meta( $post_id, '_hyve_added', true ) ) {
				$this->cancel( $post_id );
				return;
			}
			if ( $job['retry_at'] > time() ) {
				$this->schedule( $post_id, $job['retry_at'] );
				return;
			}
			// The wait is over; a stale value would end the loop after one step.
			$job['retry_at'] = 0;
			// Arm recovery before making any external call.
			$this->schedule( $post_id, time() + DB_Table::PROCESSING_STALL );
			$deadline = $this->deadline();
			$openai->pace_requests( true );
			$this->save( $post_id, $job, 'processing' );
			do {
				$before = [ $job['phase'], $job['offset'], $job['remote_activated'] ?? false ];
				$result = $this->advance( $post_id, $job );
				if ( ! $this->is_current( $post_id, $job ) ) {
					return;
				}
				if ( is_wp_error( $result ) ) {
					$this->fail( $post_id, $job, $result );
					return;
				}
				// Only real progress forgives earlier failures; waiting for a
				// budget another caller spent is not progress.
				$after = [ $job['phase'], $job['offset'], $job['remote_activated'] ?? false ];
				if ( $after !== $before ) {
					if ( $job['attempts'] > 0 || ! empty( $job['rate_waits'] ) ) {
						delete_post_meta( $post_id, '_hyve_processing_error' );
					}
					$job['attempts']   = 0;
					$job['rate_waits'] = 0;
				}
				$this->save( $post_id, $job, 'processing' );
			} while ( 0 === $job['retry_at'] && time() < $deadline );
			// Between runs a started job is still "processing"; only a job
			// that has done nothing yet is merely queued.
			$started = $job['offset'] > 0 || 'moderation' !== $job['phase'];
			$this->save( $post_id, $job, $started ? 'processing' : 'queued' );
			wp_clear_scheduled_hook( self::HOOK, [ $post_id ] );
			$this->schedule( $post_id, max( time() + 1, $job['retry_at'] ) );
		} catch ( \Throwable $error ) {
			if ( isset( $job ) && is_array( $job ) && $this->is_current( $post_id, $job ) ) {
				$this->fail( $post_id, $job, new \WP_Error( 'ingestion_error', $error->getMessage() ) );
			}
		} finally {
			$openai->pace_requests( false );
			$this->unlock( $post_id, $lease );
		}
	}

	/**
	 * When this run must stop. Every job in a cron request shares one budget,
	 * so the request stays short however many sources are queued; a run always
	 * completes at least one step.
	 *
	 * @return int
	 */
	private function deadline() {
		/**
		 * Filters how long one background ingestion request may keep working.
		 *
		 * @since 2.1.0
		 *
		 * @param int $seconds Time budget in seconds. Default 20. Zero runs a single step.
		 */
		$budget = max( 0, (int) apply_filters( 'hyve_ingestion_time_budget', 20 ) );
		$limit  = (int) ini_get( 'max_execution_time' );
		if ( $limit > 0 ) {
			$budget = min( $budget, max( 0, $limit - 10 ) );
		}
		$start = time();
		if ( wp_doing_cron() && isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
			$start = min( $start, (int) $_SERVER['REQUEST_TIME_FLOAT'] );
		}
		return $start + $budget;
	}

	/**
	 * Moderate one batch, embed one batch, or publish, keeping every
	 * successful step. Waits for the provider's budget instead of a 429.
	 *
	 * @param int                  $post_id Source ID.
	 * @param array<string, mixed> $job Job, updated by reference.
	 * @return true|\WP_Error
	 */
	private function advance( $post_id, &$job ) {
		if ( 'publish' === $job['phase'] ) {
			return $this->publish( $post_id, $job );
		}
		$openai     = OpenAI::instance();
		$moderation = 'moderation' === $job['phase'];
		$endpoint   = $moderation ? 'moderations' : 'embeddings';
		$size       = $moderation ? max( 1, (int) apply_filters( 'hyve_moderation_batch_size', 5 ) ) : self::EMBEDDING_BATCH;
		$rows       = $this->rows( $job, $job['offset'], $size );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$tokens = 0;
		foreach ( $rows as $row ) {
			$tokens += (int) $row->token_count;
		}
		$delay = $openai->rate_limit_delay( $endpoint, $tokens );
		if ( $delay > 0 ) {
			$job['retry_at'] = time() + $delay;
			return true;
		}
		$job['retry_at'] = 0;
		if ( $moderation ) {
			$result = $openai->moderate_chunks( array_map( fn( $row ) => $row->post_content, $rows ), null, self::REQUEST_TIMEOUT );
			if ( ! $this->is_current( $post_id, $job ) ) {
				return true;
			}
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( true !== $result && empty( $job['args']['override'] ) ) {
				update_post_meta( $post_id, '_hyve_moderation_failed', 1 );
				update_post_meta( $post_id, '_hyve_moderation_review', $result );
				return new \WP_Error( 'content_failed_moderation', __( 'The content failed the moderation check.', 'hyve-lite' ) );
			}
		} else {
			$result = $this->embed( $post_id, $job, $rows );
			if ( true !== $result ) {
				return $result;
			}
		}
		$job['offset'] += count( $rows );
		if ( $job['offset'] >= $job['total'] ) {
			$job['phase']  = $moderation ? 'embedding' : 'publish';
			$job['offset'] = 0;
		}
		return true;
	}

	/**
	 * Embed a batch of staged rows in one request.
	 *
	 * @param int                                                                                                         $post_id Source ID.
	 * @param array<string, mixed>                                                                                        $job Job.
	 * @param array<int, object{id: int, post_title: string, post_content: string, token_count: int, embeddings: string}> $rows Staged rows, in document order.
	 * @return true|\WP_Error
	 */
	private function embed( $post_id, $job, $rows ) {
		$pending = [];
		foreach ( $rows as $row ) {
			if ( ! in_array( $row->embeddings, [ '', '[]', 'null', null ], true ) ) {
				continue;
			}
			$pending[ $row->id ] = trim( $row->post_title . ' ' . wp_strip_all_tags( $row->post_content ) );
		}
		if ( empty( $pending ) ) {
			return true;
		}
		$result = OpenAI::instance()->create_embeddings( array_values( $pending ), OpenAI::EMBEDDING_MODEL, self::REQUEST_TIMEOUT );
		if ( ! $this->is_current( $post_id, $job ) ) {
			return true;
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$vectors = [];
		foreach ( (array) $result as $item ) {
			if ( isset( $item->index, $item->embedding ) ) {
				$vectors[ (int) $item->index ] = $item->embedding;
			}
		}
		if ( count( $vectors ) !== count( $pending ) ) {
			return new \WP_Error( 'invalid_embeddings', __( 'The embedding response was incomplete.', 'hyve-lite' ) );
		}
		$table = DB_Table::instance();
		foreach ( array_keys( $pending ) as $position => $id ) {
			$table->update(
				$id,
				[
					'embeddings'      => wp_json_encode( $vectors[ $position ] ),
					'embedding_model' => OpenAI::EMBEDDING_MODEL,
				]
			);
		}
		return true;
	}

	/**
	 * Load a slice of the staged rows, in document order.
	 *
	 * @param array<string, mixed> $job Job.
	 * @param int                  $offset First chunk index.
	 * @param int                  $size Chunk count.
	 * @return array<int, object{id: int, post_title: string, post_content: string, token_count: int, embeddings: string}>|\WP_Error
	 */
	private function rows( $job, $offset, $size ) {
		global $wpdb;
		$ids = array_slice( $job['row_ids'], $offset, $size );
		if ( empty( $ids ) ) {
			return [];
		}
		$list = implode( ',', array_map( 'intval', $ids ) );
		/**
		 * Staged rows keyed by ID.
		 *
		 * @var array<int, object{id: int, post_title: string, post_content: string, token_count: int, embeddings: string}>|null $found
		 */
		$found = $wpdb->get_results( $wpdb->prepare( "SELECT id, post_title, post_content, token_count, embeddings FROM %i WHERE id IN ($list)", DB_Table::instance()->table_name ), OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integer IDs.
		$rows  = [];
		foreach ( $ids as $id ) {
			if ( ! isset( $found[ $id ] ) ) {
				return new \WP_Error( 'storage_error', __( 'The staged content is missing. Re-add this content.', 'hyve-lite' ) );
			}
			$rows[] = $found[ $id ];
		}
		return $rows;
	}

	/**
	 * Make the staged replacement visible. Qdrant receives the whole
	 * generation hidden first, then one payload operation reveals it, so a
	 * failure part-way leaves the old knowledge answering. Deterministic
	 * point IDs make an interrupted upload safe to repeat.
	 *
	 * @param int                  $post_id Source ID.
	 * @param array<string, mixed> $job Job, updated by reference.
	 * @return true|\WP_Error
	 */
	private function publish( $post_id, &$job ) {
		global $wpdb;
		$table  = DB_Table::instance();
		$qdrant = Qdrant_API::is_active();
		// Qdrant was reconnected since this upload began, possibly to a new
		// target that never received it: upload again on the current one
		// before revealing or pruning anything there.
		if ( $qdrant && ! empty( $job['remote_started'] ) && ( $job['qdrant_connection'] ?? '' ) !== Qdrant_API::connection_id() ) {
			$job['offset'] = 0;
			unset( $job['remote_started'], $job['remote_activated'], $job['qdrant_connection'] );
		}
		if ( $qdrant && $job['offset'] < $job['total'] ) {
			$rows = $this->rows( $job, $job['offset'], self::REMOTE_BATCH );
			if ( is_wp_error( $rows ) ) {
				return $rows;
			}
			$points = [];
			foreach ( $rows as $position => $row ) {
				$points[] = [
					'id'         => Qdrant_API::point_id( $job['id'] . '-' . ( $job['offset'] + $position ) ),
					'embeddings' => json_decode( $row->embeddings, true ),
					'data'       => [
						'_hyve_ingestion_generation' => $job['id'],
						'_hyve_ingestion_pending'    => 'yes',
						'post_id'                    => (string) $post_id,
						'post_title'                 => $row->post_title,
						'post_content'               => $row->post_content,
						'token_count'                => (int) $row->token_count,
						'website_url'                => get_site_url(),
					],
				];
			}
			$job['remote_started'] = true;
			// Pinned so a marker is only ever written for this connection.
			if ( empty( $job['qdrant_connection'] ) ) {
				$job['qdrant_connection'] = Qdrant_API::connection_id();
			}
			$this->save( $post_id, $job, 'processing' );
			$result = Qdrant_API::instance()->add_points( $points );
			if ( ! $this->is_current( $post_id, $job ) ) {
				Qdrant_API::instance()->delete_ingestion_points( $post_id, $job['id'] );
				return true;
			}
			if ( is_wp_error( $result ) || ! $result ) {
				return is_wp_error( $result ) ? $result : new \WP_Error( 'qdrant_error', __( 'The Qdrant index could not be saved.', 'hyve-lite' ) );
			}
			$job['offset'] += count( $rows );
			return true;
		}
		// The remote swap can fail and is retried, so it goes before the local
		// one; a local write failure is the only way the two can disagree.
		if ( $qdrant && empty( $job['remote_activated'] ) ) {
			$ids = [];
			for ( $index = 0; $index < $job['total']; $index++ ) {
				$ids[] = Qdrant_API::point_id( $job['id'] . '-' . $index );
			}
			$result = Qdrant_API::instance()->publish_ingestion_points( $ids );
			if ( ! $this->is_current( $post_id, $job ) ) {
				Qdrant_API::instance()->delete_ingestion_points( $post_id, $job['id'] );
				return true;
			}
			if ( is_wp_error( $result ) || ! $result ) {
				return is_wp_error( $result ) ? $result : new \WP_Error( 'qdrant_error', __( 'The Qdrant index could not be published.', 'hyve-lite' ) );
			}
			// An older prune is still pending: search must now answer from
			// this generation. Moved before the reveal is recorded, so a
			// resumed run never skips it.
			if ( '' !== Qdrant_API::current_generation( $post_id ) ) {
				Qdrant_API::set_current_generation( $post_id, $job['id'], $job['qdrant_connection'] ?? null );
			}
			$job['remote_activated'] = time();
			$this->save( $post_id, $job, 'processing' );
		}
		if ( $qdrant ) {
			$result = Qdrant_API::instance()->delete_ingestion_points( $post_id, $job['id'], true );
			if ( ! $this->is_current( $post_id, $job ) ) {
				Qdrant_API::instance()->delete_ingestion_points( $post_id, $job['id'] );
				return true;
			}
			$pruned = ! is_wp_error( $result ) && $result;
			if ( ! $pruned && time() - (int) $job['remote_activated'] < DAY_IN_SECONDS ) {
				return is_wp_error( $result ) ? $result : new \WP_Error( 'qdrant_error', __( 'The old Qdrant index could not be replaced.', 'hyve-lite' ) );
			}
			// After a day of trying, finish locally. Search answers only from
			// this generation and the watchdog keeps pruning the old one.
			Qdrant_API::set_current_generation( $post_id, $pruned ? null : $job['id'], $job['qdrant_connection'] ?? null );
		}
		// Reveal the staged rows, then drop the previous ones: a brief overlap
		// beats a gap in answers.
		// Both writes are safe to repeat, so a database hiccup here is retried
		// rather than discarding a fully embedded import.
		$ids     = implode( ',', array_map( 'intval', $job['row_ids'] ) );
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET post_status = %s, storage = %s WHERE post_id = %d AND post_status = %s', $table->table_name, 'processed', $qdrant ? 'Qdrant' : 'WordPress', $post_id, 'staging' ) );
		if ( false === $updated ) {
			return new \WP_Error( 'publish_storage_error', __( 'The replacement index could not be saved.', 'hyve-lite' ) );
		}
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE post_id = %d AND id NOT IN ($ids)", $table->table_name, $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integer IDs.
		if ( false === $deleted ) {
			return new \WP_Error( 'publish_storage_error', __( 'The previous index could not be removed.', 'hyve-lite' ) );
		}
		$doc = get_post_meta( $post_id, self::SOURCE, true );
		if ( is_array( $doc ) && ! empty( $job['args']['create'] ) && 'update' === ( $job['args']['action'] ?? '' ) ) {
			// Raw, like the synchronous path: wp_update_post() unslashes.
			wp_update_post(
				[
					'ID'           => $post_id,
					'post_title'   => $doc['title'],
					'post_content' => $doc['content'],
				]
			);
		}
		$table->invalidate_ingestion_cache();
		delete_post_meta( $post_id, '_hyve_processing_error' );
		if ( ! empty( $job['args']['create'] ) || $job['source_hash'] === $this->source_hash( $post_id ) ) {
			delete_post_meta( $post_id, '_hyve_needs_update' );
		} else {
			// Edited meanwhile: the update cron indexes the current content soon.
			wp_schedule_single_event( time() + 60, 'hyve_update_posts' );
		}
		$job['offset'] = $job['total'];
		$this->save( $post_id, $job, 'complete' );
		$this->cleanup( $post_id, $job, true );
		return true;
	}

	/**
	 * Persist a bounded retry, or a terminal error without losing its source.
	 *
	 * @param int                  $post_id Source ID.
	 * @param array<string, mixed> $job Job.
	 * @param \WP_Error            $error Failure.
	 * @return void
	 */
	private function fail( $post_id, $job, $error ) {
		$code = $error->get_error_code();
		// Waiting out the provider's budget is the queue's job, not a strike,
		// but an account that never gets a budget must stop somewhere.
		if ( 'rate_limit_exceeded' === $code ) {
			$job['rate_waits'] = ( $job['rate_waits'] ?? 0 ) + 1;
		} else {
			++$job['attempts'];
		}
		$fatal = OpenAI::is_fatal_error_code( $code ) || in_array( $code, self::TERMINAL_CODES, true );
		$retry = ! $fatal && $job['attempts'] < DB_Table::MAX_PROCESS_ATTEMPTS && ( $job['rate_waits'] ?? 0 ) < self::MAX_RATE_WAITS;
		// Once Qdrant answers from the new generation, giving up would leave the
		// two stores disagreeing; keep retrying the remaining writes.
		if ( ! $fatal && ! empty( $job['remote_activated'] ) ) {
			$retry = true;
		}
		$text = OpenAI::describe_error( $error );
		wp_clear_scheduled_hook( self::HOOK, [ $post_id ] );
		if ( $retry ) {
			update_post_meta( $post_id, '_hyve_processing_error', $text . ' ' . __( 'This content will be retried automatically.', 'hyve-lite' ) );
			$endpoint        = 'moderation' === $job['phase'] ? 'moderations' : 'embeddings';
			$job['retry_at'] = time() + max( min( 600, 60 * (int) $job['attempts'] ), 60, OpenAI::instance()->rate_limit_delay( $endpoint ) );
			$this->save( $post_id, $job, 'queued' );
			$this->schedule( $post_id, $job['retry_at'] );
			return;
		}
		// The snapshot that failed is already stale: let the update cron index
		// the edited source instead of recording a verdict on old content.
		if ( empty( $job['args']['create'] ) && $job['source_hash'] !== $this->source_hash( $post_id ) ) {
			delete_post_meta( $post_id, '_hyve_moderation_failed' );
			delete_post_meta( $post_id, '_hyve_moderation_review' );
			delete_post_meta( $post_id, '_hyve_processing_error' );
			delete_post_meta( $post_id, '_hyve_ingestion' );
			$this->cleanup( $post_id, $job );
			wp_schedule_single_event( time() + 60, 'hyve_update_posts' );
			return;
		}
		// Moderation has its own meta and chip, as on the synchronous path.
		if ( 'content_failed_moderation' === $code ) {
			delete_post_meta( $post_id, '_hyve_processing_error' );
		} else {
			update_post_meta( $post_id, '_hyve_processing_error', $text . ' ' . __( 'Fix the reported issue and re-add this content.', 'hyve-lite' ) );
		}
		// Terminal until the source is edited or re-added; the update cron must not loop on it.
		delete_post_meta( $post_id, '_hyve_needs_update' );
		$this->save( $post_id, $job, 'failed' );
		$this->cleanup( $post_id, $job );
	}

	/**
	 * Persist progress and a small public summary. Writes by meta ID so a
	 * job cancelled in another request is never recreated here.
	 *
	 * @param int                  $post_id Source ID.
	 * @param array<string, mixed> $job Job.
	 * @param string               $status Public status.
	 * @return void
	 */
	private function save( $post_id, $job, $status ) {
		global $wpdb;
		$current = $wpdb->get_row( $wpdb->prepare( "SELECT meta_id, meta_value FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = %s", $post_id, self::JOB ) );
		$value   = $current ? maybe_unserialize( $current->meta_value ) : null;
		if ( ! is_array( $value ) || $value['id'] !== $job['id'] ) {
			return;
		}
		$wpdb->update( $wpdb->postmeta, [ 'meta_value' => maybe_serialize( $job ) ], [ 'meta_id' => $current->meta_id ], [ '%s' ], [ '%d' ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Keyed by meta_id.
		wp_cache_delete( $post_id, 'post_meta' );
		update_post_meta(
			$post_id,
			'_hyve_ingestion',
			[
				'status'    => $status,
				'phase'     => 'moderation' === $job['phase'] ? 'moderation' : 'embedding',
				'completed' => 'publish' === $job['phase'] ? $job['total'] : $job['offset'],
				'total'     => $job['total'],
				'retry_at'  => $job['retry_at'],
			]
		);
	}

	/**
	 * Delete the job and any unpublished staged rows.
	 *
	 * @param int                  $post_id Source ID.
	 * @param array<string, mixed> $job Job.
	 * @param bool                 $complete Whether the staged content was published.
	 * @return void
	 */
	private function cleanup( $post_id, $job, $complete = false ) {
		if ( ! $complete && ! empty( $job['remote_started'] ) && empty( $job['remote_activated'] ) ) {
			Qdrant_API::instance()->delete_ingestion_points( $post_id, $job['id'] );
		}
		$this->delete_staging( $post_id );
		delete_post_meta( $post_id, self::JOB );
		delete_post_meta( $post_id, self::SOURCE );
		wp_clear_scheduled_hook( self::HOOK, [ $post_id ] );
	}

	/**
	 * Remove hidden rows; published rows are untouched.
	 *
	 * @param int $post_id Source ID.
	 * @return void
	 */
	private function delete_staging( $post_id ) {
		global $wpdb;
		$table = DB_Table::instance();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE post_id = %d AND post_status = %s', $table->table_name, $post_id, 'staging' ) );
		$table->invalidate_ingestion_cache();
	}

	/**
	 * Stop work when a source is removed; cron may never recreate it.
	 *
	 * @param int $post_id Source ID.
	 * @return void
	 */
	public function cancel( $post_id ) {
		$job = get_post_meta( $post_id, self::JOB, true );
		delete_post_meta( $post_id, self::JOB );
		if ( is_array( $job ) ) {
			unset( $job['remote_activated'] );
			$this->cleanup( $post_id, $job );
		}
		delete_post_meta( $post_id, self::SOURCE );
		delete_post_meta( $post_id, '_hyve_ingestion' );
	}

	/**
	 * Identify a submission without storing it twice.
	 *
	 * @param array<string, mixed> $doc Source content.
	 * @return string
	 */
	private function doc_hash( $doc ) {
		return hash( 'sha256', (string) wp_json_encode( $doc ) );
	}

	/**
	 * Detect source edits made while an older snapshot is being indexed.
	 *
	 * @param int $post_id Source ID.
	 * @return string
	 */
	private function source_hash( $post_id ) {
		$post = get_post( $post_id );
		return $post ? hash( 'sha256', $post->post_title . "\n" . $post->post_content ) : '';
	}

	/**
	 * Fence work from a cancelled or replaced generation, bypassing request caches.
	 *
	 * @param int                  $post_id Source ID.
	 * @param array<string, mixed> $job Expected job.
	 * @return bool
	 * @phpstan-impure
	 */
	private function is_current( $post_id, $job ) {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = %s", $post_id, self::JOB ) );
		$value = maybe_unserialize( $value );
		return is_array( $value ) && $value['id'] === $job['id'];
	}

	/**
	 * Schedule at most one event per source.
	 *
	 * @param int $post_id Source ID.
	 * @param int $when Unix timestamp.
	 * @return void
	 */
	private function schedule( $post_id, $when ) {
		if ( ! wp_next_scheduled( self::HOOK, [ $post_id ] ) ) {
			wp_schedule_single_event( $when, self::HOOK, [ $post_id ] );
		}
	}

	/**
	 * Atomically acquire a source lease, reclaiming expired leases by value.
	 *
	 * @param int $post_id Source ID.
	 * @return string|false
	 */
	private function lock( $post_id ) {
		global $wpdb;
		$key = 'hyve_ingest_lock_' . $post_id;
		$old = get_option( $key );
		if ( is_string( $old ) && (int) $old < time() - DB_Table::PROCESSING_STALL ) {
			$this->unlock( $post_id, $old );
		}
		$value   = time() . ':' . wp_generate_uuid4();
		$claimed = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $wpdb->options (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, $value ) );
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return 1 === $claimed ? $value : false;
	}

	/**
	 * Only the lease's owner may release it.
	 *
	 * @param int    $post_id Source ID.
	 * @param string $lease Lease value.
	 * @return void
	 */
	private function unlock( $post_id, $lease ) {
		global $wpdb;
		$key = 'hyve_ingest_lock_' . $post_id;
		$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->options WHERE option_name = %s AND option_value = %s", $key, $lease ) );
		wp_cache_delete( $key, 'options' );
	}
}
