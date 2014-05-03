<?php

/**
 * Get a count of our pending users
 *
 * @since  4.2.0
 *
 * @return integer  count of our current pending users
 */
function wds_bp_registration_get_pending_user_count() { /**/
	global $wpdb;

	$sql = "SELECT count( user_id ) AS count FROM " . $wpdb->prefix . "usermeta WHERE meta_key = %s AND meta_value = %s";

	$rs = $wpdb->get_col( $wpdb->prepare( $sql, '_bprwg_is_moderated', 'true' ) );

	if ( !empty( $rs ) ) {
		return absint( $rs[0] );
	}
}

/**
 * Get our pending users
 *
 * @since  4.2.0
 *
 * @param  integer $start_from Offset to start from with our paging of pending users.
 *
 * @return array              Array of user ID objects or empty array.
 */
function wds_bp_registration_get_pending_users( $start_from = 0 ) { /**/
	global $wpdb;

	$sql = "
		SELECT u.ID AS user_id
		FROM " . $wpdb->prefix . "users AS u
		INNER JOIN " . $wpdb->prefix . "usermeta AS um
		WHERE u.ID = um.user_id
		AND um.meta_key = %s
		AND meta_value = %s
		ORDER BY u.user_registered
		LIMIT %d, 20";

	$rs = $wpdb->get_results( $wpdb->prepare( $sql, '_bprwg_is_moderated', 'true', $start_from ) );

	return ( !empty( $rs ) ) ? $rs : array();
}

/**
 * Delete our stored options so that they get reset next time.
 *
 * @since  4.2.0
 */
function wds_bp_registration_handle_reset_messages() {

	delete_option( 'bprwg_activate_message' );
	delete_option( 'bprwg_approved_message' );
	delete_option( 'bprwg_denied_message' );

}

function wds_bp_registration_handle_general_settings( $args = array() ) {
	//Handle saving our moderate setting
	if ( isset( $args['set_moderate'] ) ) {
		$bp_moderate = sanitize_text_field( $args['set_moderate'] );
		update_option( 'bprwg_moderate', $bp_moderate );
	}

	//Handle saving our private network setting
	if ( isset( $args['set_private'] ) ) {
		$privacy_network = sanitize_text_field( $args['set_private'] );
		update_option( 'bprwg_privacy_network', $privacy_network );
	}

	$activate_message = sanitize_text_field( $args['activate_message'] );
	update_option( 'bprwg_activate_message', $activate_message );

	$approved_message = sanitize_text_field( $args['approved_message'] );
	update_option( 'bprwg_approved_message', $approved_message );

	$denied_message = sanitize_text_field( $args['denied_message'] );
	update_option( 'bprwg_denied_message', $denied_message );
}

function wds_bp_registration_options_form_actions() {

	//settings save
	if ( isset( $_POST['save_general'] ) ) {

		check_admin_referer( 'bp_reg_options_check' );

		wds_bp_registration_handle_general_settings(
			array(
                'set_moderate'          => $_POST['bp_moderate'],
                'set_private'           => $_POST['privacy_network'],
                'activate_message'      => $_POST['activate_message'],
                'approved_message'      => $_POST['approved_message'],
                'denied_message'        => $_POST['denied_message']
			)
		);
	}

	if ( isset( $_POST['reset_messages'] ) ) {

		check_admin_referer( 'bp_reg_options_check' );

		wds_bp_registration_handle_reset_messages();
	}

	//request submissions
	if ( isset( $_POST['moderate'] ) ) {

		check_admin_referer( 'bp_reg_options_check' );

		$action = $_POST['moderate'];

		$checked_members = array();
		$send = false;

		if ( isset( $_POST['bp_member_check'] ) ) {
			//Grab all submitted checkboxes
			$checked_members = $_POST['bp_member_check'];
		}

		if ( !is_array( $checked_members ) ) {
			$checked_members = array( $checked_members );
		}

		//grab message
		if ( 'Deny' == $action ) {
			$send = true;
			$subject = __( 'Membership Denied', 'bp-registration-options' );
			$message = get_option( 'bprwg_denied_message' );
		}
		if ( 'Approve' == $action ) {
			$send = true;
			$subject = __( 'Membership Approved', 'bp-registration-options' );
			$message = get_option( 'bprwg_approved_message' );
		}

		foreach( $checked_members as $user_id ) {

			//Grab our userdata object while we still have a user.
			$user = get_userdata( $user_id );
			if ( $action == 'Deny' || $action == 'Ban' ) {
				//Add our user to the IP ban option.
				/*if ( 'Ban' == $action ) {

					$blockedIPs = get_option( 'bprwg_blocked_ips', array() );
					$blockedemails = get_option( 'bprwg_blocked_emails', array() );
					$blockedIPs[] = get_user_meta( $user_id, 'bprwg_ip_address', true);
					$blockedemails[] = $user->data->user_email;
					$successIP = update_option( 'bprwg_blocked_ips', $blockedIPs );
					$successEmail = update_option( 'bprwg_blocked_emails', $blockedemails );
				}*/

				if ( is_multisite() ) {
					wpmu_delete_user( $user_id );
				} else {
					wp_delete_user( $user_id );
				}

			} elseif ( $action == 'Approve' ) {
				wds_set_moderation_status( $user_id, 'false' );
			}

			//only send out message if one exists
			if ( $send ) {
				$user_name = $user->data->user_login;
				$user_email = $user->data->user_email;
				$email = str_replace( '[username]', $user_name, $message );

				add_filter( 'wp_mail_content_type', 'bp_registration_options_set_content_type' );
				wp_mail( $user_email, $subject, $email );
				remove_filter( 'wp_mail_content_type', 'bp_registration_options_set_content_type' );
			}
		}
	}
}
add_action( 'admin_init', 'wds_bp_registration_options_form_actions' );

function wds_bp_registration_options_admin_messages() { /**/

	$member_requests = wds_bp_registration_get_pending_user_count();

	if ( $member_requests > 0 && isset( $_GET['page'] ) != 'bp_registration_options_member_requests' && current_user_can( 'add_users' ) ) {

		$s = '';
		if ( $member_requests > 1 ) {
			$s = 's';
		}

		$message = '<div class="error"><p>';
		$message .= sprintf(
			__( 'You have %s new member request%s that need to be approved or denied. Please %s to take action', 'bp-registration-options' ),
			sprintf(
				'<a href="%s"><strong>%s</strong></a>',
				admin_url( '/admin.php?page=bp_registration_options_member_requests' ),
				$member_requests
			),
			$s,
			sprintf(
				'<a href="%s">%s</a>',
				admin_url( '/admin.php?page=bp_registration_options_member_requests' ),
				__( 'click here', 'bp-registration-options' )
			)
		);
		$message .= '</p></div>';

		echo $message;
	}
}
add_action('admin_notices', 'wds_bp_registration_options_admin_messages');

function wds_bp_registration_options_plugin_menu() { /**/
	global $blog_id;

	$member_requests = wds_bp_registration_get_pending_user_count();

	if ( $blog_id == 1 ) {

		$minimum_cap = 'manage_options';

		add_menu_page(
			__( 'BP Registration', 'bp-registration-options' ),
			__( 'BP Registration', 'bp-registration-options' ),
			$minimum_cap,
			'bp_registration_options',
			'bp_registration_options_settings',
			plugins_url( 'bp-registration-options/images/webdevstudios-16x16.png' )
		);

		$count = '<span class="update-plugins count-' . $member_requests . '"><span class="plugin-count">' . $member_requests . '</span></span>';

		add_submenu_page(
			'bp_registration_options',
			__( 'Member Requests ', 'bp-registration-options' ) . $member_requests,
			__( 'Member Requests ', 'bp-registration-options' ) . $count,
			$minimum_cap,
			'bp_registration_options_member_requests',
			'bp_registration_options_member_requests'
		);

		add_submenu_page(
			'bp_registration_options',
			__( 'Banned Sources', 'bp-registration-options' ),
			__( 'Banned Sources', 'bp-registration-options' ),
			$minimum_cap,
			'bp_registration_options_banned',
			'bp_registration_options_banned'
		);

		add_submenu_page(
			'bp_registration_options',
			__( 'Help / Support', 'bp-registration-options' ),
			__( 'Help / Support', 'bp-registration-options' ),
			$minimum_cap,
			'bp_registration_options_help_support',
			'bp_registration_options_help_support'
		);
	}
}
add_action( 'admin_menu', 'wds_bp_registration_options_plugin_menu' );

function wds_bp_registration_options_tab_menu( $page = '' ) { /**/

	$member_requests = wds_bp_registration_get_pending_user_count(); ?>

	<h2 class="nav-tab-wrapper">
	<?php _e( 'BP Registration Options', 'bp-registration-options' ); ?>
	<a class="nav-tab<?php if ( !$page ) echo ' nav-tab-active';?>" href="<?php echo admin_url( 'admin.php?page=bp_registration_options' ); ?>"><?php _e( 'General Settings', 'bp-registration-options' ); ?></a>
	<a class="nav-tab<?php if ( $page == 'requests' ) echo ' nav-tab-active';?>" href="<?php echo admin_url( 'admin.php?page=bp_registration_options_member_requests' ); ?>"><?php _e( 'Member Requests', 'bp-registration-options' ); ?> (<?php echo $member_requests;?>)</a>
	<a class="nav-tab<?php if ( $page == 'banned' ) echo ' nav-tab-active';?>" href="<?php echo admin_url( 'admin.php?page=bp_registration_options_banned' ); ?>"><?php _e( 'Banned', 'bp-registration-options' ); ?></a>
	</h2>
<?php }

/**
 * BP-Registration-Options main settings page output.
 */
function bp_registration_options_settings() {

    $bp_moderate        = get_option( 'bprwg_moderate' );
    $privacy_network    = get_option( 'bprwg_privacy_network' );
    $activate_message   = get_option( 'bprwg_activate_message' );
    $approved_message   = get_option('bprwg_approved_message');
    $denied_message     = get_option( 'bprwg_denied_message' );

	if ( !$activate_message ) {
		$activate_message = __( 'Your membership account is awaiting approval by the site administrator. You will not be able to fully interact with the social aspects of this website until your account is approved. Once approved or denied you will receive an email notice.', 'bp-registration-options' );
		update_option( 'bprwg_activate_message', $activate_message );
	}

	if ( !$approved_message ) {
		$approved_message = sprintf(
			__( 'Hi [username], your member account on %s has been approved! You can now login and start interacting with the rest of the community...', 'bp-registration-options' ),
			get_bloginfo( 'url' )
		);

		update_option( 'bprwg_approved_message', $approved_message );
	}

	if ( !$denied_message ) {
		$denied_message = sprintf(
			__( 'Hi [username], we regret to inform you that your member account on %s has been denied...', 'bp-registration-options' ),
			get_bloginfo( 'url' )
		);

		update_option( 'bprwg_denied_message', $denied_message);
	}
	?>
	<div class="wrap" >
		<?php wds_bp_registration_options_tab_menu(); ?>

		<form method="post">
			<?php wp_nonce_field('bp_reg_options_check'); ?>

			<p>
				<input type="checkbox" id="bp_moderate" name="bp_moderate" value="1" <?php checked( $bp_moderate, '1' ); ?>/>
				<label for="bp_moderate">
					<strong>
						<?php _e( 'Moderate New Members', 'bp-registration-options' ); ?>
					</strong> (<?php _e( 'Every new member will have to be approved by an administrator before they can interact with BuddyPress components.', 'bp-registration-options' ); ?>)
				</label>
			</p>

			<p>
				<input type="checkbox" id="privacy_network" name="privacy_network" value="1" <?php checked( $privacy_network, '1' ); ?>/>
				<label for="privacy_network">
					<?php _e( 'Only registered or approved members can view BuddyPress pages (Private Network).', 'bp-registration-options' ); ?>
				</label>
			</p>

			<table>
				<tr>
					<td align="right" valign="top">
						<?php _e( 'Activate & Profile Alert Message:', 'bp-registration-options' ); ?>
					</td>
					<td>
						<textarea name="activate_message" style="width:500px;height:100px;"><?php echo stripslashes( $activate_message );?></textarea>
					</td>
				</tr>
				<tr>
					<td align="right" valign="top">
						<?php _e( 'Account Approved Email:', 'bp-registration-options' ); ?>
					</td>
					<td>
						<textarea name="approved_message" style="width:500px;height:100px;"><?php echo stripslashes( $approved_message );?></textarea>
					</td>
				</tr>
				<tr>
					<td align="right" valign="top">
						<?php _e( 'Account Denied Email:', 'bp-registration-options' ); ?>
					</td>
					<td>
						<textarea name="denied_message" style="width:500px;height:100px;"><?php echo stripslashes( $denied_message );?></textarea>
					</td>
				</tr>
				<tr>
					<td></td>
					<td align="right">
						<table width="100%">
							<tr>
								<td>
									<?php _e( 'Short Code Key: [username]', 'bp-registration-options' ); ?>
								</td>
								<td align="right">
									<input type="submit" id="reset_messages" name="reset_messages" class="button button-secondary" value="<?php esc_attr_e( 'Reset Messages', 'bp-registration-options' ); ?>" />
								</td>
							</tr>
						</table>
					</td>
				</tr>
			</table>

			<?php do_action('bp_registration_options_general_settings_form');?>

			<input type="submit" class="button button-primary" name="save" value="<?php esc_attr_e( 'Save Options', 'bp-registration-options' ); ?>" />
		</form>
	</div>

	<?php bp_registration_options_admin_footer();
}



/**
 * New member requests ui.
 */
function bp_registration_options_member_requests() {
	global $wpdb;

	$member_requests = wds_bp_registration_get_pending_user_count();

	?>
	<div class="wrap">
		<?php wds_bp_registration_options_tab_menu( 'requests' );

		if ( $member_requests > 0 ) {
			$page = ( isset( $_GET['p'] ) ) ? $_GET['p'] : 1 ;
			$total_pages = ceil( $member_requests / 20 );
			$start_from = ( $page - 1 ) * 20;
			$sql = 'SELECT ID from ' .$wpdb->base_prefix.'users where user_status in (2,69) order by user_registered LIMIT %d, 20';
			$rs = $wpdb->get_results( $wpdb->prepare( $sql , $start_from ) );?>

			<form method="post" name="bprwg">
			<?php wp_nonce_field('bp_reg_options_check');

			/*
			Developers. Please return a multidimensional array in the following format.

			add_filter( 'bprwg_request_columns', 'bprwg_myfilter' );
			function bpro_myfilter( $fields ) {
				return $fields = array(
					array(
						'heading' => 'Column name 1',
						'content' => 'Column content 1'
					),
					array(
						'heading' => 'Column name 2',
						'content' => 'Column content 2'
					),
					array(
						'heading' => 'Column name 3',
						'content' => 'Column content 3'
					)
				);
			}
			*/

			$extra_fields = apply_filters( 'bprwg_request_columns', array() );
			if ( !empty( $extra_fields ) ) {
				$headings = wp_list_pluck( $extra_fields, 'heading' );
				$content = wp_list_pluck( $extra_fields, 'content' );
			}
			?>

			<p><?php _e( 'Please approve or deny the following new members:', 'bp-registration-options' ); ?></p>

			<table class="widefat">
			<thead>
				<tr>
					<th id="cb" class="manage-column column-cb check-column" scope="col">
						<input type="checkbox" id="bp_checkall_top" name="checkall" />
					</th>
					<th><?php _e( 'Photo', 'bp-registration-options' ); ?></th>
					<th><?php _e( 'Name', 'bp-registration-options' ); ?></th>
					<th><?php _e( 'Email', 'bp-registration-options' ); ?></th>
					<th><?php _e( 'Created', 'bp-registration-options' ); ?></th>
					<th><?php _e( 'Additional Data', 'bp-registration-options' ); ?></th>
					<?php
					if ( !empty( $headings ) ) {
						foreach( $headings as $heading ) {
							echo '<th>' . $heading . '</th>';
						}
					}
					?>
				</tr>
			</thead>
			<?php $odd = true;

			foreach( $rs as $r ) {
				$user_id = $r->ID;
				$author = new BP_Core_User( $user_id );
				$userpic = $author->avatar_mini;
				$userlink = $author->user_url;
				$username = $author->fullname;
				$user = get_userdata( $user_id );
				$useremail = $user->user_email;
				$userregistered = $user->user_registered;
				$userip = get_user_meta( $user_id, 'bprwg_ip_address', true);
				if ( $odd ) {
					echo '<tr class="alternate">';
					$odd = false;
				} else {
					echo '<tr>';
					$odd = true;
				}
				?>
					<th class="check-column" scope="row"><input type="checkbox" class="bpro_checkbox" id="bp_member_check_<?php echo $user_id; ?>" name="bp_member_check[]" value="<?php echo $user_id; ?>"  /></th>
					<td><a target="_blank" href="<?php echo $userlink; ?>"><?php echo $userpic; ?></a></td>
					<td><strong><a target="_blank" href="<?php echo $userlink; ?>"><?php echo $username; ?></a></strong></td>
					<td><a href="mailto:<?php echo $useremail;?>"><?php echo $useremail; ?></a></td>
					<td><?php echo $userregistered; ?></td>
					<td>
						<div class="alignleft">
							<img height="50" src="http://api.hostip.info/flag.php?ip=<?php echo $userip; ?>" / >
						</div>
						<div class="alignright">
							<?php
							$response = wp_remote_get( 'http://api.hostip.info/get_html.php?ip=' . $userip );
							if ( !is_wp_error( $response ) ) {
								$data = $response['body'];
								$data = str_replace( 'City:', '<br>' . __( 'City:', 'bp-registration-options' ), $data);
								$data = str_replace( 'IP:', '<br>' . __( 'IP:', 'bp-registration-options' ), $data);
								echo $data;
							} else {
								echo $userip;
							}
							?>
						</div>
					</td>
					<?php
					if ( !empty( $content ) ) {
						foreach( $content as $td ) {
							echo '<td>' . $td . '</td>';
						}
					}
					?>
				</tr>
			<?php } ?>
			<tfoot>
				<tr>
					<th class="manage-column column-cb check-column" scope="col"><input type="checkbox" id="bp_checkall_bottom" name="checkall" /></th>
					<th><?php _e( 'Photo', 'bp-registration-options' ); ?></th>
					<th><?php _e( 'Name', 'bp-registration-options' ); ?></th>
					<th><?php _e( 'Email', 'bp-registration-options' ); ?></th>
					<th><?php _e( 'Created', 'bp-registration-options' ); ?></th>
					<th><?php _e( 'Additional Data', 'bp-registration-options' ); ?></th>
					<?php
					if ( !empty( $headings ) ) {
						foreach( $headings as $heading ) {
							echo '<th>' . $heading . '</th>';
						}
					}
					?>
				</tr>
			</tfoot>
			</table>

			<p><input type="submit" class="button button-primary" name="Moderate" value="<?php esc_attr_e( 'Approve', 'bp-registration-options' ); ?>" />
			&nbsp;
			<input type="submit" class="button button-secondary" name="Moderate" value="<?php esc_attr_e( 'Deny', 'bp-registration-options' ); ?>" id="bpro_deny" />
			&nbsp;
			<input type="submit" class="button button-secondary" name="Moderate" value="<?php esc_attr_e( 'Ban', 'bp-registration-options' ); ?>" id="bpro_ban" /></p>

			<?php if ( $total_pages > 1 ) {
				echo '<h3>';
				for ( $i = 1; $i <= $total_pages; $i++ ) {
					echo "<a href='" . add_query_arg( 'p', $i ) . "'>" . $i . "</a> ";
				}
				echo '</h3>';
			}

			do_action( 'bp_registration_options_member_request_form' ); ?>

			</form>
		<?php } else {
			echo '<p><strong>' . __( 'No new members to approve.', 'bp-registration-options' ) . '</strong></p>';
		} ?>
	</div>
	<?php bp_registration_options_admin_footer();
}

/**
 * Render our page to display banned IP addresses and Email addresses
 *
 * @since  4.2
 */
function bp_registration_options_banned() {
	?>
	<div class="wrap">
	<?php

	wds_bp_registration_options_tab_menu( 'banned' );

	$blockedIPs = get_option( 'bprwg_blocked_ips' );
	$blockedemails = get_option( 'bprwg_blocked_emails' );

	if ( !empty( $blockedIPs ) || !empty( $blockedemails ) ) { ?>

		<h3><?php _e( 'The following IP addresses are currently banned.', 'bp-registration-options' ); ?></h3>
		<table class="widefat">
		<thead>
			<tr>
				<th id="cb" class="manage-column column-cb check-column" scope="col">
					<input type="checkbox" id="bp_checkall_top_blocked" name="checkall" />
				</th>
				<th><?php _e( 'IP Address', 'bp-registration-options' ); ?></th>
			</tr>
		</thead>
		<?php

		$odd = true;

		foreach( $blockedIPs as $IP ) {
			if ( $odd ) {
				echo '<tr class="alternate">';
				$odd = false;
			} else {
				echo '<tr>';
				$odd = true;
			}

			?>
			<th class="check-column" scope="row"><input type="checkbox" class="bpro_checkbox" id="bp_blocked_check_<?php echo $IP; ?>" name="bp_blockedip_check[]" value="<?php echo $IP; ?>"  /></th>
			<td><?php echo $IP; ?></a></td>
			</tr>
		<?php } ?>
		<tfoot>
			<tr>
				<th id="cb" class="manage-column column-cb check-column" scope="col">
					<input type="checkbox" id="bp_checkall_top_blocked" name="checkall" />
				</th>
				<th><?php _e( 'IP Address', 'bp-registration-options' ); ?></th>
			</tr>
		</tfoot>
		</table>

		<h3><?php _e( 'The following Email addresses are currently banned.', 'bp-registration-options' ); ?></h3>

		<table class="widefat">
		<thead>
			<tr>
				<th id="cb" class="manage-column column-cb check-column" scope="col">
					<input type="checkbox" id="bp_checkall_top_blocked" name="checkall" />
				</th>
				<th><?php _e( 'Email Address', 'bp-registration-options' ); ?></th>
			</tr>
		</thead>
		<?php

		$odd = true;

		foreach( $blockedemails as $email ) {
			if ( $odd ) {
				echo '<tr class="alternate">';
				$odd = false;
			} else {
				echo '<tr>';
				$odd = true;
			}
			?>
			<th class="check-column" scope="row"><input type="checkbox" class="bpro_checkbox" id="bp_member_check_<?php echo $user_id; ?>" name="bp_blockedemail_check[]" value=""  /></th>
			<td><?php echo $email; ?></a></td>
			</tr>
		<?php } ?>
		<tfoot>
			<tr>
				<th id="cb" class="manage-column column-cb check-column" scope="col">
					<input type="checkbox" id="bp_checkall_top_blocked" name="checkall" />
				</th>
				<th><?php _e( 'Email Address', 'bp-registration-options' ); ?></th>
			</tr>
		</tfoot>
		</table>
		<?php } else {
			echo '<p><strong>' . __( 'You have no blocked IP Addresses or Email Addresses at the moment', 'bp-registration-options' ) . '</strong></p>';
		}
		bp_registration_options_admin_footer();
}

function bp_registration_options_help_support(){
	?>
	<div class="wrap">
		<?php wds_bp_registration_options_tab_menu( 'help' );?>
	</div>
	<?php bp_registration_options_admin_footer();
}

/**
 * Display our footer content
 * @return string html for the footer output
 */
function bp_registration_options_admin_footer() { /**/
	?>
	<p style="margin-top: 50px;">
		<?php _e( 'BuddyPress Registration Options plugin created by', 'bp-registration-options' ); ?>
		<a target="_blank" href="http://webdevstudios.com">WebDevStudios.com</a>
	</p>
	<table>
		<tr>
			<td>
				<table>
					<tr>
						<td>
							<a target="_blank" href="http://webdevstudios.com">
								<img width="50" src="<?php echo plugins_url( '/images/WDS-150x150.png', dirname( __FILE__ ) );?>" />
							</a>
						</td>
						<td>
							<strong><?php _e( 'Follow', 'bp-registration-options' ); ?> WebDevStudios!</strong><br />
							<a target="_blank" href="https://plus.google.com/108871619014334838112">
								<img src="<?php echo plugins_url( '/images/google-icon.png', dirname( __FILE__ ) );?>" />
							</a>
							<a target="_blank" href="http://twitter.com/webdevstudios">
								<img src="<?php echo plugins_url( '/images/twitter-icon.png', dirname( __FILE__ ) );?>" />
							</a>
							<a target="_blank" href="http://facebook.com/webdevstudios">
								<img src="<?php echo plugins_url( '/images/facebook-icon.png', dirname( __FILE__ ) );?>" />
							</a>
						<td>
					</tr>
				</table>
			</td>
			<td>
				<table>
					<tr>
						<td>
							<a target="_blank" href="http://webdevstudios.com/team/brian-messenlehner/">
								<img src="https://lh3.googleusercontent.com/-eCNkGgNdWx8/AAAAAAAAAAI/AAAAAAAAAGQ/kjKbI1XZv3Y/photo.jpg?sz=50" />
							</a>
						</td>
						<td>
							<strong><?php _e( 'Follow', 'bp-registration-options' ); ?> Brian Messenlehner!</strong><br />
							<a target="_blank" href="https://plus.google.com/117578069784985312197">
								<img src="<?php echo plugins_url( '/images/google-icon.png', dirname( __FILE__ ) );?>" />
							</a>
							<a target="_blank" href="http://twitter.com/bmess">
								<img src="<?php echo plugins_url( '/images/twitter-icon.png', dirname( __FILE__ ) );?>" />
							</a>
							<a target="_blank" href="http://facebook.com/bmess">
								<img src="<?php echo plugins_url( '/images/facebook-icon.png', dirname( __FILE__ ) );?>" />
							</a>
						</td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
<?php
}

function bp_registration_options_js() { /**/
	?>
	<script language="javascript">
		(function($) {
			//Handle our checkboxes
			var checkboxes = $('.bpro_checkbox');
			$('#bp_checkall_top,#bp_checkall_bottom').on('click',function(){
				if ( $(this).attr('checked')) {
					$(checkboxes).each(function(){
						if ( $(this).prop('checked',false) ){
							$(this).prop('checked',true);
						}
					});
				} else {
					$(checkboxes).each(function(){
						$(this).prop('checked',false);
					});
				}
			});
			//Confirm/cancel on deny/ban.
			$('#bpro_deny').on('click',function(){
				return confirm("<?php _e( 'Are you sure you want to deny and delete the checked member(s)?', 'bp-registration-options' ); ?>");
			});
			$('#bpro_ban').on('click',function(){
				return confirm("<?php _e( 'Are you sure you want to ban and delete the checked member(s)?', 'bp-registration-options' ); ?>");
			});
			$('#reset_messages').on('click',function(){
				return confirm("<?php _e( 'Are you sure you want to reset to the default messages?', 'bp-registration-options' ); ?>");
			});
		})(jQuery);
	</script>
<?php
}
add_action( 'admin_footer', 'bp_registration_options_js' );

function bp_registration_options_set_content_type( $content_type ){ /**/
	return 'text/html';
}
