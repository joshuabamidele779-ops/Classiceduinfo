<?php
/**
 * Dashboard Overview tab.
 *
 * Override in a theme at: classic-edu-suite/dashboard/overview.php
 *
 * Every value is resolved in CES_Dashboard::overview_data(), including the
 * guarded cross-module lookups, so this file only loops and escapes. A null
 * card simply means that module is not installed.
 *
 * @package ClassicEduSuite
 *
 * @var string     $greeting     Personalised greeting.
 * @var string     $intro        Supporting line.
 * @var array|null $wallet       label, value, hint, url, action.
 * @var array|null $quote        text, author.
 * @var array      $quick_links  Each: label, url, icon.
 * @var array      $invite       text, link, button_label, whatsapp_url,
 *                               copy_link, copy_label, copied_label.
 * @var string     $links_label  Heading for the quick links panel.
 * @var string     $quote_label  Heading for the quote panel.
 * @var string     $invite_label Heading for the invite panel.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="ces-overview">
	<?php /* The hero band is decoration only — the greeting and intro are the same two strings as before. */ ?>
	<div class="ces-hero">
		<h2 class="ces-overview__greeting"><?php echo esc_html( $greeting ); ?></h2>
		<p class="ces-overview__intro"><?php echo esc_html( $intro ); ?></p>
	</div>

	<div class="ces-overview__grid">
		<?php if ( is_array( $wallet ) ) : ?>
			<section class="ces-panel ces-panel--wallet">
				<h3 class="ces-panel__title"><?php echo esc_html( $wallet['label'] ); ?></h3>
				<div class="ces-stat">
					<span class="ces-stat__value"><?php echo esc_html( $wallet['value'] ); ?></span>
					<span class="ces-stat__label"><?php echo esc_html( $wallet['label'] ); ?></span>
				</div>
				<?php if ( '' !== $wallet['hint'] ) : ?>
					<p class="ces-stat__hint"><?php echo esc_html( $wallet['hint'] ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== $wallet['url'] && '' !== $wallet['action'] ) : ?>
					<p class="ces-stat__action">
						<a class="ces-btn ces-btn--ghost" href="<?php echo esc_url( $wallet['url'] ); ?>"><?php echo esc_html( $wallet['action'] ); ?></a>
					</p>
				<?php endif; ?>
			</section>
		<?php endif; ?>

		<?php if ( is_array( $quote ) ) : ?>
			<section class="ces-panel ces-panel--quote">
				<h3 class="ces-panel__title"><?php echo esc_html( $quote_label ); ?></h3>
				<figure class="ces-quote-block">
					<blockquote class="ces-quote-block__text"><?php echo esc_html( $quote['text'] ); ?></blockquote>
					<?php if ( '' !== $quote['author'] ) : ?>
						<figcaption class="ces-quote-block__author">&mdash; <?php echo esc_html( $quote['author'] ); ?></figcaption>
					<?php endif; ?>
				</figure>
			</section>
		<?php endif; ?>

		<section class="ces-panel ces-panel--invite">
			<h3 class="ces-panel__title"><?php echo esc_html( $invite_label ); ?></h3>

			<?php if ( '' !== $invite['text'] ) : ?>
				<p class="ces-invite__text"><?php echo esc_html( $invite['text'] ); ?></p>
			<?php endif; ?>

			<div class="ces-invite__actions">
				<?php if ( '' !== $invite['whatsapp_url'] ) : ?>
					<a
						class="ces-btn ces-btn--whatsapp"
						href="<?php echo esc_url( $invite['whatsapp_url'] ); ?>"
						target="_blank"
						rel="noopener noreferrer"
					><?php echo esc_html( $invite['button_label'] ); ?></a>
				<?php elseif ( '' !== $invite['link'] ) : ?>
					<a class="ces-btn ces-btn--primary" href="<?php echo esc_url( $invite['link'] ); ?>"><?php echo esc_html( $invite['button_label'] ); ?></a>
				<?php endif; ?>

				<?php if ( '' !== $invite['copy_link'] ) : ?>
					<button
						type="button"
						class="ces-btn ces-btn--ghost"
						data-ces-copy="<?php echo esc_attr( $invite['copy_link'] ); ?>"
						data-ces-copy-done="<?php echo esc_attr( $invite['copied_label'] ); ?>"
					><?php echo esc_html( $invite['copy_label'] ); ?></button>
				<?php endif; ?>
			</div>

			<?php if ( '' !== $invite['copy_link'] ) : ?>
				<p class="ces-invite__hint"><?php echo esc_html( $invite['copy_link'] ); ?></p>
			<?php endif; ?>
		</section>

		<?php if ( ! empty( $quick_links ) ) : ?>
			<section class="ces-panel ces-panel--links">
				<h3 class="ces-panel__title"><?php echo esc_html( $links_label ); ?></h3>
				<ul class="ces-links">
					<?php foreach ( $quick_links as $ces_link ) : ?>
						<li class="ces-links__item">
							<a class="ces-links__link" href="<?php echo esc_url( $ces_link['url'] ); ?>">
								<?php if ( '' !== $ces_link['icon'] ) : ?>
									<span class="ces-links__icon" aria-hidden="true"><?php echo esc_html( $ces_link['icon'] ); ?></span>
								<?php endif; ?>
								<span class="ces-links__label"><?php echo esc_html( $ces_link['label'] ); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>
	</div>
</div>
