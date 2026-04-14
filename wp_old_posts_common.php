<?php

if ( ! function_exists( 'old_posts_parse_cli_args' ) ) {
	function old_posts_parse_cli_args( $argv ) {
		$args = array();

		foreach ( (array) $argv as $arg ) {
			if ( ! is_scalar( $arg ) ) {
				continue;
			}

			$arg = (string) $arg;
			if ( '' === $arg ) {
				continue;
			}

			$raw = 0 === strpos( $arg, '--' ) ? substr( $arg, 2 ) : $arg;
			if ( '' === $raw ) {
				continue;
			}

			if ( false === strpos( $raw, '=' ) ) {
				$args[ $raw ] = true;
				continue;
			}

			list( $key, $value ) = explode( '=', $raw, 2 );
			$args[ $key ]        = $value;
		}

		return $args;
	}
}

if ( ! function_exists( 'old_posts_collect_runtime_args' ) ) {
	function old_posts_collect_runtime_args( $wp_cli_args = array(), $argv = array() ) {
		$merged = array();

		if ( ! empty( $wp_cli_args ) ) {
			$merged = array_merge( $merged, old_posts_parse_cli_args( $wp_cli_args ) );
		}

		if ( ! empty( $argv ) ) {
			$merged = array_merge( $merged, old_posts_parse_cli_args( array_slice( (array) $argv, 1 ) ) );
		}

		return $merged;
	}
}

if ( ! function_exists( 'old_posts_output_dir' ) ) {
	function old_posts_output_dir() {
		$base_dir = getenv( 'OLD_POSTS_OUTPUT_DIR' );
		if ( is_string( $base_dir ) ) {
			$base_dir = trim( $base_dir );
		}

		if ( is_string( $base_dir ) && '' !== $base_dir ) {
			return rtrim( $base_dir, '/\\' );
		}

		$base_dir = function_exists( 'sys_get_temp_dir' ) ? sys_get_temp_dir() : '/tmp';

		return rtrim( $base_dir, '/\\' );
	}
}

if ( ! function_exists( 'old_posts_default_temp_path' ) ) {
	function old_posts_default_temp_path( $filename ) {
		$base_dir = old_posts_output_dir();
		return rtrim( $base_dir, '/\\' ) . '/' . ltrim( (string) $filename, '/\\' );
	}
}

if ( ! function_exists( 'old_posts_assert_parent_writable' ) ) {
	function old_posts_assert_parent_writable( $path, $label = 'output file' ) {
		$dir = dirname( (string) $path );

		if ( ! is_dir( $dir ) ) {
			old_posts_fail(
				'The destination directory does not exist.',
				array(
					'label' => $label,
					'path'  => $path,
					'dir'   => $dir,
				)
			);
		}

		if ( ! is_writable( $dir ) ) {
			old_posts_fail(
				'The destination directory is not writable.',
				array(
					'label' => $label,
					'path'  => $path,
					'dir'   => $dir,
				)
			);
		}
	}
}

if ( ! function_exists( 'old_posts_bool_arg' ) ) {
	function old_posts_bool_arg( $value, $default = false ) {
		if ( null === $value ) {
			return $default;
		}

		if ( is_bool( $value ) ) {
			return $value;
		}

		$normalized = strtolower( trim( (string) $value ) );
		if ( in_array( $normalized, array( '1', 'true', 'yes', 'y', 'on' ), true ) ) {
			return true;
		}

		if ( in_array( $normalized, array( '0', 'false', 'no', 'n', 'off' ), true ) ) {
			return false;
		}

		return $default;
	}
}

if ( ! function_exists( 'old_posts_csv_arg' ) ) {
	function old_posts_csv_arg( $value, $default = array() ) {
		if ( null === $value || '' === $value ) {
			return $default;
		}

		$parts = array_map( 'trim', explode( ',', (string) $value ) );
		$parts = array_values( array_filter( $parts, 'strlen' ) );

		return array_values( array_unique( $parts ) );
	}
}

if ( ! function_exists( 'old_posts_log' ) ) {
	function old_posts_log( $level, $message, $context = array() ) {
		$line = sprintf( '[%s] %s', strtoupper( $level ), $message );
		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $context, JSON_UNESCAPED_SLASHES );
		}

		fwrite( STDERR, $line . PHP_EOL );
	}
}

if ( ! function_exists( 'old_posts_fail' ) ) {
	function old_posts_fail( $message, $context = array(), $exit_code = 1 ) {
		old_posts_log( 'error', $message, $context );
		exit( $exit_code );
	}
}

if ( ! function_exists( 'old_posts_normalize_text' ) ) {
	function old_posts_normalize_text( $content ) {
		$content = strip_shortcodes( (string) $content );
		$content = wp_strip_all_tags( $content, true );
		$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
		$content = preg_replace( '/[\x{00A0}\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', ' ', $content );
		$content = preg_replace( '/\s+/u', ' ', trim( $content ) );

		return (string) $content;
	}
}

if ( ! function_exists( 'old_posts_plain_text_length' ) ) {
	function old_posts_plain_text_length( $content ) {
		$text = old_posts_normalize_text( $content );
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text, 'UTF-8' );
		}

		return strlen( $text );
	}
}

if ( ! function_exists( 'old_posts_block_attr_ids' ) ) {
	function old_posts_block_attr_ids( $value, $key, &$ids ) {
		$allowed_keys = array(
			'id',
			'ids',
			'mediaId',
			'mediaIds',
			'posterId',
			'backgroundId',
		);

		if ( ! in_array( $key, $allowed_keys, true ) ) {
			return;
		}

		if ( is_numeric( $value ) ) {
			$ids[] = (int) $value;
			return;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( is_numeric( $item ) ) {
					$ids[] = (int) $item;
				}
			}
		}
	}
}

if ( ! function_exists( 'old_posts_collect_attachment_ids_from_block' ) ) {
	function old_posts_collect_attachment_ids_from_block( $block, &$ids ) {
		if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
			foreach ( $block['attrs'] as $key => $value ) {
				old_posts_block_attr_ids( $value, $key, $ids );
			}
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as $inner_block ) {
				old_posts_collect_attachment_ids_from_block( $inner_block, $ids );
			}
		}
	}
}

if ( ! function_exists( 'old_posts_extract_attachment_ids_from_content' ) ) {
	function old_posts_extract_attachment_ids_from_content( $content ) {
		$ids = array();

		if ( function_exists( 'parse_blocks' ) ) {
			foreach ( parse_blocks( (string) $content ) as $block ) {
				old_posts_collect_attachment_ids_from_block( $block, $ids );
			}
		}

		if ( preg_match_all( '/wp-image-([0-9]+)/', (string) $content, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				$ids[] = (int) $id;
			}
		}

		if ( preg_match_all( '/attachment_([0-9]+)/', (string) $content, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				$ids[] = (int) $id;
			}
		}

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		sort( $ids );

		return $ids;
	}
}

if ( ! function_exists( 'old_posts_get_attached_children_ids' ) ) {
	function old_posts_get_attached_children_ids( $post_id ) {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_parent = %d ORDER BY ID ASC",
				$post_id
			)
		);

		return array_values( array_map( 'intval', $ids ) );
	}
}

if ( ! function_exists( 'old_posts_post_fingerprint' ) ) {
	function old_posts_post_fingerprint( WP_Post $post ) {
		return sha1(
			wp_json_encode(
				array(
					'id'                => (int) $post->ID,
					'post_type'         => (string) $post->post_type,
					'post_date'         => (string) $post->post_date,
					'content'           => (string) $post->post_content,
				),
				JSON_UNESCAPED_SLASHES
			)
		);
	}
}

if ( ! function_exists( 'old_posts_unique_int_keys' ) ) {
	function old_posts_unique_int_keys( $values_by_id ) {
		$ids = array();

		foreach ( array_keys( (array) $values_by_id ) as $value ) {
			$value = (int) $value;
			if ( $value ) {
				$ids[] = $value;
			}
		}

		$ids = array_values( array_unique( $ids ) );
		sort( $ids );

		return $ids;
	}
}

if ( ! function_exists( 'old_posts_sample_int_list_add' ) ) {
	function old_posts_sample_int_list_add( &$values, $value, $limit = 20 ) {
		$value = (int) $value;
		$limit = max( 1, (int) $limit );

		if ( ! $value ) {
			return;
		}

		if ( in_array( $value, $values, true ) ) {
			return;
		}

		if ( count( $values ) >= $limit ) {
			return;
		}

		$values[] = $value;
		sort( $values );
	}
}

if ( ! function_exists( 'old_posts_release_wp_memory' ) ) {
	function old_posts_release_wp_memory( $post_ids = array() ) {
		global $wpdb;

		if ( function_exists( 'clean_post_cache' ) ) {
			foreach ( array_values( array_unique( array_map( 'intval', (array) $post_ids ) ) ) as $post_id ) {
				if ( $post_id > 0 ) {
					clean_post_cache( $post_id );
				}
			}
		}

		if ( is_object( $wpdb ) && method_exists( $wpdb, 'flush' ) ) {
			$wpdb->flush();
		}

		if ( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}
	}
}

if ( ! function_exists( 'old_posts_live_attachment_ids_map' ) ) {
	function old_posts_live_attachment_ids_map( $relative_files, $chunk_size = 500 ) {
		global $wpdb;

		static $cache = array();

		$normalized_files = array();
		foreach ( (array) $relative_files as $relative_file ) {
			$relative_file = ltrim( (string) $relative_file, '/\\' );
			if ( '' !== $relative_file ) {
				$normalized_files[] = $relative_file;
			}
		}

		$normalized_files = array_values( array_unique( $normalized_files ) );
		if ( empty( $normalized_files ) ) {
			return array();
		}

		$uncached_files = array();
		foreach ( $normalized_files as $relative_file ) {
			if ( ! array_key_exists( $relative_file, $cache ) ) {
				$uncached_files[] = $relative_file;
			}
		}

		$chunk_size = max( 1, (int) $chunk_size );
		foreach ( array_chunk( $uncached_files, $chunk_size ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$sql          = $wpdb->prepare(
				"SELECT pm.meta_value AS relative_file, pm.post_id
				FROM {$wpdb->postmeta} AS pm
					INNER JOIN {$wpdb->posts} AS p
						ON p.ID = pm.post_id
				WHERE pm.meta_key = '_wp_attached_file'
				AND pm.meta_value IN ($placeholders)
				AND p.post_type = 'attachment'
				AND p.post_status <> 'trash'
				ORDER BY pm.meta_value ASC, pm.post_id ASC",
				$chunk
			);

			$rows    = $wpdb->get_results( $sql, ARRAY_A );
			$by_file = array_fill_keys( $chunk, array() );

			foreach ( $rows as $row ) {
				$relative_file = isset( $row['relative_file'] ) ? ltrim( (string) $row['relative_file'], '/\\' ) : '';
				$post_id       = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
				if ( '' === $relative_file || ! $post_id ) {
					continue;
				}

				if ( ! isset( $by_file[ $relative_file ] ) ) {
					$by_file[ $relative_file ] = array();
				}

				$by_file[ $relative_file ][] = $post_id;
			}

			foreach ( $chunk as $relative_file ) {
				$cache[ $relative_file ] = array_values(
					array_unique(
						array_filter(
							array_map( 'intval', $by_file[ $relative_file ] ?? array() )
						)
					)
				);
			}
		}

		$result = array();
		foreach ( $normalized_files as $relative_file ) {
			$result[ $relative_file ] = $cache[ $relative_file ] ?? array();
		}

		return $result;
	}
}

if ( ! function_exists( 'old_posts_live_attachment_ids_by_file' ) ) {
	function old_posts_live_attachment_ids_by_file( $relative_file ) {
		$relative_file = ltrim( (string) $relative_file, '/\\' );
		if ( '' === $relative_file ) {
			return array();
		}

		$map = old_posts_live_attachment_ids_map( array( $relative_file ) );

		return $map[ $relative_file ] ?? array();
	}
}

if ( ! function_exists( 'old_posts_wpml_active' ) ) {
	function old_posts_wpml_active() {
		return defined( 'ICL_SITEPRESS_VERSION' ) || has_filter( 'wpml_object_id' ) || has_filter( 'wpml_element_language_details' );
	}
}

if ( ! function_exists( 'old_posts_wpml_translations_table_exists' ) ) {
	function old_posts_wpml_translations_table_exists() {
		global $wpdb;

		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$table = $wpdb->prefix . 'icl_translations';
		$cache = $table === (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $cache;
	}
}

if ( ! function_exists( 'old_posts_wpml_context' ) ) {
	function old_posts_wpml_context( WP_Post $post ) {
		$context = array(
			'is_active'            => false,
			'language_code'        => null,
			'source_language_code' => null,
			'trid'                 => null,
			'translations'         => array(),
		);

		if ( ! old_posts_wpml_active() ) {
			return $context;
		}

		$context['is_active'] = true;

		$details = apply_filters(
			'wpml_element_language_details',
			null,
			array(
				'element_id'   => (int) $post->ID,
				'element_type' => (string) $post->post_type,
			)
		);

		if ( is_object( $details ) ) {
			$context['language_code']        = isset( $details->language_code ) ? (string) $details->language_code : null;
			$context['source_language_code'] = isset( $details->source_language_code ) ? (string) $details->source_language_code : null;
			$context['trid']                 = isset( $details->trid ) ? (int) $details->trid : null;
		}

		if ( empty( $context['trid'] ) ) {
			return $context;
		}

		$translations = apply_filters( 'wpml_get_element_translations', null, (int) $context['trid'], 'post_' . $post->post_type );
		if ( empty( $translations ) || ! is_array( $translations ) ) {
			return $context;
		}

		foreach ( $translations as $lang_code => $translation ) {
			$translation_id = 0;
			if ( is_object( $translation ) && isset( $translation->element_id ) ) {
				$translation_id = (int) $translation->element_id;
			}

			if ( ! $translation_id ) {
				continue;
			}

			$context['translations'][ $lang_code ] = array(
				'post_id'   => $translation_id,
				'permalink' => old_posts_localized_permalink( $translation_id, $lang_code ),
			);
		}

		return $context;
	}
}

if ( ! function_exists( 'old_posts_localized_permalink' ) ) {
	function old_posts_localized_permalink( $post_id, $language_code = null ) {
		static $cache = array();

		$post_id       = (int) $post_id;
		$language_code = null === $language_code ? null : (string) $language_code;
		$cache_key     = $post_id . '|' . ( $language_code ?: '' );

		if ( array_key_exists( $cache_key, $cache ) ) {
			return $cache[ $cache_key ];
		}

		$permalink = get_permalink( $post_id );
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			$cache[ $cache_key ] = '';
			return $cache[ $cache_key ];
		}

		if ( old_posts_wpml_active() && ! empty( $language_code ) && has_filter( 'wpml_permalink' ) ) {
			$localized = apply_filters( 'wpml_permalink', $permalink, $language_code, true );
			if ( is_string( $localized ) && '' !== $localized ) {
				$cache[ $cache_key ] = $localized;
				return $cache[ $cache_key ];
			}
		}

		$cache[ $cache_key ] = $permalink;
		return $cache[ $cache_key ];
	}
}

if ( ! function_exists( 'old_posts_attachment_record' ) ) {
	function old_posts_attachment_record( $attachment_id ) {
		$attachment = get_post( $attachment_id );
		if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type ) {
			return null;
		}

		return array(
			'attachment_id' => (int) $attachment->ID,
			'post_parent'   => (int) $attachment->post_parent,
			'post_status'   => (string) $attachment->post_status,
			'title'         => get_the_title( $attachment->ID ),
			'guid'          => (string) $attachment->guid,
			'file'          => (string) get_post_meta( $attachment->ID, '_wp_attached_file', true ),
			'wpml'          => old_posts_wpml_context( $attachment ),
		);
	}
}

if ( ! function_exists( 'old_posts_attachment_search_tokens' ) ) {
	function old_posts_attachment_search_tokens( $attachment ) {
		$tokens = array();

		$tokens[] = 'wp-image-' . (int) $attachment['attachment_id'];
		$tokens[] = 'attachment_' . (int) $attachment['attachment_id'];

		if ( ! empty( $attachment['guid'] ) ) {
			$tokens[] = (string) $attachment['guid'];
		}

		if ( ! empty( $attachment['file'] ) ) {
			$tokens[] = (string) $attachment['file'];
			$tokens[] = basename( (string) $attachment['file'] );
		}

		$tokens = array_values( array_unique( array_filter( $tokens, 'strlen' ) ) );

		return $tokens;
	}
}

if ( ! function_exists( 'old_posts_attachment_derived_prefix' ) ) {
	function old_posts_attachment_derived_prefix( $attachment ) {
		$file = '';

		if ( is_array( $attachment ) && ! empty( $attachment['file'] ) ) {
			$file = (string) $attachment['file'];
		} elseif ( is_array( $attachment ) && ! empty( $attachment['guid'] ) ) {
			$parts = wp_parse_url( (string) $attachment['guid'] );
			if ( ! empty( $parts['path'] ) ) {
				$file = wp_basename( (string) $parts['path'] );
			}
		}

		if ( '' === $file ) {
			return '';
		}

		return pathinfo( wp_basename( $file ), PATHINFO_FILENAME );
	}
}

if ( ! function_exists( 'old_posts_append_jsonl' ) ) {
	function old_posts_append_jsonl( $path, $payload ) {
		if ( empty( $path ) ) {
			return;
		}

		$result = file_put_contents(
			$path,
			wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ) . PHP_EOL,
			FILE_APPEND
		);

		if ( false === $result ) {
			old_posts_fail(
				'Could not write the JSONL log file.',
				array(
					'path' => $path,
				)
			);
		}
	}
}

if ( ! function_exists( 'old_posts_progress_config' ) ) {
	function old_posts_progress_config( $cli_args, $default_every = 100, $default_seconds = 30 ) {
		$every   = isset( $cli_args['progress-every'] ) ? max( 1, (int) $cli_args['progress-every'] ) : max( 1, (int) $default_every );
		$seconds = isset( $cli_args['progress-seconds'] ) ? max( 1, (int) $cli_args['progress-seconds'] ) : max( 1, (int) $default_seconds );

		return array(
			'every'   => $every,
			'seconds' => $seconds,
		);
	}
}

if ( ! function_exists( 'old_posts_progress_state' ) ) {
	function old_posts_progress_state( $every = 100, $seconds = 30 ) {
		return array(
			'every'      => max( 1, (int) $every ),
			'seconds'    => max( 1, (int) $seconds ),
			'last_count' => 0,
			'last_time'  => microtime( true ),
		);
	}
}

if ( ! function_exists( 'old_posts_progress_maybe_log' ) ) {
	function old_posts_progress_maybe_log( &$state, $message, $current, $total = 0, $context = array(), $options = array() ) {
		$current = max( 0, (int) $current );
		$total   = max( 0, (int) $total );
		$force   = ! empty( $options['force'] );
		$level   = ! empty( $options['level'] ) ? (string) $options['level'] : 'info';
		$phase   = ! empty( $options['phase'] ) ? (string) $options['phase'] : '';
		$log_path = ! empty( $options['log_path'] ) ? (string) $options['log_path'] : '';
		$action  = ! empty( $options['action'] ) ? (string) $options['action'] : 'progress';
		$extra   = ! empty( $options['extra_payload'] ) && is_array( $options['extra_payload'] ) ? $options['extra_payload'] : array();

		$now        = microtime( true );
		$delta_count = $current - (int) $state['last_count'];
		$delta_time  = $now - (float) $state['last_time'];

		if ( ! $force && $delta_count < (int) $state['every'] && $delta_time < (int) $state['seconds'] ) {
			return false;
		}

		$percent = null;
		if ( $total > 0 ) {
			$percent = min( 100, round( ( $current / $total ) * 100, 1 ) );
		}

		$payload = array_merge(
			array(
				'current' => $current,
				'total'   => $total,
			),
			null !== $percent ? array( 'percent' => $percent ) : array(),
			$context
		);

		old_posts_log( $level, $message, $payload );

		if ( '' !== $log_path && '' !== $phase ) {
			old_posts_append_jsonl(
				$log_path,
				array_merge(
					array(
						'timestamp' => gmdate( 'c' ),
						'phase'     => $phase,
						'action'    => $action,
						'status'    => 'progress',
						'current'   => $current,
						'total'     => $total,
					),
					null !== $percent ? array( 'percent' => $percent ) : array(),
					$context,
					$extra
				)
			);
		}

		$state['last_count'] = $current;
		$state['last_time']  = $now;

		return true;
	}
}

if ( ! function_exists( 'old_posts_write_json' ) ) {
	function old_posts_write_json( $path, $payload ) {
		$result = file_put_contents(
			$path,
			wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL
		);

		if ( false === $result ) {
			old_posts_fail( 'Could not write the JSON file.', array( 'path' => $path ) );
		}
	}
}

if ( ! function_exists( 'old_posts_read_manifest' ) ) {
	function old_posts_read_manifest( $path ) {
		if ( ! file_exists( $path ) ) {
			old_posts_fail( 'Manifest not found.', array( 'path' => $path ) );
		}

		$content = file_get_contents( $path );
		$data    = json_decode( $content, true );

		if ( ! is_array( $data ) ) {
			old_posts_fail( 'Invalid manifest.', array( 'path' => $path ) );
		}

		return $data;
	}
}
