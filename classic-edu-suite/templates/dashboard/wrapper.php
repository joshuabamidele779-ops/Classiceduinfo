<?php
/**
 * Student dashboard frame.
 *
 * Override in a theme at: classic-edu-suite/dashboard/wrapper.php
 *
 * The tab strip is a plain navigation list of links, not an ARIA tablist:
 * each tab is a real page load at ?tab=<slug>, so `aria-current="page"` is the
 * correct state and the strip works with no JavaScript at all. On a phone it
 * scrolls horizontally rather than wrapping into a broken second row.
 *
 * @package ClassicEduSuite
 *
 * @var array  $tabs      Normalised tabs keyed by slug: slug, label, icon, url.
 * @var string $active    Active tab slug.
 * @var string $content   Rendered markup of the active tab.
 * @var string $title     Optional heading from the shortcode's title attribute.
 * @var string $nav_label Accessible label for the tab navigation.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="ces-dash" id="ces-dashboard">
	<?php if ( '' !== trim( (string) $title ) ) : ?>
		<h2 class="ces-dash__title"><?php echo esc_html( $title ); ?></h2>
	<?php endif; ?>

	<?php if ( count( $tabs ) > 1 ) : ?>
		<nav class="ces-dash__nav" aria-label="<?php echo esc_attr( $nav_label ); ?>">
			<ul class="ces-dash__tabs">
				<?php foreach ( $tabs as $ces_tab ) : ?>
					<li class="ces-dash__tab-item">
						<a
							class="ces-dash__tab"
							href="<?php echo esc_url( $ces_tab['url'] ); ?>"
							<?php echo $ces_tab['slug'] === $active ? ' aria-current="page"' : ''; ?>
						>
							<?php if ( '' !== $ces_tab['icon'] ) : ?>
								<span class="ces-dash__tab-icon" aria-hidden="true"><?php echo esc_html( $ces_tab['icon'] ); ?></span>
							<?php endif; ?>
							<span class="ces-dash__tab-label"><?php echo esc_html( $ces_tab['label'] ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
	<?php endif; ?>

	<div class="ces-dash__panel" id="ces-dash-panel-<?php echo esc_attr( $active ); ?>">
		<?php
		// Tab callbacks are responsible for escaping their own output.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $content;
		?>
	</div>
</div>
