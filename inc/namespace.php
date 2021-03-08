<?php

namespace AMFUnsplash;

use WP_Scripts;

/**
 * Bootstrap function.
 */
function bootstrap() : void {
	add_filter( 'amf/provider', __NAMESPACE__ . '\\get_provider' );
	add_action( 'amf/inserted_attachment', __NAMESPACE__ . '\\track_download', 10, 3 );
	add_action( 'wp_default_scripts', __NAMESPACE__ . '\\override_per_page', 100 );
	add_action( 'plugins_loaded', __NAMESPACE__ . '\\register_key_setting' );
	add_action( 'admin_init', __NAMESPACE__ . '\\register_settings_ui' );
}

/**
 * Get the provider for AMF.
 *
 * @return Provider
 */
function get_provider() : Provider {
	require_once __DIR__ . '/class-provider.php';

	return new Provider();
}

/**
 * Record a download when inserting.
 *
 * Unsplash's API T&C requires us to indicate when we're downloading the image.
 *
 * @param \WP_Post $attachment Attachment object.
 * @param array $selection Raw data from the media library.
 * @param array $meta Metadata we set in AMF.
 * @return void
 */
function track_download( $attachment, $selection, $meta ) : void {
	// Record a download, if this was from our provider.
	if ( ! isset( $meta['unsplash_id'] ) ) {
		return;
	}

	Provider::track_download( $meta['unsplash_id'] );
}

/**
 * Override the per-page setting in JS.
 *
 * Unsplash has a 30 items per page limit.
 */
function override_per_page( WP_Scripts $scripts ) : void {
	$scripts->add_inline_script( 'media-models', 'wp.media.model.Query.defaultArgs.posts_per_page = 30' );
}

/**
 * Register the API key setting.
 */
function register_key_setting() : void {
	register_setting( 'media', 'amfunsplash_api_key', [
		'type' => 'string',
		'description' => 'API key for Unsplash',
		'default' => '',
	] );
}

/**
 * Get the API key.
 */
function get_api_key() : ?string {
	if ( defined( 'AMFUNSPLASH_API_KEY' ) ) {
		return AMFUNSPLASH_API_KEY;
	}

	return get_option( 'amfunsplash_api_key', null );
}

/**
 * Register the UI for the settings.
 */
function register_settings_ui() : void {
	if ( defined( 'AMFUNSPLASH_API_KEY' ) ) {
		// Skip the UI.
		return;
	}

	add_settings_section(
		'amfunsplash',
		'AMF Unsplash',
		__NAMESPACE__ . '\\render_settings_description',
		'media'
	);

	add_settings_field(
		'amfunsplash_api_key',
		'Unsplash API Key',
		__NAMESPACE__ . '\\render_field_ui',
		'media',
		'amfunsplash',
		[
			'label_for' => 'amfunsplash_api_key',
		]
	);
}

/**
 * Render the description for the settings section.
 */
function render_settings_description() : void {
	echo '<p>';
	printf(
		'To enable the Unsplash integration, <a href="%s">register an application</a> and enter your API key here.',
		'https://unsplash.com/documentation#registering-your-application'
	);
	echo '</p>';
}

/**
 * Render the field input.
 */
function render_field_ui() : void {
	$value = get_option( 'amfunsplash_api_key', '' );
	printf(
		'<input
			class="regular-text code"
			id="amfunsplash_api_key"
			name="amfunsplash_api_key"
			type="text"
			value="%s"
		/>',
		esc_attr( $value )
	);
}
