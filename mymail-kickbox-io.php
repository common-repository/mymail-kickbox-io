<?php
/*
Plugin Name: MyMail Kickbox.io Integration
Plugin URI: https://evp.to/mymail?utm_campaign=wporg&utm_source=MyMail+Kickbox.io+Integration
Description: Verifies your subscribers email addresses with kickbox.io
Version: 0.2
Author: EverPress
Author URI: https://everpress.co

License: GPLv2 or later
*/


define( 'MYMAIL_KICKBOXIO_VERSION', '0.2' );
define( 'MYMAIL_KICKBOXIO_REQUIRED_VERSION', '2.0.30' );
define( 'MYMAIL_KICKBOXIO_DOMAIN', 'mymail-kickboxio' );

class MyMailKickBoxIO {

	private $plugin_path;
	private $plugin_url;

	public function __construct() {

		$this->plugin_path = plugin_dir_path( __FILE__ );
		$this->plugin_url  = plugin_dir_url( __FILE__ );

		register_activation_hook( __FILE__, array( &$this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( &$this, 'deactivate' ) );

		load_plugin_textdomain( 'mymail-kickboxio', false, dirname( plugin_basename( __FILE__ ) ) . '/lang' );

		add_action( 'init', array( &$this, 'init' ), 1 );
	}

	public function activate( $network_wide ) {

		if ( function_exists( 'mymail' ) ) {

			mymail_notice( sprintf( __( 'Define your kickbox.io options on the %s!', 'mymail-kickboxio' ), '<a href="options-general.php?page=newsletter-settings&mymail_remove_notice=kickboxio#kickboxio">Settings Page</a>' ), '', false, 'kickboxio' );

			$defaults = array(
				'kickboxio_timeout'        => 6000,
				'kickboxio_response'       => array( 'deliverable', 'risky', 'unknown' ),
				'kickboxio_reasons'        => array( 'invalid_domain', 'rejected_email' ),
				'kickboxio_role'           => true,
				'kickboxio_free'           => true,
				'kickboxio_accept_all'     => true,
				'kickboxio_response_error' => __( 'Sorry, your email address is not accepted!', 'mymail-kickboxio' ),
				'kickboxio_reasons_error'  => __( 'Sorry, your email address is not accepted!', 'mymail-kickboxio' ),
				'kickboxio_error'          => __( 'Sorry, your email address is not accepted!', 'mymail-kickboxio' ),
				'kickboxio_sendex'         => 0.4,
			);

			$mymail_options = mymail_options();

			foreach ( $defaults as $key => $value ) {
				if ( ! isset( $mymail_options[ $key ] ) ) {
					mymail_update_option( $key, $value );
				}
			}
		}

	}

	public function deactivate( $network_wide ) {}


	public function init() {

		if ( ! function_exists( 'mymail' ) ) {

			add_action( 'admin_notices', array( $this, 'notice' ) );

		} else {

			if ( is_admin() ) {

				add_filter( 'mymail_setting_sections', array( &$this, 'settings_tab' ) );
				add_action( 'mymail_section_tab_kickboxio', array( &$this, 'settings' ) );

			}

			add_action( 'mymail_verify_subscriber', array( $this, 'verify_subscriber' ) );

		}

		if ( function_exists( 'mailster' ) ) {

			add_action(
				'admin_notices',
				function() {

					$name = 'MyMail Kickbox.io Integration';
					$slug = 'mailster-kickbox-io/mailster-kickbox-io.php';

					$install_url = wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=' . dirname( $slug ) ), 'install-plugin_' . dirname( $slug ) );

					$search_url = add_query_arg(
						array(
							's'    => $slug,
							'tab'  => 'search',
							'type' => 'term',
						),
						admin_url( 'plugin-install.php' )
					);

					?>
			<div class="error">
				<p>
				<strong><?php echo esc_html( $name ); ?></strong> is deprecated in Mailster and no longer maintained! Please switch to the <a href="<?php echo esc_url( $search_url ); ?>">new version</a> as soon as possible or <a href="<?php echo esc_url( $install_url ); ?>">install it now!</a>
				</p>
			</div>
					<?php

				}
			);
		}

	}

	public function verify_subscriber( $entry ) {

		if ( ! isset( $entry['email'] ) ) {
			return $entry;
		}
		if ( ! mymail_option( 'kickboxio_import' ) && defined( 'MYMAIL_DO_BULKIMPORT' ) && MYMAIL_DO_BULKIMPORT ) {
			return $entry;
		}

		$is_valid = $this->verify( $entry['email'] );
		if ( is_wp_error( $is_valid ) ) {
			return $is_valid;
		}

		return $entry;

	}

	public function verify( $email ) {

		$endpoint = 'https://api.kickbox.io/v2/verify';

		$url = add_query_arg(
			array(
				'email'   => urlencode( $email ),
				'apikey'  => mymail_option( 'kickboxio_apikey' ),
				'timeout' => mymail_option( 'kickboxio_timeout' ),
			),
			$endpoint
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => ( mymail_option( 'kickboxio_timeout' ) / 1000 ) + 3,
			)
		);

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$headers = wp_remote_retrieve_headers( $response );

		if ( isset( $headers['x-kickbox-balance'] ) ) {
			mymail_update_option( 'kickboxio_balance', $headers['x-kickbox-balance'] );
		}

		$result = json_decode( $body );

		if ( ! $result->success ) {
			mymail_notice( '<strong>' . sprintf( __( 'There was an error while verifying an email address via kickbox.io: %s', 'mymail_kickboxoi' ), $result->message ) . '</strong>', 'error', false, 'kickboxioerror' );
			return true;
		}

		// general acceptation
		if ( ! in_array( $result->result, mymail_option( 'kickboxio_response', array() ) ) ) {
			return new WP_Error( 'kickboxio_response', mymail_option( 'kickboxio_response_error' ), 'email' );
		}

		// reasons specific rejection
		if ( in_array( $result->reason, mymail_option( 'kickboxio_reasons', array() ) ) ) {
			return new WP_Error( 'kickboxio_reasons', mymail_option( 'kickboxio_reasons_error' ), 'email' );
		}

		// special rejections
		if ( $result->role && ! mymail_option( 'kickboxio_role' ) ) {
			return new WP_Error( 'kickboxio_role', mymail_option( 'kickboxio_error' ), 'email' );
		}

		if ( $result->free && ! mymail_option( 'kickboxio_free' ) ) {
			return new WP_Error( 'kickboxio_free', mymail_option( 'kickboxio_error' ), 'email' );
		}

		if ( $result->disposable && ! mymail_option( 'kickboxio_disposable' ) ) {
			return new WP_Error( 'kickboxio_disposable', mymail_option( 'kickboxio_error' ), 'email' );
		}

		if ( $result->accept_all && ! mymail_option( 'kickboxio_accept_all' ) ) {
			return new WP_Error( 'kickboxio_accept_all', mymail_option( 'kickboxio_error' ), 'email' );
		}

		if ( $result->sendex < mymail_option( 'kickboxio_sendex' ) ) {
			return new WP_Error( 'kickboxio_sendex', mymail_option( 'kickboxio_error' ), 'email' );
		}

		return true;

	}

	public function settings_tab( $settings ) {

		$position = 3;
		$settings = array_slice( $settings, 0, $position, true ) +
					array( 'kickboxio' => 'Kickbox.io' ) +
					array_slice( $settings, $position, null, true );

		return $settings;
	}


	public function settings() {

		?>
	<table class="form-table">
		<tr valign="top">
			<th scope="row"><?php _e( 'API Key', 'mymail_kickboxoi' ); ?></th>
			<td><p><input type="text" name="mymail_options[kickboxio_apikey]" value="<?php echo esc_attr( mymail_option( 'kickboxio_apikey' ) ); ?>" class="large-text"></p></td>
		</tr>
		<?php if ( null != mymail_option( 'kickboxio_balance' ) ) : ?>
		<tr valign="top">
			<th scope="row"><?php _e( 'Balance', 'mymail_kickboxoi' ); ?></th>
			<td><input type="hidden" name="mymail_options[kickboxio_balance]" value="<?php echo esc_attr( mymail_option( 'kickboxio_balance' ) ); ?>"><p><?php echo sprintf( __( 'You have %d credits left', MYMAIL_KICKBOXIO_DOMAIN ), mymail_option( 'kickboxio_balance' ) ); ?></p></td>
		</tr>
		<?php endif; ?>
		<tr valign="top">
			<th scope="row"><?php _e( 'Timeout', 'mymail_kickboxoi' ); ?></th>
			<td><p><input type="text" name="mymail_options[kickboxio_timeout]" value="<?php echo esc_attr( mymail_option( 'kickboxio_timeout' ) ); ?>" class="small-text"> Milliseconds</p></td>
		</tr>
		<tr valign="top">
			<th scope="row"><?php _e( 'Import', 'mymail_kickboxoi' ); ?></th>
			<td><p><label><input type="hidden" name="mymail_options[kickboxio_import]" value=""><input type="checkbox" name="mymail_options[kickboxio_import]" value="1" <?php checked( mymail_option( 'kickboxio_import' ) ); ?>> use for import</label></p>
			<p class="description">This will significantly decrease import time because for every subscriber WordPress needs to verify the email on the kickbox.io server. It's better to use the <a href="https://kickbox.io/app/verify" class="external">list verification</a> to verify large lists.</p>
				</td>
		</tr>
	</table>
	<h3>Rules</h3>
	<p class="description">You can define rules when you accept an email address and when you don't. All rules are based on the API response by kickbox.io. Please check their <a href="http://docs.kickbox.io/docs/using-the-api" class="external">API documentation</a> for more info.</p>
	<p class="description">By default the given options are fine and can be kept. If you have special needs feel free to adopt them.</p>
	<table class="form-table">
		<tr valign="top">
			<th scope="row"><?php _e( 'Accept email if response is', 'mymail_kickboxoi' ); ?></th>
			<td>
		<?php
		$reasons = array(
			'deliverable'   => 'deliverable',
			'undeliverable' => 'undeliverable',
			'risky'         => 'risky',
			'unknown'       => 'unknown',
		);

		$checked = mymail_option( 'kickboxio_response', array() );
		foreach ( $reasons as $code => $reason ) {
			echo '<p><label><input type="checkbox" name="mymail_options[kickboxio_response][]" value="' . esc_attr( $code ) . '" ' . checked( in_array( $code, $checked ), true, false ) . '><code>[' . $code . ']</code> ' . esc_attr( $reason ) . '</label></p>';
		}
		?>
			<p><strong><?php _e( 'Error Message if rule doesn\'t match', 'mymail_kickboxoi' ); ?></strong>
			<input type="text" name="mymail_options[kickboxio_response_error]" value="<?php echo esc_attr( mymail_option( 'kickboxio_response_error' ) ); ?>" class="large-text"></p>
			</td>
		</tr>
		<tr valign="top">
			<th scope="row"><?php _e( 'Reject email if reason is', 'mymail_kickboxoi' ); ?></th>
			<td>
		<?php

		$reasons = array(
			// 'invalid_email' => 'Specified email is not a valid email address syntax',
			'invalid_domain'     => 'Domain for email does not exist',
			'rejected_email'     => 'Email address was rejected by the SMTP server, email address does not exist',
			'accepted_email'     => 'Email address was accepted by the SMTP server',
			'low_quality'        => 'Email address has quality issues that may make it a risky or low-value address',
			'low_deliverability' => 'Email address appears to be deliverable, but deliverability cannot be guaranteed',
			'no_connect'         => 'Could not connect to SMTP server',
			'timeout'            => 'SMTP session timed out',
			'invalid_smtp'       => 'SMTP server returned an unexpected/invalid response',
			'unavailable_smtp'   => 'SMTP server was unavailable to process our request',
			'unexpected_error'   => 'An unexpected error has occurred',
		);

		$checked = mymail_option( 'kickboxio_reasons', array() );
		foreach ( $reasons as $code => $reason ) {
			echo '<p><label><input type="checkbox" name="mymail_options[kickboxio_reasons][]" value="' . esc_attr( $code ) . '" ' . checked( in_array( $code, $checked ), true, false ) . '><code>[' . $code . ']</code> ' . esc_attr( $reason ) . '</label></p>';
		}
		?>
			<p><strong><?php _e( 'Error Message if rule doesn\'t match', 'mymail_kickboxoi' ); ?></strong>
			<input type="text" name="mymail_options[kickboxio_reasons_error]" value="<?php echo esc_attr( mymail_option( 'kickboxio_reasons_error' ) ); ?>" class="large-text"></p>
			</td>
		</tr>
		<tr valign="top">
			<th scope="row"><?php _e( 'Accept email address if', 'mymail_kickboxoi' ); ?></th>
			<td>
			<p><label><input type="checkbox" name="mymail_options[kickboxio_role]" value="1" <?php checked( mymail_option( 'kickboxio_role' ) ); ?>> is a role address (postmaster@example.com, support@example.com, etc)</label></p>
			<p><label><input type="checkbox" name="mymail_options[kickboxio_free]" value="1" <?php checked( mymail_option( 'kickboxio_free' ) ); ?>> uses a free email service like gmail.com or yahoo.com.</label></p>
			<p><label><input type="checkbox" name="mymail_options[kickboxio_disposable]" value="1" <?php checked( mymail_option( 'kickboxio_disposable' ) ); ?>> uses a disposable domain like trashmail.com or mailinator.com.</label></p>
			<p><label><input type="checkbox" name="mymail_options[kickboxio_accept_all]" value="1" <?php checked( mymail_option( 'kickboxio_accept_all' ) ); ?>>was accepted, but the domain appears to accept all emails addressed to that domain</label></p>
			<p><a href="http://docs.kickbox.io/v2.0/docs/the-sendex" class="external">Sendex score</a> is at least<input type="text" name="mymail_options[kickboxio_sendex]" value="<?php echo floatval( mymail_option( 'kickboxio_sendex' ) ); ?>" class="small-text"></p>

			<p><strong><?php _e( 'Error Message if rule doesn\'t match', 'mymail_kickboxoi' ); ?></strong>
			<input type="text" name="mymail_options[kickboxio_error]" value="<?php echo esc_attr( mymail_option( 'kickboxio_error' ) ); ?>" class="large-text"></p>
			</td>
			</td>
		</tr>
	</table>

		<?php
	}


	public function notice() {
		?>
	<div id="message" class="error">
	  <p>
	   <strong>Kickbox.io for MyMail</strong> requires the <a href="https://evp.to/mymail?utm_campaign=wporg&utm_source=MyMail+Kickbox.io+Integration">MyMail Newsletter Plugin</a>, at least version <strong><?php echo MYMAIL_KICKBOXIO_REQUIRED_VERSION; ?></strong>. Plugin deactivated.
	  </p>
	</div>
		<?php
	}


}

new MyMailKickBoxIO();
