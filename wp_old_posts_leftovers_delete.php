<?php

require_once __DIR__ . '/wp_old_posts_common.php';

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run with WP-CLI: wp eval-file wp_old_posts_leftovers_delete.php key=value ...\n" );
	exit( 1 );
}

if ( ! function_exists( 'old_posts_leftovers_delete_confirmation' ) ) {
	function old_posts_leftovers_delete_confirmation( $report_path ) {
		return 'CONFIRM-' . strtoupper( substr( sha1( (string) $report_path . '|delete-leftovers' ), 0, 8 ) );
	}
}

if ( ! function_exists( 'old_posts_leftovers_delete_read_report' ) ) {
	function old_posts_leftovers_delete_read_report( $path ) {
		if ( ! file_exists( $path ) ) {
			old_posts_fail( 'Leftovers report not found.', array( 'path' => $path ) );
		}

		$content = file_get_contents( $path );
		$data    = json_decode( $content, true );

		if ( ! is_array( $data ) || empty( $data['items'] ) || ! is_array( $data['items'] ) ) {
			old_posts_fail( 'Invalid leftovers report.', array( 'path' => $path ) );
		}

		return $data;
	}
}

if ( ! function_exists( 'old_posts_leftovers_delete_allowed_map' ) ) {
	function old_posts_leftovers_delete_allowed_map( $report ) {
		$allowed = array();
		$reference_lookup_files = array();

		foreach ( $report['items'] as $item ) {
			if ( ! empty( $item['relative_file'] ) ) {
				$reference_lookup_files[] = (string) $item['relative_file'];
			}

			if ( empty( $item['leftover_files'] ) || ! is_array( $item['leftover_files'] ) ) {
				continue;
			}

			foreach ( $item['leftover_files'] as $leftover ) {
				if ( ! empty( $leftover['relative'] ) ) {
					$reference_lookup_files[] = (string) $leftover['relative'];
				}
			}
		}

		$live_attachment_ids_map = old_posts_live_attachment_ids_map( $reference_lookup_files );

		foreach ( $report['items'] as $item ) {
			if ( empty( $item['leftover_files'] ) || ! is_array( $item['leftover_files'] ) ) {
				continue;
			}

			foreach ( $item['leftover_files'] as $leftover ) {
				$absolute = isset( $leftover['path'] ) ? (string) $leftover['path'] : '';
				$relative = isset( $leftover['relative'] ) ? (string) $leftover['relative'] : '';
				if ( '' === $absolute ) {
					continue;
				}

				$allowed[ $absolute ] = array(
					'path'                                 => $absolute,
					'relative'                             => $relative,
					'attachment_id'                        => isset( $item['attachment_id'] ) ? (int) $item['attachment_id'] : 0,
					'derived_prefix'                       => isset( $item['derived_prefix'] ) ? (string) $item['derived_prefix'] : '',
					'post_parent'                          => isset( $item['post_parent'] ) ? (int) $item['post_parent'] : 0,
					'base_relative_file'                   => isset( $item['relative_file'] ) ? (string) $item['relative_file'] : '',
					'base_still_referenced_attachment_ids' => $live_attachment_ids_map[ ltrim( isset( $item['relative_file'] ) ? (string) $item['relative_file'] : '', '/\\' ) ] ?? array(),
					'still_referenced_attachment_ids'      => $live_attachment_ids_map[ ltrim( $relative, '/\\' ) ] ?? array(),
				);
			}
		}

		return $allowed;
	}
}

if ( ! function_exists( 'old_posts_leftovers_delete_read_selection' ) ) {
	function old_posts_leftovers_delete_read_selection( $path ) {
		if ( ! file_exists( $path ) ) {
			old_posts_fail( 'Selection file not found.', array( 'path' => $path ) );
		}

		$lines = file( $path, FILE_IGNORE_NEW_LINES );
		if ( false === $lines ) {
			old_posts_fail( 'Could not read the selection file.', array( 'path' => $path ) );
		}

		$entries = array();
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}

			$entries[] = $line;
		}

		if ( empty( $entries ) ) {
			old_posts_fail( 'The selection file is empty.', array( 'path' => $path ) );
		}

		return array_values( array_unique( $entries ) );
	}
}

if ( ! function_exists( 'old_posts_leftovers_delete_resolve_entry' ) ) {
	function old_posts_leftovers_delete_resolve_entry( $entry, $allowed_map, $uploads_basedir ) {
		if ( isset( $allowed_map[ $entry ] ) ) {
			return $allowed_map[ $entry ];
		}

		$normalized = ltrim( str_replace( '\\', '/', (string) $entry ), '/' );
		foreach ( $allowed_map as $absolute => $metadata ) {
			if ( $normalized === ltrim( str_replace( '\\', '/', (string) $metadata['relative'] ), '/' ) ) {
				return $metadata;
			}
		}

		if ( '' !== $uploads_basedir ) {
			$absolute = rtrim( $uploads_basedir, '/\\' ) . '/' . $normalized;
			if ( isset( $allowed_map[ $absolute ] ) ) {
				return $allowed_map[ $absolute ];
			}
		}

		return null;
	}
}

$cli_args      = old_posts_collect_runtime_args(
	isset( $args ) && is_array( $args ) ? $args : array(),
	isset( $GLOBALS['argv'] ) && is_array( $GLOBALS['argv'] ) ? $GLOBALS['argv'] : array()
);
$report_path   = isset( $cli_args['report'] ) ? (string) $cli_args['report'] : old_posts_default_temp_path( 'old-posts-leftovers-report.json' );
$selection_path = isset( $cli_args['selection'] ) ? (string) $cli_args['selection'] : '';
$dry_run       = old_posts_bool_arg( isset( $cli_args['dry-run'] ) ? $cli_args['dry-run'] : null, true );
$log_path      = isset( $cli_args['log'] ) ? (string) $cli_args['log'] : old_posts_default_temp_path( 'old-posts-leftovers-delete-log.jsonl' );
$confirm       = isset( $cli_args['confirm'] ) ? (string) $cli_args['confirm'] : '';
$progress_cfg  = old_posts_progress_config( $cli_args, 25, 30 );

if ( '' === $selection_path ) {
	old_posts_fail( 'Provide selection= with the path to the approved files list.' );
}

old_posts_assert_parent_writable( $log_path, 'leftovers JSONL log' );

$expected_confirmation = old_posts_leftovers_delete_confirmation( $report_path );
if ( ! $dry_run && $confirm !== $expected_confirmation ) {
	old_posts_fail(
		'Missing or invalid confirmation token.',
		array(
			'expected' => $expected_confirmation,
			'phase'    => 'delete-leftovers',
		)
	);
}

$report        = old_posts_leftovers_delete_read_report( $report_path );
$allowed_map   = old_posts_leftovers_delete_allowed_map( $report );
$selection     = old_posts_leftovers_delete_read_selection( $selection_path );
$uploads       = wp_get_upload_dir();
$uploads_basedir = $uploads['basedir'] ?? '';

old_posts_append_jsonl(
	$log_path,
	array(
		'timestamp'      => gmdate( 'c' ),
		'phase'          => 'delete-leftovers',
		'action'         => 'start_execution',
		'status'         => 'started',
		'dry_run'        => $dry_run,
		'report'         => $report_path,
		'selection'      => $selection_path,
		'selected_count' => count( $selection ),
	)
);

$processed = 0;
$failures  = 0;
$progress_state = old_posts_progress_state( $progress_cfg['every'], $progress_cfg['seconds'] );

foreach ( $selection as $entry ) {
	$resolved = old_posts_leftovers_delete_resolve_entry( $entry, $allowed_map, $uploads_basedir );

	if ( null === $resolved ) {
		old_posts_log( 'error', 'Selected file does not belong to the leftovers report.', array( 'entry' => $entry ) );
		old_posts_append_jsonl(
			$log_path,
			array(
				'timestamp' => gmdate( 'c' ),
				'phase'     => 'delete-leftovers',
				'action'    => 'skip_leftover',
				'status'    => 'not_in_report',
				'entry'     => $entry,
				'dry_run'   => $dry_run,
			)
		);
		++$failures;
		++$processed;
		old_posts_progress_maybe_log(
			$progress_state,
			'Deleting approved leftovers.',
			$processed,
			count( $selection ),
			array(
				'failures' => $failures,
			),
			array(
				'phase'    => 'delete-leftovers',
				'log_path' => $log_path,
				'action'   => 'progress_checkpoint',
			)
		);
		continue;
	}

	$path   = $resolved['path'];
	$status = 'dry-run';

	if ( ! empty( $resolved['base_still_referenced_attachment_ids'] ) || ! empty( $resolved['still_referenced_attachment_ids'] ) ) {
		old_posts_log(
			'warning',
			'The selected file will not be removed because live attachments still reference it.',
			array(
				'entry'                               => $entry,
				'path'                                => $path,
				'base_relative_file'                  => $resolved['base_relative_file'],
				'base_still_referenced_attachment_ids' => $resolved['base_still_referenced_attachment_ids'],
				'still_referenced_attachment_ids'      => $resolved['still_referenced_attachment_ids'],
			)
		);
		old_posts_append_jsonl(
			$log_path,
			array(
				'timestamp'                     => gmdate( 'c' ),
				'phase'                         => 'delete-leftovers',
				'action'                        => 'skip_leftover',
				'status'                        => 'still_referenced_by_attachment',
				'entry'                         => $entry,
				'dry_run'                       => $dry_run,
				'path'                          => $path,
				'relative'                      => $resolved['relative'],
				'base_relative_file'            => $resolved['base_relative_file'],
				'attachment_id'                 => $resolved['attachment_id'],
				'base_still_referenced_attachment_ids' => $resolved['base_still_referenced_attachment_ids'],
				'still_referenced_attachment_ids'      => $resolved['still_referenced_attachment_ids'],
			)
		);
		++$failures;
		++$processed;
		old_posts_progress_maybe_log(
			$progress_state,
			'Deleting approved leftovers.',
			$processed,
			count( $selection ),
			array(
				'failures' => $failures,
			),
			array(
				'phase'    => 'delete-leftovers',
				'log_path' => $log_path,
				'action'   => 'progress_checkpoint',
			)
		);
		continue;
	}

	if ( ! file_exists( $path ) ) {
		$status = 'already_missing';
	} elseif ( ! $dry_run ) {
		$status = @unlink( $path ) ? 'ok' : 'failed';
		if ( 'failed' === $status ) {
			++$failures;
		}
	}

	old_posts_append_jsonl(
		$log_path,
		array(
			'timestamp'      => gmdate( 'c' ),
			'phase'          => 'delete-leftovers',
			'action'         => 'delete_leftover_file',
			'status'         => $status,
			'dry_run'        => $dry_run,
			'path'           => $path,
			'relative'       => $resolved['relative'],
			'attachment_id'  => $resolved['attachment_id'],
			'derived_prefix' => $resolved['derived_prefix'],
			'post_parent'    => $resolved['post_parent'],
		)
	);

	++$processed;
	old_posts_progress_maybe_log(
		$progress_state,
		'Deleting approved leftovers.',
		$processed,
		count( $selection ),
		array(
			'failures' => $failures,
		),
		array(
			'phase'    => 'delete-leftovers',
			'log_path' => $log_path,
			'action'   => 'progress_checkpoint',
		)
	);
}

old_posts_progress_maybe_log(
	$progress_state,
	'Leftovers deletion complete.',
	$processed,
	count( $selection ),
	array(
		'failures' => $failures,
	),
	array(
		'phase'    => 'delete-leftovers',
		'log_path' => $log_path,
		'action'   => 'progress_checkpoint',
		'force'    => true,
	)
);

old_posts_append_jsonl(
	$log_path,
	array(
		'timestamp' => gmdate( 'c' ),
		'phase'     => 'delete-leftovers',
		'action'    => 'finish_execution',
		'status'    => $failures ? 'warning' : 'success',
		'dry_run'   => $dry_run,
		'processed' => $processed,
		'failures'  => $failures,
	)
);

old_posts_log(
	$failures ? 'warning' : 'success',
	'Leftovers deletion finished.',
	array(
		'dry_run'   => $dry_run,
		'processed' => $processed,
		'failures'  => $failures,
		'log'       => $log_path,
	)
);
