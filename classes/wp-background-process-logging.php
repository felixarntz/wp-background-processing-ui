<?php

if ( ! class_exists( 'WP_Background_Process_Logging' ) ) {
	class WP_Background_Process_Logging {
		private static $initialized = false;

		public static function add( $message, $process_identifier, $type = 'success', $process_key = null, $timestamp = null ) {
			global $wpdb;

			if ( ! $process_key ) {
				$process_key = get_site_option( 'current_background_process_' . $process_identifier );
				if ( ! $process_key ) {
					return 0;
				}
			}

			if ( ! $timestamp ) {
				$timestamp = current_time( 'timestamp' );
			}

			$values = compact( 'message', 'type', 'process_identifier', 'process_key', 'timestamp' );
			$value_types = array( '%s', '%s', '%s', '%s', '%d' );

			$status = $wpdb->insert( $wpdb->background_process_logs, $values, $value_types );
			if ( ! $status ) {
				return 0;
			}

			return $wpdb->insert_id;
		}

		public static function query( $args = array() ) {
			global $wpdb;

			$args = wp_parse_args( $args, array(
				'number'	=> 10,
				'offset'	=> 0,
				'orderby'	=> 'timestamp',
				'order'		=> 'DESC',
				'fields'	=> 'all',
			) );

			if ( 0 === $args['number'] ) {
				return array();
			}

			$strfields = array( 'message', 'type', 'process_identifier', 'process_key' );
			$intfields = array( 'id', 'timestamp' );

			$selects = 'ids' === $args['fields'] ? 'id' : '*';

			$query = "SELECT $selects FROM $wpdb->background_process_logs";

			$keys = array();
			$values = array();

			foreach ( $strfields as $field ) {
				if ( ! isset( $args[ $field ] ) ) {
					continue;
				}

				if ( is_array( $args[ $field ] ) ) {
					if ( 0 === count( $args[ $field ] ) ) {
						return array();
					}

					if ( 1 < count( $args[ $field ] ) ) {
						$keys[] = $field . ' IN (' . implode( ', ', array_fill( 0, count( $args[ $field ] ), '%s' ) ) . ')';
						foreach ( $args[ $field ] as $value ) {
							$values[] = $value;
						}
						continue;
					}

					$args[ $field ] = $args[ $field ][0];
				}

				$keys[] = $field . ' = %s';
				$values[] = $args[ $field ];
			}

			foreach ( $intfields as $field ) {
				if ( ! isset( $args[ $field ] ) ) {
					continue;
				}

				if ( is_array( $args[ $field ] ) ) {
					if ( 0 === count( $args[ $field ] ) ) {
						return array();
					}

					if ( 1 < count( $args[ $field ] ) ) {
						$keys[] = $field . ' IN (' . implode( ', ', array_fill( 0, count( $args[ $field ] ), '%d' ) ) . ')';
						foreach ( $args[ $field ] as $value ) {
							$values[] = $value;
						}
						continue;
					}

					$args[ $field ] = $args[ $field ][0];
				}

				$compare = isset( $args[ $field . '_compare' ] ) ? trim( $args[ $field . '_compare' ] ) : '=';

				$keys[] = $field . ' ' . $compare . ' %d';
				$values[] = $args[ $field ];
			}

			if ( 0 < count( $keys ) ) {
				$query .= " WHERE " . implode( " AND ", $keys );
			}

			if ( $args['orderby'] ) {
				$order = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
				$query .= " ORDER BY " . $args['orderby'] . " " . $order;
			}

			if ( 0 < $args['number'] ) {
				$query .= " LIMIT " . $args['offset'] . ", " . $args['number'];
			}

			if ( 0 < count( $values ) ) {
				array_unshift( $values, $query );
				$query = call_user_func_array( array( $wpdb, 'prepare' ), $values );
			}

			if ( 'ids' === $args['fields'] ) {
				return $wpdb->get_col( $query );
			}

			return $wpdb->get_results( $query );
		}

		public static function delete( $args = array() ) {
			global $wpdb;

			$args = wp_parse_args( $args );

			$strfields = array( 'message', 'type', 'process_identifier', 'process_key' );
			$intfields = array( 'id', 'timestamp' );

			$values = array();
			$value_types = array();

			foreach ( $strfields as $field ) {
				if ( ! isset( $args[ $field ] ) ) {
					continue;
				}

				// arrays are not supported at this point
				if ( is_array( $args[ $field ] ) ) {
					continue;
				}

				$values[ $field ] = $args[ $field ];
				$value_types[] = '%s';
			}

			foreach ( $intfields as $field ) {
				if ( ! isset( $args[ $field ] ) ) {
					continue;
				}

				// arrays are not supported at this point
				if ( is_array( $args[ $field ] ) ) {
					continue;
				}

				$values[ $field ] = $args[ $field ];
				$value_types[] = '%d';
			}

			if ( 0 === count( $values ) ) {
				return $wpdb->query( "TRUNCATE TABLE $wpdb->background_process_logs" );
			}

			return $wpdb->delete( $wpdb->background_process_logs, $values, $value_types );
		}

		public static function init() {
			if ( self::$initialized ) {
				return;
			}
			self::$initialized = true;

			self::register_table();
			self::maybe_install_table();
		}

		public static function uninstall() {
			global $wpdb;

			$wpdb->query( "DROP TABLE IF EXISTS $wpdb->background_process_logs" );

			delete_site_option( 'wp_background_process_logging_table_installed' );
		}

		private static function register_table() {
			global $wpdb;

			$wpdb->global_tables[] = 'background_process_logs';
			$wpdb->background_process_logs = $wpdb->base_prefix . 'background_process_logs';
		}

		private static function maybe_install_table() {
			global $wpdb;

			if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$installed = get_site_option( 'wp_background_process_logging_table_installed' );
			if ( $installed ) {
				return;
			}

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			$charset_collate = self::get_charset_collate();

			$sql = "CREATE TABLE $wpdb->background_process_logs (
	id bigint(20) unsigned NOT NULL auto_increment,
	message text NOT NULL,
	type varchar(20) NOT NULL default 'success',
	process_identifier varchar(64) NOT NULL,
	process_key varchar(64) NOT NULL,
	timestamp bigint(20) unsigned NOT NULL,
	PRIMARY KEY  (id),
	KEY process_identifier (process_identifier)
) $charset_collate;\n";

			$r = dbDelta( $sql );

			update_site_option( 'wp_background_process_logging_table_installed', '1' );
		}

		private static function get_charset_collate() {
			global $wpdb;

			$charset_collate = '';
			if ( $wpdb->has_cap( 'collation' ) ) {
				if ( ! empty( $wpdb->charset ) ) {
					$charset_collate = "DEFAULT CHARACTER SET " . $wpdb->charset;
				}
				if ( ! empty( $wpdb->collate ) ) {
					$charset_collate .= " COLLATE " . $wpdb->collate;
				}
			}

			return $charset_collate;
		}
	}
}
