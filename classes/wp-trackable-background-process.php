<?php

if ( ! class_exists( 'WP_Trackable_Background_Process' ) ) {
	abstract class WP_Trackable_Background_Process extends WP_Background_Process {
		protected $keep_old_logs = false;

		public function __construct() {
			parent::__construct();

			add_action( 'wp_ajax_start_background_process_' . $this->identifier, array( $this, 'maybe_start' ) );
			add_action( 'wp_ajax_get_background_process_' . $this->identifier . '_logs', array( $this, 'get_logs' ) );
			add_action( 'wp_ajax_empty_old_background_process_' . $this->identifier . '_logs', array( $this, 'empty_old_logs' ) );
			add_action( 'heartbeat_received', array( $this, 'maybe_send_process_info' ), 10, 3 );
		}

		public function enqueue_script( $progress_selector, $start_button_selector, $empty_logs_button_selector = '#empty-logs' ) {
			$baseurl = plugin_dir_url( dirname( __FILE__ ) ) . 'assets/';

			$process_key = get_site_option( 'current_background_process_' . $this->identifier );

			wp_enqueue_style( 'wp-background-processing-ui', $baseurl . 'wp-background-processing-ui.css', array(), '1.0.0' );
			wp_enqueue_script( 'wp-background-processing-ui', $baseurl . 'wp-background-processing-ui.js', array(
				'jquery',
				'heartbeat',
				'wp-util',
			), '1.0.0', true );

			wp_localize_script( 'wp-background-processing-ui', 'wpBackgroundProcessingUI', array(
				'processIdentifier'				=> $this->identifier,
				'processActive'					=> (bool) $process_key,
				'processNonce'					=> wp_create_nonce( $this->identifier ),
				'l10n'							=> array(
					'missingHeartbeat'				=> __( 'WP Heartbeat not loaded.', 'wp-background-processing-ui' ),
					'missingUtil'					=> __( 'WP Util not loaded.', 'wp-background-processing-ui' ),
					'invalidProgressSelector'		=> sprintf( __( 'Could not find element %s.', 'wp-background-processing-ui' ), $progress_selector ),
					'invalidStartButtonSelector'	=> sprintf( __( 'Could not find element %s.', 'wp-background-processing-ui' ), $start_button_selector ),
				),
				'selectors'						=> array(
					'progress'						=> $progress_selector,
					'startButton'					=> $start_button_selector,
					'emptyLogsButton'				=> $empty_logs_button_selector,
				),
			) );
		}

		public function print_script_template() {
			?>
			<script type="text/html" id="tmpl-background-process-info">
				<# if ( ! _.isUndefined( data.processInfo.key ) ) { #>
					<div class="progress-wrap">
						<div class="progress-bar">
							<progress id="progressbar-total" max="100" value="{{ data.processInfo.percentage }}"></progress>
						</div>
						<div class="status">
							<span id="completed-total" class="completed">{{ data.processInfo.progress }}/{{ data.processInfo.total }}</span>
							<span id="progress-total" class="progress">({{ data.processInfo.percentage }}%)</span>
						</div>
					</div>
					<# if ( ! _.isUndefined( data.processInfo.logs ) && data.processInfo.logs.length ) { #>
						<div class="logs">
							<h3><?php _e( 'Logs', 'wp-background-processing-ui' ); ?></h3>
							<ul>
								<# _.each( data.processInfo.logs, function( log ) {
									#>
									<li id="log-{{ log.id }}" class="log log-{{ log.type }}">
										<span>{{ log.message }}</span>
									</li>
									<#
								}); #>
							</ul>
							<# if ( data.hasMoreLogs ) { #>
								<button id="logs-more" class="logs-more button button-secondary"><?php _e( 'Show More', 'wp-background-processing-ui' ); ?></button>
							<# } #>
						</div>
					<# } #>
				<# } #>
			</script>
			<?php
		}

		public function maybe_start() {
			if ( ! isset( $_REQUEST['nonce'] ) ) {
				wp_send_json_error( __( 'Missing nonce.', 'wp-background-processing-ui' ) );
			}

			if ( ! check_ajax_referer( $this->identifier, 'nonce', false ) ) {
				wp_send_json_error( __( 'Invalid nonce.', 'wp-background-processing-ui' ) );
			}

			$status = $this->start();
			if ( ! $status ) {
				wp_send_json_error( __( 'Process not started.', 'wp-background-processing-ui' ) );
			}

			wp_send_json_success( __( 'Process started.', 'wp-background-processing-ui' ) );
		}

		public function get_logs() {
			if ( ! isset( $_REQUEST['nonce'] ) ) {
				wp_send_json_error( __( 'Missing nonce.', 'wp-background-processing-ui' ) );
			}

			if ( ! check_ajax_referer( $this->identifier, 'nonce', false ) ) {
				wp_send_json_error( __( 'Invalid nonce.', 'wp-background-processing-ui' ) );
			}

			if ( ! isset( $_REQUEST['key'] ) ) {
				wp_send_json_error( __( 'Missing process key.', 'wp-background-processing-ui' ) );
			}

			$args = array(
				'number'				=> 25,
				'process_identifier'	=> $this->identifier,
				'process_key'			=> wp_unslash( $_REQUEST['key'] ),
			);

			if ( isset( $_REQUEST['afterId'] ) && $_REQUEST['afterId'] ) {
				$args['id'] = absint( $_REQUEST['afterId'] );
				$args['id_compare'] = '>';
			} elseif ( isset( $_REQUEST['beforeId'] ) && $_REQUEST['beforeId'] ) {
				$args['id'] = absint( $_REQUEST['beforeId'] );
				$args['id_compare'] = '<';
			}

			$logs = WP_Background_Process_Logging::query( $args );

			wp_send_json_success( $logs );
		}

		public function empty_old_logs() {
			if ( ! isset( $_REQUEST['nonce'] ) ) {
				wp_send_json_error( __( 'Missing nonce.', 'wp-background-processing-ui' ) );
			}

			if ( ! check_ajax_referer( $this->identifier, 'nonce', false ) ) {
				wp_send_json_error( __( 'Invalid nonce.', 'wp-background-processing-ui' ) );
			}

			$this->delete_old_process_info();

			wp_send_json_success( __( 'Logs emptied.', 'wp-background-processing-ui' ) );
		}

		public function maybe_send_process_info( $response, $data, $screen_id ) {
			if ( ! isset( $data['requestBackgroundProcessInfo'] ) ) {
				return $response;
			}

			if ( $this->identifier !== $data['requestBackgroundProcessInfo'] ) {
				return $response;
			}

			$process_key = get_site_option( 'current_background_process_' . $this->identifier );
			if ( ! $process_key ) {
				$process_key = get_site_option( 'last_background_process_' . $this->identifier );
				if ( ! $process_key ) {
					return $response;
				}
			}

			$args = array(
				'process_identifier'	=> $this->identifier,
				'process_key'			=> $process_key,
			);
			if ( isset( $data['backgroundProcessInfoLatestLogId'] ) && $data['backgroundProcessInfoLatestLogId'] ) {
				$args['number'] = -1;
				$args['id'] = absint( $data['backgroundProcessInfoLatestLogId'] );
				$args['id_compare'] = '>';
			} else {
				$args['number'] = 25;
			}

			$logs = WP_Background_Process_Logging::query( $args );

			$process_info = array(
				'identifier'	=> $this->identifier,
				'key'			=> $process_key,
				'total'			=> (int) get_site_option( 'background_process_' . $this->identifier . '_total', 0 ),
				'progress'		=> (int) get_site_option( 'background_process_' . $this->identifier . '_progress', 0 ),
				'logs'			=> $logs,
			);

			$response['backgroundProcessInfo'] = $process_info;

			return $response;
		}

		public function get_nonce() {
			return wp_create_nonce( $this->identifier );
		}

		protected function start() {
			if ( (bool) get_site_option( 'current_background_process_' . $this->identifier ) ) {
				return false;
			}

			// Only keep old process info until the next process is started.
			$this->delete_old_process_info();

			$process_key = $this->generate_process_key();

			update_site_option( 'current_background_process_' . $this->identifier, $process_key );

			$this->before_start();

			$this->dispatch();

			return true;
		}

		protected function finish() {
			$process_key = get_site_option( 'current_background_process_' . $this->identifier );
			if ( ! $process_key ) {
				return false;
			}

			delete_site_option( 'current_background_process_' . $this->identifier );

			update_site_option( 'last_background_process_' . $this->identifier, $process_key );

			return true;
		}

		protected function complete() {
			$this->finish();

			parent::complete();
		}

		protected function log( $message, $type = 'success' ) {
			return WP_Background_Process_Logging::add( $message, $this->identifier, $type );
		}

		protected function increase_progress( $number = 1 ) {
			$progress = get_site_option( 'background_process_' . $this->identifier . '_progress', 0 );
			update_site_option( 'background_process_' . $this->identifier . '_progress', $progress + $number );
		}

		protected function increase_total( $number = 0 ) {
			if ( ! $number ) {
				$number = count( $this->data );
			}

			$total = get_site_option( 'background_process_' . $this->identifier . '_total', 0 );
			update_site_option( 'background_process_' . $this->identifier . '_total', $total + $number );
		}

		protected function delete_old_process_info() {
			$process_key = get_site_option( 'last_background_process_' . $this->identifier );
			if ( ! $process_key ) {
				return;
			}

			if ( ! $this->keep_old_logs ) {
				WP_Background_Process_Logging::delete( array(
					'process_identifier'	=> $this->identifier,
					'process_key'			=> $process_key,
				) );
			}

			delete_site_option( 'background_process_' . $this->identifier . '_total' );
			delete_site_option( 'background_process_' . $this->identifier . '_progress' );
			delete_site_option( 'last_background_process_' . $this->identifier );
		}

		protected function generate_process_key( $length = 64 ) {
			$unique = md5( microtime() . rand() );

			return substr( $unique, 0, $length );
		}

		protected function before_start() {
			// empty
		}
	}
}
