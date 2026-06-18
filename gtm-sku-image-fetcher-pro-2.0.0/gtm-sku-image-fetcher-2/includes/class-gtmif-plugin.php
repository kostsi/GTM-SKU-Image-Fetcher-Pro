<?php
namespace GTMIF;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Plugin {
	private static $instance = null;

	public static function instance() : Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		Settings::init();
		Logger::init();
		Providers::init();
		Runner::init();
		Admin::init();
	}
}
