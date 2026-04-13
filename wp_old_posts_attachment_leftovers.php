<?php

require_once __DIR__ . '/wp_old_posts_common.php';

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run with WP-CLI: wp eval-file wp_old_posts_attachment_leftovers.php key=value ...\n" );
	exit( 1 );
}

if ( ! function_exists( 'old_posts_leftovers_log_paths' ) ) {
	function old_posts_leftovers_log_paths( $cli_args ) {
		$paths = array();

		if ( ! empty( $cli_args['log'] ) ) {
			$paths = old_posts_csv_arg( $cli_args['log'] );
		} elseif ( ! empty( $cli_args['log-glob'] ) ) {
			$globbed = glob( (string) $cli_args['log-glob'] );
			if ( false !== $globbed ) {
				$paths = $globbed;
			}
		} elseif ( ! empty( $cli_args['year'] ) ) {
			$paths[] = old_posts_default_temp_path( 'old-posts-execution-log-attachments-' . $cli_args['year'] . '.jsonl' );
		}

		$paths = array_values( array_unique( array_filter( $paths, 'strlen' ) ) );

		if ( empty( $paths ) ) {
			old_posts_fail(
				'Provide log=, log-glob=, or year= so the script can find delete-attachments JSONL logs.'
			);
		}

		foreach ( $paths as $path ) {
			if ( ! file_exists( $path ) ) {
				old_posts_fail( 'JSONL log not found.', array( 'path' => $path ) );
			}
		}

		return $paths;
	}
}

if ( ! function_exists( 'old_posts_leftovers_attachment_record_map' ) ) {
	function old_posts_leftovers_attachment_record_map( $manifest ) {
		$map = array();

		foreach ( $manifest['attachments'] as $attachment_record ) {
			$map[ (int) $attachment_record['attachment_id'] ] = $attachment_record;
		}

		return $map;
	}
}

if ( ! function_exists( 'old_posts_leftovers_deleted_attachment_ids' ) ) {
	function old_posts_leftovers_deleted_attachment_ids( $log_paths ) {
		$records = array();

		foreach ( $log_paths as $log_path ) {
			$handle = fopen( $log_path, 'r' );
			if ( false === $handle ) {
				old_posts_fail( 'Could not open the JSONL log.', array( 'path' => $log_path ) );
			}

			while ( false !== ( $line = fgets( $handle ) ) ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}

				$entry = json_decode( $line, true );
				if ( ! is_array( $entry ) ) {
					continue;
				}

				if ( 'delete-attachments' !== ( $entry['phase'] ?? null ) ) {
					continue;
				}

				if ( 'delete_attachment' !== ( $entry['action'] ?? null ) ) {
					continue;
				}

				if ( 'ok' !== ( $entry['status'] ?? null ) ) {
					continue;
				}

				if ( ! empty( $entry['dry_run'] ) ) {
					continue;
				}

				$attachment_id = isset( $entry['attachment_id'] ) ? (int) $entry['attachment_id'] : 0;
				if ( $attachment_id ) {
					$records[ $attachment_id ] = array(
						'attachment_id'            => $attachment_id,
						'file'                     => isset( $entry['file'] ) ? (string) $entry['file'] : '',
						'guid'                     => isset( $entry['guid'] ) ? (string) $entry['guid'] : '',
						'derived_prefix'           => isset( $entry['derived_prefix'] ) ? (string) $entry['derived_prefix'] : '',
						'group_root_attachment_id' => isset( $entry['group_root_attachment_id'] ) ? (int) $entry['group_root_attachment_id'] : 0,
					);
				}
			}

			fclose( $handle );
		}

		ksort( $records );

		return $records;
	}
}

if ( ! function_exists( 'old_posts_leftovers_relative_file' ) ) {
	function old_posts_leftovers_relative_file( $attachment_record ) {
		if ( ! empty( $attachment_record['file'] ) ) {
			return ltrim( (string) $attachment_record['file'], '/\\' );
		}

		if ( ! empty( $attachment_record['guid'] ) ) {
			$parts = wp_parse_url( (string) $attachment_record['guid'] );
			if ( ! empty( $parts['path'] ) && false !== strpos( $parts['path'], '/uploads/' ) ) {
				return ltrim( substr( $parts['path'], strpos( $parts['path'], '/uploads/' ) + 9 ), '/\\' );
			}
		}

		return '';
	}
}

if ( ! function_exists( 'old_posts_leftovers_matcher' ) ) {
	function old_posts_leftovers_matcher( $absolute_file ) {
		$filename = wp_basename( $absolute_file );
		$basename = pathinfo( $filename, PATHINFO_FILENAME );

		return '/^' . preg_quote( $basename, '/' ) . '(?:-[^.\/]+)?(?:\..+)$/i';
	}
}

$cli_args = old_posts_collect_runtime_args(
	isset( $args ) && is_array( $args ) ? $args : array(),
	isset( $GLOBALS['argv'] ) && is_array( $GLOBALS['argv'] ) ? $GLOBALS['argv'] : array()
);

$manifest_path = isset( $cli_args['manifest'] ) ? (string) $cli_args['manifest'] : old_posts_default_temp_path( 'old-posts-manifest.json' );
$output_path   = isset( $cli_args['output'] ) ? (string) $cli_args['output'] : old_posts_default_temp_path( 'old-posts-leftovers-report.json' );
$year_filter   = isset( $cli_args['year'] ) ? (string) $cli_args['year'] : null;
$include_clean = old_posts_bool_arg( isset( $cli_args['include-clean'] ) ? $cli_args['include-clean'] : null, false );
$log_paths     = old_posts_leftovers_log_paths( $cli_args );
$progress_cfg  = old_posts_progress_config( $cli_args, 50, 30 );

old_posts_assert_parent_writable( $output_path, 'leftovers JSON report' );

$manifest          = old_posts_read_manifest( $manifest_path );
$attachment_map    = old_posts_leftovers_attachment_record_map( $manifest );
$deleted_records   = old_posts_leftovers_deleted_attachment_ids( $log_paths );
$uploads           = wp_get_upload_dir();
$uploads_basedir   = $uploads['basedir'] ?? '';
$candidate_post_map = array();

foreach ( $manifest['posts'] as $post_record ) {
	$candidate_post_map[ (int) $post_record['post_id'] ] = $post_record;
}

$report_items         = array();
$missing_manifest_ids = array();
$leftover_files_count = 0;
$progress_state       = old_posts_progress_state( $progress_cfg['every'], $progress_cfg['seconds'] );
$scanned_records      = 0;

foreach ( $deleted_records as $attachment_id => $deleted_record ) {
	++$scanned_records;
	$group_root_attachment_id = ! empty( $deleted_record['group_root_attachment_id'] ) ? (int) $deleted_record['group_root_attachment_id'] : 0;
	$root_attachment_record   = ( $group_root_attachment_id && ! empty( $attachment_map[ $group_root_attachment_id ] ) )
		? $attachment_map[ $group_root_attachment_id ]
		: null;
	$attachment_record = ! empty( $attachment_map[ $attachment_id ] )
		? $attachment_map[ $attachment_id ]
		: array(
			'attachment_id'      => $attachment_id,
			'title'              => '',
			'post_parent'        => 0,
			'candidate_post_ids' => array(),
			'file'               => $deleted_record['file'],
			'guid'               => $deleted_record['guid'],
		);

	if ( empty( $attachment_map[ $attachment_id ] ) && is_array( $root_attachment_record ) ) {
		$attachment_record['title']              = $root_attachment_record['title'];
		$attachment_record['post_parent']        = $root_attachment_record['post_parent'];
		$attachment_record['candidate_post_ids'] = $root_attachment_record['candidate_post_ids'];
	}

	if ( empty( $attachment_map[ $attachment_id ] ) && ! is_array( $root_attachment_record ) ) {
		$missing_manifest_ids[] = $attachment_id;
	}

	if ( $year_filter ) {
		$belongs_to_year = false;
		foreach ( $attachment_record['candidate_post_ids'] as $candidate_post_id ) {
			if ( ! empty( $candidate_post_map[ (int) $candidate_post_id ] ) && 0 === strpos( (string) $candidate_post_map[ (int) $candidate_post_id ]['post_date'], $year_filter ) ) {
				$belongs_to_year = true;
				break;
			}
		}

		if ( ! $belongs_to_year ) {
			continue;
		}
	}

	$relative_file = old_posts_leftovers_relative_file( $attachment_record );
	if ( '' === $relative_file || '' === $uploads_basedir ) {
		$item = array(
			'attachment_id'            => $attachment_id,
			'group_root_attachment_id' => $group_root_attachment_id,
			'relative_file'            => $relative_file,
			'leftover_files'           => array(),
			'notes'                    => array( 'missing_relative_file_or_uploads_basedir' ),
		);

		if ( $include_clean ) {
			$report_items[] = $item;
		}
		old_posts_progress_maybe_log(
			$progress_state,
			'Auditing leftover files.',
			$scanned_records,
			count( $deleted_records ),
			array(
				'leftover_files' => $leftover_files_count,
			)
		);
		continue;
	}

	$absolute_file = trailingslashit( $uploads_basedir ) . $relative_file;
	$dir           = dirname( $absolute_file );
	$matcher       = old_posts_leftovers_matcher( $absolute_file );
	$leftovers     = array();

	if ( is_dir( $dir ) ) {
		$entries = scandir( $dir );
		if ( false !== $entries ) {
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}

				$absolute_entry = $dir . '/' . $entry;
				if ( ! is_file( $absolute_entry ) ) {
					continue;
				}

				if ( ! preg_match( $matcher, $entry ) ) {
					continue;
				}

				$leftovers[] = array(
					'path'     => $absolute_entry,
					'relative' => ltrim( str_replace( rtrim( $uploads_basedir, '/\\' ), '', $absolute_entry ), '/\\' ),
					'size'     => filesize( $absolute_entry ),
				);
			}
		}
	}

	if ( empty( $leftovers ) && ! $include_clean ) {
		continue;
	}

	$leftover_files_count += count( $leftovers );

	$report_items[] = array(
		'attachment_id'            => $attachment_id,
		'group_root_attachment_id' => $group_root_attachment_id,
		'title'                    => $attachment_record['title'],
		'post_parent'              => $attachment_record['post_parent'],
		'relative_file'            => $relative_file,
		'derived_prefix'           => ! empty( $deleted_record['derived_prefix'] ) ? $deleted_record['derived_prefix'] : old_posts_attachment_derived_prefix( $attachment_record ),
		'expected_base_path'       => $absolute_file,
		'candidate_post_ids'       => $attachment_record['candidate_post_ids'],
		'leftover_count'           => count( $leftovers ),
		'leftover_files'           => $leftovers,
	);

	old_posts_progress_maybe_log(
		$progress_state,
		'Auditing leftover files.',
		$scanned_records,
		count( $deleted_records ),
		array(
			'leftover_files' => $leftover_files_count,
		)
	);
}

old_posts_progress_maybe_log(
	$progress_state,
	'Leftovers audit complete.',
	count( $deleted_records ),
	count( $deleted_records ),
	array(
		'leftover_files' => $leftover_files_count,
	),
	array(
		'force' => true,
	)
);

$report = array(
	'generated_at' => gmdate( 'c' ),
	'manifest'     => $manifest_path,
	'log_paths'     => $log_paths,
	'year_filter'   => $year_filter,
	'summary'       => array(
		'deleted_attachments_from_logs' => count( $deleted_records ),
		'attachments_in_report'         => count( $report_items ),
		'leftover_files_count'          => $leftover_files_count,
		'missing_manifest_ids'          => $missing_manifest_ids,
	),
	'items'         => $report_items,
);

old_posts_write_json( $output_path, $report );

old_posts_log(
	'success',
	'Leftovers report generated.',
	array(
		'output'                 => $output_path,
		'deleted_from_logs'      => count( $deleted_records ),
		'attachments_in_report'  => count( $report_items ),
		'leftover_files_count'   => $leftover_files_count,
	)
);
