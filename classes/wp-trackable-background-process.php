<?php

if ( ! class_exists( 'WP_Trackable_Background_Process' ) ) {
	abstract class WP_Trackable_Background_Process extends WP_Background_Process {
		public function __construct() {
			parent::__construct();
		}
	}
}
