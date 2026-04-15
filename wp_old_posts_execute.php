<?php

require_once __DIR__ . '/wp_old_posts_common.php';

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run with WP-CLI: wp eval-file wp_old_posts_execute.php key=value ...\n" );
	exit( 1 );
}

function old_posts_expected_confirmation( $manifest, $phase ) {
	return 'CONFIRM-' . strtoupper( substr( sha1( $manifest['generated_at'] . '|' . $manifest['site']['home_url'] . '|' . $phase ), 0, 8 ) );
}

function old_posts_post_matches_manifest( $post_id, $expected_plain_text_length, $expected_fingerprint ) {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return false;
	}

	return old_posts_plain_text_length( $post->post_content ) === (int) $expected_plain_text_length
		&& old_posts_post_fingerprint( $post ) === (string) $expected_fingerprint;
}

function old_posts_attachment_has_external_usage( $attachment, $candidate_post_ids, $scan_postmeta = false ) {
	global $wpdb;

	$attachment_id = (int) $attachment['attachment_id'];
	$exclude_ids   = array_map( 'intval', array_keys( $candidate_post_ids ) );
	$exclude_ids[] = 0;
	$exclude_sql   = implode( ',', $exclude_ids );

	$thumbnail_hit = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT post_id
			FROM {$wpdb->postmeta}
			WHERE meta_key = '_thumbnail_id'
			AND meta_value = %d
			AND post_id NOT IN ($exclude_sql)
			LIMIT 1",
			$attachment_id
		)
	);

	if ( $thumbnail_hit ) {
		return array(
			'kind'    => 'thumbnail',
			'post_id' => $thumbnail_hit,
		);
	}

	foreach ( old_posts_attachment_search_tokens( $attachment ) as $token ) {
		$like = '%' . $wpdb->esc_like( $token ) . '%';

		$post_hit = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID
				FROM {$wpdb->posts}
				WHERE ID NOT IN ($exclude_sql)
				AND post_type <> 'attachment'
				AND post_type <> 'revision'
				AND post_status <> 'trash'
				AND post_status <> 'auto-draft'
				AND post_content LIKE %s
				LIMIT 1",
				$like
			)
		);

		if ( $post_hit ) {
			return array(
				'kind'    => 'content',
				'post_id' => $post_hit,
				'token'   => $token,
			);
		}

		if ( $scan_postmeta ) {
			$postmeta_hit = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id
					FROM {$wpdb->postmeta}
					WHERE post_id NOT IN ($exclude_sql)
					AND meta_value LIKE %s
					LIMIT 1",
					$like
				)
			);

			if ( $postmeta_hit ) {
				return array(
					'kind'    => 'postmeta',
					'post_id' => $postmeta_hit,
					'token'   => $token,
				);
			}
		}
	}

	return null;
}

function old_posts_attachment_wpml_translation_ids( $attachment_record ) {
	$ids = array();

	if ( ! empty( $attachment_record['attachment_id'] ) ) {
		$ids[] = (int) $attachment_record['attachment_id'];
	}

	if ( empty( $attachment_record['wpml']['translations'] ) || ! is_array( $attachment_record['wpml']['translations'] ) ) {
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	foreach ( $attachment_record['wpml']['translations'] as $translation ) {
		$translation_id = isset( $translation['post_id'] ) ? (int) $translation['post_id'] : 0;
		if ( $translation_id ) {
			$ids[] = $translation_id;
		}
	}

	$ids = array_values( array_unique( array_filter( $ids ) ) );
	sort( $ids );

	return $ids;
}

function old_posts_attachment_same_file_attachment_ids( $relative_file ) {
	global $wpdb;

	static $cache = array();

	$relative_file = ltrim( (string) $relative_file, '/\\' );
	if ( '' === $relative_file ) {
		return array();
	}

	if ( isset( $cache[ $relative_file ] ) ) {
		return $cache[ $relative_file ];
	}

	$sql = $wpdb->prepare(
		"SELECT DISTINCT pm.post_id
		FROM {$wpdb->postmeta} AS pm
			INNER JOIN {$wpdb->posts} AS p
				ON p.ID = pm.post_id
		WHERE pm.meta_key = '_wp_attached_file'
		AND pm.meta_value = %s
		AND p.post_type = 'attachment'
		AND p.post_status <> 'trash'",
		$relative_file
	);

	$ids                  = array_map( 'intval', $wpdb->get_col( $sql ) );
	$ids                  = array_values( array_unique( array_filter( $ids ) ) );
	sort( $ids );
	$cache[ $relative_file ] = $ids;

	return $cache[ $relative_file ];
}

function old_posts_attachment_runtime_record( $attachment_id, $attachment_record_map ) {
	$attachment_id = (int) $attachment_id;

	if ( ! empty( $attachment_record_map[ $attachment_id ] ) ) {
		return $attachment_record_map[ $attachment_id ];
	}

	return old_posts_attachment_record( $attachment_id );
}

function old_posts_attachment_group_delete_plan( $attachment_record, $attachment_record_map, $candidate_post_ids, $recheck_usage, $scan_postmeta, $root_exists = true, $skip_recheck_attachment_ids = array() ) {
	$attachment_id               = (int) $attachment_record['attachment_id'];
	$same_file_ids               = old_posts_attachment_same_file_attachment_ids( $attachment_record['file'] ?? '' );
	$wpml_translation_ids        = old_posts_attachment_wpml_translation_ids( $attachment_record );
	$outside_wpml_ids            = array_values( array_diff( $same_file_ids, $wpml_translation_ids ) );
	$delete_records              = array();
	$blocking_attachments        = array();
	$skip_recheck_attachment_ids = array_fill_keys( array_values( array_filter( array_map( 'intval', (array) $skip_recheck_attachment_ids ) ) ), true );

	if ( $root_exists ) {
		$delete_records[ $attachment_id ] = $attachment_record;
	}

	if ( count( $same_file_ids ) <= 1 && ! $root_exists ) {
		if ( ! empty( $same_file_ids ) ) {
			foreach ( $same_file_ids as $related_attachment_id ) {
				$related_attachment_id = (int) $related_attachment_id;
				$related_record        = old_posts_attachment_runtime_record( $related_attachment_id, $attachment_record_map );
				if ( is_array( $related_record ) ) {
					$delete_records[ $related_attachment_id ] = $related_record;
				}
			}
		} else {
			return array(
				'group_delete_enabled'         => false,
				'delete_records'               => $delete_records,
				'same_file_attachment_ids'     => $same_file_ids,
				'wpml_translation_ids'         => $wpml_translation_ids,
				'outside_wpml_attachment_ids'  => array(),
				'blocking_attachments'         => array(),
				'root_exists'                  => $root_exists,
			);
		}
	}

	if ( count( $same_file_ids ) <= 1 && $root_exists ) {
		return array(
			'group_delete_enabled'         => false,
			'delete_records'               => $delete_records,
			'same_file_attachment_ids'     => $same_file_ids,
			'wpml_translation_ids'         => $wpml_translation_ids,
			'outside_wpml_attachment_ids'  => array(),
			'blocking_attachments'         => array(),
			'root_exists'                  => $root_exists,
		);
	}

	if ( ! empty( $outside_wpml_ids ) ) {
		foreach ( $outside_wpml_ids as $outside_id ) {
			$blocking_attachments[] = array(
				'attachment_id' => (int) $outside_id,
				'reason'        => 'shared_file_outside_wpml_group',
			);
		}

		return array(
			'group_delete_enabled'         => false,
			'delete_records'               => $delete_records,
			'same_file_attachment_ids'     => $same_file_ids,
			'wpml_translation_ids'         => $wpml_translation_ids,
			'outside_wpml_attachment_ids'  => $outside_wpml_ids,
			'blocking_attachments'         => $blocking_attachments,
			'root_exists'                  => $root_exists,
		);
	}

	foreach ( $same_file_ids as $related_attachment_id ) {
		$related_attachment_id = (int) $related_attachment_id;
		$related_record        = $related_attachment_id === $attachment_id
			? $attachment_record
			: old_posts_attachment_runtime_record( $related_attachment_id, $attachment_record_map );

		if ( ! is_array( $related_record ) ) {
			$blocking_attachments[] = array(
				'attachment_id' => $related_attachment_id,
				'reason'        => 'missing_attachment_record',
			);
			continue;
		}

		if ( isset( $attachment_record_map[ $related_attachment_id ] ) && empty( $attachment_record_map[ $related_attachment_id ]['safe_to_delete'] ) ) {
			$blocking_attachments[] = array(
				'attachment_id' => $related_attachment_id,
				'reason'        => 'unsafe_in_manifest',
			);
			continue;
		}

		if ( ! empty( $related_record['post_parent'] ) && ! isset( $candidate_post_ids[ (int) $related_record['post_parent'] ] ) ) {
			$blocking_attachments[] = array(
				'attachment_id' => $related_attachment_id,
				'reason'        => 'non_candidate_parent',
				'post_parent'   => (int) $related_record['post_parent'],
			);
			continue;
		}

		if ( $recheck_usage && ! isset( $skip_recheck_attachment_ids[ $related_attachment_id ] ) ) {
			$external_usage = old_posts_attachment_has_external_usage( $related_record, $candidate_post_ids, $scan_postmeta );
			if ( null !== $external_usage ) {
				$blocking_attachments[] = array_merge(
					array(
						'attachment_id' => $related_attachment_id,
						'reason'        => 'external_usage_detected',
					),
					$external_usage
				);
				continue;
			}
		}

		$delete_records[ $related_attachment_id ] = $related_record;
	}

	$group_delete_enabled = empty( $blocking_attachments ) && (
		( $root_exists && count( $delete_records ) > 1 ) ||
		( ! $root_exists && count( $delete_records ) >= 1 )
	);

	if ( ! $group_delete_enabled ) {
		$delete_records = $root_exists
			? array( $attachment_id => $attachment_record )
			: array();
	} elseif ( isset( $delete_records[ $attachment_id ] ) ) {
		$root_record = $delete_records[ $attachment_id ];
		unset( $delete_records[ $attachment_id ] );
		ksort( $delete_records );
		$delete_records[ $attachment_id ] = $root_record;
	}

	return array(
		'group_delete_enabled'         => $group_delete_enabled,
		'delete_records'               => $delete_records,
		'same_file_attachment_ids'     => $same_file_ids,
		'wpml_translation_ids'         => $wpml_translation_ids,
		'outside_wpml_attachment_ids'  => $outside_wpml_ids,
		'blocking_attachments'         => $blocking_attachments,
		'root_exists'                  => $root_exists,
	);
}

function old_posts_increment_counters( &$processed, &$limit_counter, $count_toward_limit = true ) {
	++$processed;
	if ( $count_toward_limit ) {
		++$limit_counter;
	}
}

$cli_args = old_posts_collect_runtime_args(
	isset( $args ) && is_array( $args ) ? $args : array(),
	isset( $GLOBALS['argv'] ) && is_array( $GLOBALS['argv'] ) ? $GLOBALS['argv'] : array()
);
$duplicate_cli_args = old_posts_runtime_arg_duplicates();

$manifest_path = isset( $cli_args['manifest'] ) ? (string) $cli_args['manifest'] : old_posts_default_temp_path( 'old-posts-manifest.json' );
$phase         = isset( $cli_args['phase'] ) ? (string) $cli_args['phase'] : 'trash-posts';
$batch_size    = isset( $cli_args['batch-size'] ) ? max( 1, (int) $cli_args['batch-size'] ) : 25;
$year_filter   = isset( $cli_args['year'] ) ? (string) $cli_args['year'] : null;
$limit         = isset( $cli_args['limit'] ) ? max( 1, (int) $cli_args['limit'] ) : 0;
$dry_run       = old_posts_bool_arg( isset( $cli_args['dry-run'] ) ? $cli_args['dry-run'] : null, true );
$scan_postmeta = old_posts_bool_arg( isset( $cli_args['scan-postmeta'] ) ? $cli_args['scan-postmeta'] : null, false );
$recheck_usage = old_posts_bool_arg( isset( $cli_args['recheck-usage'] ) ? $cli_args['recheck-usage'] : null, true );
$limit_ignore_already_removed = old_posts_bool_arg( isset( $cli_args['limit-ignore-already-removed'] ) ? $cli_args['limit-ignore-already-removed'] : null, false );
$progress_cfg  = old_posts_progress_config( $cli_args, 25, 30 );
$log_path      = isset( $cli_args['log'] ) ? (string) $cli_args['log'] : old_posts_default_temp_path( 'old-posts-execution-log.jsonl' );
$output_path   = isset( $cli_args['output'] ) ? (string) $cli_args['output'] : old_posts_default_temp_path( 'old-posts-redirects.csv' );

$manifest = old_posts_read_manifest( $manifest_path );

if ( ! empty( $duplicate_cli_args ) ) {
	old_posts_log(
		'warning',
		'Some runtime arguments were provided more than once; the last value will be used.',
		array(
			'duplicates' => $duplicate_cli_args,
		)
	);
}

if ( trailingslashit( home_url( '/' ) ) !== trailingslashit( $manifest['site']['home_url'] ) ) {
	old_posts_fail(
		'The manifest was generated for a different site.',
		array(
			'current_home_url'  => home_url( '/' ),
			'manifest_home_url' => $manifest['site']['home_url'],
		)
	);
}

$expected_confirmation = old_posts_expected_confirmation( $manifest, $phase );
$provided_confirmation = isset( $cli_args['confirm'] ) ? (string) $cli_args['confirm'] : '';

if ( in_array( $phase, array( 'trash-posts', 'force-delete-posts', 'delete-attachments' ), true ) && ! $dry_run && $provided_confirmation !== $expected_confirmation ) {
	old_posts_fail(
		'Missing or invalid confirmation token.',
		array(
			'expected' => $expected_confirmation,
			'phase'    => $phase,
		)
	);
}

if ( 'export-redirects' === $phase ) {
	old_posts_assert_parent_writable( $output_path, 'redirect CSV output' );

	$rows = array( 'source_url,status,target_url,notes' );
	$exported_redirects = 0;
	$skipped_posts      = 0;
	foreach ( $manifest['posts'] as $post_record ) {
		if ( $year_filter && 0 !== strpos( (string) $post_record['post_date'], $year_filter ) ) {
			continue;
		}

		$redirect = $post_record['redirect'];
		if ( empty( $redirect['eligible_for_export'] ) ) {
			++$skipped_posts;
			continue;
		}

		$rows[]   = sprintf(
			'"%s","%s","%s","%s"',
			str_replace( '"', '""', $redirect['source_url'] ),
			str_replace( '"', '""', (string) $redirect['recommended_status'] ),
			str_replace( '"', '""', '410' === (string) $redirect['recommended_action'] ? '' : (string) $redirect['recommended_target'] ),
			str_replace( '"', '""', (string) $redirect['reason'] )
		);
		++$exported_redirects;
	}

	$result = file_put_contents( $output_path, implode( PHP_EOL, $rows ) . PHP_EOL );
	if ( false === $result ) {
		old_posts_fail( 'Could not write the redirects CSV.', array( 'path' => $output_path ) );
	}

	old_posts_log(
		'success',
		'Redirect CSV exported.',
		array(
			'output'             => $output_path,
			'exported_redirects' => $exported_redirects,
			'skipped_posts'      => $skipped_posts,
			'filter'             => 'eligible_for_export=true',
		)
	);
	exit( 0 );
}

old_posts_assert_parent_writable( $log_path, 'JSONL log output' );

$candidate_post_ids = array();
$post_records       = array();
foreach ( $manifest['posts'] as $post_record ) {
	if ( $year_filter && 0 !== strpos( (string) $post_record['post_date'], $year_filter ) ) {
		continue;
	}

	$candidate_post_ids[ (int) $post_record['post_id'] ] = true;
	$post_records[]                                      = $post_record;
}

$attachment_records = array();
foreach ( $manifest['attachments'] as $attachment_record ) {
	if ( $year_filter ) {
		$belongs_to_year = false;
		foreach ( $attachment_record['candidate_post_ids'] as $candidate_post_id ) {
			if ( isset( $candidate_post_ids[ (int) $candidate_post_id ] ) ) {
				$belongs_to_year = true;
				break;
			}
		}

		if ( ! $belongs_to_year ) {
			continue;
		}
	}

	$attachment_records[] = $attachment_record;
}

$attachment_record_map = array();
foreach ( $manifest['attachments'] as $attachment_record ) {
	$attachment_record_map[ (int) $attachment_record['attachment_id'] ] = $attachment_record;
}

$processed     = 0;
$limit_counter = 0;
$failures      = 0;
$total_records = 'delete-attachments' === $phase ? count( $attachment_records ) : count( $post_records );
$progress_goal = $limit > 0 ? $limit : $total_records;
$progress_state = old_posts_progress_state( $progress_cfg['every'], $progress_cfg['seconds'] );

old_posts_append_jsonl(
	$log_path,
	array(
		'timestamp'      => gmdate( 'c' ),
		'phase'          => $phase,
		'action'         => 'start_execution',
		'status'         => 'started',
		'dry_run'        => $dry_run,
		'year_filter'    => $year_filter,
		'batch_size'     => $batch_size,
		'limit'          => $limit,
		'post_records'   => count( $post_records ),
		'attachments'    => count( $attachment_records ),
		'recheck_usage'  => $recheck_usage,
		'duplicate_args' => $duplicate_cli_args,
		'limit_ignore_already_removed' => $limit_ignore_already_removed,
		'progress_every' => $progress_cfg['every'],
		'progress_seconds' => $progress_cfg['seconds'],
		'manifest_path'  => $manifest_path,
	)
);

if ( 'trash-posts' === $phase || 'force-delete-posts' === $phase ) {
	$stop_processing = false;
	foreach ( array_chunk( $post_records, $batch_size ) as $post_batch ) {
		$processed_object_ids = array();
		foreach ( $post_batch as $post_record ) {
			if ( $limit && $limit_counter >= $limit ) {
				$stop_processing = true;
				break;
			}

			$post_id = (int) $post_record['post_id'];
			$post    = get_post( $post_id );
			$processed_object_ids[] = $post_id;

			if ( 'trash-posts' === $phase && $post instanceof WP_Post && 'trash' === $post->post_status ) {
				old_posts_log( 'info', 'Post is already in the trash. Skipping.', array( 'post_id' => $post_id ) );
				old_posts_append_jsonl(
					$log_path,
					array(
						'timestamp' => gmdate( 'c' ),
						'phase'     => $phase,
						'action'    => 'skip_post',
						'status'    => 'already_in_trash',
						'post_id'   => $post_id,
						'dry_run'   => $dry_run,
					)
				);
				old_posts_increment_counters( $processed, $limit_counter );
				old_posts_progress_maybe_log(
					$progress_state,
					'Execution progress.',
					$limit > 0 ? $limit_counter : $processed,
					$progress_goal,
					array(
						'processed_records' => $processed,
						'total_records'     => $total_records,
						'phase'             => $phase,
						'failures'          => $failures,
					),
					array(
						'phase'    => $phase,
						'log_path' => $log_path,
						'action'   => 'progress_checkpoint',
					)
				);
				continue;
			}

			if ( 'force-delete-posts' === $phase && ! $post ) {
				old_posts_log( 'info', 'Post is already gone. Skipping.', array( 'post_id' => $post_id ) );
				old_posts_append_jsonl(
					$log_path,
					array(
						'timestamp' => gmdate( 'c' ),
						'phase'     => $phase,
						'action'    => 'skip_post',
						'status'    => 'already_removed',
						'post_id'   => $post_id,
						'dry_run'   => $dry_run,
					)
				);
				old_posts_increment_counters( $processed, $limit_counter, ! $limit_ignore_already_removed );
				old_posts_progress_maybe_log(
					$progress_state,
					'Execution progress.',
					$limit > 0 ? $limit_counter : $processed,
					$progress_goal,
					array(
						'processed_records' => $processed,
						'total_records'     => $total_records,
						'phase'             => $phase,
						'failures'          => $failures,
					),
					array(
						'phase'    => $phase,
						'log_path' => $log_path,
						'action'   => 'progress_checkpoint',
					)
				);
				continue;
			}

			if ( ! $post instanceof WP_Post ) {
				old_posts_log( 'error', 'Post not found.', array( 'post_id' => $post_id ) );
				old_posts_append_jsonl(
					$log_path,
					array(
						'timestamp' => gmdate( 'c' ),
						'phase'     => $phase,
						'action'    => 'skip_post',
						'status'    => 'post_not_found',
						'post_id'   => $post_id,
						'dry_run'   => $dry_run,
					)
				);
				++$failures;
				old_posts_increment_counters( $processed, $limit_counter );
				old_posts_progress_maybe_log(
					$progress_state,
					'Execution progress.',
					$limit > 0 ? $limit_counter : $processed,
					$progress_goal,
					array(
						'processed_records' => $processed,
						'total_records'     => $total_records,
						'phase'             => $phase,
						'failures'          => $failures,
					),
					array(
						'phase'    => $phase,
						'log_path' => $log_path,
						'action'   => 'progress_checkpoint',
					)
				);
				continue;
			}

			if ( 'force-delete-posts' === $phase && 'trash' !== $post->post_status ) {
				old_posts_log( 'error', 'Force delete can run only on posts that are already in the trash.', array( 'post_id' => $post_id, 'status' => $post->post_status ) );
				old_posts_append_jsonl(
					$log_path,
					array(
						'timestamp' => gmdate( 'c' ),
						'phase'     => $phase,
						'action'    => 'skip_post',
						'status'    => 'not_in_trash',
						'post_id'   => $post_id,
						'dry_run'   => $dry_run,
					)
				);
				++$failures;
				old_posts_increment_counters( $processed, $limit_counter );
				old_posts_progress_maybe_log(
					$progress_state,
					'Execution progress.',
					$limit > 0 ? $limit_counter : $processed,
					$progress_goal,
					array(
						'processed_records' => $processed,
						'total_records'     => $total_records,
						'phase'             => $phase,
						'failures'          => $failures,
					),
					array(
						'phase'    => $phase,
						'log_path' => $log_path,
						'action'   => 'progress_checkpoint',
					)
				);
				continue;
			}

			if ( ! old_posts_post_matches_manifest( $post_id, $post_record['plain_text_length'], $post_record['fingerprint'] ) ) {
				old_posts_log( 'error', 'Post content no longer matches the manifest. Generate a new manifest before proceeding.', array( 'post_id' => $post_id ) );
				old_posts_append_jsonl(
					$log_path,
					array(
						'timestamp' => gmdate( 'c' ),
						'phase'     => $phase,
						'action'    => 'skip_post',
						'status'    => 'manifest_mismatch',
						'post_id'   => $post_id,
						'dry_run'   => $dry_run,
					)
				);
				++$failures;
				old_posts_increment_counters( $processed, $limit_counter );
				old_posts_progress_maybe_log(
					$progress_state,
					'Execution progress.',
					$limit > 0 ? $limit_counter : $processed,
					$progress_goal,
					array(
						'processed_records' => $processed,
						'total_records'     => $total_records,
						'phase'             => $phase,
						'failures'          => $failures,
					),
					array(
						'phase'    => $phase,
						'log_path' => $log_path,
						'action'   => 'progress_checkpoint',
					)
				);
				continue;
			}

			$action = 'trash-posts' === $phase ? 'trash_post' : 'force_delete_post';
			$status = 'dry-run';

			if ( ! $dry_run ) {
				$result = 'trash-posts' === $phase ? wp_trash_post( $post_id ) : wp_delete_post( $post_id, true );
				if ( false === $result || null === $result ) {
					$status = 'failed';
					++$failures;
				} else {
					$status = 'ok';
				}
			}

			old_posts_append_jsonl(
				$log_path,
				array(
					'timestamp' => gmdate( 'c' ),
					'phase'     => $phase,
					'action'    => $action,
					'status'    => $status,
					'post_id'   => $post_id,
					'dry_run'   => $dry_run,
				)
			);

			old_posts_increment_counters( $processed, $limit_counter );
			old_posts_progress_maybe_log(
				$progress_state,
				'Execution progress.',
				$limit > 0 ? $limit_counter : $processed,
				$progress_goal,
				array(
					'processed_records' => $processed,
					'total_records'     => $total_records,
					'phase'             => $phase,
					'failures'          => $failures,
				),
				array(
					'phase'    => $phase,
					'log_path' => $log_path,
					'action'   => 'progress_checkpoint',
				)
			);
		}

		// Long destructive runs can accumulate runtime cache entries unless we clear them between batches.
		old_posts_release_wp_memory( $processed_object_ids );

		if ( $stop_processing ) {
			break;
		}
	}
}

if ( 'delete-attachments' === $phase ) {
	$attachment_records_processed = 0;
	$stop_processing = false;
	foreach ( array_chunk( $attachment_records, $batch_size ) as $attachment_batch ) {
		$processed_object_ids = array();
		foreach ( $attachment_batch as $attachment_record ) {
			if ( $limit && $limit_counter >= $limit ) {
				$stop_processing = true;
				break;
			}

			$attachment_progress_index = $attachment_records_processed + 1;
			$attachment_id          = (int) $attachment_record['attachment_id'];
			$attachment             = get_post( $attachment_id );
			$root_exists            = $attachment instanceof WP_Post;
			$skip_group_recheck_ids = array();
			$processed_object_ids[] = $attachment_id;

			if ( empty( $attachment_record['safe_to_delete'] ) ) {
				old_posts_log( 'error', 'Attachment is marked unsafe in the manifest.', array( 'attachment_id' => $attachment_id ) );
				old_posts_append_jsonl(
					$log_path,
					array(
						'timestamp'     => gmdate( 'c' ),
						'phase'         => $phase,
						'action'        => 'skip_attachment',
						'status'        => 'unsafe_in_manifest',
						'attachment_id' => $attachment_id,
						'dry_run'       => $dry_run,
					)
				);
				++$failures;
				old_posts_increment_counters( $processed, $limit_counter );
				$attachment_records_processed = $attachment_progress_index;
				old_posts_progress_maybe_log(
					$progress_state,
					'Execution progress.',
					$limit > 0 ? $limit_counter : $attachment_records_processed,
					$progress_goal,
					array(
						'processed_records' => $attachment_records_processed,
						'total_records'     => $total_records,
						'processed_attachments' => $processed,
						'phase'             => $phase,
						'failures'          => $failures,
					),
					array(
						'phase'    => $phase,
						'log_path' => $log_path,
						'action'   => 'progress_checkpoint',
					)
				);
				continue;
			}

			if ( $recheck_usage ) {
				$external_usage = old_posts_attachment_has_external_usage( $attachment_record, $candidate_post_ids, $scan_postmeta );
				if ( null !== $external_usage ) {
					old_posts_log( 'error', 'External usage was detected during preflight. The attachment will not be removed.', array_merge( array( 'attachment_id' => $attachment_id ), $external_usage ) );
					old_posts_append_jsonl(
						$log_path,
						array_merge(
							array(
								'timestamp'     => gmdate( 'c' ),
								'phase'         => $phase,
								'action'        => 'skip_attachment',
								'status'        => 'external_usage_detected',
								'attachment_id' => $attachment_id,
								'dry_run'       => $dry_run,
							),
							$external_usage
						)
					);
					++$failures;
					old_posts_increment_counters( $processed, $limit_counter );
					$attachment_records_processed = $attachment_progress_index;
					old_posts_progress_maybe_log(
						$progress_state,
						'Execution progress.',
						$limit > 0 ? $limit_counter : $attachment_records_processed,
						$progress_goal,
						array(
							'processed_records' => $attachment_records_processed,
							'total_records'     => $total_records,
							'processed_attachments' => $processed,
							'phase'             => $phase,
							'failures'          => $failures,
						),
						array(
							'phase'    => $phase,
							'log_path' => $log_path,
							'action'   => 'progress_checkpoint',
						)
					);
					continue;
				}

				$skip_group_recheck_ids[] = $attachment_id;
			}

			$group_plan = old_posts_attachment_group_delete_plan(
				$attachment_record,
				$attachment_record_map,
				$candidate_post_ids,
				$recheck_usage,
				$scan_postmeta,
				$root_exists,
				$skip_group_recheck_ids
			);

			$logged_root_already_removed = false;

			if ( ! $root_exists ) {
				if ( empty( $group_plan['delete_records'] ) ) {
					old_posts_log( 'info', 'Attachment is already gone. Skipping.', array( 'attachment_id' => $attachment_id ) );
					old_posts_append_jsonl(
						$log_path,
						array(
							'timestamp'     => gmdate( 'c' ),
							'phase'         => $phase,
							'action'        => 'skip_attachment',
							'status'        => 'already_removed',
							'attachment_id' => $attachment_id,
							'dry_run'       => $dry_run,
						)
					);
					old_posts_increment_counters( $processed, $limit_counter, ! $limit_ignore_already_removed );
					$attachment_records_processed = $attachment_progress_index;
					old_posts_progress_maybe_log(
						$progress_state,
						'Execution progress.',
						$limit > 0 ? $limit_counter : $attachment_records_processed,
						$progress_goal,
						array(
							'processed_records' => $attachment_records_processed,
							'total_records'     => $total_records,
							'processed_attachments' => $processed,
							'phase'             => $phase,
							'failures'          => $failures,
						),
						array(
							'phase'    => $phase,
							'log_path' => $log_path,
							'action'   => 'progress_checkpoint',
						)
					);
					continue;
				}

				old_posts_log(
					'info',
					'The manifest root attachment is already gone; this rerun will try to finish the surviving WPML group that shares the same file.',
					array(
						'attachment_id'        => $attachment_id,
						'group_attachment_ids' => array_values( array_map( 'intval', array_keys( $group_plan['delete_records'] ) ) ),
						'shared_file'          => $attachment_record['file'] ?? '',
					)
				);
				old_posts_append_jsonl(
					$log_path,
					array(
						'timestamp'                 => gmdate( 'c' ),
						'phase'                     => $phase,
						'action'                    => 'skip_attachment',
						'status'                    => 'already_removed',
						'attachment_id'             => $attachment_id,
						'dry_run'                   => $dry_run,
						'group_followup'            => true,
						'group_delete_candidate'    => true,
						'shared_file'               => $attachment_record['file'] ?? '',
						'shared_file_attachment_ids' => $group_plan['same_file_attachment_ids'],
					)
				);
				$logged_root_already_removed = true;
			}

			if ( $group_plan['group_delete_enabled'] ) {
				old_posts_log(
					'info',
					'A WPML attachment group that shares the same file will be deleted together.',
					array(
						'attachment_id'         => $attachment_id,
						'group_attachment_ids'  => array_values( array_map( 'intval', array_keys( $group_plan['delete_records'] ) ) ),
						'shared_file'           => $attachment_record['file'] ?? '',
						'root_exists'           => $root_exists,
					)
				);
			} elseif ( count( $group_plan['same_file_attachment_ids'] ) > 1 ) {
				old_posts_log(
					'warning',
					'The physical file will remain on disk because live attachment references still point to it.',
					array(
						'attachment_id'                => $attachment_id,
						'shared_file_attachment_ids'   => $group_plan['same_file_attachment_ids'],
						'wpml_translation_ids'         => $group_plan['wpml_translation_ids'],
						'outside_wpml_attachment_ids'  => $group_plan['outside_wpml_attachment_ids'],
						'blocking_attachments'         => $group_plan['blocking_attachments'],
						'shared_file'                  => $attachment_record['file'] ?? '',
						'root_exists'                  => $root_exists,
					)
				);
				if ( ! $logged_root_already_removed && ! $root_exists ) {
					old_posts_append_jsonl(
						$log_path,
						array(
							'timestamp'                 => gmdate( 'c' ),
							'phase'                     => $phase,
							'action'                    => 'skip_attachment',
							'status'                    => 'already_removed',
							'attachment_id'             => $attachment_id,
							'dry_run'                   => $dry_run,
							'group_followup'            => true,
							'group_delete_candidate'    => false,
							'shared_file'               => $attachment_record['file'] ?? '',
							'shared_file_attachment_ids' => $group_plan['same_file_attachment_ids'],
						)
					);
					$logged_root_already_removed = true;
				}
				old_posts_append_jsonl(
					$log_path,
					array(
						'timestamp'                   => gmdate( 'c' ),
						'phase'                       => $phase,
						'action'                      => 'attachment_file_retained',
						'status'                      => 'shared_attachment_references',
						'attachment_id'               => $attachment_id,
						'dry_run'                     => $dry_run,
						'file'                        => $attachment_record['file'] ?? '',
						'group_delete_enabled'        => false,
						'shared_file_attachment_ids'  => $group_plan['same_file_attachment_ids'],
						'wpml_translation_ids'        => $group_plan['wpml_translation_ids'],
						'outside_wpml_attachment_ids' => $group_plan['outside_wpml_attachment_ids'],
						'blocking_attachments'        => $group_plan['blocking_attachments'],
						'root_exists'                 => $root_exists,
					)
				);
			}

			if ( ! $root_exists && empty( $group_plan['delete_records'] ) ) {
				old_posts_increment_counters( $processed, $limit_counter );
				$attachment_records_processed = $attachment_progress_index;
				old_posts_progress_maybe_log(
					$progress_state,
					'Execution progress.',
					$limit > 0 ? $limit_counter : $attachment_records_processed,
					$progress_goal,
					array(
						'processed_records' => $attachment_records_processed,
						'total_records'     => $total_records,
						'processed_attachments' => $processed,
						'phase'             => $phase,
						'failures'          => $failures,
					),
					array(
						'phase'    => $phase,
						'log_path' => $log_path,
						'action'   => 'progress_checkpoint',
					)
				);
				continue;
			}

			$root_counted = false;
			foreach ( $group_plan['delete_records'] as $delete_attachment_id => $delete_attachment_record ) {
				$processed_object_ids[] = (int) $delete_attachment_id;
				$status = 'dry-run';
				if ( ! $dry_run ) {
					$result = wp_delete_attachment( $delete_attachment_id, true );
					if ( false === $result || null === $result ) {
						$status = 'failed';
						++$failures;
					} else {
						$status = 'ok';
					}
				}

				old_posts_append_jsonl(
					$log_path,
					array(
						'timestamp'               => gmdate( 'c' ),
						'phase'                   => $phase,
						'action'                  => 'delete_attachment',
						'status'                  => $status,
						'attachment_id'           => (int) $delete_attachment_id,
						'dry_run'                 => $dry_run,
						'file'                    => $delete_attachment_record['file'] ?? '',
						'guid'                    => $delete_attachment_record['guid'] ?? '',
						'derived_prefix'          => old_posts_attachment_derived_prefix( $delete_attachment_record ),
						'group_root_attachment_id' => $attachment_id,
						'deletion_scope'          => $group_plan['group_delete_enabled'] ? 'wpml_file_group' : 'single_attachment',
						'shared_file_group_size'  => count( $group_plan['delete_records'] ),
						'root_exists'             => $root_exists,
					)
				);

				$count_toward_limit = false;
				if ( $root_exists ) {
					$count_toward_limit = (int) $delete_attachment_id === $attachment_id;
				} elseif ( ! $root_counted ) {
					$count_toward_limit = true;
					$root_counted       = true;
				}

				old_posts_increment_counters( $processed, $limit_counter, $count_toward_limit );
				$attachment_records_processed = $attachment_progress_index;
				old_posts_progress_maybe_log(
					$progress_state,
					'Execution progress.',
					$limit > 0 ? $limit_counter : $attachment_records_processed,
					$progress_goal,
					array(
						'processed_records' => $attachment_records_processed,
						'total_records'     => $total_records,
						'processed_attachments' => $processed,
						'phase'             => $phase,
						'failures'          => $failures,
					),
					array(
						'phase'    => $phase,
						'log_path' => $log_path,
						'action'   => 'progress_checkpoint',
					)
				);
			}

		}

		old_posts_release_wp_memory( $processed_object_ids );

		if ( $stop_processing ) {
			break;
		}
	}
}

$progress_current = $limit > 0 ? $limit_counter : $processed;
$progress_context = array(
	'processed_records' => $processed,
	'total_records'     => $total_records,
	'phase'             => $phase,
	'failures'          => $failures,
);

if ( 'delete-attachments' === $phase ) {
	$progress_current                       = $limit > 0 ? $limit_counter : $attachment_records_processed;
	$progress_context['processed_records']  = $attachment_records_processed;
	$progress_context['processed_attachments'] = $processed;
}

old_posts_progress_maybe_log(
	$progress_state,
	'Execution progress complete.',
	$progress_current,
	$progress_goal,
	$progress_context,
	array(
		'phase'    => $phase,
		'log_path' => $log_path,
		'action'   => 'progress_checkpoint',
		'force'    => true,
	)
);

old_posts_log(
	$failures ? 'warning' : 'success',
	'Execution finished.',
	array(
		'phase'      => $phase,
		'dry_run'    => $dry_run,
		'processed'  => $processed,
		'counted_toward_limit' => $limit_counter,
		'failures'   => $failures,
		'log'        => $log_path,
	)
);

old_posts_append_jsonl(
	$log_path,
	array(
		'timestamp'   => gmdate( 'c' ),
		'phase'       => $phase,
		'action'      => 'finish_execution',
		'status'      => $failures ? 'warning' : 'success',
		'dry_run'     => $dry_run,
		'processed'   => $processed,
		'counted_toward_limit' => $limit_counter,
		'failures'    => $failures,
		'year_filter' => $year_filter,
	)
);
