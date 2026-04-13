<?php

require_once __DIR__ . '/wp_old_posts_common.php';

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run with WP-CLI: wp eval-file wp_old_posts_leftovers_selection.php key=value ...\n" );
	exit( 1 );
}

if ( ! function_exists( 'old_posts_leftovers_selection_read_report' ) ) {
	function old_posts_leftovers_selection_read_report( $path ) {
		if ( ! file_exists( $path ) ) {
			old_posts_fail( 'Leftovers report not found.', array( 'path' => $path ) );
		}

		$content = file_get_contents( $path );
		$data    = json_decode( $content, true );

		if ( ! is_array( $data ) || ! array_key_exists( 'items', $data ) || ! is_array( $data['items'] ) ) {
			old_posts_fail( 'Invalid leftovers report.', array( 'path' => $path ) );
		}

		return $data;
	}
}

$cli_args    = old_posts_collect_runtime_args(
	isset( $args ) && is_array( $args ) ? $args : array(),
	isset( $GLOBALS['argv'] ) && is_array( $GLOBALS['argv'] ) ? $GLOBALS['argv'] : array()
);
$report_path = isset( $cli_args['report'] ) ? (string) $cli_args['report'] : old_posts_default_temp_path( 'old-posts-leftovers-report.json' );
$output_path = isset( $cli_args['output'] ) ? (string) $cli_args['output'] : old_posts_default_temp_path( 'old-posts-leftovers-approved.txt' );
$progress_cfg = old_posts_progress_config( $cli_args, 100, 30 );

old_posts_assert_parent_writable( $output_path, 'leftovers selection output' );

$report = old_posts_leftovers_selection_read_report( $report_path );
$lines  = array();
$seen   = array();
$progress_state = old_posts_progress_state( $progress_cfg['every'], $progress_cfg['seconds'] );
$processed_items = 0;
$skipped_referenced_items = 0;
$skipped_referenced_paths = 0;
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
	++$processed_items;
	if ( empty( $item['leftover_files'] ) || ! is_array( $item['leftover_files'] ) ) {
		old_posts_progress_maybe_log(
			$progress_state,
			'Building the leftovers selection file.',
			$processed_items,
			count( $report['items'] ),
			array(
				'selected_paths' => count( $lines ),
			)
		);
		continue;
	}

	$relative_file                       = isset( $item['relative_file'] ) ? (string) $item['relative_file'] : '';
	$base_referenced_attachment_ids      = $live_attachment_ids_map[ ltrim( $relative_file, '/\\' ) ] ?? array();
	if ( ! empty( $base_referenced_attachment_ids ) ) {
		++$skipped_referenced_items;
		$skipped_referenced_paths += count( $item['leftover_files'] );
		old_posts_log(
			'warning',
			'Skipping report item because the base file is still referenced by live attachments.',
			array(
				'relative_file'                   => $relative_file,
				'attachment_id'                   => isset( $item['attachment_id'] ) ? (int) $item['attachment_id'] : 0,
				'still_referenced_attachment_ids' => $base_referenced_attachment_ids,
			)
		);
		old_posts_progress_maybe_log(
			$progress_state,
			'Building the leftovers selection file.',
			$processed_items,
			count( $report['items'] ),
			array(
				'selected_paths'          => count( $lines ),
				'skipped_referenced_items' => $skipped_referenced_items,
			)
		);
		continue;
	}

	foreach ( $item['leftover_files'] as $leftover ) {
		$relative = isset( $leftover['relative'] ) ? trim( (string) $leftover['relative'] ) : '';
		if ( '' === $relative || isset( $seen[ $relative ] ) ) {
			continue;
		}

		$leftover_referenced_attachment_ids = $live_attachment_ids_map[ ltrim( $relative, '/\\' ) ] ?? array();
		if ( ! empty( $leftover_referenced_attachment_ids ) ) {
			++$skipped_referenced_paths;
			old_posts_log(
				'warning',
				'Skipping leftover path because a live attachment still references this exact file.',
				array(
					'relative'                        => $relative,
					'base_relative_file'              => $relative_file,
					'attachment_id'                   => isset( $item['attachment_id'] ) ? (int) $item['attachment_id'] : 0,
					'still_referenced_attachment_ids' => $leftover_referenced_attachment_ids,
				)
			);
			continue;
		}

		$seen[ $relative ] = true;
		$lines[]           = $relative;
	}

	old_posts_progress_maybe_log(
		$progress_state,
		'Building the leftovers selection file.',
		$processed_items,
		count( $report['items'] ),
		array(
			'selected_paths'           => count( $lines ),
			'skipped_referenced_items' => $skipped_referenced_items,
			'skipped_referenced_paths' => $skipped_referenced_paths,
		)
	);
}

old_posts_progress_maybe_log(
	$progress_state,
	'Leftovers selection build complete.',
	$processed_items,
	count( $report['items'] ),
	array(
		'selected_paths'           => count( $lines ),
		'skipped_referenced_items' => $skipped_referenced_items,
		'skipped_referenced_paths' => $skipped_referenced_paths,
	),
	array(
		'force' => true,
	)
);

sort( $lines, SORT_NATURAL | SORT_FLAG_CASE );

$result = file_put_contents( $output_path, implode( PHP_EOL, $lines ) . ( empty( $lines ) ? '' : PHP_EOL ) );
if ( false === $result ) {
	old_posts_fail( 'Could not write the selection file.', array( 'path' => $output_path ) );
}

old_posts_log(
	'success',
	'Leftovers selection file generated.',
	array(
		'output'                  => $output_path,
		'count'                   => count( $lines ),
		'mode'                    => 'relative_to_uploads',
		'skipped_referenced_items' => $skipped_referenced_items,
		'skipped_referenced_paths' => $skipped_referenced_paths,
	)
);
