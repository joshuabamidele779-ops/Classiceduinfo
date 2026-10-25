<?php
/**
 * Recording a lesson in the browser.
 *
 * A tutor with a laptop, a phone and no editing software can make a decent
 * teaching video, and the thing stopping them is not talent — it is the
 * twenty minutes of faff between "I know this topic" and "there is a file I
 * can upload". So the Studio records for them, in the browser, with no
 * software to install:
 *
 *   - **Screen and voice**, which is what most teaching actually needs: a
 *     past paper on screen, a pen tool, and someone talking through it.
 *   - **Screen with a camera inset**, composited live onto a canvas. Seeing
 *     the teacher's face in the corner measurably helps students stay with a
 *     video, and it costs nothing to add.
 *   - **Camera only**, for an introduction or a pep talk.
 *
 * Around the recorder sit the three things that stop a recording being
 * wasted:
 *
 *   **The check.** Microphone level meter, camera preview, a reminder about
 *   the light and the background noise, and a ten-second test they play back
 *   before committing. Most bad recordings are bad audio, and bad audio is
 *   always discovered after the recording.
 *
 *   **The teleprompter.** Reads the script from the lesson plan and scrolls
 *   it at a speed they set, so they can look at the camera instead of down
 *   at their notes.
 *
 *   **The frame grab.** One button turns the current frame into the lesson's
 *   cover image.
 *
 * The finished recording is a WebM file, which the LMS uploader accepts and a
 * browser plays natively — so a recorded lesson is watchable even on a host
 * with no ffmpeg at all. The upload itself is the LMS module's own chunked,
 * resumable uploader; nothing here reimplements it.
 *
 * @package ClassicEduSuite\Classroom
 */

defined( 'ABSPATH' ) || exit;

/**
 * The recorder panel and the upload bridge.
 */
class CES_Classroom_Recorder {

	/**
	 * The nonce the LMS uploader expects.
	 */
	const UPLOAD_NONCE = 'ces_lms_upload';

	/**
	 * admin-ajax actions the uploader exposes.
	 *
	 * Listed here because the module lends a consultant the LMS upload
	 * capability for exactly these actions and no others.
	 *
	 * @var string[]
	 */
	public static $upload_actions = array(
		'ces_lms_upload_begin',
		'ces_lms_upload_chunk',
		'ces_lms_upload_finish',
		'ces_lms_upload_abort',
	);

	/**
	 * Whether in-browser recording is switched on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return CES_Helpers::enabled( 'classroom_recorder_enabled' );
	}

	/**
	 * The longest single recording allowed, in minutes.
	 *
	 * @return int
	 */
	public static function max_minutes() {
		return max( 1, min( 180, CES_Helpers::setting_int( 'classroom_recorder_minutes', 30 ) ) );
	}

	/**
	 * What the browser needs to talk to the uploader.
	 *
	 * @return array
	 */
	public static function upload_config() {
		$chunk = class_exists( 'CES_Lms_Admin_Upload' ) ? (int) CES_Lms_Admin_Upload::chunk_size() : 1048576;
		$max   = class_exists( 'CES_Lms_Admin_Upload' ) ? (int) CES_Lms_Admin_Upload::max_bytes() : 2147483648;

		return array(
			'ajaxUrl'    => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
			'nonce'      => wp_create_nonce( self::UPLOAD_NONCE ),
			'chunkSize'  => $chunk,
			'maxBytes'   => $max,
			'maxMinutes' => self::max_minutes(),
			'accept'     => class_exists( 'CES_Lms_Admin_Upload' )
				? array_keys( CES_Lms_Admin_Upload::allowed_types() )
				: array( 'mp4', 'mov', 'webm', 'm4v' ),
		);
	}

	/**
	 * The recorder and uploader panel for one lesson.
	 *
	 * @param int $lesson_id Lesson.
	 * @return void
	 */
	public static function render( $lesson_id ) {
		$lesson_id = (int) $lesson_id;
		$status    = class_exists( 'CES_Lms_Meta' ) ? CES_Lms_Meta::status( $lesson_id ) : '';
		$playable  = class_exists( 'CES_Lms_Meta' ) && CES_Lms_Meta::is_playable( $lesson_id );
		$plan      = CES_Classroom_Lesson_Tools::plan( $lesson_id );
		$upload    = self::upload_config();
		?>
		<details class="ces-studio__panel ces-studio__video" <?php echo $playable ? '' : 'open'; ?>
			data-ces-recorder data-ces-lesson="<?php echo esc_attr( (string) $lesson_id ); ?>">
			<summary><?php esc_html_e( 'The video', 'classic-edu-suite' ); ?></summary>

			<?php if ( $playable ) : ?>
				<p class="ces-notice ces-notice--success">
					<?php
					printf(
						/* translators: 1: status label, 2: duration. */
						esc_html__( 'Video is ready (%1$s, %2$s). Recording again replaces it.', 'classic-edu-suite' ),
						esc_html( CES_Lms_Meta::status_label( $status ) ),
						esc_html( CES_Lms_Meta::format_duration( CES_Lms_Meta::duration( $lesson_id ) ) )
					);
					?>
				</p>
			<?php elseif ( '' !== $status && class_exists( 'CES_Lms_Meta' ) && CES_Lms_Meta::STATUS_NONE !== $status ) : ?>
				<p class="ces-notice">
					<?php
					printf(
						/* translators: %s: status label. */
						esc_html__( 'Video status: %s. Large videos take a few minutes to process — you can carry on with the plan while it does.', 'classic-edu-suite' ),
						esc_html( CES_Lms_Meta::status_label( $status ) )
					);
					?>
				</p>
			<?php endif; ?>

			<div class="ces-rec" data-ces-rec-root>

				<div class="ces-rec__tabs" role="tablist">
					<button type="button" class="ces-rec__tab is-active" data-ces-rec-view="record" role="tab" aria-selected="true">
						<?php esc_html_e( 'Record now', 'classic-edu-suite' ); ?>
					</button>
					<button type="button" class="ces-rec__tab" data-ces-rec-view="upload" role="tab" aria-selected="false">
						<?php esc_html_e( 'Upload a file', 'classic-edu-suite' ); ?>
					</button>
				</div>

				<!-- Recording -->
				<div class="ces-rec__view" data-ces-rec-panel="record">
					<?php if ( ! self::enabled() ) : ?>
						<p class="ces-studio__hint"><?php esc_html_e( 'In-browser recording is switched off on this site. Upload a file instead.', 'classic-edu-suite' ); ?></p>
					<?php else : ?>

						<p class="ces-rec__unsupported" data-ces-rec-unsupported hidden>
							<?php esc_html_e( 'This browser cannot record. Use Chrome, Edge or Firefox on a computer — or record on your phone and upload the file.', 'classic-edu-suite' ); ?>
						</p>

						<div class="ces-rec__setup" data-ces-rec-setup>
							<p class="ces-field">
								<label for="ces-rec-mode"><?php esc_html_e( 'What to record', 'classic-edu-suite' ); ?></label>
								<select id="ces-rec-mode" data-ces-rec-mode>
									<option value="screen"><?php esc_html_e( 'My screen and my voice', 'classic-edu-suite' ); ?></option>
									<option value="screen-camera"><?php esc_html_e( 'My screen, my voice, and me in the corner', 'classic-edu-suite' ); ?></option>
									<option value="camera"><?php esc_html_e( 'Just me and my voice', 'classic-edu-suite' ); ?></option>
								</select>
								<span class="ces-field__hint"><?php esc_html_e( 'For most teaching, screen and voice is the one: put the question on screen and talk through it.', 'classic-edu-suite' ); ?></span>
							</p>

							<p>
								<button type="button" class="ces-btn ces-btn--ghost" data-ces-rec-check>
									<?php esc_html_e( 'Check my microphone and camera', 'classic-edu-suite' ); ?>
								</button>
							</p>
						</div>

						<div class="ces-rec__check" data-ces-rec-checkpanel hidden>
							<div class="ces-rec__preview-wrap">
								<video class="ces-rec__preview" data-ces-rec-preview muted playsinline></video>
								<canvas class="ces-rec__canvas" data-ces-rec-canvas hidden></canvas>
							</div>

							<div class="ces-rec__meter-wrap">
								<span class="ces-rec__meter-label"><?php esc_html_e( 'Microphone', 'classic-edu-suite' ); ?></span>
								<span class="ces-rec__meter"><span data-ces-rec-meter></span></span>
								<span class="ces-rec__meter-hint" data-ces-rec-meter-hint><?php esc_html_e( 'Say something at your normal teaching volume.', 'classic-edu-suite' ); ?></span>
							</div>

							<ul class="ces-rec__advice">
								<li><?php esc_html_e( 'Sit with a window in front of you, not behind you.', 'classic-edu-suite' ); ?></li>
								<li><?php esc_html_e( 'Close the door and switch off the fan if you can — fan noise ruins audio more than anything else.', 'classic-edu-suite' ); ?></li>
								<li><?php esc_html_e( 'Earphones with a mic beat a laptop microphone every time.', 'classic-edu-suite' ); ?></li>
								<li><?php esc_html_e( 'Close other tabs before you share your screen, and turn off notifications.', 'classic-edu-suite' ); ?></li>
							</ul>

							<p class="ces-rec__readout" data-ces-rec-readout></p>

							<p>
								<button type="button" class="ces-btn ces-btn--ghost" data-ces-rec-test><?php esc_html_e( 'Record ten seconds and play it back', 'classic-edu-suite' ); ?></button>
								<button type="button" class="ces-btn ces-btn--ghost" data-ces-rec-stop-check hidden><?php esc_html_e( 'Stop the check', 'classic-edu-suite' ); ?></button>
							</p>

							<video class="ces-rec__playback" data-ces-rec-test-playback controls hidden></video>
						</div>

						<?php if ( '' !== trim( $plan['script'] ) ) : ?>
							<details class="ces-rec__prompter" data-ces-rec-prompter>
								<summary><?php esc_html_e( 'Teleprompter', 'classic-edu-suite' ); ?></summary>
								<p class="ces-field">
									<label for="ces-rec-speed"><?php esc_html_e( 'Scroll speed', 'classic-edu-suite' ); ?></label>
									<input type="range" id="ces-rec-speed" min="0" max="10" value="3" data-ces-rec-speed />
								</p>
								<div class="ces-rec__script" data-ces-rec-script tabindex="0"><?php echo esc_html( $plan['script'] ); ?></div>
								<p class="ces-studio__hint"><?php esc_html_e( 'It scrolls while you record. Edit the words in the plan above.', 'classic-edu-suite' ); ?></p>
							</details>
						<?php else : ?>
							<p class="ces-studio__hint"><?php esc_html_e( 'Tip: put your script in the “Script or prompts” box in the plan, and a teleprompter appears here.', 'classic-edu-suite' ); ?></p>
						<?php endif; ?>

						<div class="ces-rec__controls">
							<button type="button" class="ces-btn ces-btn--primary" data-ces-rec-start disabled>
								<?php esc_html_e( 'Start recording', 'classic-edu-suite' ); ?>
							</button>
							<button type="button" class="ces-btn ces-btn--ghost" data-ces-rec-pause hidden><?php esc_html_e( 'Pause', 'classic-edu-suite' ); ?></button>
							<button type="button" class="ces-btn ces-btn--ghost" data-ces-rec-finish hidden><?php esc_html_e( 'Stop and keep it', 'classic-edu-suite' ); ?></button>
							<button type="button" class="ces-btn ces-btn--ghost" data-ces-rec-grab hidden><?php esc_html_e( 'Use this frame as the cover', 'classic-edu-suite' ); ?></button>
							<span class="ces-rec__timer" data-ces-rec-timer></span>
						</div>

						<p class="ces-rec__hint" data-ces-rec-hint></p>

						<div class="ces-rec__result" data-ces-rec-result hidden>
							<h4><?php esc_html_e( 'Your recording', 'classic-edu-suite' ); ?></h4>
							<video class="ces-rec__playback" data-ces-rec-playback controls></video>
							<p>
								<button type="button" class="ces-btn ces-btn--primary" data-ces-rec-upload><?php esc_html_e( 'Use this recording', 'classic-edu-suite' ); ?></button>
								<button type="button" class="ces-btn ces-btn--ghost" data-ces-rec-discard><?php esc_html_e( 'Throw it away and start again', 'classic-edu-suite' ); ?></button>
							</p>
						</div>
					<?php endif; ?>
				</div>

				<!-- Uploading -->
				<div class="ces-rec__view" data-ces-rec-panel="upload" hidden>
					<p class="ces-studio__hint">
						<?php
						printf(
							/* translators: 1: list of file types, 2: size limit. */
							esc_html__( 'Accepts %1$s, up to %2$s. A dropped connection resumes where it left off, so a long upload on a bad line is safe.', 'classic-edu-suite' ),
							esc_html( strtoupper( implode( ', ', (array) $upload['accept'] ) ) ),
							esc_html( size_format( (int) $upload['maxBytes'] ) )
						);
						?>
					</p>

					<p class="ces-field">
						<label for="ces-rec-file"><?php esc_html_e( 'Choose a video', 'classic-edu-suite' ); ?></label>
						<input type="file" id="ces-rec-file" accept="video/*" data-ces-rec-file />
					</p>
				</div>

				<!-- Progress, shared by both -->
				<div class="ces-rec__progress" data-ces-rec-progress hidden>
					<div class="ces-rec__bar"><span data-ces-rec-bar></span></div>
					<p class="ces-rec__progress-text" data-ces-rec-progress-text></p>
				</div>
			</div>
		</details>
		<?php
	}
}
