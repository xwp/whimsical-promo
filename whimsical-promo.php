<?php
/**
 * Plugin Name: Whimsical Promo
 * Description: Editor-managed promo cards (newsletter, app, custom CTAs) rendered at theme hooks or on exit intent, with client-side chaining, cookies, and dataLayer/gtag tracking.
 * Plugin URI: https://github.com/xwp/whimsical-promo
 * Author: XWP
 * Version: 1.0.0
 * Requires PHP: 8.1
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: whimsical-promo
 *
 * @package WhimsicalPromo
 */

namespace WhimsicalPromo;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const PLUGIN_FILE = __FILE__;

require_once __DIR__ . '/trait-singleton.php';

// Static registry, no hooks of its own — loaded first because Post_Type's meta
// definitions ask it for the style list.
require_once __DIR__ . '/classes/class-styles.php';

// Also static, and reads the style registry to include a worked example.
require_once __DIR__ . '/classes/class-agent-brief.php';

require_once __DIR__ . '/classes/class-post-type.php';
require_once __DIR__ . '/classes/class-meta-box.php';
require_once __DIR__ . '/classes/class-render.php';
require_once __DIR__ . '/classes/class-assets.php';
require_once __DIR__ . '/classes/class-css-route.php';
require_once __DIR__ . '/classes/class-settings.php';

Post_Type::get_instance();
Meta_Box::get_instance();
Render::get_instance();
Assets::get_instance();
CSS_Route::get_instance();
Settings::get_instance();
