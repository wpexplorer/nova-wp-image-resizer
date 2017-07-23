<?php
/**
 * Resize images dinamically
 *
 * @param  string $attach_id
 * @param  string|array $size
 * @param  bool $retina
 * @return array
 * @since  1.0
 *
 */
function nova_resize_thumbnail( $attach_id, $size = '', $retina = false ) {
	
	if ( ! $attach_id ) {
		return;
	}

	// Check retina support
	$retina_support = false;

	// Check if image size is custom or defined
	$is_intermediate = false;

	// Size is an array
	$int_size = isset( $size['size'] ) ? $size['size'] : '';
	$width    = isset( $size['width'] ) ? $size['width'] : '';
	$height   = isset( $size['height'] ) ? $size['height'] : '';
	$crop     = isset( $size['crop'] ) ? $size['crop'] : 'center-center';

	// Check if size is intermediate or not
	if ( $int_size ) {
		$is_intermediate = true;
	}

	// Sanitize dimensions
	$width  = intval( $width );
	$height = intval( $height );
	$crop   = is_array( $crop ) ? implode( '-', $crop ) : $crop;

	// Get attachment data
	$src  = wp_get_attachment_image_src( $attach_id, 'full' );
	$path = get_attached_file( $attach_id );

	if ( empty( $path ) ) {
		return;
	}

	// Get attachment details
	$info         = pathinfo( $path );
	$extension    = '.' . $info['extension'];
	$path_no_ext  = $info['dirname'] . '/' . $info['filename'];

	// Array of valid crop locations
	$crop_locations = array(
		'left-top',
		'right-top',
		'center-top',
		'left-center',
		'right-center',
		'center-center',
		'left-bottom',
		'right-bottom',
		'center-bottom',
	);

	// Define crop suffix if custom crop is set and valid
	$crop_suffix = ( is_string( $crop ) && array_key_exists( $crop, $crop_locations ) ) ? $crop : '';

	// Turn crop value into array
	$crop_array = $crop_suffix ? explode( '-', $crop ) : $crop;

	// Calculate output dimensions after resize
	$cropped_dims = image_resize_dimensions( $src[1], $src[2], $width, $height, $crop_array );

	// Target image size dims
	$dst_w = isset( $cropped_dims[4] ) ? $cropped_dims[4] : '';
	$dst_h = isset( $cropped_dims[5] ) ? $cropped_dims[5] : '';

	// Suffix width and height values
	$s_width  = $width ? $width : $dst_w;
	$s_height = $height ? $height : $dst_h;

	// Define suffix
	if ( $retina ) {
		$suffix = $s_width / 2 . 'x' . $s_height / 2;
	} else {
		$suffix = $s_width . 'x' . $s_height;
	}
	$suffix = $crop_suffix ? $suffix . '-' . $crop_suffix : $suffix;
	$suffix = $retina ? $suffix . '@2x' : $suffix;

	// Define custom intermediate_size based on suffix
	$int_size = $int_size ? $int_size : 'nova_' . $suffix;

	// If current image size is smaller or equal to target size return full image
	if ( empty( $cropped_dims )
		|| $dst_w > $src[1]
		|| $dst_h > $src[2]
		|| ( $dst_w == $src[1] && $dst_h == $src[2] )
	) {
		
		if ( $retina ) return; // don't return original for retina images

		return array(
			'url'    => $src[0],
			'width'  => $src[1],
			'height' => $src[2],
		);

	}

	// Retina can't be cropped to exactly 2x
	if ( $retina && ( $dst_w !== $width || $dst_h !== $height ) ) {
		return;
	}

	// Get cropped path
	$cropped_path = $path_no_ext . '-' . $suffix . $extension;

	// Return chached image
	// And try and generate retina if not created already
	if ( file_exists( $cropped_path ) ) {

		$new_path = str_replace( basename( $src[0] ), basename( $cropped_path ), $src[0] );

		if ( ! $retina && $retina_support ) {
			$retina_dims = array(
				'width'  => $dst_w*2,
				'height' => $dst_h*2,
				'crop'   => $crop
			);
			$retina_src = nova_resize_thumbnail( $attach_id, $retina_dims, true );
		}

		return array(
			'url'    => $new_path,
			'width'  => $dst_w,
			'height' => $dst_h,
			'retina' => ! empty( $retina_src['url'] ) ? $retina_src['url'] : '',
		);

	}

	// Crop image
	$editor = wp_get_image_editor( $path );

	if ( ! is_wp_error( $editor ) && ! is_wp_error( $editor->resize( $width, $height, $crop_array ) ) ) {

		// Get resized file
		$new_path = $editor->generate_filename( $suffix );
		$editor   = $editor->save( $new_path );

		// Set new image url from resized image
		if ( ! is_wp_error( $editor ) ) {

			// Cropped image
			$cropped_img = str_replace( basename( $src[0] ), basename( $new_path ), $src[0] );

			// Generate retina version
			if ( ! $retina && $retina_support ) {
				$retina_dims = array(
					'width'  => $dst_w*2,
					'height' => $dst_h*2,
					'crop'   => $crop
				);
				$retina_src = nova_resize_thumbnail( $attach_id, $retina_dims, true );
			}

			// Get thumbnail meta
			$meta = wp_get_attachment_metadata( $attach_id );

			// Update meta
			if ( is_array( $meta ) ) {

				$meta['sizes'] = isset( $meta['sizes'] ) ? $meta['sizes'] : array();

				if ( ! array_key_exists( $int_size, $meta['sizes'] )
					|| ( $dst_w != $meta['sizes'][$int_size]['width'] || $dst_h != $meta['sizes'][$int_size]['height'] )
				) {

					// Check correct mime type
					$mime_type = wp_check_filetype( $cropped_img );
					$mime_type = isset( $mime_type['type'] ) ? $mime_type['type'] : '';

					// Cropped image file name
					$dst_filename = $info['filename'] . '-' . $suffix . $extension;

					// Add cropped image to image meta
					$meta['sizes'][$int_size] = array(
						'file'      => $dst_filename,
						'width'     => $dst_w,
						'height'    => $dst_h,
						'mime-type' => $mime_type,
						'nova-wp'   => true,
					);

					// Update meta
					wp_update_attachment_metadata( $attach_id, $meta );

				}

			}

			// Return cropped image
			return array(
				'url'               => $cropped_img,
				'width'             => $dst_w,
				'height'            => $dst_h,
				'retina'            => ! empty( $retina_src['url'] ) ? $retina_src['url'] : '',
				'intermediate_size' => $int_size,
			);

		}

	}

	// Couldn't dynamically create image so return original
	else {

		return array(
			'url'    => $src[0],
			'width'  => $src[1],
			'height' => $src[2],
		);

	}

}