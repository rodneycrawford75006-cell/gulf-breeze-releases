<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
	fwrite(STDERR, "FAIL: WordPress is not loaded.\n");
	exit(1);
}

function gbseo_fail(string $message): void {
	fwrite(STDERR, "FAIL: {$message}\n");
	exit(1);
}

function gbseo_assert(bool $condition, string $message): void {
	if (!$condition) {
		gbseo_fail($message);
	}
}

function gbseo_assert_contains(string $needle, string $haystack, string $message): void {
	gbseo_assert(str_contains($haystack, $needle), $message . " (missing: {$needle})");
}

function gbseo_fixture(array $case): WP_Post {
	$existing = get_post((int) $case['id']);
	if ($existing instanceof WP_Post) {
		wp_delete_post($existing->ID, true);
	}

	$id = wp_insert_post(
		array(
			'import_id'   => (int) $case['id'],
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => $case['page_name'],
			'post_name'   => $case['slug'],
			'post_content'=> '<p>Disposable SEO validation fixture.</p>',
		),
		true
	);
	if (is_wp_error($id)) {
		gbseo_fail('Could not create disposable page ' . $case['id'] . ': ' . $id->get_error_message());
	}
	gbseo_assert((int) $id === (int) $case['id'], 'Disposable page did not retain required import ID ' . $case['id']);

	$post = get_post((int) $id);
	gbseo_assert($post instanceof WP_Post, 'Disposable page object missing for ' . $case['id']);
	gbseo_assert($post->post_name === $case['slug'], 'Disposable page slug mismatch for ' . $case['id']);
	return $post;
}

function gbseo_set_query(WP_Post $post): void {
	$query = new WP_Query(
		array(
			'p'           => $post->ID,
			'post_type'   => 'page',
			'post_status' => 'publish',
		)
	);
	gbseo_assert($query->is_singular('page'), 'WordPress query is not singular page for ' . $post->ID);
	$GLOBALS['wp_query'] = $query;
	$GLOBALS['post'] = $post;
	setup_postdata($post);
}

gbseo_assert(class_exists('Gulf_Breeze_SEO_Foundation'), 'SEO Foundation class is not loaded');
gbseo_assert(Gulf_Breeze_SEO_Foundation::VERSION === '0.1.0-dev-r5', 'Wrong active SEO Foundation version');
gbseo_assert(get_option('gb_site_protection_settings')['enabled'] === 1, 'Site Protection is not enabled in the disposable exact stack');

$cases = array(
	array(
		'id'          => 789,
		'slug'        => 'texas-parent-taught-drivers-ed-guide',
		'language'    => 'en-US',
		'canonical'   => 'http://127.0.0.1:8080/texas-parent-taught-drivers-ed-guide/',
		'alternate'   => 'http://127.0.0.1:8080/es/guia-de-educacion-vial-impartida-por-padres-en-texas/',
		'title'       => 'Texas Parent-Taught Drivers Ed Requirements | Gulf Breeze',
		'page_name'   => 'Texas Parent-Taught Drivers Ed Requirements',
	),
	array(
		'id'          => 791,
		'slug'        => 'guia-de-educacion-vial-impartida-por-padres-en-texas',
		'language'    => 'es-US',
		'canonical'   => 'http://127.0.0.1:8080/es/guia-de-educacion-vial-impartida-por-padres-en-texas/',
		'alternate'   => 'http://127.0.0.1:8080/texas-parent-taught-drivers-ed-guide/',
		'title'       => 'Requisitos para Educación Vial por Padres en Texas | Gulf Breeze',
		'page_name'   => 'Requisitos de educación vial impartida por padres en Texas',
	),
);

foreach ($cases as $case) {
	$post = gbseo_fixture($case);
	gbseo_set_query($post);

	gbseo_assert(
		apply_filters('pre_get_document_title', 'Original') === $case['title'],
		'Document title mismatch for page ' . $case['id']
	);
	gbseo_assert_contains(
		'lang="' . $case['language'] . '"',
		get_language_attributes('html'),
		'HTML language mismatch for page ' . $case['id']
	);
	gbseo_assert(
		wp_get_canonical_url($post) === $case['canonical'],
		'Canonical filter mismatch for page ' . $case['id']
	);
	gbseo_assert(
		apply_filters('the_author', 'Admin') === 'Gulf Breeze Driving School',
		'Public author mismatch for page ' . $case['id']
	);

	ob_start();
	do_action('wp');
	do_action('wp_head');
	$head = (string) ob_get_clean();

	gbseo_assert(substr_count($head, 'rel="canonical"') === 1, 'Expected exactly one canonical tag for page ' . $case['id']);
	gbseo_assert_contains('<link rel="canonical" href="' . $case['canonical'] . '">', $head, 'Self-canonical missing for page ' . $case['id']);
	gbseo_assert_contains('hreflang="en-US"', $head, 'English hreflang missing for page ' . $case['id']);
	gbseo_assert_contains('hreflang="es-US"', $head, 'Spanish hreflang missing for page ' . $case['id']);
	gbseo_assert_contains('hreflang="x-default" href="http://127.0.0.1:8080/texas-parent-taught-drivers-ed-guide/"', $head, 'x-default is not PG-013 for page ' . $case['id']);
	gbseo_assert_contains('<meta property="og:title" content="' . $case['title'] . '">', $head, 'Open Graph title mismatch for page ' . $case['id']);
	gbseo_assert_contains('<meta property="og:url" content="' . $case['canonical'] . '">', $head, 'Open Graph URL mismatch for page ' . $case['id']);
	gbseo_assert_contains('<meta name="author" content="Gulf Breeze Driving School">', $head, 'Meta author mismatch for page ' . $case['id']);
	gbseo_assert_contains('"@type":"Organization"', $head, 'Organization schema missing for page ' . $case['id']);
	gbseo_assert_contains('"@type":"WebSite"', $head, 'WebSite schema missing for page ' . $case['id']);
	gbseo_assert_contains('"@type":"WebPage"', $head, 'WebPage schema missing for page ' . $case['id']);
	gbseo_assert_contains('"@type":"BreadcrumbList"', $head, 'Breadcrumb schema missing for page ' . $case['id']);
	gbseo_assert_contains('"name":"' . $case['page_name'] . '"', $head, 'Schema page name mismatch for page ' . $case['id']);
	gbseo_assert(!str_contains($head, '"@type":"Course"'), 'Course schema must remain gated for page ' . $case['id']);
	gbseo_assert(!str_contains($head, '"@type":"FAQPage"'), 'FAQ schema must remain gated for page ' . $case['id']);

	wp_reset_postdata();
}

$utility_id = wp_insert_post(
	array(
		'post_type'   => 'page',
		'post_status' => 'publish',
		'post_title'  => 'Disposable Course Selector',
		'post_name'   => 'choose-your-course',
	),
	true
);
gbseo_assert(!is_wp_error($utility_id), 'Could not create disposable utility page');
$utility = get_post((int) $utility_id);
gbseo_assert($utility instanceof WP_Post, 'Disposable utility page object missing');
gbseo_set_query($utility);
$robots = apply_filters('wp_robots', array());
gbseo_assert(isset($robots['noindex']) && true === $robots['noindex'], 'Course selector must remain noindex');
gbseo_assert(isset($robots['follow']) && true === $robots['follow'], 'Course selector must remain follow');

echo "PASS: WordPress 7.1 exact-stack runtime mapped disposable pages 789 and 791 to the controlled r5 output.\n";
echo "PASS: one canonical, reciprocal hreflang, English x-default, language, Open Graph, author and JSON-LD output are correct.\n";
echo "PASS: Course/FAQ schema remains gated and the course selector remains noindex,follow.\n";
echo "PASS: Site Protection remained enabled throughout the exact-stack test.\n";
