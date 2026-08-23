<?php
declare(strict_types=1);

final class WP_Post {
	public int $ID;
	public string $post_name;
	public function __construct(int $id, string $post_name) {
		$this->ID = $id;
		$this->post_name = $post_name;
	}
}

$GLOBALS['gb_test_post'] = null;
$GLOBALS['gb_actions'] = array();

function add_filter() {}
function add_action() {}
function remove_action() {}
function is_singular($type = '') { return 'page' === $type; }
function get_queried_object() { return $GLOBALS['gb_test_post']; }
function get_queried_object_id() { return $GLOBALS['gb_test_post'] instanceof WP_Post ? $GLOBALS['gb_test_post']->ID : 0; }
function get_post_type() { return 'page'; }
function get_post_field($field, $id) { return $GLOBALS['gb_test_post']->post_name; }
function is_author() { return false; }
function is_admin() { return false; }
function get_page_by_path() { return null; }
function home_url($path = '/') { return 'https://gulfbreezedrivingschool.com' . $path; }
function trailingslashit($value) { return rtrim($value, '/') . '/'; }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_url($value) { return (string) $value; }
function get_site_icon_url() { return 'https://gulfbreezedrivingschool.com/icon.png'; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags | JSON_THROW_ON_ERROR); }

define('ABSPATH', __DIR__ . '/');
$plugin_file = getenv('GB_SEO_PLUGIN_PHP') ?: __DIR__ . '/../gulf-breeze-seo-foundation/gulf-breeze-seo-foundation.php';
require $plugin_file;

function fail(string $message): void {
	fwrite(STDERR, "FAIL: {$message}\n");
	exit(1);
}

function assert_contains(string $needle, string $haystack, string $message): void {
	if (!str_contains($haystack, $needle)) {
		fail($message . " (missing: {$needle})");
	}
}

$cases = array(
	array(
		'id' => 789,
		'slug' => 'texas-parent-taught-drivers-ed-guide',
		'language' => 'en-US',
		'canonical' => 'https://gulfbreezedrivingschool.com/texas-parent-taught-drivers-ed-guide/',
		'alternate' => 'https://gulfbreezedrivingschool.com/es/guia-de-educacion-vial-impartida-por-padres-en-texas/',
		'title' => 'Texas Parent-Taught Drivers Ed Requirements | Gulf Breeze',
		'page_name' => 'Texas Parent-Taught Drivers Ed Requirements',
	),
	array(
		'id' => 791,
		'slug' => 'guia-de-educacion-vial-impartida-por-padres-en-texas',
		'language' => 'es-US',
		'canonical' => 'https://gulfbreezedrivingschool.com/es/guia-de-educacion-vial-impartida-por-padres-en-texas/',
		'alternate' => 'https://gulfbreezedrivingschool.com/texas-parent-taught-drivers-ed-guide/',
		'title' => 'Requisitos para Educación Vial por Padres en Texas | Gulf Breeze',
		'page_name' => 'Requisitos de educación vial impartida por padres en Texas',
	),
);

foreach ($cases as $case) {
	$GLOBALS['gb_test_post'] = new WP_Post($case['id'], $case['slug']);
	if (Gulf_Breeze_SEO_Foundation::filter_document_title('Original') !== $case['title']) {
		fail("Wrong title for page {$case['id']}");
	}
	$language = Gulf_Breeze_SEO_Foundation::filter_language_attributes('lang="en-US" dir="ltr"', 'html');
	assert_contains('lang="' . $case['language'] . '"', $language, "Wrong HTML language for page {$case['id']}");
	$canonical = Gulf_Breeze_SEO_Foundation::filter_canonical_url('https://example.test/original/', $GLOBALS['gb_test_post']);
	if ($canonical !== $case['canonical']) {
		fail("Wrong canonical filter output for page {$case['id']}");
	}

	ob_start();
	Gulf_Breeze_SEO_Foundation::render_authority_head();
	$head = (string) ob_get_clean();
	assert_contains('Gulf Breeze SEO Foundation 0.1.0-dev-r5', $head, "Wrong runtime version for page {$case['id']}");
	assert_contains('<meta name="author" content="Gulf Breeze Driving School">', $head, "Wrong author for page {$case['id']}");
	assert_contains('<link rel="canonical" href="' . $case['canonical'] . '">', $head, "Missing self-canonical for page {$case['id']}");
	assert_contains('hreflang="en-US"', $head, "Missing English hreflang for page {$case['id']}");
	assert_contains('hreflang="es-US"', $head, "Missing Spanish hreflang for page {$case['id']}");
	assert_contains('hreflang="x-default" href="https://gulfbreezedrivingschool.com/texas-parent-taught-drivers-ed-guide/"', $head, "Wrong x-default for page {$case['id']}");
	assert_contains('<meta property="og:url" content="' . $case['canonical'] . '">', $head, "Wrong Open Graph URL for page {$case['id']}");
	assert_contains('<meta property="og:title" content="' . $case['title'] . '">', $head, "Wrong Open Graph title for page {$case['id']}");
	assert_contains('"@type":"Organization"', $head, "Organization schema missing for page {$case['id']}");
	assert_contains('"@type":"WebSite"', $head, "WebSite schema missing for page {$case['id']}");
	assert_contains('"@type":"WebPage"', $head, "WebPage schema missing for page {$case['id']}");
	assert_contains('"@type":"BreadcrumbList"', $head, "Breadcrumb schema missing for page {$case['id']}");
	assert_contains('"name":"' . $case['page_name'] . '"', $head, "Wrong schema page name for page {$case['id']}");
	if (str_contains($head, 'Course') || str_contains($head, 'FAQPage')) {
		fail("Gated Course or FAQ schema appeared for page {$case['id']}");
	}
}

echo "PASS: pages 789 and 791 emit the controlled r5 title, language, canonical, reciprocal hreflang, Open Graph, author and JSON-LD output.\n";
echo "PASS: x-default resolves to PG-013 and gated Course/FAQ schema remains absent.\n";
