<?php

namespace AMFUnsplash;

use AssetManagerFramework\Image;
use AssetManagerFramework\Interfaces\Resize;
use AssetManagerFramework\MediaList;
use AssetManagerFramework\Provider as BaseProvider;
use stdClass;
use WP_Post;

class Provider extends BaseProvider implements Resize {
	/**
	 * Base URL for the Unsplash API.
	 */
	const BASE_URL = 'https://api.unsplash.com';

	/**
	 * Return the provider ID.
	 *
	 * @return string
	 */
	public function get_id() : string {
		return 'unsplash';
	}

	/**
	 * Return the provider name.
	 *
	 * @return string
	 */
	public function get_name() : string {
		return __( 'Unsplash', 'amf-unsplash' );
	}

	/**
	 * Parse input query args into an Unsplash query.
	 *
	 * @param array $input
	 * @return array
	 */
	protected function parse_args( array $input ) : array {
		$query = [
			'page' => 1,
			'per_page' => 30,
			'order_by' => 'latest',
		];

		if ( isset( $input['posts_per_page'] ) ) {
			$query['per_page'] = absint( $input['posts_per_page'] );
		}
		if ( isset( $input['paged'] ) ) {
			$query['page'] = absint( $input['paged'] );
		}
		if ( ! empty( $input['orderby'] ) ) {
			$dir = strtolower( $input['order'] ?? 'desc' );
			switch ( $input['orderby'] ) {
				case 'date':
					$query['order_by'] = $dir === 'desc' ? 'latest' : 'oldest';
					break;
			}
		}
		if ( isset( $input['s'] ) ) {
			$query['query'] = $input['s'];

			// Override to sort by relevance. (Requires hack in search_images)
			$query['order_by'] = 'relevant';
		}

		return $query;
	}

	/**
	 * Retrieve the images for a query.
	 *
	 * @param array $args Query args from the media library
	 * @return MediaList Found images.
	 */
	protected function request( array $args ) : MediaList {
		if ( ! empty( $args['s'] ) ) {
			return $this->search_images( $args );
		} else {
			return $this->request_images( $args );
		}
	}

	/**
	 * Retrieve the images for a list query.
	 *
	 * @param array $args Query args from the media library
	 * @return MediaList Found images.
	 */
	protected function request_images( array $args ) : MediaList {
		$query = $this->parse_args( $args );

		$response = $this->fetch( '/photos', $query );
		$items = $this->prepare_images( $response['data'] );

		return new MediaList( ...$items );
	}

	/**
	 * Retrieve the images for a search query.
	 *
	 * @param array $args Query args from the media library
	 * @return MediaList Found images.
	 */
	protected function search_images( array $args ) : MediaList {
		$query = $this->parse_args( $args );

		$response = $this->fetch( '/search/photos', $query );
		$items = [];
		$i = $query['page'] * $query['per_page'];

		foreach ( $response['data']->results as $image ) {
			$item = $this->prepare_image_for_response( $image );

			// Override the date so that WP doesn't break the ordering. We use
			// the current position in the stream, but subtract them from a
			// large number so that the client-side reverse-chronological
			// ordering remains intact. (As at 2020-04-17, Unsplash had 1.7m
			// photos, so this gives some breathing room.)
			// Note that this sets date directly so that dateFormatted (which
			// is displayed to the user) is still accurate.
			$i--;
			$item->date = 1e8 - $i;

			$items[] = $item;
		}

		return new MediaList( ...$items );
	}

	/**
	 * Prepare a list of images for the response.
	 *
	 * WordPress requires each image to have a unique ID, and for items to
	 * always be sorted by date. Unsplash inserts promoted images into the
	 * stream, which have a) duplicate IDs, and b) out-of-order timestamps.
	 *
	 * We handle this by using the neighbour's timestamp for any ads, and
	 * adding this timestamp to the ID to deduplicate.
	 *
	 * @param array $images
	 * @return array
	 */
	protected function prepare_images( array $images ) : array {
		$items = [];

		/** @var int|null */
		$prev_date = null;
		$needs_date = null;
		foreach ( $images as $image ) {
			$item = $this->prepare_image_for_response( $image );

			// Fix ads.
			if ( ! $item->date ) {
				if ( $prev_date ) {
					$item->id = $item->id . $prev_date;
					$item->set_date( $prev_date );
				}
				else {
					$needs_date = $item;
				}
			} else {
				$prev_date = $item->date;
				if ( $needs_date ) {
					$needs_date->id = $item->id . $item->date;
					$needs_date->set_date( $item->date );
					$needs_date = null;
				}
			}

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Prepare an Unsplash image's data for the response.
	 *
	 * @param stdClass $image Raw data from the Unsplash API
	 * @return Image Formatted image for use in AMF.
	 */
	protected function prepare_image_for_response( stdClass $image ) : Image {
		$item = new Image(
			$image->id,
			'image/jpeg'
		);

		// Map data directly.
		$item->set_url( $image->urls->raw );
		$item->set_filename( $image->id . '.jpg' );
		$item->set_link( $image->links->html );
		$item->set_title(
			$image->description ?? $image->alt_description ?? ''
		);
		$item->set_width( $image->width );
		$item->set_height( $image->height );
		$item->set_alt( $image->alt_description ?? '' );

		// Ads in the stream need to be deduplicated and given synthetic times.
		if ( empty( $image->sponsorship ) ) {
			$time = $image->promoted_at;
			$item->set_date( strtotime( $time ) );
		}

		// Generate attribution.
		$utm = '?utm_source=altis&utm_medium=referral';
		$description = sprintf(
			__( 'Photo by <a href="%1$s">%2$s</a> on <a href="%3$s">Unsplash</a>' ),
			esc_url( $image->user->links->html . $utm ),
			esc_html( $image->user->name ),
			esc_url( 'https://unsplash.com/' . $utm )
		);
		$item->set_description( $description );
		$item->set_caption( $description );

		// Generate sizes.
		$sizes = $this->get_image_sizes( $image );
		$item->set_sizes( $sizes );

		// Add additional metadata for later.
		$item->add_amf_meta( 'unsplash_id', $image->id );

		return $item;
	}

	/**
	 * Fetch an API endpoint.
	 *
	 * @param string $path API endpoint path (prefixed with /)
	 * @param array $args Query arguments to add to URL.
	 * @param array $options Other options to pass to WP HTTP.
	 * @return array
	 */
	protected static function fetch( string $path, array $args = [], array $options = [] ) {
		$url = static::BASE_URL . $path;
		$url = add_query_arg( urlencode_deep( $args ), $url );

		$defaults = [
			'headers' => [
				'Accept-Version' => 'v1',
				'Authorization' => sprintf( 'Client-ID %s', get_api_key() ),
			],
		];
		$options = array_merge( $defaults, $options );
		$result = wp_remote_get( $url, $options );
		if ( is_wp_error( $result ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $result ) );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return null;
		}

		return [
			'headers' => wp_remote_retrieve_headers( $result ),
			'data' => $data,
		];
	}

	/**
	 * Get size mapping from a given image.
	 *
	 * From the API documentation:
	 *
	 * - `full` returns the photo in jpg format with its maximum dimensions.
	 *   For performance purposes, we don’t recommend using this as the photos
	 *   will load slowly for your users.
	 *
	 * - `regular` returns the photo in jpg format with a width of 1080 pixels.
	 *
	 * - `small` returns the photo in jpg format with a width of 400 pixels.
	 *
	 * - `thumb` returns the photo in jpg format with a width of 200 pixels.
	 *
	 * - `raw` returns a base image URL with just the photo path and the ixid
	 *   parameter for your API application. Use this to easily add additional
	 *   image parameters to construct your own image URL.
	 *
	 * @param stdClass $image
	 * @return array
	 */
	protected static function get_image_sizes( stdClass $image ) : array {
		$registered_sizes = wp_get_registered_image_subsizes();
		$registered_sizes['full'] = [
			'width' => $image->width,
			'height' => $image->height,
			'crop' => false,
		];
		if ( isset( $registered_sizes['medium'] ) ) {
			$registered_sizes['medium']['crop'] = true;
		}

		$orientation = $image->height > $image->width ? 'portrait' : 'landscape';
		$sizes = [];
		foreach ( $registered_sizes as $name => $size ) {
			$imgix_args = [
				'w' => $size['width'],
				'h' => $size['height'],
				'fit' => $size['crop'] ? 'crop' : 'max',
			];
			$sizes[ $name ] = [
				'width' => $size['width'],
				'height' => $size['height'],
				'orientation' => $orientation,
				'url' => add_query_arg( urlencode_deep( $imgix_args ), $image->urls->raw ),
			];
		}

		return $sizes;
	}

	/**
	 * Track a download of the image.
	 *
	 * Sends a request to the API in non-blocking mode to track downloads.
	 *
	 * @param string $id Image ID to track.
	 * @return void
	 */
	public static function track_download( string $id ) : void {
		$endpoint = sprintf( '/photos/%s/download', $id );
		static::fetch( $endpoint, [], [
			'blocking' => false,
		] );
	}

	/**
	 * Support dynamically sized images.
	 *
	 * @param WP_Post $attachment The current unsplash attachment.
	 * @param integer $width Target width.
	 * @param integer $height Target height.
	 * @param boolean $crop Whether to crop the image or not.
	 * @return string
	 */
	public function resize( WP_Post $attachment, int $width, int $height, $crop = false ) : string {
		$base_url = wp_get_attachment_url( $attachment->ID );

		$query_args = [
			'w' => $width,
			'h' => $height,
			'fit' => $crop ? 'crop' : 'clip',
			'crop' => 'faces,focalpoint',
		];

		if ( is_array( $crop ) ) {
			$crop = array_filter( $crop, function ( $value ) {
				return $value !== 'center';
			} );
			$query_args['crop'] = implode( ',', $crop );
		}

		return add_query_args( urlencode_deep( $query_args ), $base_url );
	}
}
