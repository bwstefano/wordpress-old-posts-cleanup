<?php

require_once __DIR__ . '/wp_old_posts_common.php';

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run with WP-CLI: wp eval-file wp_old_posts_remaining_posts.php key=value ...\n" );
	exit( 1 );
}

if ( ! function_exists( 'old_posts_remaining_years' ) ) {
	function old_posts_remaining_years( $cli_args, $manifest ) {
		$years = array();

		if ( ! empty( $cli_args['year'] ) ) {
			$years[] = (string) $cli_args['year'];
		}

		if ( ! empty( $cli_args['years'] ) ) {
			$years = array_merge( $years, old_posts_csv_arg( $cli_args['years'] ) );
		}

		if ( ! empty( $cli_args['year-list'] ) ) {
			$years = array_merge(
				$years,
				preg_split( '/[\s,]+/', trim( (string) $cli_args['year-list'] ) ) ?: array()
			);
		}

		if ( empty( $years ) && ! empty( $manifest['summary']['by_year'] ) && is_array( $manifest['summary']['by_year'] ) ) {
			$years = array_keys( $manifest['summary']['by_year'] );
		}

		$years = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $year ) {
							$year = trim( (string) $year );
							return preg_match( '/^\d{4}$/', $year ) ? $year : '';
						},
						$years
					),
					'strlen'
				)
			)
		);
		sort( $years );

		if ( empty( $years ) ) {
			old_posts_fail( 'Provide year= or years=YYYY,YYYY so the remaining-posts report knows which years to inspect.' );
		}

		return $years;
	}
}

if ( ! function_exists( 'old_posts_remaining_manifest_post_map' ) ) {
	function old_posts_remaining_manifest_post_map( $manifest ) {
		$map = array();

		foreach ( $manifest['posts'] ?? array() as $post_record ) {
			$post_id = isset( $post_record['post_id'] ) ? (int) $post_record['post_id'] : 0;
			if ( $post_id ) {
				$map[ $post_id ] = $post_record;
			}
		}

		return $map;
	}
}

if ( ! function_exists( 'old_posts_remaining_review_reason' ) ) {
	function old_posts_remaining_review_reason( WP_Post $post, $plain_text_length, $char_limit, $manifest_record ) {
		if ( is_array( $manifest_record ) ) {
			$current_fingerprint = old_posts_post_fingerprint( $post );
			$matches_manifest    = (int) $plain_text_length === (int) ( $manifest_record['plain_text_length'] ?? -1 )
				&& $current_fingerprint === (string) ( $manifest_record['fingerprint'] ?? '' );

			return $matches_manifest ? 'manifest_candidate_still_live' : 'manifest_candidate_changed_since_manifest';
		}

		if ( $plain_text_length > $char_limit ) {
			return 'not_in_manifest_over_char_limit';
		}

		return 'eligible_now_but_missing_from_manifest';
	}
}

if ( ! function_exists( 'old_posts_remaining_csv_escape' ) ) {
	function old_posts_remaining_csv_escape( $value ) {
		$value = is_scalar( $value ) || null === $value ? (string) $value : wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
		return '"' . str_replace( '"', '""', $value ) . '"';
	}
}

if ( ! function_exists( 'old_posts_remaining_write_csv' ) ) {
	function old_posts_remaining_write_csv( $path, $items ) {
		$rows = array(
			'post_id,post_date,post_status,year,plain_text_length,review_reason,is_manifest_candidate,current_matches_manifest,needs_manual_include,candidate_selection_reason,manual_include_requested,post_title,permalink,featured_media,attachment_count,attachment_ids,language_code,source_language_code,trid',
		);

		foreach ( $items as $item ) {
			$rows[] = implode(
				',',
				array(
					old_posts_remaining_csv_escape( $item['post_id'] ),
					old_posts_remaining_csv_escape( $item['post_date'] ),
					old_posts_remaining_csv_escape( $item['post_status'] ),
					old_posts_remaining_csv_escape( $item['year'] ),
					old_posts_remaining_csv_escape( $item['plain_text_length'] ),
					old_posts_remaining_csv_escape( $item['review_reason'] ),
					old_posts_remaining_csv_escape( ! empty( $item['is_manifest_candidate'] ) ? '1' : '0' ),
					old_posts_remaining_csv_escape( ! empty( $item['current_matches_manifest'] ) ? '1' : '0' ),
					old_posts_remaining_csv_escape( ! empty( $item['needs_manual_include'] ) ? '1' : '0' ),
					old_posts_remaining_csv_escape( $item['candidate_selection_reason'] ?? '' ),
					old_posts_remaining_csv_escape( ! empty( $item['manual_include_requested'] ) ? '1' : '0' ),
					old_posts_remaining_csv_escape( $item['post_title'] ),
					old_posts_remaining_csv_escape( $item['permalink'] ),
					old_posts_remaining_csv_escape( $item['featured_media'] ),
					old_posts_remaining_csv_escape( $item['attachment_count'] ),
					old_posts_remaining_csv_escape( implode( '|', array_map( 'intval', $item['attachment_ids'] ?? array() ) ) ),
					old_posts_remaining_csv_escape( $item['wpml']['language_code'] ?? '' ),
					old_posts_remaining_csv_escape( $item['wpml']['source_language_code'] ?? '' ),
					old_posts_remaining_csv_escape( $item['wpml']['trid'] ?? '' ),
				)
			);
		}

		$result = file_put_contents( $path, implode( PHP_EOL, $rows ) . PHP_EOL );
		if ( false === $result ) {
			old_posts_fail( 'Could not write the remaining-posts CSV file.', array( 'path' => $path ) );
		}
	}
}

$cli_args        = old_posts_collect_runtime_args(
	isset( $args ) && is_array( $args ) ? $args : array(),
	isset( $GLOBALS['argv'] ) && is_array( $GLOBALS['argv'] ) ? $GLOBALS['argv'] : array()
);
$manifest_path   = isset( $cli_args['manifest'] ) ? (string) $cli_args['manifest'] : old_posts_default_temp_path( 'old-posts-manifest.json' );
$output_path     = isset( $cli_args['output'] ) ? (string) $cli_args['output'] : old_posts_default_temp_path( 'old-posts-remaining-posts.json' );
$csv_output_path = isset( $cli_args['csv-output'] ) ? (string) $cli_args['csv-output'] : '';
$batch_size      = isset( $cli_args['batch-size'] ) ? max( 10, (int) $cli_args['batch-size'] ) : 100;
$progress_cfg    = old_posts_progress_config( $cli_args, $batch_size, 30 );

old_posts_assert_parent_writable( $output_path, 'remaining posts JSON report' );
if ( '' !== $csv_output_path ) {
	old_posts_assert_parent_writable( $csv_output_path, 'remaining posts CSV report' );
}

$manifest = old_posts_read_manifest( $manifest_path );

if ( trailingslashit( home_url( '/' ) ) !== trailingslashit( $manifest['site']['home_url'] ) ) {
	old_posts_fail(
		'The manifest was generated for a different site.',
		array(
			'current_home_url'  => home_url( '/' ),
			'manifest_home_url' => $manifest['site']['home_url'],
		)
	);
}

$years          = old_posts_remaining_years( $cli_args, $manifest );
$post_type      = isset( $cli_args['post-type'] ) ? (string) $cli_args['post-type'] : (string) ( $manifest['criteria']['post_type'] ?? 'post' );
$before_date    = isset( $cli_args['before'] ) ? (string) $cli_args['before'] : (string) ( $manifest['criteria']['before'] ?? '2017-01-01 00:00:00' );
$char_limit     = isset( $cli_args['limit'] ) ? (int) $cli_args['limit'] : (int) ( $manifest['criteria']['char_limit'] ?? 300 );
$statuses       = old_posts_csv_arg( $cli_args['statuses'] ?? null, $manifest['criteria']['statuses'] ?? array( 'publish', 'draft' ) );
$manifest_posts = old_posts_remaining_manifest_post_map( $manifest );

if ( empty( $statuses ) ) {
	old_posts_fail( 'Provide at least one post status in statuses=...' );
}

global $wpdb;

$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
$year_placeholders   = implode( ',', array_fill( 0, count( $years ), '%s' ) );
$count_query         = $wpdb->prepare(
	"SELECT COUNT(*)
	FROM {$wpdb->posts}
	WHERE post_type = %s
	AND post_date < %s
	AND post_status IN ($status_placeholders)
	AND SUBSTRING(post_date, 1, 4) IN ($year_placeholders)",
	array_merge( array( $post_type, $before_date ), $statuses, $years )
);
$remaining_count     = (int) $wpdb->get_var( $count_query );

$items            = array();
$by_year          = array();
$by_status        = array();
$by_reason        = array();
$progress_state   = old_posts_progress_state( $progress_cfg['every'], $progress_cfg['seconds'] );
$total_scanned    = 0;
$last_scanned_id  = 0;

while ( true ) {
	$post_id_batch = array_map(
		'intval',
		$wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID
				FROM {$wpdb->posts}
				WHERE post_type = %s
				AND post_date < %s
				AND post_status IN ($status_placeholders)
				AND SUBSTRING(post_date, 1, 4) IN ($year_placeholders)
				AND ID > %d
				ORDER BY ID ASC
				LIMIT %d",
				array_merge( array( $post_type, $before_date ), $statuses, $years, array( $last_scanned_id, $batch_size ) )
			)
		)
	);

	if ( empty( $post_id_batch ) ) {
		break;
	}

	$processed_object_ids = array();
	foreach ( $post_id_batch as $post_id ) {
		$last_scanned_id = (int) $post_id;
		$post            = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			continue;
		}

		++$total_scanned;
		$plain_text_length = old_posts_plain_text_length( $post->post_content );
		$featured_media    = (int) get_post_thumbnail_id( $post->ID );
		$embedded_ids      = old_posts_extract_attachment_ids_from_content( $post->post_content );
		$child_ids         = old_posts_get_attached_children_ids( $post->ID );
		$attachment_ids    = array_values( array_unique( array_merge( $embedded_ids, $child_ids, $featured_media ? array( $featured_media ) : array() ) ) );
		sort( $attachment_ids );

		$manifest_record         = $manifest_posts[ (int) $post->ID ] ?? null;
		$review_reason           = old_posts_remaining_review_reason( $post, $plain_text_length, $char_limit, $manifest_record );
		$current_fingerprint     = old_posts_post_fingerprint( $post );
		$current_matches_manifest = is_array( $manifest_record )
			&& (int) $plain_text_length === (int) ( $manifest_record['plain_text_length'] ?? -1 )
			&& $current_fingerprint === (string) ( $manifest_record['fingerprint'] ?? '' );
		$wpml_context            = old_posts_wpml_context( $post );
		$year                    = substr( (string) $post->post_date, 0, 4 );

		$item = array(
			'post_id'                   => (int) $post->ID,
			'post_type'                 => (string) $post->post_type,
			'post_status'               => (string) $post->post_status,
			'post_date'                 => (string) $post->post_date,
			'year'                      => $year,
			'post_title'                => get_the_title( $post->ID ),
			'permalink'                 => old_posts_localized_permalink( $post->ID, $wpml_context['language_code'] ?? null ),
			'plain_text_length'         => $plain_text_length,
			'featured_media'            => $featured_media,
			'attachment_ids'            => $attachment_ids,
			'attachment_count'          => count( $attachment_ids ),
			'wpml'                      => $wpml_context,
			'is_manifest_candidate'     => is_array( $manifest_record ),
			'current_matches_manifest'  => $current_matches_manifest,
			'needs_manual_include'      => ! is_array( $manifest_record ),
			'review_reason'             => $review_reason,
			'candidate_selection_reason' => is_array( $manifest_record ) ? (string) ( $manifest_record['selection_reason'] ?? 'char_limit' ) : '',
			'manual_include_requested'  => is_array( $manifest_record ) ? ! empty( $manifest_record['manual_include_requested'] ) : false,
		);

		$items[] = $item;
		$processed_object_ids[] = (int) $post->ID;

		if ( ! isset( $by_year[ $year ] ) ) {
			$by_year[ $year ] = 0;
		}
		++$by_year[ $year ];

		if ( ! isset( $by_status[ $item['post_status'] ] ) ) {
			$by_status[ $item['post_status'] ] = 0;
		}
		++$by_status[ $item['post_status'] ];

		if ( ! isset( $by_reason[ $review_reason ] ) ) {
			$by_reason[ $review_reason ] = 0;
		}
		++$by_reason[ $review_reason ];

		old_posts_progress_maybe_log(
			$progress_state,
			'Scanning remaining posts for manual review.',
			$total_scanned,
			$remaining_count,
			array(
				'years' => $years,
			)
		);
	}

	old_posts_release_wp_memory( $processed_object_ids );
}

usort(
	$items,
	static function ( $a, $b ) {
		if ( $a['post_date'] === $b['post_date'] ) {
			return $a['post_id'] <=> $b['post_id'];
		}

		return strcmp( $a['post_date'], $b['post_date'] );
	}
);

ksort( $by_year );
ksort( $by_status );
ksort( $by_reason );

$report = array(
	'generated_at' => gmdate( 'c' ),
	'site'         => array(
		'home_url'       => home_url( '/' ),
		'site_url'       => site_url( '/' ),
		'blog_charset'   => get_bloginfo( 'charset' ),
		'is_wpml_active' => old_posts_wpml_active(),
	),
	'criteria'     => array(
		'manifest'   => $manifest_path,
		'post_type'  => $post_type,
		'before'     => $before_date,
		'limit'      => $char_limit,
		'statuses'   => $statuses,
		'years'      => $years,
		'batch_size' => $batch_size,
	),
	'summary'      => array(
		'remaining_posts'               => count( $items ),
		'remaining_manifest_candidates' => count(
			array_filter(
				$items,
				static function ( $item ) {
					return ! empty( $item['is_manifest_candidate'] );
				}
			)
		),
		'remaining_non_candidates'      => count(
			array_filter(
				$items,
				static function ( $item ) {
					return empty( $item['is_manifest_candidate'] );
				}
			)
		),
		'needs_manual_include_count'    => count(
			array_filter(
				$items,
				static function ( $item ) {
					return ! empty( $item['needs_manual_include'] );
				}
			)
		),
		'by_year'                       => $by_year,
		'by_status'                     => $by_status,
		'by_reason'                     => $by_reason,
	),
	'items'        => $items,
);

old_posts_write_json( $output_path, $report );

if ( '' !== $csv_output_path ) {
	old_posts_remaining_write_csv( $csv_output_path, $items );
}

old_posts_log(
	'success',
	'Remaining-posts report generated.',
	array(
		'output'        => $output_path,
		'csv_output'    => $csv_output_path,
		'remaining'     => count( $items ),
		'manual_review' => $report['summary']['needs_manual_include_count'],
		'years'         => $years,
	)
);
