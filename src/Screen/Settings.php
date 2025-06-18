<?php
/**
 * Settings screen provider.
 *
 * @package SatisPress
 * @license GPL-2.0-or-later
 * @since 0.2.0
 */

declare ( strict_types = 1 );

namespace SatisPress\Screen;

use Cedaro\WP\Plugin\AbstractHookProvider;
use SatisPress\Authentication\ApiKey\ApiKey;
use SatisPress\Authentication\ApiKey\ApiKeyRepository;
use SatisPress\Capabilities;
use SatisPress\Provider\HealthCheck;
use WP_Theme;

use function SatisPress\get_packages_permalink;
use function SatisPress\preload_rest_data;

/**
 * Settings screen provider class.
 *
 * @since 0.2.0
 */
class Settings extends AbstractHookProvider {
	/**
	 * API Key repository.
	 *
	 * @var ApiKeyRepository
	 */
	protected $api_keys;

	/**
	 * Create the setting screen.
	 *
	 * @param ApiKeyRepository $api_keys API Key repository.
	 */
	public function __construct( ApiKeyRepository $api_keys ) {
		$this->api_keys = $api_keys;
	}

	/**
	 * Register hooks.
	 *
	 * @since 0.3.0
	 */
	public function register_hooks() {
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', [ $this, 'add_menu_item' ] );
		} else {
			add_action( 'admin_menu', [ $this, 'add_menu_item' ] );
		}

		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'add_sections' ] );
		add_action( 'admin_init', [ $this, 'add_settings' ] );
	}

	/**
	 * Add the settings menu item.
	 *
	 * @since 0.2.0
	 */
	public function add_menu_item() {
		$parent_slug = 'options-general.php';
		if ( is_network_admin() ) {
			$parent_slug = 'settings.php';
		}

		$page_hook = add_submenu_page(
			$parent_slug,
			esc_html__( 'SatisPress', 'satispress' ),
			esc_html__( 'SatisPress', 'satispress' ),
			Capabilities::MANAGE_OPTIONS,
			'satispress',
			[ $this, 'render_screen' ]
		);

		add_action( 'load-' . $page_hook, [ $this, 'load_screen' ] );
	}

	/**
	 * Set up the screen.
	 *
	 * @since 0.3.0
	 */
	public function load_screen() {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_notices', [ HealthCheck::class, 'display_authorization_notice' ] );
		add_action( 'admin_notices', [ HealthCheck::class, 'display_permalink_notice' ] );
	}

	/**
	 * Enqueue scripts and styles.
	 *
	 * @since 0.2.0
	 */
	public function enqueue_assets() {
		wp_enqueue_script( 'satispress-admin' );
		wp_enqueue_style( 'satispress-admin' );
		wp_enqueue_script( 'satispress-access' );
		wp_enqueue_script( 'satispress-repository' );

		wp_localize_script(
			'satispress-access',
			'_satispressAccessData',
			[
				'editedUserId' => get_current_user_id(),
			]
		);

		$preload_paths = [
			'/satispress/v1/packages',
		];

		if ( current_user_can( Capabilities::MANAGE_OPTIONS ) ) {
			$preload_paths = array_merge(
				$preload_paths,
				[
					'/satispress/v1/apikeys?user=' . get_current_user_id(),
					'/satispress/v1/plugins?_fields=slug,name,type',
					'/satispress/v1/themes?_fields=slug,name,type',
				]
			);
		}

		preload_rest_data( $preload_paths );
	}

	/**
	 * Register settings.
	 *
	 * @since 0.2.0
	 */
	public function register_settings() {
		register_setting( 'satispress', 'satispress', [ $this, 'sanitize_settings' ] );
	}

	/**
	 * Add settings sections.
	 *
	 * @since 0.2.0
	 */
	public function add_sections() {
		add_settings_section(
			'default',
			esc_html__( 'General', 'satispress' ),
			'__return_null',
			'satispress'
		);
	}

	/**
	 * Register individual settings.
	 *
	 * @since 0.2.0
	 */
	public function add_settings() {
		add_settings_field(
			'vendor',
			'<label for="satispress-vendor">' . esc_html__( 'Vendor', 'satispress' ) . '</label>',
			[ $this, 'render_field_vendor' ],
			'satispress',
			'default'
		);

		add_settings_field(
			'cache_duration',
			'<label for="satispress-cache-duration">' . esc_html__( 'Cache Duration', 'satispress' ) . '</label>',
			[ $this, 'render_field_cache_duration' ],
			'satispress',
			'default'
		);

		add_settings_field(
			'enable_cache',
			'<label for="satispress-enable-cache">' . esc_html__( 'Enable Cache', 'satispress' ) . '</label>',
			[ $this, 'render_field_enable_cache' ],
			'satispress',
			'default'
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @since 0.2.0
	 *
	 * @param array $value Settings values.
	 * @return array Sanitized and filtered settings values.
	 */
	public function sanitize_settings( array $value ): array {
		if ( ! empty( $value['vendor'] ) ) {
			$value['vendor'] = preg_replace( '/[^a-z0-9_\-\.]+/i', '', $value['vendor'] );
		}

		// Handle cache duration
		if ( isset( $value['cache_duration'] ) ) {
			$hours = floatval( $value['cache_duration'] );
			$seconds = max( 60, $hours * HOUR_IN_SECONDS ); // Minimum 1 minute
			update_option( 'satispress_cache_duration', (int) $seconds );
			unset( $value['cache_duration'] ); // Don't save in main option
		}

		// Handle cache enablement
		$enable_cache = isset( $value['enable_cache'] ) && $value['enable_cache'];
		update_option( 'satispress_enable_cache', $enable_cache );
		unset( $value['enable_cache'] ); // Don't save in main option

		return (array) apply_filters( 'satispress_sanitize_settings', $value );
	}

	/**
	 * Display the screen.
	 *
	 * @since 0.2.0
	 */
	public function render_screen() {
		$permalink = esc_url( get_packages_permalink() );

		$tabs = [
			'repository' => [
				'name'       => esc_html__( 'Repository', 'satispress' ),
				'capability' => Capabilities::VIEW_PACKAGES,
			],
			'access'     => [
				'name'       => esc_html__( 'Access', 'satispress' ),
				'capability' => Capabilities::MANAGE_OPTIONS,
				'is_active'  => false,
			],
			'composer'   => [
				'name'       => esc_html__( 'Composer', 'satispress' ),
				'capability' => Capabilities::VIEW_PACKAGES,
			],
			'settings'   => [
				'name'       => esc_html__( 'Settings', 'satispress' ),
				'capability' => Capabilities::MANAGE_OPTIONS,
			],
		];

		$active_tab = 'repository';

		include $this->plugin->get_path( 'views/screen-settings.php' );
	}

	/**
	 * Display a field for defining the vendor.
	 *
	 * @since 0.2.0
	 */
	public function render_field_vendor() {
		$value = $this->get_setting( 'vendor', '' );
		?>
		<p>
			<input type="text" name="satispress[vendor]" id="satispress-vendor" value="<?php echo esc_attr( $value ); ?>"><br />
			<span class="description">Default is <code>satispress</code></span>
		</p>
		<?php
	}

	/**
	 * Display the cache duration field.
	 *
	 * @since 1.0.0
	 */
	public function render_field_cache_duration() {
		$value = get_option( 'satispress_cache_duration', HOUR_IN_SECONDS );
		
		// Convert to hours for display
		$hours = $value / HOUR_IN_SECONDS;
		?>
		<p>
			<input type="number" 
				   id="satispress-cache-duration" 
				   name="satispress[cache_duration]" 
				   value="<?php echo esc_attr( $hours ); ?>" 
				   min="0" 
				   step="1" 
				   class="small-text">
			<label for="satispress-cache-duration"><?php esc_html_e( 'hours', 'satispress' ); ?></label><br>
			<span class="description">
				<?php esc_html_e( 'How long to cache the repository data. Minimum: 1 minute (0.016 hours).', 'satispress' ); ?>
			</span>
		</p>
		<?php
	}

	/**
	 * Display the cache enable field.
	 *
	 * @since 1.0.0
	 */
	public function render_field_enable_cache() {
		$enabled = get_option( 'satispress_enable_cache', true );
		?>
		<p>
			<label>
				<input type="checkbox" 
					   id="satispress-enable-cache" 
					   name="satispress[enable_cache]" 
					   value="1" 
					   <?php checked( $enabled ); ?>>
				<?php esc_html_e( 'Enable repository caching', 'satispress' ); ?>
			</label><br>
			<span class="description">
				<?php esc_html_e( 'Cache the packages.json response to improve performance. Disable for debugging.', 'satispress' ); ?>
			</span>
		</p>
		<?php
	}

	/**
	 * Retrieve a setting.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key     Setting name.
	 * @param mixed  $default Optional. Default setting value.
	 * @return mixed
	 */
	protected function get_setting( string $key, $default = null ) {
		$option = get_option( 'satispress' );

		return $option[ $key ] ?? $default;
	}
}
