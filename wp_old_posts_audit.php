<?php

require_once __DIR__ . '/wp_old_posts_common.php';

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run with WP-CLI: wp eval-file wp_old_posts_audit.php key=value ...\n" );
	exit( 1 );
}

$cli_args = old_posts_collect_runtime_args(
	isset( $args ) && is_array( $args ) ? $args : array(),
	isset( $GLOBALS['argv'] ) && is_array( $GLOBALS['argv'] ) ? $GLOBALS['argv'] : array()
);

$output_path   = isset( $cli_args['output'] ) ? (string) $cli_args['output'] : old_posts_default_temp_path( 'old-posts-manifest.json' );
$before_date   = isset( $cli_args['before'] ) ? (string) $cli_args['before'] : '2017-01-01 00:00:00';
$char_limit    = isset( $cli_args['limit'] ) ? (int) $cli_args['limit'] : 300;
$post_type     = isset( $cli_args['post-type'] ) ? (string) $cli_args['post-type'] : 'post';
$statuses      = old_posts_csv_arg( isset( $cli_args['statuses'] ) ? $cli_args['statuses'] : null, array( 'publish', 'draft' ) );
$batch_size    = isset( $cli_args['batch-size'] ) ? max( 10, (int) $cli_args['batch-size'] ) : 100;
$usage_scan    = old_posts_bool_arg( isset( $cli_args['scan-usage'] ) ? $cli_args['scan-usage'] : null, true );
$max_posts     = isset( $cli_args['max-posts'] ) ? max( 1, (int) $cli_args['max-posts'] ) : 0;
$progress_cfg  = old_posts_progress_config( $cli_args, $batch_size, 30 );

if ( empty( $statuses ) ) {
	old_posts_fail( 'Provide at least one post status in statuses=...' );
}

old_posts_assert_parent_writable( $output_path, 'manifest JSON output' );

global $wpdb;

$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
$count_query  = $wpdb->prepare(
	"SELECT COUNT(*)
	FROM {$wpdb->posts}
	WHERE post_type = %s
	AND post_date < %s
	AND post_status IN ($placeholders)",
	array_merge( array( $post_type, $before_date ), $statuses )
);

$pre_cutoff_post_count = (int) $wpdb->get_var( $count_query );
old_posts_release_wp_memory();

if ( 0 === $pre_cutoff_post_count ) {
	old_posts_fail(
		'No posts matched the requested criteria.',
		array(
			'post_type' => $post_type,
			'before'    => $before_date,
			'statuses'  => $statuses,
		)
	);
}

old_posts_log(
	'info',
	'Starting the old-posts audit.',
	array(
		'pre_cutoff_posts' => $pre_cutoff_post_count,
		'usage_scan'       => $usage_scan,
		'wpml_active'      => old_posts_wpml_active(),
		'resolved_args'    => array(
			'output'     => $output_path,
			'before'     => $before_date,
			'limit'      => $char_limit,
			'post_type'  => $post_type,
			'statuses'   => $statuses,
			'batch_size' => $batch_size,
			'max_posts'  => $max_posts,
			'progress_every' => $progress_cfg['every'],
			'progress_seconds' => $progress_cfg['seconds'],
		),
	)
);

$candidate_posts       = array();
$candidate_post_ids    = array();
$candidate_attachments = array();
$candidate_by_language = array();
$candidate_by_year     = array();
$candidate_by_status   = array();
$redirect_exportable_posts = 0;

$total_scanned = 0;
$scan_progress = old_posts_progress_state( $progress_cfg['every'], $progress_cfg['seconds'] );
$last_scanned_id = 0;
$stop_scan = false;
while ( true ) {
	$post_id_batch = array_map(
		'intval',
		$wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID
				FROM {$wpdb->posts}
				WHERE post_type = %s
				AND post_date < %s
				AND post_status IN ($placeholders)
				AND ID > %d
				ORDER BY ID ASC
				LIMIT %d",
				array_merge( array( $post_type, $before_date ), $statuses, array( $last_scanned_id, $batch_size ) )
			)
		)
	);

	if ( empty( $post_id_batch ) ) {
		break;
	}

	$processed_object_ids = array();
	foreach ( $post_id_batch as $post_id ) {
		$last_scanned_id = (int) $post_id;
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			continue;
		}

		++$total_scanned;
		old_posts_progress_maybe_log(
			$scan_progress,
			'Scanning posts for deletion candidates.',
			$total_scanned,
			$pre_cutoff_post_count,
			array(
				'candidate_posts' => count( $candidate_posts ),
			)
		);

		$plain_text_length = old_posts_plain_text_length( $post->post_content );
		if ( $plain_text_length > $char_limit ) {
			continue;
		}

		$featured_media = (int) get_post_thumbnail_id( $post->ID );
		$embedded_ids   = old_posts_extract_attachment_ids_from_content( $post->post_content );
		$child_ids      = old_posts_get_attached_children_ids( $post->ID );

		$attachment_reasons = array();

		if ( $featured_media ) {
			$attachment_reasons[ $featured_media ][] = 'featured_media';
		}

		foreach ( $embedded_ids as $attachment_id ) {
			$attachment_reasons[ $attachment_id ][] = 'embedded_content';
		}

		foreach ( $child_ids as $attachment_id ) {
			$attachment_reasons[ $attachment_id ][] = 'attached_child';
		}

		$attachment_ids = array_keys( $attachment_reasons );
		sort( $attachment_ids );

		$wpml_context = old_posts_wpml_context( $post );
		$language     = $wpml_context['language_code'] ?: 'und';
		$year         = substr( (string) $post->post_date, 0, 4 );
		$post_status  = (string) $post->post_status;
		$permalink    = old_posts_localized_permalink( $post->ID, $wpml_context['language_code'] );

		if ( ! isset( $candidate_by_language[ $language ] ) ) {
			$candidate_by_language[ $language ] = 0;
		}
		++$candidate_by_language[ $language ];

		if ( ! isset( $candidate_by_year[ $year ] ) ) {
			$candidate_by_year[ $year ] = 0;
		}
		++$candidate_by_year[ $year ];

		if ( ! isset( $candidate_by_status[ $post_status ] ) ) {
			$candidate_by_status[ $post_status ] = 0;
		}
		++$candidate_by_status[ $post_status ];

		$redirect_eligible = 'publish' === $post_status && '' !== $permalink;
		$redirect_reason   = $redirect_eligible
			? 'Content removed without an equivalent replacement.'
			: ( 'publish' !== $post_status
				? 'Post is not public; do not export a redirect by default.'
				: 'Permalink is missing; review manually before exporting redirects.' );

		if ( $redirect_eligible ) {
			++$redirect_exportable_posts;
		}

		foreach ( $attachment_ids as $attachment_id ) {
			if ( ! isset( $candidate_attachments[ $attachment_id ] ) ) {
				$base_record = old_posts_attachment_record( $attachment_id );
				if ( null === $base_record ) {
					continue;
				}

				$base_record['candidate_post_ids']                = array();
				$base_record['usage_detected']                    = false;
				$base_record['non_candidate_usage_detected']      = false;
				$base_record['usage_post_ids_sample']             = array();
				$base_record['non_candidate_usage_post_ids_sample'] = array();
				$base_record['safe_to_delete']                    = false;
				$base_record['risk_flags']                        = array();
				$candidate_attachments[ $attachment_id ]          = $base_record;
				$processed_object_ids[]                           = $attachment_id;
			}

			$candidate_attachments[ $attachment_id ]['candidate_post_ids'][ (string) $post->ID ] = true;
		}

		$candidate_posts[] = array(
			'post_id'            => (int) $post->ID,
			'post_type'          => (string) $post->post_type,
			'post_status'        => $post_status,
			'post_date'          => (string) $post->post_date,
			'post_modified_gmt'  => (string) $post->post_modified_gmt,
			'post_name'          => (string) $post->post_name,
			'post_title'         => get_the_title( $post->ID ),
			'permalink'          => $permalink,
			'plain_text_length'  => $plain_text_length,
			'featured_media'     => $featured_media,
			'attachment_ids'     => $attachment_ids,
			'wpml'               => $wpml_context,
			'fingerprint'        => old_posts_post_fingerprint( $post ),
			'redirect'           => array(
				'observed_permalink' => $permalink,
				'source_url'         => $redirect_eligible ? $permalink : '',
				'eligible_for_export' => $redirect_eligible,
				'eligibility_reason' => $redirect_eligible ? 'public_post' : ( '' === $permalink ? 'missing_permalink' : 'post_status_not_public' ),
				'recommended_status' => $redirect_eligible ? 410 : null,
				'recommended_target' => home_url( '/' ),
				'recommended_action' => $redirect_eligible ? '410' : 'skip',
				'reason'             => $redirect_reason,
			),
		);

		$candidate_post_ids[ $post->ID ] = true;
		$processed_object_ids[]          = (int) $post->ID;

		if ( $max_posts && count( $candidate_posts ) >= $max_posts ) {
			$stop_scan = true;
			break;
		}
	}

	old_posts_release_wp_memory( $processed_object_ids );

	if ( $stop_scan ) {
		break;
	}
}

old_posts_progress_maybe_log(
	$scan_progress,
	'Initial candidate scan complete.',
	$total_scanned,
	$pre_cutoff_post_count,
	array(
		'candidate_posts' => count( $candidate_posts ),
	),
	array(
		'force' => true,
	)
);

$candidate_attachment_ids = array_map( 'intval', array_keys( $candidate_attachments ) );
sort( $candidate_attachment_ids );

if ( $usage_scan && ! empty( $candidate_attachment_ids ) ) {
	old_posts_log(
		'info',
		'Starting the global usage scan for candidate media.',
		array(
			'candidate_attachments' => count( $candidate_attachment_ids ),
		)
	);

	$candidate_set  = array_fill_keys( $candidate_attachment_ids, true );
	$usage_progress = old_posts_progress_state( max( 1, ceil( $progress_cfg['every'] / max( 1, $batch_size ) ) ), $progress_cfg['seconds'] );
	$thumbnail_scan_processed = 0;
	$candidate_attachment_count = count( $candidate_attachment_ids );

	for ( $offset = 0; $offset < $candidate_attachment_count; $offset += 250 ) {
		$attachment_id_chunk = array_slice( $candidate_attachment_ids, $offset, 250 );
		$in_sql        = implode( ',', array_fill( 0, count( $attachment_id_chunk ), '%d' ) );
		$thumbnail_sql = $wpdb->prepare(
			"SELECT post_id, meta_value
			FROM {$wpdb->postmeta}
			WHERE meta_key = '_thumbnail_id'
			AND meta_value IN ($in_sql)",
			$attachment_id_chunk
		);

		$thumbnail_rows = $wpdb->get_results( $thumbnail_sql, ARRAY_A );
		foreach ( $thumbnail_rows as $row ) {
			$attachment_id = (int) $row['meta_value'];
			$post_id       = (int) $row['post_id'];
			if ( ! isset( $candidate_attachments[ $attachment_id ] ) || ! $post_id ) {
				continue;
			}

			$candidate_attachments[ $attachment_id ]['usage_detected'] = true;
			old_posts_sample_int_list_add( $candidate_attachments[ $attachment_id ]['usage_post_ids_sample'], $post_id );

			if ( ! isset( $candidate_post_ids[ $post_id ] ) ) {
				$candidate_attachments[ $attachment_id ]['non_candidate_usage_detected'] = true;
				old_posts_sample_int_list_add( $candidate_attachments[ $attachment_id ]['non_candidate_usage_post_ids_sample'], $post_id );
			}
		}
		$thumbnail_scan_processed += count( $attachment_id_chunk );
		unset( $thumbnail_rows );
		old_posts_release_wp_memory();

		old_posts_progress_maybe_log(
			$usage_progress,
			'Scanning featured-image references.',
			$thumbnail_scan_processed,
			$candidate_attachment_count,
			array(
				'candidate_attachments' => $candidate_attachment_count,
			)
		);
	}

	old_posts_progress_maybe_log(
		$usage_progress,
		'Featured-image scan complete.',
		$thumbnail_scan_processed,
		$candidate_attachment_count,
		array(
			'candidate_attachments' => $candidate_attachment_count,
		),
		array(
			'force' => true,
		)
	);

	$content_scan_total = (int) $wpdb->get_var(
		"SELECT COUNT(*)
		FROM {$wpdb->posts}
		WHERE post_type <> 'attachment'
		AND post_type <> 'revision'
		AND post_status <> 'trash'
		AND post_status <> 'auto-draft'"
	);
	$content_scan_processed = 0;
	$content_progress = old_posts_progress_state( $progress_cfg['every'], $progress_cfg['seconds'] );
	$last_id = 0;
	while ( true ) {
		$content_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content
				FROM {$wpdb->posts}
				WHERE ID > %d
				AND post_type <> 'attachment'
				AND post_type <> 'revision'
				AND post_status <> 'trash'
				AND post_status <> 'auto-draft'
				ORDER BY ID ASC
				LIMIT %d",
				$last_id,
				$batch_size
			),
			ARRAY_A
		);

		if ( empty( $content_rows ) ) {
			break;
		}

		foreach ( $content_rows as $row ) {
			$last_id = (int) $row['ID'];
			++$content_scan_processed;
			$refs    = old_posts_extract_attachment_ids_from_content( $row['post_content'] );
			if ( empty( $refs ) ) {
				old_posts_progress_maybe_log(
					$content_progress,
					'Scanning global attachment usage in post_content.',
					$content_scan_processed,
					$content_scan_total,
					array(
						'last_post_id' => $last_id,
					)
				);
				continue;
			}

			foreach ( $refs as $attachment_id ) {
				if ( isset( $candidate_set[ $attachment_id ] ) && isset( $candidate_attachments[ $attachment_id ] ) ) {
					$candidate_attachments[ $attachment_id ]['usage_detected'] = true;
					old_posts_sample_int_list_add( $candidate_attachments[ $attachment_id ]['usage_post_ids_sample'], (int) $row['ID'] );

					if ( ! isset( $candidate_post_ids[ (int) $row['ID'] ] ) ) {
						$candidate_attachments[ $attachment_id ]['non_candidate_usage_detected'] = true;
						old_posts_sample_int_list_add( $candidate_attachments[ $attachment_id ]['non_candidate_usage_post_ids_sample'], (int) $row['ID'] );
					}
				}
			}

			old_posts_progress_maybe_log(
				$content_progress,
				'Scanning global attachment usage in post_content.',
				$content_scan_processed,
				$content_scan_total,
				array(
					'last_post_id' => $last_id,
				)
			);
		}

		unset( $content_rows );
		old_posts_release_wp_memory();
	}

	old_posts_progress_maybe_log(
		$content_progress,
		'Global usage scan complete.',
		$content_scan_processed,
		$content_scan_total,
		array(
			'candidate_attachments' => count( $candidate_attachment_ids ),
		),
		array(
			'force' => true,
		)
	);

}

foreach ( $candidate_attachments as $attachment_id => &$attachment_record ) {
	$usage_detected               = ! empty( $attachment_record['usage_detected'] );
	$non_candidate_usage_detected = ! empty( $attachment_record['non_candidate_usage_detected'] );

	$risk_flags = array();
	if ( ! $usage_scan ) {
		$risk_flags[] = 'usage_scan_disabled';
	}

	if ( $non_candidate_usage_detected ) {
		$risk_flags[] = 'used_by_non_candidate_post';
	}

	if ( ! empty( $attachment_record['post_parent'] ) && ! isset( $candidate_post_ids[ $attachment_record['post_parent'] ] ) ) {
		$risk_flags[] = 'attached_to_non_candidate_post';
	}

	if ( $usage_scan && ! $usage_detected ) {
		$risk_flags[] = 'no_detected_content_or_thumbnail_usage';
	}

	$attachment_record['candidate_post_ids']                  = old_posts_unique_int_keys( $attachment_record['candidate_post_ids'] );
	$attachment_record['usage_detected']                      = $usage_detected;
	$attachment_record['non_candidate_usage_detected']        = $non_candidate_usage_detected;
	$attachment_record['usage_post_ids_sample']               = array_values( array_map( 'intval', $attachment_record['usage_post_ids_sample'] ) );
	$attachment_record['non_candidate_usage_post_ids_sample'] = array_values( array_map( 'intval', $attachment_record['non_candidate_usage_post_ids_sample'] ) );
	$attachment_record['risk_flags']                          = array_values( array_unique( $risk_flags ) );
	$attachment_record['safe_to_delete']                      = $usage_scan
		&& ! $non_candidate_usage_detected
		&& ( empty( $attachment_record['post_parent'] ) || isset( $candidate_post_ids[ $attachment_record['post_parent'] ] ) );
}
unset( $attachment_record );

$attachment_records = array_values( $candidate_attachments );
usort(
	$attachment_records,
	static function ( $a, $b ) {
		return $a['attachment_id'] <=> $b['attachment_id'];
	}
);
unset( $candidate_attachments, $candidate_attachment_ids, $candidate_post_ids );
old_posts_release_wp_memory();

$safe_attachment_count = 0;
foreach ( $attachment_records as $attachment_record ) {
	if ( ! empty( $attachment_record['safe_to_delete'] ) ) {
		++$safe_attachment_count;
	}
}

ksort( $candidate_by_language );
ksort( $candidate_by_year );
ksort( $candidate_by_status );

$manifest = array(
	'generated_at' => gmdate( 'c' ),
	'site'         => array(
		'home_url'      => home_url( '/' ),
		'site_url'      => site_url( '/' ),
		'blog_charset'  => get_bloginfo( 'charset' ),
		'is_wpml_active' => old_posts_wpml_active(),
	),
	'criteria'     => array(
		'post_type'       => $post_type,
		'before'          => $before_date,
		'char_limit'      => $char_limit,
		'statuses'        => $statuses,
		'usage_scan'      => $usage_scan,
		'batch_size'      => $batch_size,
		'max_posts'       => $max_posts,
		'redirect_policy' => array(
			'recommended_default' => 410,
			'not_recommended'     => '301-home',
			'export_statuses'     => array( 'publish' ),
		),
	),
	'summary'      => array(
		'pre_cutoff_posts_scanned'     => $total_scanned,
		'candidate_posts'              => count( $candidate_posts ),
		'candidate_attachments'        => count( $attachment_records ),
		'safe_attachment_delete_count' => $safe_attachment_count,
		'redirect_exportable_posts'    => $redirect_exportable_posts,
		'by_language'                  => $candidate_by_language,
		'by_year'                      => $candidate_by_year,
		'by_status'                    => $candidate_by_status,
	),
	'posts'        => $candidate_posts,
	'attachments'  => $attachment_records,
);

old_posts_write_json( $output_path, $manifest );

old_posts_log(
	'success',
	'Audit completed.',
	array(
		'output'                    => $output_path,
		'candidate_posts'           => count( $candidate_posts ),
		'candidate_attachments'     => count( $attachment_records ),
		'safe_attachment_deletes'   => $safe_attachment_count,
	)
);
