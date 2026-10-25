<?php
/**
 * Daily quote popup.
 *
 * Override in a theme at: classic-edu-suite/quotes/popup.php
 *
 * @package ClassicEduSuite
 *
 * @var array  $quote         id, text, author, categories.
 * @var array  $cta           enabled, is_guest, text, link, button_label,
 *                            whatsapp_url, copy_link, coins.
 * @var array  $foot          label, url.
 * @var string $heading       Eyebrow heading.
 * @var bool   $is_preview    True inside the admin preview: rendered open and
 *                            without the dismissal behaviour.
 * @var string $dismiss_label Accessible label for the close button.
 * @var string $copy_label    Label for the copy-link button.
 * @var string $copied_label  Confirmation shown after copying.
 */

defined( 'ABSPATH' ) || exit;

$ces_show_cta = ! empty( $cta['enabled'] ) && ! empty( $cta['link'] );
?>
<div
	id="ces-quote-popup"
	class="ces-quote-popup<?php echo $is_preview ? ' ces-quote-popup--preview is-open' : ''; ?>"
	data-quote-id="<?php echo esc_attr( (string) $quote['id'] ); ?>"
	<?php
	if ( ! $is_preview ) :
		echo 'hidden aria-hidden="true"';
	endif;
	?>
>
	<div class="ces-quote-popup__scrim" data-ces-quote-close aria-hidden="true"></div>

	<div
		class="ces-quote-popup__dialog"
		role="dialog"
		aria-modal="true"
		aria-labelledby="ces-quote-popup-heading"
		aria-describedby="ces-quote-popup-text"
		tabindex="-1"
		data-ces-quote-initial
	>
		<div class="ces-quote-popup__bar">
			<p class="ces-quote-popup__eyebrow" id="ces-quote-popup-heading"><?php echo esc_html( $heading ); ?></p>
			<button
				type="button"
				class="ces-quote-popup__close"
				data-ces-quote-close
				aria-label="<?php echo esc_attr( $dismiss_label ); ?>"
			>&times;</button>
		</div>

		<blockquote class="ces-quote-popup__quote">
			<p class="ces-quote-popup__text" id="ces-quote-popup-text"><?php echo esc_html( $quote['text'] ); ?></p>
			<?php if ( '' !== $quote['author'] ) : ?>
				<cite class="ces-quote-popup__author">&mdash; <?php echo esc_html( $quote['author'] ); ?></cite>
			<?php endif; ?>
		</blockquote>

		<?php if ( $ces_show_cta ) : ?>
			<div class="ces-quote-popup__task">
				<span class="ces-quote-popup__task-label"><?php echo esc_html( $cta['text'] ); ?></span>

				<div class="ces-quote-popup__actions">
					<?php if ( ! empty( $cta['whatsapp_url'] ) ) : ?>
						<a
							class="ces-quote-popup__btn ces-quote-popup__btn--whatsapp"
							href="<?php echo esc_url( $cta['whatsapp_url'] ); ?>"
							target="_blank"
							rel="noopener noreferrer"
							data-ces-quote-cta
						><?php echo esc_html( $cta['button_label'] ); ?></a>

						<?php if ( ! empty( $cta['copy_link'] ) ) : ?>
							<button
								type="button"
								class="ces-quote-popup__btn ces-quote-popup__btn--ghost"
								data-ces-quote-copy="<?php echo esc_attr( $cta['copy_link'] ); ?>"
								data-label-done="<?php echo esc_attr( $copied_label ); ?>"
							><?php echo esc_html( $copy_label ); ?></button>
						<?php endif; ?>
					<?php else : ?>
						<a
							class="ces-quote-popup__btn ces-quote-popup__btn--primary"
							href="<?php echo esc_url( $cta['link'] ); ?>"
							data-ces-quote-cta
						><?php echo esc_html( $cta['button_label'] ); ?></a>
					<?php endif; ?>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $foot['url'] ) && ! empty( $foot['label'] ) ) : ?>
			<p class="ces-quote-popup__foot">
				<a href="<?php echo esc_url( $foot['url'] ); ?>"><?php echo esc_html( $foot['label'] ); ?></a>
			</p>
		<?php endif; ?>
	</div>
</div>
