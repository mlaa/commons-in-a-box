<?php
/**
 * CBox's Plugin Upgrade and Install API
 *
 * @package Commons_In_A_Box
 * @subpackage Plugins
 */

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

// require the WP_Upgrader class so we can extend it!
require_once( ABSPATH . 'wp-admin/includes/class-wp-upgrader.php' );

// add the Plugin Dependencies plugin; just in case this file is called outside the admin area
if ( ! class_exists( 'Plugin_Dependencies' ) )
	require_once( CIAB_LIB_DIR . 'wp-plugin-dependencies/plugin-dependencies.php' );

/**
 * CBox's custom plugin upgrader.
 *
 * Extends the {@link Plugin_Upgrader} class to allow for our custom required spec.
 *
 * @package Commons_In_A_Box
 * @subpackage Plugins
 */
class CBox_Plugin_Upgrader extends Plugin_Upgrader {

	/**
	 * Overrides the parent {@link Plugin_Upgrader::bulk_upgrader()} method.
	 *
	 * Uses CBox's own registered upgrade links.
	 *
	 * @param str $plugins Array of plugin names
	 */
	function bulk_upgrade( $plugins ) {

		$this->init();
		$this->bulk = true;
		$this->upgrade_strings();

		$current = CIAB_Plugins::required_plugins();

		add_filter('upgrader_clear_destination', array(&$this, 'delete_old_plugin'), 10, 4);

		$this->skin->header();

		// Connect to the Filesystem first.
		$res = $this->fs_connect( array(WP_CONTENT_DIR, WP_PLUGIN_DIR) );
		if ( ! $res ) {
			$this->skin->footer();
			return false;
		}

		$this->skin->bulk_header();

		// Only start maintenance mode if running in Multisite OR the plugin is in use
		$maintenance = is_multisite(); // @TODO: This should only kick in for individual sites if at all possible.
		foreach ( $plugins as $plugin ) {
			$plugin_loader = Plugin_Dependencies::get_pluginloader_by_name( $plugin );
			$maintenance = $maintenance || (is_plugin_active($plugin_loader) ); // Only activate Maintenance mode if a plugin is active
		}

		if ( $maintenance )
			$this->maintenance_mode(true);

		$results = array();

		$this->update_count = count($plugins);
		$this->update_current = 0;
		foreach ( $plugins as $plugin ) {
			$this->update_current++;
			$this->skin->plugin_info['Title'] = $plugin;

			// see if plugin is active
			$plugin_loader = Plugin_Dependencies::get_pluginloader_by_name( $plugin );
			$this->skin->plugin_active = is_plugin_active( $plugin_loader );

			$result = $this->run(array(
						'package' => $current[$plugin]['download_url'],
						'destination' => WP_PLUGIN_DIR,
						'clear_destination' => true,
						'clear_working' => true,
						'is_multi' => true,
						'hook_extra' => array(
									'plugin' => $plugin_loader
						)
					));

			$results[$plugin_loader] = $this->result;

			// Prevent credentials auth screen from displaying multiple times
			if ( false === $result )
				break;
		} //end foreach $plugins

		$this->maintenance_mode(false);

		$this->skin->bulk_footer();

		$this->skin->footer();

		// Cleanup our hooks, in case something else does a upgrade on this connection.
		remove_filter('upgrader_clear_destination', array(&$this, 'delete_old_plugin'));

		// Force refresh of plugin update information
		delete_site_transient('update_plugins');

		return $results;
	}

	/**
	 * Bulk install plugins.
	 *
	 * Download links for plugins are listed in {@link CIAB_Plugins::required_plugins()}.
	 *
	 * @param str $plugins Array of plugin names
	 */
	function bulk_install( $plugins ) {

		$this->init();
		$this->bulk = true;
		$this->install_strings();

		// download URLs for each plugin should be registered in either the following:
		$dependency = CIAB_Plugins::dependency_plugins();
		$required = CIAB_Plugins::required_plugins();

		add_filter('upgrader_source_selection', array(&$this, 'check_package') );

		$this->skin->header();

		// Connect to the Filesystem first.
		$res = $this->fs_connect( array(WP_CONTENT_DIR, WP_PLUGIN_DIR) );
		if ( ! $res ) {
			$this->skin->footer();
			return false;
		}

		$this->skin->bulk_header();

		$results = array();

		$this->update_count = count( $plugins);
		$this->update_current = 0;
		foreach ( $plugins as $plugin ) {
			$this->update_current++;
			$this->skin->plugin_info['Title'] = $plugin;

			// set the download URL
			if ( ! empty( $dependency[$plugin]['download_url'] ) )
				$download_url = $dependency[$plugin]['download_url'];
			elseif ( ! empty( $required[$plugin]['download_url'] ) )
				$download_url = $required[$plugin]['download_url'];
			else
				$download_url = false;

			$result = $this->run(array(
						'package' => $download_url,
						'destination' => WP_PLUGIN_DIR,
						'clear_destination' => false, //Do not overwrite files.
						'clear_working' => true,
						'is_multi' => true,
						'hook_extra' => array()
					));

			//$results[$plugin_loader] = $this->result;

			// Prevent credentials auth screen from displaying multiple times
			if ( false === $result )
				break;
		} //end foreach $plugins

		$this->skin->bulk_footer();

		$this->skin->footer();

		// Cleanup our hooks, in case something else does a upgrade on this connection.
		remove_filter('upgrader_source_selection', array(&$this, 'check_package') );

		// Force refresh of plugin update information
		delete_site_transient('update_plugins');

		return $results;
	}

	/**
	 * Bulk activates plugins.
	 *
	 * @param str $plugins Array of plugin names
	 */
	function bulk_activate( $plugins ) {

		if ( empty( $plugins ) )
			return false;

		// Only activate plugins which are not already active.
		$check = is_multisite() ? 'is_plugin_active_for_network' : 'is_plugin_active';

		foreach ( $plugins as $i => $plugin ) {
			$plugin_loader = Plugin_Dependencies::get_pluginloader_by_name( $plugin );

			// if already active, skip!
			if ( ! empty( $plugin_loader ) && $check( $plugin ) ) {
				unset( $plugins[ $i ] );
				continue;
			}

			// activate the plugin
			activate_plugin( $plugin_loader );
		}

		$recent = (array)get_option('recently_activated');
		foreach ( $plugins as $plugin => $time)
			if ( isset($recent[ $plugin ]) )
				unset($recent[ $plugin ]);

		update_option('recently_activated', $recent);

		return true;
	}

}

/**
 * The UI for CBox's updater.
 *
 * Extends the {@link Bulk_Plugin_Upgrader_Skin} class.
 *
 * @package Commons_In_A_Box
 * @subpackage Plugins
 */
class CBox_Bulk_Plugin_Upgrader_Skin extends Bulk_Plugin_Upgrader_Skin {


	/**
	 * Setup our custom strings.
	 *
	 * Needed when bulk-installing to prevent the default bulk-upgrader strings to be used.
	 */
	function add_strings() {
		parent::add_strings();

		// if first step is bulk-upgrading, then stop string overrides!
		if ( ! empty( $this->options['step_one'] ) && $this->options['step_one'] == 'upgrade' )
			return;

		// if we're bulk-installing, switch up the strings!
		if ( ! empty( $this->options['install_strings'] ) ) {
			$this->upgrader->strings['skin_before_update_header'] = __( 'Installing Plugin %1$s (%2$d/%3$d)', 'cbox' );

			$this->upgrader->strings['skin_upgrade_start']        = __( 'The installation process is starting. This process may take a while on some hosts, so please be patient.', 'cbox' );
			$this->upgrader->strings['skin_update_failed_error']  = __( 'An error occurred while installing %1$s: <strong>%2$s</strong>.', 'cbox' );
			$this->upgrader->strings['skin_update_failed']        = __( 'The installation of %1$s failed.', 'cbox' );
			$this->upgrader->strings['skin_update_successful']    = __( '%1$s installed successfully.', 'cbox' ) . ' <a onclick="%2$s" href="#" class="hide-if-no-js"><span>' . __( 'Show Details', 'cbox' ) . '</span><span class="hidden">' . __( 'Hide Details', 'cbox' ) . '</span>.</a>';
			$this->upgrader->strings['skin_upgrade_end']          = __( 'Plugins finished installing.', 'cbox' );
		}
	}

	/**
	 * After the bulk-upgrader has completed, do some extra stuff.
	 *
	 * We try to upgrade plugins first.  Next, we install plugins that are not available.
	 * Lastly, we activate any plugins needed.
	 */
	function bulk_footer() {
		// install plugins after the upgrader is done if available
		if ( ! empty( $this->options['install_plugins'] ) ) {
			if (! empty( $this->options['activate_plugins'] ) ) {
				$skin_args['activate_plugins'] = $this->options['activate_plugins'];
			}

			$skin_args['install_strings'] = true;

			echo '<p>' . __( 'Plugins updated.', 'cbox' ) . '</p>';

			usleep(500000);

			echo '<h3>' . __( 'Now Installing Plugins...', 'cbox' ) . '</h3>';

			usleep(500000);

 			$installer = new CBox_Plugin_Upgrader(
 				new CBox_Bulk_Plugin_Upgrader_Skin( $skin_args )
 			);

 			$installer->bulk_install( $this->options['install_plugins'] );
		}

		// activate plugins after the upgrader / installer is done if available
		elseif ( ! empty( $this->options['activate_plugins'] ) ) {
			usleep(500000);

			echo '<h3>' . __( 'Now Activating Plugins...', 'cbox' ) . '</h3>';

			usleep(500000);

 			$activate = CBox_Plugin_Upgrader::bulk_activate( $this->options['activate_plugins'] );
 		?>

			<p><?php _e( 'Plugins activated.', 'cbox' ); ?></p>

			<p><?php echo '<a href="' . self_admin_url( 'admin.php?page=cbox' ) . '" title="' . esc_attr__( 'Go to Cbox dashboard' ) . '" target="_parent">' . __('Return to CBox dashboard') . '</a>';?></p>
 		<?php
		}

		// process is completed!
		// show link to Cbox dashboard
		else {
			echo '<a href="' . self_admin_url( 'admin.php?page=cbox' ) . '" title="' . esc_attr__( 'Go to Cbox dashboard' ) . '" target="_parent">' . __('Return to CBox dashboard') . '</a>';
		}
	}

	/**
	 * Overriding this so we can change the ID of the DIV so toggling works... sigh...
	 */
	function before() {
		$title = $this->plugin_info['Title'];

		if ( ! empty( $this->options['install_plugins'] ) ) {
			$current = $this->upgrader->update_current;
			$this->upgrader->update_current = 'install-' . $current;
		}

		$this->in_loop = true;
		printf( '<h4>' . $this->upgrader->strings['skin_before_update_header'] . ' <img alt="" src="' . admin_url( 'images/wpspin_light.gif' ) . '" class="hidden waiting-' . $this->upgrader->update_current . '" style="vertical-align:middle;" /></h4>',  $title, $this->upgrader->update_current, $this->upgrader->update_count);
		echo '<script type="text/javascript">jQuery(\'.waiting-' . esc_js($this->upgrader->update_current) . '\').show();</script>';
		echo '<div class="update-messages hide-if-js" id="progress-' . esc_attr($this->upgrader->update_current) . '"><p>';

		if ( ! empty( $this->options['install_plugins'] ) ) {
			$this->upgrader->update_current = $current;
		}

		$this->flush_output();
	}

	/**
	 * Overriding this so we can change the ID of the DIV so toggling works... sigh...
	 */
	function after() {
		$title = $this->plugin_info['Title'];

		if ( ! empty( $this->options['install_plugins'] ) ) {
			$current = $this->upgrader->update_current;
			$this->upgrader->update_current = 'install-' . $current;
		}

		echo '</p></div>';
		if ( $this->error || ! $this->result ) {
			if ( $this->error )
				echo '<div class="error"><p>' . sprintf($this->upgrader->strings['skin_update_failed_error'], $title, $this->error) . '</p></div>';
			else
				echo '<div class="error"><p>' . sprintf($this->upgrader->strings['skin_update_failed'], $title) . '</p></div>';

			echo '<script type="text/javascript">jQuery(\'#progress-' . esc_js($this->upgrader->update_current) . '\').show();</script>';
		}
		if ( !empty($this->result) && !is_wp_error($this->result) ) {
			echo '<div class="updated"><p>' . sprintf($this->upgrader->strings['skin_update_successful'], $title, 'jQuery(\'#progress-' . esc_js($this->upgrader->update_current) . '\').toggle();jQuery(\'span\', this).toggle(); return false;') . '</p></div>';
			echo '<script type="text/javascript">jQuery(\'.waiting-' . esc_js($this->upgrader->update_current) . '\').hide();</script>';
		}

		if ( ! empty( $this->options['install_plugins'] ) ) {
			$this->upgrader->update_current = $current;
		}

		$this->reset();
		$this->flush_output();
	}
}

/**
 * CBox Updater.
 *
 * Wraps the bulk-upgrading, bulk-installing and bulk-activating process into one!
 *
 * @package Commons_In_A_Box
 * @subpackage Plugins
 */
class CBox_Updater {

	private static $is_upgrade  = false;
	private static $is_install  = false;
	private static $is_activate = false;

	/**
	 * Constructor.
	 *
	 * @param array $plugins Associative array of plugin names
	 */
	function __construct( $plugins = false ) {
		if ( ! empty( $plugins['upgrade'] ) )
			self::$is_upgrade  = true;

		if( ! empty( $plugins['install'] ) )
			self::$is_install  = true;

		if( ! empty( $plugins['activate'] ) )
			self::$is_activate = true;

		// dependency-time!
		// flatten the associative array to make dependency checks easier
		$plugin_list = call_user_func_array( 'array_merge', $plugins );

		// get requirements
		$requirements = Plugin_Dependencies::get_requirements();

		// loop through each submitted plugin and check for any dependencies
		foreach( $plugin_list as $plugin ) {
			// we have dependents!
			if ( ! empty( $requirements[$plugin] ) ) {

				// now loop through each dependent plugin state and add that plugin to our list
				// before we start the whole process!
				foreach( $requirements[$plugin] as $dep_state => $dep_plugins ) {
					switch( $dep_state ) {
						case 'inactive' :
							if ( ! self::$is_activate ) {
								$plugins['activate'] = array();
								self::$is_activate = true;
							}

							// push dependent plugins to the beginning of the activation plugins list
							$plugins['activate'] = array_merge( $dep_plugins, $plugins['activate'] );

							break;

						case 'not-installed' :
							if ( ! self::$is_install ) {
								$plugins['install'] = array();
								self::$is_install = true;
							}

							// push dependent plugins to the beginning of the installation plugins list
							$plugins['install'] = array_merge( $dep_plugins, $plugins['install'] );

							break;

						case 'incompatible' :
							if ( ! self::$is_upgrade ) {
								$plugins['upgrade'] = array();
								self::$is_upgrade = true;
							}

							$plugin_names = wp_list_pluck( $dep_plugins, 'name' );

							// push dependent plugins to the beginning of the upgrade plugins list
							$plugins['upgrade'] = array_merge( $plugin_names, $plugins['upgrade'] );

							break;
					}
				}
			}
		}

		// this tells WP_Upgrader to activate the plugin after any upgrade or successful install
		add_filter( 'upgrader_post_install', array( &$this, 'activate_post_install' ), 10, 3 );

 		// start the whole damn thing!
 		// We always try to upgrade plugins first.  Next, we install plugins that are not available.
 		// Lastly, we activate any plugins needed.

 		// let's see if upgrades are available; if so, start with that
 		if ( self::$is_upgrade ) {
			// if installs are available as well, this tells CBox_Plugin_Upgrader 
			// to install plugins after the upgrader is done
			if ( self::$is_install ) {
				$skin_args['install_plugins'] = $plugins['install'];
				$skin_args['install_strings'] = true;
			}

			// if activations are available as well, this tells CBox_Plugin_Upgrader 
			// to activate plugins after the upgrader is done
			if ( self::$is_activate ) {
				$skin_args['activate_plugins'] = $plugins['activate'];
			}

			// tell the installer that this is the first step
			$skin_args['step_one'] = 'upgrade';

			echo '<h3>' . __( 'Upgrading Existing Plugins...', 'cbox' ) . '</h3>';

 			// instantiate the upgrader
 			// we add our custom arguments to the skin
 			$installer = new CBox_Plugin_Upgrader(
 				new CBox_Bulk_Plugin_Upgrader_Skin( $skin_args )
 			);

 			// now start the upgrade!
 			$installer->bulk_upgrade( $plugins['upgrade'] );
 		}

		// if no upgrades are available, move on to installs
 		elseif( self::$is_install ) {
			// if activations are available as well, this tells CBox_Plugin_Upgrader 
			// to activate plugins after the upgrader is done
			if ( self::$is_activate ) {
				$skin_args['activate_plugins'] = $plugins['activate'];
			}

			$skin_args['install_strings'] = true;

			echo '<h3>' . __( 'Installing Plugins...', 'cbox' ) . '</h3>';

 			// instantiate the upgrader
 			// we add our custom arguments to the skin
 			$installer = new CBox_Plugin_Upgrader(
 				new CBox_Bulk_Plugin_Upgrader_Skin( $skin_args )
 			);

 			// now start the install!
 			$installer->bulk_install( $plugins['install'] );
 		}

		// if no upgrades or installs are available, move on to activations
 		elseif( self::$is_activate ) {
			echo '<h3>' . __( 'Activating Plugins...', 'cbox' ) . '</h3>';

 			$activate = CBox_Plugin_Upgrader::bulk_activate( $plugins['activate'] );
 		?>

			<p><?php _e( 'Plugins activated.', 'cbox' ); ?></p>

			<p><?php printf( __( 'Return to the <a href="%s">CBox dashboard</a>.', 'cbox' ), self_admin_url( 'admin.php?page=cbox' ) ); ?></p>
 		<?php
 		}

	}

	/**
	 * Activates a plugin after upgrading or installing a plugin
	 */
	public function activate_post_install( $bool, $hook_extra, $result ) {

		// activates a plugin post-upgrade
		if ( ! empty( $hook_extra['plugin'] ) ) {
			activate_plugin( $hook_extra['plugin'] );
		}
		// activates a plugin post-install
		elseif ( ! empty( $result['destination_name'] ) ) {
			// when a plugin is installed, we need to find the plugin loader file
			$plugin_loader = array_keys( get_plugins( '/' . $result['destination_name'] ) );
			$plugin_loader = $plugin_loader[0];

			// this makes sure that validate_plugin() works in activate_plugin()
			wp_cache_flush();

			// now activate the plugin
			activate_plugin( $result['destination_name'] . '/' . $plugin_loader );
		}

		return $bool;
	}
}

?>