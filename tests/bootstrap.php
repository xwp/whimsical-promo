<?php
/**
 * Bootstrap the WP test environment for the Whimsical Promo plugin.
 *
 * @package WhimsicalPromo
 */

// Give access to tests_add_filter() function.
require_once getenv( 'WP_PHPUNIT__DIR' ) . '/includes/functions.php'; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingCustomFunction

/**
 * Manually load the plugin being tested.
 */
tests_add_filter(
	'muplugins_loaded',
	function () {
		require dirname( __DIR__ ) . '/whimsical-promo.php';
	}
);

// Start up the WP testing environment.
require getenv( 'WP_PHPUNIT__DIR' ) . '/includes/bootstrap.php'; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingCustomFunction

// Shared test case, loaded after WP_UnitTestCase exists.
require_once __DIR__ . '/class-promo-testcase.php'; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
