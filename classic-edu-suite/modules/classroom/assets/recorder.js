/**
 * Recording a lesson in the browser, and getting it to the server.
 *
 * Three modes. Screen plus voice is the ordinary one and needs no
 * compositing: the screen track and the microphone track go straight into a
 * MediaRecorder. Screen with the tutor in the corner does need compositing,
 * so the screen and camera are drawn onto a canvas fifteen times a second and
 * the canvas's own stream is what gets recorded. Camera only is the simple
 * case again.
 *
 * Fifteen frames a second is deliberate. A teaching video is a still page
 * with a voice over it; thirty frames doubles the file size to capture
 * nothing, and the file has to climb a Nigerian upload connection
 * afterwards.
 *
 * The microphone meter is the single most valuable thing on this screen.
 * Almost every unusable recording is unusable because of audio, and audio
 * problems are always discovered after the recording. So the meter runs
 * before the record button will even enable, and it says in words whether
 * the level is right.
 *
 * Uploading speaks the LMS module's own chunked protocol — begin, chunk,
 * chunk, chunk, finish — which resumes from the server's byte offset after a
 * dropped connection instead of starting a 300 MB file again.
 *
 * @package ClassicEduSuite\Classroom
 */
( function () {
	'use strict';

	var CFG = window.CESClass || {};
	var I18N = CFG.i18n || {};
	var UP = CFG.upload || {};

	var root = document.querySelector( '[data-ces-recorder]' );

	if ( ! root || ! window.CESChat ) {
		return;
	}

	var api = window.CESChat.api;
	var lessonId = parseInt( root.getAttribute( 'data-ces-lesson' ), 10 ) || 0;

	/**
	 * Find an element inside the recorder.
	 *
	 * @param {string} name Data attribute, without the `data-ces-rec-` prefix.
	 * @return {HTMLElement|null} The element.
	 */
	function el( name ) {
		return root.querySelector( '[data-ces-rec-' + name + ']' );
	}

	var ui = {
		setup: el( 'setup' ),
		check: el( 'check' ),
		checkPanel: el( 'checkpanel' ),
		preview: el( 'preview' ),
		canvas: el( 'canvas' ),
		meter: el( 'meter' ),
		meterHint: el( 'meter-hint' ),
		readout: el( 'readout' ),
		test: el( 'test' ),
		stopCheck: el( 'stop-check' ),
		testPlayback: el( 'test-playback' ),
		mode: el( 'mode' ),
		start: el( 'start' ),
		pause: el( 'pause' ),
		finish: el( 'finish' ),
		grab: el( 'grab' ),
		timer: el( 'timer' ),
		hint: el( 'hint' ),
		result: el( 'result' ),
		playback: el( 'playback' ),
		upload: el( 'upload' ),
		discard: el( 'discard' ),
		file: el( 'file' ),
		progress: el( 'progress' ),
		bar: el( 'bar' ),
		progressText: el( 'progress-text' ),
		unsupported: el( 'unsupported' ),
		prompter: el( 'prompter' ),
		script: el( 'script' ),
		speed: el( 'speed' )
	};

	var state = {
		stream: null,
		screenStream: null,
		cameraStream: null,
		audioStream: null,
		recorder: null,
		chunks: [],
		blob: null,
		started: 0,
		elapsed: 0,
		tick: null,
		painting: null,
		audioCtx: null,
		analyser: null,
		meterRaf: null,
		scrolling: null,
		testing: false
	};

	/* -----------------------------------------------------------------
	 * Support.
	 * -------------------------------------------------------------- */

	var supported = !! (
		window.MediaRecorder &&
		window.navigator.mediaDevices &&
		window.navigator.mediaDevices.getUserMedia
	);

	if ( ! supported ) {
		if ( ui.unsupported ) {
			ui.unsupported.hidden = false;
		}

		if ( ui.setup ) {
			ui.setup.hidden = true;
		}
	}

	/**
	 * Whether this browser can capture the screen.
	 *
	 * @return {boolean} True when screen capture is available.
	 */
	function canShareScreen() {
		return !! ( window.navigator.mediaDevices && window.navigator.mediaDevices.getDisplayMedia );
	}

	/**
	 * The best container and codec this browser will record.
	 *
	 * @return {string} A mime type, or '' to let the browser choose.
	 */
	function pickMime() {
		var wanted = [
			'video/webm;codecs=vp9,opus',
			'video/webm;codecs=vp8,opus',
			'video/webm',
			'video/mp4'
		];

		for ( var i = 0; i < wanted.length; i++ ) {
			if ( window.MediaRecorder.isTypeSupported && window.MediaRecorder.isTypeSupported( wanted[ i ] ) ) {
				return wanted[ i ];
			}
		}

		return '';
	}

	/**
	 * Say something under the controls.
	 *
	 * @param {string}  text Message.
	 * @param {boolean} bad  Whether it is a problem.
	 * @return {void}
	 */
	function hint( text, bad ) {
		if ( ! ui.hint ) {
			return;
		}

		ui.hint.textContent = text || '';
		ui.hint.className = 'ces-rec__hint' + ( bad ? ' is-bad' : '' );
	}

	/* -----------------------------------------------------------------
	 * Getting the streams.
	 * -------------------------------------------------------------- */

	/**
	 * Ask for whatever the chosen mode needs.
	 *
	 * @return {Promise<MediaStream>} The stream to record.
	 */
	function openStreams() {
		var mode = ui.mode ? ui.mode.value : 'screen';

		if ( 'screen' !== mode && 'screen-camera' !== mode ) {
			return window.navigator.mediaDevices
				.getUserMedia( {
					video: { width: { ideal: 1280 }, height: { ideal: 720 }, frameRate: { ideal: 15, max: 20 } },
					audio: { echoCancellation: true, noiseSuppression: true }
				} )
				.then( function ( stream ) {
					state.cameraStream = stream;
					state.audioStream = stream;

					return stream;
				} );
		}

		if ( ! canShareScreen() ) {
			return Promise.reject( new Error( 'no-screen' ) );
		}

		return window.navigator.mediaDevices
			.getDisplayMedia( {
				video: { frameRate: { ideal: 15, max: 20 } },
				audio: false
			} )
			.then( function ( screen ) {
				state.screenStream = screen;

				return window.navigator.mediaDevices.getUserMedia( {
					audio: { echoCancellation: true, noiseSuppression: true }
				} );
			} )
			.then( function ( audio ) {
				state.audioStream = audio;

				if ( 'screen' === mode ) {
					var combined = new MediaStream();

					state.screenStream.getVideoTracks().forEach( function ( track ) {
						combined.addTrack( track );
					} );

					audio.getAudioTracks().forEach( function ( track ) {
						combined.addTrack( track );
					} );

					return combined;
				}

				// Screen with the camera in the corner: get the camera too,
				// then composite both onto a canvas.
				return window.navigator.mediaDevices
					.getUserMedia( {
						video: { width: { ideal: 480 }, height: { ideal: 360 }, frameRate: { ideal: 15 } },
						audio: false
					} )
					.then( function ( camera ) {
						state.cameraStream = camera;

						return composite( state.screenStream, camera, audio );
					} );
			} );
	}

	/**
	 * Draw the screen with the camera inset, and record the canvas.
	 *
	 * @param {MediaStream} screen Screen capture.
	 * @param {MediaStream} camera Camera capture.
	 * @param {MediaStream} audio  Microphone.
	 * @return {MediaStream} The canvas stream, with audio attached.
	 */
	function composite( screen, camera, audio ) {
		var screenVideo = document.createElement( 'video' );
		var cameraVideo = document.createElement( 'video' );

		screenVideo.srcObject = screen;
		screenVideo.muted = true;
		cameraVideo.srcObject = camera;
		cameraVideo.muted = true;

		screenVideo.play();
		cameraVideo.play();

		var canvas = ui.canvas || document.createElement( 'canvas' );
		var settings = screen.getVideoTracks()[0].getSettings ? screen.getVideoTracks()[0].getSettings() : {};

		canvas.width = settings.width || 1280;
		canvas.height = settings.height || 720;

		var context = canvas.getContext( '2d' );

		var paint = function () {
			if ( screenVideo.readyState >= 2 ) {
				context.drawImage( screenVideo, 0, 0, canvas.width, canvas.height );
			}

			if ( cameraVideo.readyState >= 2 ) {
				var inset = Math.round( canvas.width / 5 );
				var height = Math.round( inset * 0.75 );
				var x = canvas.width - inset - 24;
				var y = canvas.height - height - 24;

				context.save();
				context.shadowColor = 'rgba(0,0,0,0.45)';
				context.shadowBlur = 18;
				context.drawImage( cameraVideo, x, y, inset, height );
				context.restore();
			}
		};

		state.painting = window.setInterval( paint, 1000 / 15 );

		var stream = canvas.captureStream( 15 );

		audio.getAudioTracks().forEach( function ( track ) {
			stream.addTrack( track );
		} );

		return stream;
	}

	/**
	 * Stop everything and release the camera light.
	 *
	 * @return {void}
	 */
	function releaseStreams() {
		[ state.stream, state.screenStream, state.cameraStream, state.audioStream ].forEach( function ( stream ) {
			if ( ! stream ) {
				return;
			}

			stream.getTracks().forEach( function ( track ) {
				track.stop();
			} );
		} );

		if ( state.painting ) {
			window.clearInterval( state.painting );
			state.painting = null;
		}

		if ( state.meterRaf ) {
			window.cancelAnimationFrame( state.meterRaf );
			state.meterRaf = null;
		}

		if ( state.audioCtx && state.audioCtx.close ) {
			state.audioCtx.close().catch( function () {} );
			state.audioCtx = null;
		}

		state.stream = null;
		state.screenStream = null;
		state.cameraStream = null;
		state.audioStream = null;
	}

	/* -----------------------------------------------------------------
	 * The check.
	 * -------------------------------------------------------------- */

	/**
	 * Watch the microphone level and report it in words.
	 *
	 * @param {MediaStream} stream A stream with an audio track.
	 * @return {void}
	 */
	function startMeter( stream ) {
		var Ctx = window.AudioContext || window.webkitAudioContext;

		if ( ! Ctx || ! stream.getAudioTracks().length ) {
			return;
		}

		state.audioCtx = new Ctx();

		var source = state.audioCtx.createMediaStreamSource( stream );

		state.analyser = state.audioCtx.createAnalyser();
		state.analyser.fftSize = 512;

		source.connect( state.analyser );

		var data = new Uint8Array( state.analyser.frequencyBinCount );
		var peak = 0;
		var since = Date.now();

		var read = function () {
			state.analyser.getByteFrequencyData( data );

			var sum = 0;

			for ( var i = 0; i < data.length; i++ ) {
				sum += data[ i ] * data[ i ];
			}

			var level = Math.min( 100, Math.round( Math.sqrt( sum / data.length ) * 2.2 ) );

			peak = Math.max( peak, level );

			if ( ui.meter ) {
				ui.meter.style.width = level + '%';
				ui.meter.className = level > 78 ? 'is-loud' : ( level > 12 ? 'is-good' : '' );
			}

			// Judge it on the loudest moment of the last few seconds, not on
			// the gaps between words.
			if ( Date.now() - since > 2500 ) {
				if ( ui.meterHint ) {
					if ( peak < 12 ) {
						ui.meterHint.textContent = I18N.tooQuiet || '';
						ui.meterHint.className = 'ces-rec__meter-hint is-bad';
					} else if ( peak > 85 ) {
						ui.meterHint.textContent = I18N.tooLoud || '';
						ui.meterHint.className = 'ces-rec__meter-hint is-warn';
					} else {
						ui.meterHint.textContent = I18N.goodLevel || '';
						ui.meterHint.className = 'ces-rec__meter-hint is-good';
					}
				}

				peak = 0;
				since = Date.now();
			}

			state.meterRaf = window.requestAnimationFrame( read );
		};

		read();
	}

	/**
	 * Run the pre-flight check.
	 *
	 * @return {void}
	 */
	function runCheck() {
		if ( ui.check ) {
			ui.check.disabled = true;
		}

		openStreams()
			.then( function ( stream ) {
				state.stream = stream;

				if ( ui.checkPanel ) {
					ui.checkPanel.hidden = false;
				}

				if ( ui.preview ) {
					ui.preview.srcObject = stream;
					ui.preview.play().catch( function () {} );
				}

				startMeter( state.audioStream || stream );

				var track = stream.getVideoTracks()[0];
				var settings = track && track.getSettings ? track.getSettings() : {};

				if ( ui.readout ) {
					ui.readout.textContent = settings.width
						? settings.width + '×' + settings.height + ' · ' + Math.round( settings.frameRate || 15 ) + 'fps'
						: '';
				}

				if ( ui.start ) {
					ui.start.disabled = false;
				}

				if ( ui.stopCheck ) {
					ui.stopCheck.hidden = false;
				}

				hint( '' );
			} )
			.catch( function ( error ) {
				if ( ui.check ) {
					ui.check.disabled = false;
				}

				hint( 'no-screen' === error.message
					? 'This browser cannot share a screen. Choose “Just me and my voice”, or use Chrome on a computer.'
					: ( I18N.noPermission || '' ), true );
			} );
	}

	/* -----------------------------------------------------------------
	 * Recording.
	 * -------------------------------------------------------------- */

	/**
	 * Format seconds as m:ss.
	 *
	 * @param {number} seconds Seconds.
	 * @return {string} Timestamp.
	 */
	function clock( seconds ) {
		var mins = Math.floor( seconds / 60 );
		var rest = Math.floor( seconds % 60 );

		return mins + ':' + ( rest < 10 ? '0' : '' ) + rest;
	}

	/**
	 * Keep the timer moving and stop at the ceiling.
	 *
	 * @return {void}
	 */
	function startTimer() {
		var limit = ( parseInt( UP.maxMinutes, 10 ) || 30 ) * 60;

		state.tick = window.setInterval( function () {
			var running = state.elapsed + ( state.started ? ( Date.now() - state.started ) / 1000 : 0 );

			if ( ui.timer ) {
				ui.timer.textContent = clock( running ) + ' / ' + clock( limit );
			}

			if ( running >= limit ) {
				hint( 'That is the longest single recording allowed. Stopping it here — shorter lessons work better anyway.' );
				finish();
			}
		}, 500 );
	}

	/**
	 * Begin recording.
	 *
	 * @param {boolean} test Whether this is the ten-second test.
	 * @return {void}
	 */
	function record( test ) {
		if ( ! state.stream ) {
			hint( I18N.noPermission || '', true );

			return;
		}

		state.chunks = [];
		state.blob = null;
		state.elapsed = 0;
		state.testing = !! test;

		var mime = pickMime();

		try {
			state.recorder = mime
				? new window.MediaRecorder( state.stream, { mimeType: mime, videoBitsPerSecond: 1200000 } )
				: new window.MediaRecorder( state.stream );
		} catch ( error ) {
			hint( 'This browser refused to start recording. Try Chrome on a computer.', true );

			return;
		}

		state.recorder.ondataavailable = function ( event ) {
			if ( event.data && event.data.size ) {
				state.chunks.push( event.data );
			}
		};

		state.recorder.onstop = function () {
			state.blob = new window.Blob( state.chunks, { type: state.chunks.length ? state.chunks[0].type : 'video/webm' } );

			if ( state.tick ) {
				window.clearInterval( state.tick );
				state.tick = null;
			}

			if ( state.testing ) {
				if ( ui.testPlayback ) {
					ui.testPlayback.hidden = false;
					ui.testPlayback.src = window.URL.createObjectURL( state.blob );
				}

				hint( 'Play that back. If you can hear yourself clearly, you are ready.' );
				state.testing = false;

				return;
			}

			if ( ui.result ) {
				ui.result.hidden = false;
			}

			if ( ui.playback ) {
				ui.playback.src = window.URL.createObjectURL( state.blob );
			}

			hint( 'Watch it back before you keep it.' );
		};

		// A timeslice means a crash loses seconds, not everything.
		state.recorder.start( 4000 );
		state.started = Date.now();

		startTimer();

		if ( ! test ) {
			if ( ui.start ) {
				ui.start.hidden = true;
			}

			[ 'pause', 'finish', 'grab' ].forEach( function ( name ) {
				if ( ui[ name ] ) {
					ui[ name ].hidden = false;
				}
			} );

			if ( ui.result ) {
				ui.result.hidden = true;
			}

			hint( I18N.recording || 'Recording' );
			startPrompter();
		} else {
			window.setTimeout( function () {
				if ( state.recorder && 'inactive' !== state.recorder.state ) {
					state.recorder.stop();
				}
			}, 10000 );
		}
	}

	/**
	 * Pause or resume.
	 *
	 * @return {void}
	 */
	function togglePause() {
		if ( ! state.recorder ) {
			return;
		}

		if ( 'recording' === state.recorder.state ) {
			state.recorder.pause();
			state.elapsed += ( Date.now() - state.started ) / 1000;
			state.started = 0;

			if ( ui.pause ) {
				ui.pause.textContent = I18N.resume || 'Resume';
			}

			hint( I18N.paused || 'Paused' );
			stopPrompter();

			return;
		}

		state.recorder.resume();
		state.started = Date.now();

		if ( ui.pause ) {
			ui.pause.textContent = I18N.pause || 'Pause';
		}

		hint( I18N.recording || 'Recording' );
		startPrompter();
	}

	/**
	 * Stop and keep it.
	 *
	 * @return {void}
	 */
	function finish() {
		if ( state.recorder && 'inactive' !== state.recorder.state ) {
			state.recorder.stop();
		}

		stopPrompter();

		[ 'pause', 'finish', 'grab' ].forEach( function ( name ) {
			if ( ui[ name ] ) {
				ui[ name ].hidden = true;
			}
		} );

		if ( ui.start ) {
			ui.start.hidden = false;
		}
	}

	/**
	 * Grab the current frame as the lesson's cover.
	 *
	 * @return {void}
	 */
	function grabFrame() {
		var source = ui.preview;

		if ( ! source || ! source.videoWidth ) {
			return;
		}

		var canvas = document.createElement( 'canvas' );

		canvas.width = source.videoWidth;
		canvas.height = source.videoHeight;
		canvas.getContext( '2d' ).drawImage( source, 0, 0, canvas.width, canvas.height );

		var image = canvas.toDataURL( 'image/jpeg', 0.82 );

		api( '/class/lessons/' + lessonId + '/thumbnail', {
			method: 'POST',
			body: { image: image }
		} )
			.then( function () {
				hint( 'Saved that frame as the cover image.' );
			} )
			.catch( function ( error ) {
				hint( error.message || '', true );
			} );
	}

	/* -----------------------------------------------------------------
	 * Teleprompter.
	 * -------------------------------------------------------------- */

	/**
	 * Scroll the script while recording.
	 *
	 * @return {void}
	 */
	function startPrompter() {
		if ( ! ui.script || state.scrolling ) {
			return;
		}

		state.scrolling = window.setInterval( function () {
			var speed = ui.speed ? parseInt( ui.speed.value, 10 ) : 3;

			if ( speed > 0 ) {
				ui.script.scrollTop += speed / 3;
			}
		}, 60 );
	}

	/**
	 * Stop scrolling.
	 *
	 * @return {void}
	 */
	function stopPrompter() {
		if ( state.scrolling ) {
			window.clearInterval( state.scrolling );
			state.scrolling = null;
		}
	}

	/* -----------------------------------------------------------------
	 * Uploading.
	 * -------------------------------------------------------------- */

	/**
	 * Show upload progress.
	 *
	 * @param {number} done  Bytes sent.
	 * @param {number} total Bytes in all.
	 * @param {string} [text] Override message.
	 * @return {void}
	 */
	function progress( done, total, text ) {
		if ( ui.progress ) {
			ui.progress.hidden = false;
		}

		var percent = total > 0 ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : 0;

		if ( ui.bar ) {
			ui.bar.style.width = percent + '%';
		}

		if ( ui.progressText ) {
			ui.progressText.textContent = text || ( ( I18N.uploading || 'Uploading' ) + ' ' + percent + '%' );
		}
	}

	/**
	 * POST a FormData payload to admin-ajax.
	 *
	 * @param {string}   action admin-ajax action.
	 * @param {FormData} body   Payload.
	 * @return {Promise<Object>} The `data` half of the JSON response.
	 */
	function ajax( action, body ) {
		body.append( 'action', action );
		body.append( 'nonce', UP.nonce || '' );

		return window
			.fetch( UP.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					var message = payload && payload.data && payload.data.message
						? payload.data.message
						: ( I18N.uploadFailed || 'Upload failed' );

					throw new Error( message );
				}

				return payload.data;
			} );
	}

	/**
	 * Send a blob to the server in resumable chunks.
	 *
	 * @param {Blob}   blob The video.
	 * @param {string} name A filename with the right extension.
	 * @return {Promise<Object>} The finish response.
	 */
	function upload( blob, name ) {
		if ( ! lessonId ) {
			return Promise.reject( new Error( 'No lesson' ) );
		}

		if ( blob.size > ( parseInt( UP.maxBytes, 10 ) || 0 ) ) {
			return Promise.reject( new Error( 'That file is larger than this site allows.' ) );
		}

		var begin = new FormData();

		begin.append( 'lesson_id', String( lessonId ) );
		begin.append( 'name', name );
		begin.append( 'size', String( blob.size ) );

		progress( 0, blob.size );

		return ajax( 'ces_lms_upload_begin', begin ).then( function ( opened ) {
			var uid = opened.uid;
			var size = parseInt( opened.chunkSize, 10 ) || 1048576;

			/**
			 * Send one slice, retrying a few times before giving up.
			 *
			 * @param {number} offset Byte offset.
			 * @param {number} tries  Attempt number.
			 * @return {Promise} Resolves when the whole file is sent.
			 */
			function send( offset, tries ) {
				if ( offset >= blob.size ) {
					return Promise.resolve();
				}

				var slice = blob.slice( offset, Math.min( offset + size, blob.size ) );
				var body = new FormData();

				body.append( 'uid', uid );
				body.append( 'offset', String( offset ) );
				body.append( 'chunk', slice, 'chunk.bin' );

				return ajax( 'ces_lms_upload_chunk', body )
					.then( function ( data ) {
						var written = parseInt( data.written, 10 ) || 0;

						progress( written, blob.size );

						return send( written, 1 );
					} )
					.catch( function ( error ) {
						if ( tries >= 4 ) {
							throw error;
						}

						// Wait a moment and ask again from the same offset;
						// the server tells us where it really is.
						return new Promise( function ( resolve ) {
							window.setTimeout( resolve, 1200 * tries );
						} ).then( function () {
							return send( offset, tries + 1 );
						} );
					} );
			}

			return send( 0, 1 ).then( function () {
				var done = new FormData();

				done.append( 'uid', uid );

				progress( blob.size, blob.size, 'Finishing…' );

				return ajax( 'ces_lms_upload_finish', done );
			} );
		} );
	}

	/**
	 * Upload whatever has been recorded or chosen.
	 *
	 * @param {Blob}   blob The video.
	 * @param {string} name Filename.
	 * @return {void}
	 */
	function sendIt( blob, name ) {
		if ( ui.upload ) {
			ui.upload.disabled = true;
		}

		upload( blob, name )
			.then( function () {
				progress( 1, 1, I18N.processing || '' );

				window.setTimeout( function () {
					window.location.reload();
				}, 2500 );
			} )
			.catch( function ( error ) {
				progress( 0, 1, error.message || I18N.uploadFailed );

				if ( ui.upload ) {
					ui.upload.disabled = false;
				}
			} );
	}

	/* -----------------------------------------------------------------
	 * Wiring.
	 * -------------------------------------------------------------- */

	if ( ui.check ) {
		ui.check.addEventListener( 'click', runCheck );
	}

	if ( ui.test ) {
		ui.test.addEventListener( 'click', function () {
			hint( 'Recording ten seconds…' );
			record( true );
		} );
	}

	if ( ui.stopCheck ) {
		ui.stopCheck.addEventListener( 'click', function () {
			releaseStreams();

			if ( ui.checkPanel ) {
				ui.checkPanel.hidden = true;
			}

			if ( ui.check ) {
				ui.check.disabled = false;
			}

			if ( ui.start ) {
				ui.start.disabled = true;
			}
		} );
	}

	if ( ui.start ) {
		ui.start.addEventListener( 'click', function () {
			record( false );
		} );
	}

	if ( ui.pause ) {
		ui.pause.addEventListener( 'click', togglePause );
	}

	if ( ui.finish ) {
		ui.finish.addEventListener( 'click', finish );
	}

	if ( ui.grab ) {
		ui.grab.addEventListener( 'click', grabFrame );
	}

	if ( ui.discard ) {
		ui.discard.addEventListener( 'click', function () {
			state.blob = null;
			state.chunks = [];

			if ( ui.result ) {
				ui.result.hidden = true;
			}

			if ( ui.playback ) {
				ui.playback.removeAttribute( 'src' );
			}

			hint( '' );
		} );
	}

	if ( ui.upload ) {
		ui.upload.addEventListener( 'click', function () {
			if ( ! state.blob ) {
				return;
			}

			var extension = state.blob.type && state.blob.type.indexOf( 'mp4' ) > -1 ? 'mp4' : 'webm';

			sendIt( state.blob, 'lesson-' + lessonId + '-recording.' + extension );
		} );
	}

	if ( ui.file ) {
		ui.file.addEventListener( 'change', function () {
			if ( ! ui.file.files || ! ui.file.files.length ) {
				return;
			}

			var file = ui.file.files[0];
			var extension = ( file.name.split( '.' ).pop() || '' ).toLowerCase();
			var allowed = UP.accept || [];

			if ( allowed.length && allowed.indexOf( extension ) === -1 ) {
				progress( 0, 1, 'That file type is not accepted. Use ' + allowed.join( ', ' ).toUpperCase() + '.' );

				return;
			}

			sendIt( file, file.name );
		} );
	}

	// The two tabs.
	Array.prototype.forEach.call(
		root.querySelectorAll( '[data-ces-rec-view]' ),
		function ( tab ) {
			tab.addEventListener( 'click', function () {
				var wanted = tab.getAttribute( 'data-ces-rec-view' );

				Array.prototype.forEach.call(
					root.querySelectorAll( '[data-ces-rec-view]' ),
					function ( other ) {
						var on = other === tab;

						other.className = 'ces-rec__tab' + ( on ? ' is-active' : '' );
						other.setAttribute( 'aria-selected', on ? 'true' : 'false' );
					}
				);

				Array.prototype.forEach.call(
					root.querySelectorAll( '[data-ces-rec-panel]' ),
					function ( panel ) {
						panel.hidden = panel.getAttribute( 'data-ces-rec-panel' ) !== wanted;
					}
				);
			} );
		}
	);

	// Never leave the camera light on, and never lose a recording silently.
	window.addEventListener( 'beforeunload', function ( event ) {
		if ( state.recorder && 'inactive' !== state.recorder.state ) {
			event.preventDefault();
			event.returnValue = '';

			return '';
		}

		if ( state.blob ) {
			event.preventDefault();
			event.returnValue = '';

			return '';
		}

		releaseStreams();
	} );
}() );
