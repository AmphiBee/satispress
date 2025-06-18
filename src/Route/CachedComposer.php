<?php
/**
 * Cached Composer packages.json endpoint.
 *
 * @package SatisPress
 * @license GPL-2.0-or-later
 * @since 1.0.0
 */

declare ( strict_types = 1 );

namespace SatisPress\Route;

use SatisPress\Capabilities;
use SatisPress\Exception\HttpException;
use SatisPress\HTTP\Request;
use SatisPress\HTTP\Response;
use SatisPress\HTTP\ResponseBody\JsonBody;
use SatisPress\Repository\PackageRepository;
use SatisPress\Transformer\PackageRepositoryTransformer;
use WP_Http as HTTP;

/**
 * Class for rendering packages.json with cache for Composer.
 *
 * @since 1.0.0
 */
class CachedComposer implements Route {
	/**
	 * Package repository.
	 *
	 * @var PackageRepository
	 */
	protected $repository;

	/**
	 * Repository transformer.
	 *
	 * @var PackageRepositoryTransformer
	 */
	protected $transformer;

	/**
	 * Cache duration in seconds.
	 *
	 * @var int
	 */
	protected $cache_duration;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param PackageRepository            $repository      Package repository.
	 * @param PackageRepositoryTransformer $transformer     Repository transformer.
	 * @param int                         $cache_duration  Cache duration in seconds.
	 */
	public function __construct( 
		PackageRepository $repository, 
		PackageRepositoryTransformer $transformer,
		int $cache_duration = 3600 
	) {
		$this->repository     = $repository;
		$this->transformer    = $transformer;
		$this->cache_duration = $cache_duration;
	}

	/**
	 * Handle a request to the packages.json endpoint with cache.
	 *
	 * @since 1.0.0
	 *
	 * @param Request $request HTTP request instance.
	 * @throws HttpException If the user doesn't have permission to view packages.
	 * @return Response
	 */
	public function handle( Request $request ): Response {
		if ( ! current_user_can( Capabilities::VIEW_PACKAGES ) ) {
			throw HttpException::forForbiddenResource();
		}

		// Generate cache key based on user and packages
		$cache_key = $this->generate_cache_key();
		
		// Try to retrieve from cache
		$cached_data = get_transient( $cache_key );
		
		if ( false !== $cached_data ) {
			return new Response(
				new JsonBody( $cached_data ),
				HTTP::OK,
				[ 
					'Content-Type' => 'application/json; charset=' . get_option( 'blog_charset' ),
					'X-SatisPress-Cache' => 'HIT'
				]
			);
		}

		// Generate data if not cached or invalid
		$data = $this->transformer->transform( $this->repository );
		
		// Cache the data
		$this->cache_data( $cache_key, $data );
		
		return new Response(
			new JsonBody( $data ),
			HTTP::OK,
			[ 
				'Content-Type' => 'application/json; charset=' . get_option( 'blog_charset' ),
				'X-SatisPress-Cache' => 'MISS'
			]
		);
	}

	/**
	 * Generate a unique cache key.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	protected function generate_cache_key(): string {
		$user_id = get_current_user_id();
		$plugins_hash = md5( serialize( get_option( 'satispress_plugins', [] ) ) );
		$themes_hash = md5( serialize( get_option( 'satispress_themes', [] ) ) );
		
		return "satispress_packages_json_{$user_id}_{$plugins_hash}_{$themes_hash}";
	}

	/**
	 * Cache the data.
	 *
	 * @since 1.0.0
	 *
	 * @param string $cache_key Cache key.
	 * @param array  $data      Data to cache.
	 * @return bool
	 */
	protected function cache_data( string $cache_key, array $data ): bool {
		$cache_duration = $this->get_cache_duration();
		
		// Store data with timestamp for debugging
		$cache_data = [
			'data' => $data,
			'timestamp' => time(),
			'duration' => $cache_duration
		];
		
		return set_transient( $cache_key, $data, $cache_duration );
	}

	/**
	 * Get the configured cache duration.
	 *
	 * @since 1.0.0
	 *
	 * @return int Duration in seconds.
	 */
	protected function get_cache_duration(): int {
		/**
		 * Filter the cache duration for the Composer repository.
		 *
		 * @since 1.0.0
		 *
		 * @param int $duration Cache duration in seconds.
		 */
		$duration = apply_filters( 'satispress_composer_cache_duration', $this->cache_duration );
		
		// Get from options if configured
		$configured_duration = get_option( 'satispress_cache_duration', $duration );
		
		return max( 60, absint( $configured_duration ) ); // Minimum 1 minute
	}

	/**
	 * Invalidate the cache.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function invalidate_cache(): bool {
		$cache_key = $this->generate_cache_key();
		return delete_transient( $cache_key );
	}

	/**
	 * Invalidate all SatisPress cache.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function invalidate_all_cache(): bool {
		global $wpdb;
		
		// Delete all SatisPress transients
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient%satispress_packages_json_%'" );
		
		// Trigger action for external cache invalidation
		do_action( 'satispress_cache_invalidated', 'manual_all' );
		
		return true;
	}

	/**
	 * Clear expired cache entries.
	 *
	 * @since 1.0.0
	 *
	 * @return int Number of deleted entries.
	 */
	public static function cleanup_expired_cache(): int {
		global $wpdb;
		
		$deleted = $wpdb->query( 
			"DELETE FROM {$wpdb->options} 
			WHERE option_name LIKE '_transient_timeout_satispress_packages_json_%' 
			AND option_value < UNIX_TIMESTAMP()"
		);
		
		// Clean up orphaned transient data
		$wpdb->query(
			"DELETE FROM {$wpdb->options} 
			WHERE option_name LIKE '_transient_satispress_packages_json_%' 
			AND option_name NOT IN (
				SELECT REPLACE(option_name, '_timeout_', '_') 
				FROM {$wpdb->options} 
				WHERE option_name LIKE '_transient_timeout_satispress_packages_json_%'
			)"
		);
		
		return absint( $deleted );
	}
} 