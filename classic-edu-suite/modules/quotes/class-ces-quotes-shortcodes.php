<?php
/**
 * Shortcodes owned by the quotes module.
 *
 *   [ces_quote_of_the_day]  The site-wide quote for today.
 *   [ces_random_quote]      A random active quote on every render.
 *
 * Both accept: class="" to add a CSS class, show_author="1|0",
 * and [ces_random_quote] also accepts category="slug-or-name".
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Quote embedding shortcodes.
 */
class CES_Quotes_Shortcodes {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( 'ces_quote_of_the_day', array( __CLASS__, 'quote_of_the_day' ) );
		add_shortcode( 'ces_random_quote', array( __CLASS__, 'random_quote' ) );
	}

	/**
	 * `[ces_quote_of_the_day]`.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function quote_of_the_day( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'class'       => '',
				'show_author' => '1',
			),
			$atts,
			'ces_quote_of_the_day'
		);

		return self::render_block( (int) CES_Quotes_Scheduler::quote_of_the_day(), $atts );
	}

	/**
	 * `[ces_random_quote]`.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function random_quote( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'class'       => '',
				'show_author' => '1',
				'category'    => '',
			),
			$atts,
			'ces_random_quote'
		);

		$quote_id = (int) CES_Quotes_Scheduler::random_quote( sanitize_text_field( (string) $atts['category'] ) );

		return self::render_block( $quote_id, $atts );
	}

	/**
	 * Render an inline quote block.
	 *
	 * @param int   $quote_id Quote post ID.
	 * @param array $atts     Normalised attributes.
	 * @return string
	 */
	private static function render_block( $quote_id, array $atts ) {
		if ( $quote_id <= 0 ) {
			return '';
		}

		$quote = CES_Quotes_Cpt::to_array( $quote_id );

		if ( empty( $quote['id'] ) || '' === $quote['text'] ) {
			return '';
		}

		if ( class_exists( 'CES_Assets' ) && method_exists( 'CES_Assets', 'enqueue_base' ) ) {
			CES_Assets::enqueue_base();
		}

		$classes = 'ces-quote-block';

		if ( ! empty( $atts['class'] ) ) {
			$classes .= ' ' . sanitize_html_class( (string) $atts['class'] );
		}

		$show_author = ! empty( $atts['show_author'] ) && '0' !== (string) $atts['show_author'];

		$html  = '<figure class="' . esc_attr( $classes ) . '">';
		$html .= '<blockquote class="ces-quote-block__text">' . esc_html( $quote['text'] ) . '</blockquote>';

		if ( $show_author && '' !== $quote['author'] ) {
			$html .= '<figcaption class="ces-quote-block__author">— ' . esc_html( $quote['author'] ) . '</figcaption>';
		}

		$html .= '</figure>';

		return $html;
	}
}
