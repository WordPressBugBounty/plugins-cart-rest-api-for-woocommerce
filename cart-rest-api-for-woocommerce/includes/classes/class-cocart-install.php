<?php
/**
 * Class: CoCart_Install
 *
 * @author  Sébastien Dumont
 * @package CoCart\Classes
 * @since   1.2.0 Introduced.
 * @version 4.3.24
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * CoCart installation related functions and actions.
 *
 * @since 1.2.0 Introduced.
 */
class CoCart_Install {

	/**
	 * DB updates and callbacks that need to be run per version.
	 *
	 * @var array
	 */
	private static $db_updates = array(
		'3.0.0'  => array(
			'cocart_update_300_db_structure',
			'cocart_update_300_db_version',
		),
		'4.3.23' => array(
			'cocart_update_4323_db_structure',
			'cocart_update_4323_db_version',
		),
	);

	/**
	 * Constructor.
	 *
	 * @access public
	 *
	 * @static
	 *
	 * @since   1.2.0 Introduced.
	 * @version 4.0.0
	 */
	public static function init() {
		// Checks version of CoCart and install/update if needed.
		add_action( 'init', array( __CLASS__, 'check_version' ), 5 );
		add_action( 'init', array( __CLASS__, 'manual_database_update' ), 20 );
		add_action( 'cocart_run_update_callback', array( __CLASS__, 'run_update_callback' ) );
		add_action( 'cocart_update_db_to_current_version', array( __CLASS__, 'update_db_version' ) );
		add_action( 'admin_init', array( __CLASS__, 'install_actions' ) );

		// Run transfer sessions in the background when called.
		add_action( 'cocart_run_transfer_sessions', 'cocart_transfer_sessions' );

		// Drop tables when MU blog is deleted.
		add_filter( 'wpmu_drop_tables', array( __CLASS__, 'wpmu_drop_tables' ) );
	} // END init()

	/**
	 * Check plugin version and run the updater if necessary.
	 *
	 * This check is done on all requests and runs if the versions do not match.
	 *
	 * @access public
	 *
	 * @static
	 *
	 * @hook cocart_updated
	 */
	public static function check_version() {
		$cocart_version      = get_option( 'cocart_version' );
		$cocart_code_version = COCART_VERSION;
		$requires_update     = version_compare( $cocart_version, $cocart_code_version, '<' );
		if ( ! defined( 'IFRAME_REQUEST' ) && $requires_update ) {
			self::install();
			/**
			 * Hook: Runs after CoCart has been updated.
			 *
			 * @since 1.2.0 Introduced.
			 */
			do_action( 'cocart_updated' );
		}
	} // END check_version()

	/**
	 * Perform a manual database update when triggered by WooCommerce System Tools.
	 *
	 * @access public
	 *
	 * @static
	 *
	 * @since 3.0.0 Introduced.
	 */
	public static function manual_database_update() {
		$blog_id = get_current_blog_id();

		add_action( 'wp_' . $blog_id . '_cocart_updater_cron', array( __CLASS__, 'run_manual_database_update' ) );
	} // END manual_database_update()

	/**
	 * Run manual database update.
	 *
	 * @access public
	 *
	 * @static
	 *
	 * @version 3.0.0 Introduced.
	 */
	public static function run_manual_database_update() {
		self::update();
	} // END run_manual_database_update()

	/**
	 * Run an update callback when triggered by ActionScheduler.
	 *
	 * @access public
	 *
	 * @static
	 *
	 * @since 3.0.0 Introduced.
	 *
	 * @param string $callback Callback name.
	 */
	public static function run_update_callback( $callback ) {
		include_once COCART_ABSPATH . 'includes/cocart-update-functions.php';

		if ( is_callable( $callback ) ) {
			self::run_update_callback_start( $callback );
			$result = (bool) call_user_func( $callback );
			self::run_update_callback_end( $callback, $result );
		}
	} // END run_update_callback()

	/**
	 * Triggered when a callback will run.
	 *
	 * @access protected
	 *
	 * @static
	 *
	 * @since 3.0.0 Introduced.
	 *
	 * @param string $callback Callback name.
	 */
	protected static function run_update_callback_start( $callback ) {
		define( 'COCART_UPDATING', true );
	} // END run_update_callback_start()

	/**
	 * Triggered when a callback has ran.
	 *
	 * @access protected
	 *
	 * @static
	 *
	 * @since 3.0.0 Introduced.
	 *
	 * @param string $callback Callback name.
	 * @param bool   $result   Return value from callback. Non-false need to run again.
	 */
	protected static function run_update_callback_end( $callback, $result ) {
		if ( $result ) {
			WC()->queue()->add(
				'cocart_run_update_callback',
				array(
					'update_callback' => $callback,
				),
				'cocart-db-updates'
			);
		}
	} // END run_update_callback_end()

	/**
	 * Install actions when an update button is clicked within the admin area.
	 *
	 * @access public
	 *
	 * @static
	 *
	 * @since 3.0.0 Introduced.
	 */
	public static function install_actions() {
		if ( ! empty( $_GET['do_update_cocart'] ) ) {
			check_admin_referer( 'cocart_db_update', 'cocart_db_update_nonce' );
			CoCart_Logger::log( esc_html__( 'Manual database update triggered.', 'cart-rest-api-for-woocommerce' ), 'info' );
			self::update();
			CoCart_Admin_Notices::add_notice( 'update_db', true );
		}
	} // END install_actions()

	/**
	 * Install CoCart.
	 *
	 * @access public
	 *
	 * @static
	 *
	 * @since 1.2.0 Introduced.
	 *
	 * @hook cocart_installed
	 */
	public static function install() {
		if ( ! is_blog_installed() ) {
			return;
		}

		if ( ! version_compare( get_option( 'cocart_version' ), COCART_VERSION, '<' ) ) {
			return;
		}

		// Check if we are not already running this routine.
		if ( 'yes' === get_transient( 'cocart_installing' ) ) {
			return;
		}

		// If WooCommerce is NOT active, don't install CoCart.
		if ( ! defined( 'WC_VERSION' ) ) {
			CoCart_Admin_Notices::add_notice( 'check_wc' );
			return;
		}

		// If we made it till here nothing is running yet, lets set the transient now for five minutes.
		set_transient( 'cocart_installing', 'yes', MINUTE_IN_SECONDS * 5 );
		if ( ! defined( 'COCART_INSTALLING' ) ) {
			define( 'COCART_INSTALLING', true );
		}

		// Remove all admin notices.
		self::remove_admin_notices();

		// Install database tables.
		self::create_tables();
		self::verify_base_tables();

		// Creates cron jobs.
		self::create_cron_jobs();

		// Create files.
		self::create_files();

		// Set activation date.
		self::set_install_date();

		// Transfer sessions.
		self::transfer_sessions();

		// Maybe see if we need to enable the setup wizard or not.
		self::maybe_enable_setup_wizard();

		// Update plugin version.
		self::update_version();

		// Maybe update database version.
		self::maybe_update_db_version();

		delete_transient( 'cocart_installing' );

		/**
		 * Hook: Runs after CoCart has been installed.
		 *
		 * @since 1.2.0 Introduced.
		 */
		do_action( 'cocart_installed' );
	} // END install()

	/**
	 * Check if all the base tables are present.
	 *
	 * @access public
	 *
	 * @static
	 *
	 * @since   3.0.0 Introduced.
	 * @version 3.1.0
	 *
	 * @param bool $modify_notice Whether to modify notice based on if all tables are present.
	 * @param bool $execute       Whether to execute get_schema queries as well.
	 *
	 * @return array List of queries.
	 */
	public static function verify_base_tables( $modify_notice = true, $execute = false ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		if ( $execute ) {
			self::create_tables();
			self::maybe_update_db_version();
		}

		$queries        = dbDelta( self::get_schema(), false );
		$missing_tables = array();

		foreach ( $queries as $table_name => $result ) {
			if ( "Created table $table_name" === $result ) {
				$missing_tables[] = $table_name;
			}
		}

		if ( 0 < count( $missing_tables ) ) {
			if ( $modify_notice ) {
				CoCart_Admin_Notices::add_notice( 'base_tables_missing' );
			}

			update_option( 'cocart_schema_missing_tables', $missing_tables );
		} else {
			if ( $modify_notice ) {
				CoCart_Admin_Notices::remove_notice( 'base_tables_missing' );
			}

			delete_option( 'cocart_schema_missing_tables' );
		}

		return $missing_tables;
	} // END verify_base_tables()

	/**
	 * Reset any notices added to admin.
	 *
	 * @access private
	 *
	 * @static
	 *
	 * @since   3.0.0 Introduced.
	 * @version 3.0.12
	 */
	private static function remove_admin_notices() {
		if ( ! class_exists( 'CoCart_Admin_Notices' ) ) {
			include_once COCART_ABSPATH . 'includes/classes/admin/class-cocart-admin-notices.php';
		}
		CoCart_Admin_Notices::remove_all_notices();
	} // END remove_admin_notices()

	/**
	 * Is this a brand new CoCart install?
	 *
	 * A brand new install has no version yet. Also treat empty installs as 'new'.
	 *
	 * @access public
	 *
	 * @static
	 *
	 * @since 3.0.0 Introduced.
	 *
	 * @return boolean
	 */
	public static function is_new_install() {
		return is_null( get_option( 'cocart_version', null ) );
	} // END is_new_install()

	/**
	 * Is a Database update needed?
	 *
	 * @access public
	 *
	 * @static
	 *
	 * @since 3.0.0 Introduced.
	 *
	 * @return boolean
	 */
	public static function needs_db_update() {
		$current_db_version = get_option( 'cocart_db_version', null );
		$updates            = self::get_db_update_callbacks();
		$update_versions    = array_keys( $updates );
		usort( $update_versions, 'version_compare' );

		return ! is_null( $current_db_version ) && version_compare( $current_db_version, end( $update_versions ), '<' );
	} // END needs_db_update()

	/**
	 * See if we need the setup wizard or not.
	 *
	 * @access private
	 *
	 * @static
	 *
	 * @since 3.1.0 Introduced.
	 */
	private static function maybe_enable_setup_wizard() {
		/**
		 * Hook: Runs when CoCart needs to enable the setup wizard.
		 *
		 * @since 3.1.0 Introduced.
		 *
		 * @param bool $enable Whether to enable the setup wizard or not.
		 */
		if ( apply_filters( 'cocart_enable_setup_wizard', true ) && self::is_new_install() ) {
			CoCart_Admin_Notices::add_notice( 'setup_wizard', true );
			set_transient( '_cocart_activation_redirect', 1, 30 );
		}
	} // END maybe_enable_setup_wizard()

	/**
	 * See if we need to show or run database updates during install.
	 *
	 * @access private
	 *
	 * @static
	 *
	 * @since 3.0.0 Introduced.
	 */
	private static function maybe_update_db_version() {
		if ( self::needs_db_update() ) {
			/**
			 * Hook: Runs when CoCart needs to update the database.
			 *
			 * @since 3.0.0 Introduced.
			 *
			 * @param bool $update Whether to update the database or not.
			 */
			if ( apply_filters( 'cocart_enable_auto_update_db', true ) ) {
				CoCart_Logger::log( esc_html__( 'Automatic database update triggered.', 'cart-rest-api-for-woocommerce' ), 'info' );
				self::update();
			} else {
				CoCart_Admin_Notices::add_notice( 'update_db', true );
			}
		} else {
			self::update_db_version();
		}
	} // END maybe_update_db_version()

	/**
	 * Update plugin version to current.
	 *
	 * @access private
	 *
	 * @since   1.2.0 Introduced.
	 * @version 2.8.3
	 *
	 * @static
	 */
	private static function update_version() {
		update_option( 'cocart_version', COCART_VERSION );
	} // END update_version()

	/**
	 * Get list of DB update callbacks.
	 *
	 * @access public
	 *
	 * @static
	 *
	 * @since 3.0.0 Introduced.
	 *
	 * @return array Array of update callbacks.
	 */
	public static function get_db_update_callbacks() {
		return self::$db_updates;
	} // END get_db_update_callbacks()

	/**
	 * Push all needed DB updates to the queue for processing.
	 *
	 * @access private
	 *
	 * @static
	 *
	 * @since 3.0.0 Introduced.
	 */
	private static function update() {
		$current_db_version     = get_option( 'cocart_db_version' );
		$current_cocart_version = COCART_DB_VERSION;
		$loop                   = 0;

		CoCart_Logger::log(
			sprintf(
				/* translators: %1$s = Current DB version, %2$s = Installed CoCart Version */
				esc_html__( 'Scheduling database update (from %1$s to %2$s)...', 'cart-rest-api-for-woocommerce' ),
				$current_db_version,
				$current_cocart_version
			),
			'info'
		);

		foreach ( self::get_db_update_callbacks() as $version => $update_callbacks ) {
			if ( version_compare( $current_db_version, $version, '<' ) ) {
				foreach ( $update_callbacks as $update_callback ) {
					WC()->queue()->schedule_single(
						time() + $loop,
						'cocart_run_update_callback',
						array(
							'update_callback' => $update_callback,
						),
						'cocart-db-updates'
					);
					++$loop;
				}

				CoCart_Logger::log(
					sprint_f(
						/* translators: %s = Version of Database */
						esc_html__( 'Updates from version %s scheduled.', 'cart-rest-api-for-woocommerce' ),
						$version
					),
					'info'
				);
			}
		}

		// After the callbacks finish, update the db version to the current CoCart version.
		if ( version_compare( $current_db_version, $current_cocart_version, '<' ) &&
			! WC()->queue()->get_next( 'cocart_update_db_to_current_version' ) ) {
			WC()->queue()->schedule_single(
				time() + $loop,
				'cocart_update_db_to_current_version',
				array(
					'version' => $current_cocart_version,
				),
				'cocart-db-updates'
			);
		}

		CoCart_Logger::log( esc_html__( 'Database update scheduled.', 'cart-rest-api-for-woocommerce' ), 'info' );
	} // END update()

	/**
	 * Update CoCart DB version.
	 *
	 * @access public
	 *
	 * @static
	 *
	 * @since 2.9.0 Introduced.
	 *
	 * @param string|null $version New CoCart DB version or null.
	 */
	public static function update_db_version( $version = null ) {
		update_option( 'cocart_db_version', is_null( $version ) ? COCART_DB_VERSION : $version );
	} // END update_db_version()

	/**
	 * Set the time the plugin was installed.
	 *
	 * @access public
	 *
	 * @static
	 */
	public static function set_install_date() {
		add_option( 'cocart_install_date', time() );
	} // END set_install_date()

	/**
	 * Create cron jobs (clear them first).
	 *
	 * @access private
	 *
	 * @static
	 *
	 * @since   2.1.0 Introduced.
	 * @version 3.1.0
	 */
	private static function create_cron_jobs() {
		wp_clear_scheduled_hook( 'woocommerce_cleanup_sessions' ); // Remove WooCommerce cleanup sessions event.
		wp_clear_scheduled_hook( 'cocart_cleanup_carts' );

		wp_schedule_event( time() + ( 6 * HOUR_IN_SECONDS ), 'twicedaily', 'cocart_cleanup_carts' );
	} // END create_cron_jobs()

	/**
	 * Creates database tables which the plugin needs to function.
	 * WARNING: If you are modifying this method, make sure that its safe to call regardless of the state of database.
	 *
	 * This is called from `install` method and is executed in-sync when CoCart is installed or updated.
	 * This can also be called optionally from `verify_base_tables`.
	 *
	 * @access private
	 *
	 * @static
	 *
	 * @since   2.1.0 Introduced.
	 * @version 3.1.0
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	private static function create_tables() {
		global $wpdb;

		$show_errors = $wpdb->hide_errors();
		$table_name  = $wpdb->prefix . 'cocart_carts';

		$table = self::get_schema();

		$exists = self::maybe_create_table( $table_name, $table );

		if ( $show_errors ) {
			$wpdb->show_errors();
		}

		// If table does not exist, ask user if they have privileges.
		if ( ! $exists ) {
			self::add_create_table_notice( $table_name );
		}
	} // END create_tables()

	/**
	 * Get Table schema.
	 *
	 * @access private
	 *
	 * @static
	 *
	 * @since 3.0.0 Introduced.
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return string Table schema.
	 */
	private static function get_schema() {
		global $wpdb;

		$collate = $wpdb->has_cap( 'collation' ) ? $wpdb->get_charset_collate() : '';

		$table = "CREATE TABLE {$wpdb->prefix}cocart_carts (
cart_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
cart_key char(42) NOT NULL,
cart_value longtext NOT NULL,
cart_created bigint(20) unsigned NOT NULL,
cart_expiry bigint(20) unsigned NOT NULL,
cart_source varchar(200) NOT NULL,
cart_hash varchar(200) NOT NULL,
PRIMARY KEY  (cart_id),
UNIQUE KEY cart_key (cart_key)
) $collate;";

		return $table;
	} // END get_schema()

	/**
	 * Create database table, if it doesn't already exist.
	 *
	 * Based on admin/install-helper.php maybe_create_table function.
	 *
	 * @source https://developer.wordpress.org/reference/functions/maybe_create_table/
	 *
	 * @access protected
	 *
	 * @since 3.1.0 Introduced.
	 *
	 * @param string $table_name Database table name.
	 * @param string $create_sql Create database table SQL.
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return bool False on error, true if already exists or success.
	 */
	protected static function maybe_create_table( $table_name, $create_sql ) {
		global $wpdb;

		if ( in_array( $table_name, $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ), 0 ), true ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return true;
		}

		$wpdb->query( $create_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return in_array( $table_name, $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ), 0 ), true ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	} // END maybe_create_table()

	/**
	 * Add a notice if table creation fails.
	 *
	 * @access protected
	 *
	 * @static
	 *
	 * @since 3.1.0 Introduced.
	 *
	 * @param string $table_name Name of the missing table.
	 */
	protected static function add_create_table_notice( $table_name ) {
		set_transient( '_cocart_db_creation_failed', 1, MINUTE_IN_SECONDS * 5 );

		$notice = sprintf(
			/* translators: %2$s table name, %3$s database user, %4$s database name. */
			esc_html__( '%1$s %2$s table creation failed. Does the %3$s user have CREATE privileges on the %4$s database?', 'cart-rest-api-for-woocommerce' ),
			'CoCart',
			'<code>' . esc_html( $table_name ) . '</code>',
			'<code>' . esc_html( DB_USER ) . '</code>',
			'<code>' . esc_html( DB_NAME ) . '</code>'
		);

		CoCart_Admin_Notices::add_custom_notice( 'db_creation_failed', $notice );
	} // END add_create_table_notice()

	/**
	 * Return a list of CoCart tables. Used to make sure all CoCart tables
	 * are dropped when uninstalling the plugin in a single site
	 * or multi site environment.
	 *
	 * @access public
	 *
	 * @static
	 *
	 * @since 2.1.0 Introduced.
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return array $tables List of CoCart tables.
	 */
	public static function get_tables() {
		global $wpdb;

		$tables = array(
			"{$wpdb->prefix}cocart_carts",
		);

		return $tables;
	} // END get_tables()

	/**
	 * Drop CoCart tables.
	 *
	 * @access public
	 *
	 * @static
	 *
	 * @since 2.1.0 Introduced.
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;

		$tables = self::get_tables();

		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}
	} // END drop_tables()

	/**
	 * Uninstall tables when MU blog is deleted.
	 *
	 * @access public
	 *
	 * @since 2.1.0 Introduced.
	 *
	 * @param array $tables List of tables that will be deleted by WP.
	 *
	 * @return array $tables List of tables that will be deleted by WP.
	 */
	public static function wpmu_drop_tables( $tables ) {
		return array_merge( $tables, self::get_tables() );
	} // END wpmu_drop_tables()

	/**
	 * Create files/directories.
	 *
	 * @access private
	 *
	 * @static
	 *
	 * @since 3.0.0 Introduced.
	 */
	private static function create_files() {
		/**
		 * Bypass if filesystem is read-only and/or non-standard upload system is used.
		 *
		 * @since 3.0.0 Introduced.
		 *
		 * @param bool $skip Whether to skip creating files or not.
		 */
		if ( apply_filters( 'cocart_install_skip_create_files', false ) ) {
			return;
		}

		// Install files and folders for uploading files and prevent hotlinking.
		$upload_dir = wp_get_upload_dir();

		$files = array(
			array(
				'base'    => $upload_dir['basedir'] . '/cocart_uploads',
				'file'    => 'index.html',
				'content' => '',
			),
			array(
				'base'    => $upload_dir['basedir'] . '/cocart_uploads',
				'file'    => '.htaccess',
				'content' => 'deny from all',
			),
		);

		foreach ( $files as $file ) {
			if ( wp_mkdir_p( $file['base'] ) && ! file_exists( trailingslashit( $file['base'] ) . $file['file'] ) ) {
				$file_handle = @fopen( trailingslashit( $file['base'] ) . $file['file'], 'wb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_read_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fopen

				if ( $file_handle ) {
					fwrite( $file_handle, $file['content'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
					fclose( $file_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				}
			}
		}
	} // END create_files()

	/**
	 * Transfers sessions in the background.
	 *
	 * @access private
	 *
	 * @static
	 *
	 * @since 4.4.0 Introduced.
	 *
	 * @return void
	 */
	private static function transfer_sessions() {
		if ( self::is_new_install() ) {
			WC()->queue()->schedule_single( time(), 'cocart_run_transfer_sessions', array(), 'cocart-transfer-sessions' );

			CoCart_Logger::log( esc_html__( 'Sessions scheduled for transfer.', 'cart-rest-api-for-woocommerce' ), 'info' );
		}
	} // END transfer_sessions()
} // END class.

CoCart_Install::init();
