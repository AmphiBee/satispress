<?php
/**
 * Provider for repository cache management.
 *
 * @package SatisPress
 * @license GPL-2.0-or-later
 * @since 1.0.0
 */

declare ( strict_types = 1 );

namespace SatisPress\Provider;

use Cedaro\WP\Plugin\AbstractHookProvider;
use SatisPress\Route\CachedComposer;

/**
 * Provider class for repository cache management.
 *
 * @since 1.0.0
 */
class RepositoryCache extends AbstractHookProvider {
	/**
	 * Register hooks.
	 *
	 * @since 1.0.0
	 */
	public function register_hooks() {
		// Invalidate cache on package modifications
		add_action( 'update_option_satispress_plugins', [ $this, 'invalidate_cache_on_plugins_change' ], 10, 3 );
		add_action( 'update_option_satispress_themes', [ $this, 'invalidate_cache_on_themes_change' ], 10, 3 );
		add_action( 'add_option_satispress_plugins', [ $this, 'invalidate_cache_on_option_add' ], 10, 2 );
		add_action( 'add_option_satispress_themes', [ $this, 'invalidate_cache_on_option_add' ], 10, 2 );

		// Invalidate cache on plugin/theme updates
		add_action( 'upgrader_process_complete', [ $this, 'invalidate_cache_on_upgrade' ], 10, 2 );
		add_action( 'activated_plugin', [ $this, 'invalidate_cache_on_plugin_activation' ] );
		add_action( 'deactivated_plugin', [ $this, 'invalidate_cache_on_plugin_deactivation' ] );
		add_action( 'switch_theme', [ $this, 'invalidate_cache_on_theme_switch' ] );

		// Hook for manual invalidation via admin
		add_action( 'wp_ajax_satispress_clear_cache', [ $this, 'handle_manual_cache_clear' ] );

		// Add button in admin
		add_action( 'admin_init', [ $this, 'add_cache_clear_button' ] );
	}

	/**
	 * Invalidate cache on whitelisted plugins change.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $old_value Old value.
	 * @param array  $value     New value.
	 * @param string $option    Option name.
	 */
	public function invalidate_cache_on_plugins_change( $old_value, $value, string $option ) {
		if ( $old_value !== $value ) {
			$this->invalidate_all_cache();
			$this->log_cache_invalidation( 'Plugins whitelist changed' );
		}
	}

	/**
	 * Invalidate cache on whitelisted themes change.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $old_value Old value.
	 * @param array  $value     New value.
	 * @param string $option    Option name.
	 */
	public function invalidate_cache_on_themes_change( $old_value, $value, string $option ) {
		if ( $old_value !== $value ) {
			$this->invalidate_all_cache();
			$this->log_cache_invalidation( 'Themes whitelist changed' );
		}
	}

	/**
	 * Invalidate cache when option is added.
	 *
	 * @since 1.0.0
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 */
	public function invalidate_cache_on_option_add( string $option, $value ) {
		$this->invalidate_all_cache();
		$this->log_cache_invalidation( "Option added: {$option}" );
	}

	/**
	 * Invalidate cache on plugin/theme updates.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Upgrader $upgrader_object WP_Upgrader instance.
	 * @param array        $options         Update options.
	 */
	public function invalidate_cache_on_upgrade( $upgrader_object, array $options ) {
		if ( isset( $options['type'] ) && in_array( $options['type'], [ 'plugin', 'theme' ], true ) ) {
			$this->invalidate_all_cache();
			$this->log_cache_invalidation( "Package upgraded: {$options['type']}" );
		}
	}

	/**
	 * Invalidate cache on plugin activation.
	 *
	 * @since 1.0.0
	 *
	 * @param string $plugin Plugin basename.
	 */
	public function invalidate_cache_on_plugin_activation( string $plugin ) {
		// Check if plugin is in whitelist
		$whitelisted_plugins = get_option( 'satispress_plugins', [] );
		if ( in_array( $plugin, $whitelisted_plugins, true ) ) {
			$this->invalidate_all_cache();
			$this->log_cache_invalidation( "Whitelisted plugin activated: {$plugin}" );
		}
	}

	/**
	 * Invalidate cache on plugin deactivation.
	 *
	 * @since 1.0.0
	 *
	 * @param string $plugin Plugin basename.
	 */
	public function invalidate_cache_on_plugin_deactivation( string $plugin ) {
		// Check if plugin is in whitelist
		$whitelisted_plugins = get_option( 'satispress_plugins', [] );
		if ( in_array( $plugin, $whitelisted_plugins, true ) ) {
			$this->invalidate_all_cache();
			$this->log_cache_invalidation( "Whitelisted plugin deactivated: {$plugin}" );
		}
	}

	/**
	 * Invalidate cache on theme switch.
	 *
	 * @since 1.0.0
	 *
	 * @param string $new_name    New theme name.
	 * @param WP_Theme $new_theme New theme instance.
	 */
	public function invalidate_cache_on_theme_switch( string $new_name, $new_theme = null ) {
		// Check if theme is in whitelist
		$whitelisted_themes = get_option( 'satispress_themes', [] );
		if ( in_array( $new_name, $whitelisted_themes, true ) ) {
			$this->invalidate_all_cache();
			$this->log_cache_invalidation( "Whitelisted theme activated: {$new_name}" );
		}
	}

	/**
	 * Handle manual cache clearing via AJAX.
	 *
	 * @since 1.0.0
	 */
	public function handle_manual_cache_clear() {
		// Check permissions and nonce
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions' );
		}

		if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'satispress_clear_cache' ) ) {
			wp_die( 'Invalid nonce' );
		}

		$result = $this->invalidate_all_cache();
		$this->log_cache_invalidation( 'Manual cache clear' );

		wp_send_json_success( [
			'message' => $result ? 
				__( 'Cache successfully cleared.', 'satispress' ) : 
				__( 'Error clearing cache.', 'satispress' )
		] );
	}

	/**
	 * Add cache clear button in admin.
	 *
	 * @since 1.0.0
	 */
	public function add_cache_clear_button() {
		add_action( 'satispress_settings_page_after_form', [ $this, 'render_cache_clear_button' ] );
	}

	/**
	 * Render cache clear button.
	 *
	 * @since 1.0.0
	 */
	public function render_cache_clear_button() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$nonce = wp_create_nonce( 'satispress_clear_cache' );
		?>
		<div class="satispress-cache-controls">
			<h3><?php esc_html_e( 'Cache Management', 'satispress' ); ?></h3>
			<p><?php esc_html_e( 'Clear the repository cache to force regeneration of packages.json.', 'satispress' ); ?></p>
			
			<button type="button" 
					id="satispress-clear-cache" 
					class="button button-secondary" 
					data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Clear Repository Cache', 'satispress' ); ?>
			</button>
			
			<div id="satispress-cache-status" style="margin-top: 10px;"></div>
		</div>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('#satispress-clear-cache').on('click', function() {
				var button = $(this);
				var status = $('#satispress-cache-status');
				
				button.prop('disabled', true).text('<?php esc_html_e( 'Clearing...', 'satispress' ); ?>');
				status.removeClass('notice-success notice-error').html('');
				
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'satispress_clear_cache',
						nonce: button.data('nonce')
					},
					success: function(response) {
						if (response.success) {
							status.addClass('notice notice-success').html('<p>' + response.data.message + '</p>');
						} else {
							status.addClass('notice notice-error').html('<p>' + response.data.message + '</p>');
						}
					},
					error: function() {
						status.addClass('notice notice-error').html('<p><?php esc_html_e( 'Error clearing cache.', 'satispress' ); ?></p>');
					},
					complete: function() {
						button.prop('disabled', false).text('<?php esc_html_e( 'Clear Repository Cache', 'satispress' ); ?>');
					}
				});
			});
		});
		</script>
		
		<style>
		.satispress-cache-controls {
			background: #fff;
			border: 1px solid #c3c4c7;
			border-radius: 4px;
			padding: 20px;
			margin-top: 20px;
		}
		.satispress-cache-controls h3 {
			margin-top: 0;
		}
		</style>
		<?php
	}

	/**
	 * Invalidate all cache.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	protected function invalidate_all_cache(): bool {
		$result = CachedComposer::invalidate_all_cache();
		
		/**
		 * Fired after cache invalidation.
		 *
		 * @since 1.0.0
		 *
		 * @param string $context Invalidation context.
		 */
		do_action( 'satispress_cache_invalidated', 'repository_change' );
		
		return $result;
	}

	/**
	 * Log cache invalidation.
	 *
	 * @since 1.0.0
	 *
	 * @param string $reason Invalidation reason.
	 */
	protected function log_cache_invalidation( string $reason ) {
		if ( ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ) {
			error_log( "SatisPress: Cache invalidated - {$reason}" );
		}
		
		/**
		 * Fired when cache is invalidated with specific reason.
		 *
		 * @since 1.0.0
		 *
		 * @param string $reason Invalidation reason.
		 */
		do_action( 'satispress_cache_invalidation_logged', $reason );
	}
} 