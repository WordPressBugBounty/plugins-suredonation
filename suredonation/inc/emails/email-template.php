<?php
/**
 * Email template loader.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Emails;

use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email_Template class.
 *
 * @since 0.0.1
 */
class Email_Template {
	use Get_Instance;

	/**
	 * Class constructor.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function __construct() {
	}

	/**
	 * Get email header.
	 *
	 * @since 0.0.1
	 * @return string
	 */
	public function get_header() {
		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<title><?php echo esc_html__( 'Donation Notification', 'suredonation' ); ?></title>
		</head>
		<body style="margin: 0; padding: 0;">
			<div id="sd_wrapper" dir="ltr" style="margin: 0; background-color: #F8F8FC; padding: 40px 0 0 0; width: 100%;">
				<table border="0" cellpadding="0" cellspacing="0" width="100%">
					<tbody>
						<tr>
							<td align="center" valign="top">
								<table border="0" cellpadding="0" cellspacing="0" width="600" id="sd_template_container" style="background-color: #ffffff; border: 1px solid #dce0e6; margin-bottom: 25px;">
									<tbody>
										<tr>
											<td align="center" valign="top">
												<table border="0" cellpadding="0" cellspacing="0" width="600" id="sd_template_body">
													<tbody>
														<tr>
															<td valign="top" id="sd_body_content" style="background-color: #ffffff;">
																<table border="0" cellpadding="20" cellspacing="0" width="100%">
																	<tbody>
																		<tr>
																			<td valign="top" style="padding: 32px;">
																				<div id="sd_body_content_inner" style="color: #384860; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; font-size: 14px; line-height: 20px; text-align: left;">
		<?php
		$output = ob_get_clean();
		return false !== $output ? $output : '';
	}

	/**
	 * Get email footer.
	 *
	 * @since 0.0.1
	 * @return string
	 */
	public function get_footer() {
		ob_start();
		?>
																				</div>
																			</td>
																		</tr>
																	</tbody>
																</table>
															</td>
														</tr>
													</tbody>
												</table>
											</td>
										</tr>
									</tbody>
								</table>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</body>
		</html>
		<?php
		$output = ob_get_clean();
		return false !== $output ? $output : '';
	}

	/**
	 * Render email template with body content.
	 *
	 * @param string $email_body Email body content.
	 * @since 0.0.1
	 * @return string Complete email HTML.
	 */
	public function render( $email_body ) {
		// Convert newlines to <br> tags for plain text.
		$email_body = nl2br( $email_body );

		return $this->get_header() . $email_body . $this->get_footer();
	}
}
