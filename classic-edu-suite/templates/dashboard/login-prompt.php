<?php
/**
 * Dashboard prompt for logged-out visitors.
 *
 * Override in a theme at: classic-edu-suite/dashboard/login-prompt.php
 *
 * This is deliberately an invitation, not an error: a visitor who lands on the
 * dashboard URL from a shared referral link should see what they would get.
 *
 * @package ClassicEduSuite
 *
 * The Gmail button appears above the ordinary sign-in link when the accounts
 * module is present and Google sign-in is configured. It is asked for here
 * rather than passed in, so the dashboard class needs no knowledge of a
 * module that may not be installed.
 *
 * @var string   $login_url Login page URL, already carrying redirect_to.
 * @var string   $heading   Panel heading.
 * @var string   $message   Supporting copy.
 * @var string[] $bullets   Reasons to sign in.
 * @var string   $cta_label Button label.
 */

defined( 'ABSPATH' ) || exit;

$ces_google = '';

if ( class_exists( 'CES_Accounts_Login' ) ) {
	$ces_google = CES_Accounts_Login::button_html(
		array(
			'redirect_to' => $login_url,
			'note'        => __( 'No password to remember. We only ever see your name, your email address and your picture.', 'classic-edu-suite' ),
		)
	);
}
?>
<div class="ces-login">
	<h2 class="ces-login__title"><?php echo esc_html( $heading ); ?></h2>
	<p class="ces-login__message"><?php echo esc_html( $message ); ?></p>

	<?php if ( ! empty( $bullets ) ) : ?>
		<ul class="ces-login__list">
			<?php foreach ( $bullets as $ces_bullet ) : ?>
				<li><?php echo esc_html( $ces_bullet ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( '' !== $ces_google ) : ?>
		<?php
		// Escaped where it is built, in CES_Accounts_Login::button_html().
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $ces_google;
		?>
		<p class="ces-google-wrap__or"><span><?php esc_html_e( 'or', 'classic-edu-suite' ); ?></span></p>
	<?php endif; ?>

	<p class="ces-login__action">
		<a class="ces-btn <?php echo '' !== $ces_google ? 'ces-btn--ghost' : 'ces-btn--primary'; ?> ces-btn--block" href="<?php echo esc_url( $login_url ); ?>"><?php echo esc_html( $cta_label ); ?></a>
	</p>
</div>
