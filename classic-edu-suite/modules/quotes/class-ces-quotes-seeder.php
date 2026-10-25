<?php
/**
 * Starter quote pool, installed once so the popup works out of the box.
 *
 * Attribution policy: every quote below is either attributed to the person it
 * is routinely and verifiably credited to, or marked as a proverb. Nothing is
 * credited to a real person on a guess. If you are not sure who said a line,
 * ship it as a proverb or leave the attribution empty.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Seeds the initial quote pool.
 */
class CES_Quotes_Seeder {

	/**
	 * Option flag marking the seed as done.
	 */
	const OPTION = 'ces_quotes_seeded';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		// Runs after the post type is registered on `init`.
		add_action( 'init', array( __CLASS__, 'maybe_seed' ), 20 );
	}

	/**
	 * Install the starter pool on the first run only.
	 *
	 * @return int Number of quotes created.
	 */
	public static function maybe_seed() {
		if ( get_option( self::OPTION ) ) {
			return 0;
		}

		// Claim the flag before doing the work, so two concurrent requests
		// cannot both seed the pool.
		update_option( self::OPTION, CES_VERSION, false );

		return self::install();
	}

	/**
	 * Insert the starter quotes, skipping any that already exist.
	 *
	 * @return int Number created.
	 */
	public static function install() {
		$created = 0;

		foreach ( self::quotes() as $entry ) {
			$result = CES_Quotes_Cpt::create(
				$entry['text'],
				array(
					'author'          => isset( $entry['author'] ) ? $entry['author'] : '',
					'active'          => true,
					'weight'          => isset( $entry['weight'] ) ? (int) $entry['weight'] : 1,
					'terms'           => isset( $entry['terms'] ) ? $entry['terms'] : array(),
					'skip_duplicates' => true,
				)
			);

			if ( is_int( $result ) && $result > 0 ) {
				$created++;
			}
		}

		if ( $created > 0 && class_exists( 'CES_Quotes_Scheduler' ) ) {
			CES_Quotes_Scheduler::flush_pool();
		}

		CES_Helpers::log( 'Seeded starter quotes', $created );

		return $created;
	}

	/**
	 * The starter pool.
	 *
	 * @return array[] Each entry: text, author, terms, weight.
	 */
	public static function quotes() {
		$motivation  = __( 'Motivation', 'classic-edu-suite' );
		$study       = __( 'Study', 'classic-edu-suite' );
		$persistence = __( 'Persistence', 'classic-edu-suite' );
		$proverbs    = __( 'Proverbs', 'classic-edu-suite' );

		return array(
			array(
				'text'   => 'Education is the most powerful weapon which we can use to change the world.',
				'author' => 'Nelson Mandela',
				'terms'  => array( $motivation ),
				'weight' => 3,
			),
			array(
				'text'   => 'It always seems impossible until it is done.',
				'author' => 'Nelson Mandela',
				'terms'  => array( $persistence ),
				'weight' => 2,
			),
			array(
				'text'   => 'The beautiful thing about learning is that no one can take it away from you.',
				'author' => 'B.B. King',
				'terms'  => array( $study ),
				'weight' => 2,
			),
			array(
				'text'   => 'An investment in knowledge pays the best interest.',
				'author' => 'Benjamin Franklin',
				'terms'  => array( $study ),
			),
			array(
				'text'   => 'Energy and persistence conquer all things.',
				'author' => 'Benjamin Franklin',
				'terms'  => array( $persistence ),
			),
			array(
				'text'   => 'I have no special talent. I am only passionately curious.',
				'author' => 'Albert Einstein',
				'terms'  => array( $study ),
				'weight' => 2,
			),
			array(
				'text'   => 'Nothing in life is to be feared, it is only to be understood.',
				'author' => 'Marie Curie',
				'terms'  => array( $motivation ),
			),
			array(
				'text'   => 'Learning never exhausts the mind.',
				'author' => 'Leonardo da Vinci',
				'terms'  => array( $study ),
			),
			array(
				'text'   => 'Genius is one percent inspiration and ninety-nine percent perspiration.',
				'author' => 'Thomas Edison',
				'terms'  => array( $persistence ),
			),
			array(
				'text'   => 'Our greatest weakness lies in giving up. The most certain way to succeed is always to try just one more time.',
				'author' => 'Thomas Edison',
				'terms'  => array( $persistence ),
				'weight' => 2,
			),
			array(
				'text'   => 'Study hard what interests you the most in the most undisciplined, irreverent and original manner possible.',
				'author' => 'Richard Feynman',
				'terms'  => array( $study ),
			),
			array(
				'text'   => 'The future belongs to those who believe in the beauty of their dreams.',
				'author' => 'Eleanor Roosevelt',
				'terms'  => array( $motivation ),
			),
			array(
				'text'   => 'The more that you read, the more things you will know. The more that you learn, the more places you will go.',
				'author' => 'Dr. Seuss',
				'terms'  => array( $study ),
				'weight' => 2,
			),
			array(
				'text'   => 'Discipline is the bridge between goals and accomplishment.',
				'author' => 'Jim Rohn',
				'terms'  => array( $persistence ),
				'weight' => 2,
			),
			array(
				'text'   => 'Success is the sum of small efforts, repeated day in and day out.',
				'author' => 'Robert Collier',
				'terms'  => array( $persistence ),
				'weight' => 3,
			),
			array(
				'text'   => 'Develop a passion for learning. If you do, you will never cease to grow.',
				'author' => 'Anthony J. D’Angelo',
				'terms'  => array( $study ),
			),
			array(
				'text'   => 'Try to learn something about everything and everything about something.',
				'author' => 'Thomas Henry Huxley',
				'terms'  => array( $study ),
			),
			array(
				'text'   => 'The only person who is educated is the one who has learned how to learn and change.',
				'author' => 'Carl Rogers',
				'terms'  => array( $study ),
			),
			array(
				'text'   => 'Perseverance is not a long race; it is many short races one after the other.',
				'author' => 'Walter Elliot',
				'terms'  => array( $persistence ),
			),
			array(
				'text'   => 'Live as if you were to die tomorrow. Learn as if you were to live forever.',
				'author' => 'Mahatma Gandhi',
				'terms'  => array( $motivation ),
				'weight' => 2,
			),
			array(
				'text'   => 'However long the night, the dawn will break.',
				'author' => 'African proverb',
				'terms'  => array( $proverbs, $persistence ),
				'weight' => 2,
			),
			array(
				'text'   => 'If you want to go fast, go alone. If you want to go far, go together.',
				'author' => 'African proverb',
				'terms'  => array( $proverbs, $motivation ),
				'weight' => 2,
			),
			array(
				'text'   => 'Smooth seas do not make skilful sailors.',
				'author' => 'African proverb',
				'terms'  => array( $proverbs, $persistence ),
			),
			array(
				'text'   => 'Knowledge is like a garden: if it is not cultivated, it cannot be harvested.',
				'author' => 'African proverb',
				'terms'  => array( $proverbs, $study ),
				'weight' => 2,
			),
			array(
				'text'   => 'Little by little, a little becomes a lot.',
				'author' => 'Tanzanian proverb',
				'terms'  => array( $proverbs, $persistence ),
				'weight' => 3,
			),
			array(
				'text'   => 'Wisdom is like a baobab tree; no one individual can embrace it.',
				'author' => 'Akan proverb',
				'terms'  => array( $proverbs, $study ),
			),
			array(
				'text'   => 'Fall seven times, stand up eight.',
				'author' => 'Japanese proverb',
				'terms'  => array( $proverbs, $persistence ),
				'weight' => 2,
			),
			array(
				'text'   => 'A journey of a thousand miles begins with a single step.',
				'author' => 'Chinese proverb',
				'terms'  => array( $proverbs, $motivation ),
			),
		);
	}
}
