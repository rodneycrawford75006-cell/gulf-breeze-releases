<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function gb_test_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

gb_test_assert( class_exists( 'Gulf_Breeze_Configuration' ), 'Core class is unavailable.' );
gb_test_assert( defined( 'GB_CORE_VERSION' ) && '2.3.26-dev' === GB_CORE_VERSION, 'Unexpected Core version.' );
gb_test_assert( function_exists( 'gb_core_provider_profile' ), 'Provider-profile API is unavailable.' );
gb_test_assert( function_exists( 'gb_core_provider_profile_snapshot' ), 'Snapshot API is unavailable.' );

$legacy = array(
	'legal_name'             => 'Rodney Crawford DBA Gulf Breeze Driving School',
	'public_name'            => 'Gulf Breeze Driving School',
	'owner_name'             => 'Rodney Crawford',
	'phone'                  => '(555) 555-0100',
	'support_email'          => 'support@example.com',
	'legal_email'            => 'notices@example.com',
	'timezone'               => 'America/Chicago',
	'record_retention_note'  => 'administrator-only test note',
);
update_option( Gulf_Breeze_Configuration::OPTION, $legacy, false );

$core = Gulf_Breeze_Configuration::instance();
$first = $core->sanitize( $legacy );
update_option( Gulf_Breeze_Configuration::OPTION, $first, false );
gb_test_assert( '1.0' === $first['_schema_version'], 'Schema version was not established.' );
gb_test_assert( 1 === $first['_profile_version'], 'Legacy profile did not begin at version 1.' );
gb_test_assert( 64 === strlen( $first['_profile_hash'] ), 'Profile hash is not SHA-256.' );
gb_test_assert( 'en-US' === $first['default_locale'] && 'en-US,es-US' === $first['enabled_locales'], 'Locale defaults were not normalized.' );
gb_test_assert( 'DEVELOPMENT / SAMPLE DATA' === $first['environment_label'], 'Environment label default was not normalized.' );

$same = $core->sanitize( $first );
gb_test_assert( 1 === $same['_profile_version'], 'Unchanged save incorrectly advanced the profile version.' );
gb_test_assert( $first['_effective_at_utc'] === $same['_effective_at_utc'], 'Unchanged save altered the effective time.' );
gb_test_assert( $first['_profile_hash'] === $same['_profile_hash'], 'Unchanged save altered the profile hash.' );

update_option( Gulf_Breeze_Configuration::OPTION, $same, false );
$changed_submission = $same;
$changed_submission['phone'] = '(555) 555-0101';
$changed = $core->sanitize( $changed_submission );
update_option( Gulf_Breeze_Configuration::OPTION, $changed, false );
gb_test_assert( 2 === $changed['_profile_version'], 'Changed save did not advance the profile version.' );
gb_test_assert( $first['_profile_hash'] !== $changed['_profile_hash'], 'Changed provider facts did not alter the profile hash.' );

$profile = gb_core_provider_profile();
gb_test_assert( '(555) 555-0101' === $profile['phone'], 'Provider-profile API did not return the saved phone.' );
gb_test_assert( 'support@example.com' === $profile['support_email'], 'Provider-profile API did not return the support email.' );
gb_test_assert( 'notices@example.com' === $profile['legal_email'], 'Provider-profile API did not return the legal email.' );
gb_test_assert( ! array_key_exists( 'record_retention_note', $profile ), 'Private administrator note leaked through the provider-profile API.' );
gb_test_assert( 2 === $profile['profile_version'], 'Provider-profile API returned the wrong version.' );

$snapshot = gb_core_provider_profile_snapshot();
gb_test_assert( isset( $snapshot['profile'], $snapshot['hash'] ) && 64 === strlen( $snapshot['hash'] ), 'Immutable snapshot payload is malformed.' );
gb_test_assert( $profile === $snapshot['profile'], 'Snapshot profile differs from the authoritative API profile.' );

$invalid = $changed;
$invalid['support_email'] = 'not-an-email';
$sanitized_invalid = $core->sanitize( $invalid );
gb_test_assert( '' === $sanitized_invalid['support_email'], 'Invalid support email was not rejected.' );

$courses = Gulf_Breeze_Configuration::course_definitions();
gb_test_assert( 330 === $courses['adult_en']['instruction_minutes'], 'Adult English instructional minutes changed.' );

echo "PASS Core 2.3.26 WordPress provider-profile persistence, versioning, hashing, privacy, and compatibility tests\n";
