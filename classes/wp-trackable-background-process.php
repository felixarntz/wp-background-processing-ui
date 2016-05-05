<?php

if ( ! class_exists( 'WP_Trackable_Background_Process' ) ) {
	abstract class WP_Trackable_Background_Process extends WP_Background_Process {
		public function __construct() {
			parent::__construct();

			add_action( 'wp_ajax_dispatch_background_process_' . $this->identifier, array( $this, 'maybe_dispatch' ) );
			add_action( 'wp_ajax_get_background_process_' . $this->identifier . '_logs', array( $this, 'get_logs' ) );
			add_action( 'heartbeat_received', array( $this, 'maybe_send_process_info' ), 10, 3 );
		}

		public function dispatch() {
			$status = $this->start();
			if ( ! $status ) {
				return false;
			}

			parent::dispatch();

			return true;
		}

		public function save() {
			$total = 0;
			if ( ! get_site_option( 'last_background_process_' . $this->identifier ) ) {
				$total = get_site_option( 'background_process_' . $this->identifier . '_total', 0 );
			} else {
				$this->delete_old_process_info();
			}

			update_site_option( 'background_process_' . $this->identifier . '_total', $total + count( $this->data ) );

			return parent::save();
		}

		protected function complete() {
			$this->finish();

			parent::complete();
		}

		protected function log( $message, $type = 'success' ) {
			$progress = get_site_option( 'background_process_' . $this->identifier . '_progress', 0 );
			update_site_option( 'background_process_' . $this->identifier . '_progress', $progress + 1 );

			return WP_Background_Process_Logging::add( $message, $this->identifier, $type );
		}

		protected function start() {
			if ( (bool) get_site_option( 'current_background_process_' . $this->identifier ) ) {
				return false;
			}

			// Only keep old process info until the next process is started.
			$this->delete_old_process_info();

			$process_key = $this->generate_process_key();

			update_site_option( 'current_background_process_' . $this->identifier, $process_key );

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

		public function delete_old_process_info() {
			$process_key = get_site_option( 'last_background_process_' . $this->identifier );
			if ( ! $process_key ) {
				return;
			}

			WP_Background_Process_Logging::delete( array(
				'process_identifier'	=> $this->identifier,
				'process_key'			=> $process_key,
			) );

			delete_site_option( 'background_process_' . $this->identifier . '_total' );
			delete_site_option( 'background_process_' . $this->identifier . '_progress' );
			delete_site_option( 'last_background_process_' . $this->identifier );
		}

		protected function generate_process_key( $length = 64 ) {
			$unique = md5( microtime() . rand() );

			return substr( $unique, 0, $length );
		}

		public function get_nonce() {
			return wp_create_nonce( $this->identifier );
		}

		public function enqueue_script( $progress_selector, $dispatch_button_selector ) {
			$url = plugin_dir_url( dirname( __FILE__ ) ) . 'assets/wp-background-processing-ui.js';

			$process_key = get_site_option( 'current_background_process_' . $this->identifier );
			if ( ! $process_key ) {
				$process_key = get_site_option( 'last_background_process_' . $this->identifier );
			}

			wp_enqueue_script( 'wp-background-processing-ui', $url, array(
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
					'invalidDispatchButtonSelector'	=> sprintf( __( 'Could not find element %s.', 'wp-background-processing-ui' ), $dispatch_button_selector ),
				),
				'selectors'						=> array(
					'progress'						=> $progress_selector,
					'dispatchButton'				=> $dispatch_button_selector,
				),
			) );
		}

		public function print_script_template() {
			?>
			<script type="text/html" id="tmpl-background-process-info">
				<# if ( processInfo.key ) { #>
					<div class="progress-wrap">
						<div class="progress">
							<progress id="progressbar-total" max="100" value="{{ processInfo.percentage }}"></progress>
						</div>
						<div class="status">
							<span id="completed-total" class="completed">{{ processInfo.progress }}/{{ processInfo.total }}</span>
							<span id="progress-total" class="progress">{{ processInfo.percentage }}%</span>
						</div>
					</div>
					<# if ( processInfo.logs && processInfo.logs.length ) { #>
						<div class="logs">
							<# _.each( processInfo.logs, function( log ) {
								#>
								<div id="log-{{ log.id }}" class="log log-{{ log.type }}">

								</div>
								<#
							}); #>
						</div>
						<# if ( hasMoreLogs ) { #>
							<button id="logs-more" class="logs-more button button-secondary"><?php _e( 'Show More', 'wp-background-processing-ui' ); ?></button>
						<# } #>
					<# } #>
				<# } #>
			</script>
			<?php
		}

		public function maybe_dispatch() {
			if ( ! isset( $_REQUEST['nonce'] ) ) {
				wp_send_json_error( __( 'Missing nonce.', 'wp-background-processing-ui' ) );
			}

			if ( ! check_ajax_referer( $this->identifier, 'nonce', false ) ) {
				wp_send_json_error( __( 'Invalid nonce.', 'wp-background-processing-ui' ) );
			}

			$status = $this->dispatch();
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

			$args = array(
				'number'	=> 25,
			);

			if ( isset( $_REQUEST['afterTimestamp'] ) && $_REQUEST['afterTimestamp'] ) {
				$args['timestamp'] = absint( $_REQUEST['afterTimestamp'] );
				$args['timestamp'] = '>';
			} elseif ( isset( $_REQUEST['beforeTimestamp'] ) && $_REQUEST['beforeTimestamp'] ) {
				$args['timestamp'] = absint( $_REQUEST['beforeTimestamp'] );
				$args['timestamp'] = '<';
			}

			$logs = WP_Background_Process_Logging::query( $args );

			wp_send_json_success( $logs );
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

			$args = array();
			if ( isset( $data['backgroundProcessInfoLatestLogTimestamp'] ) && $data['backgroundProcessInfoLatestLogTimestamp'] ) {
				$args['timestamp'] = absint( $data['backgroundProcessInfoLatestLogTimestamp'] );
				$args['timestamp'] = '>';
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
	}
}
