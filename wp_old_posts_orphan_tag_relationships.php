<?php

require_once __DIR__ . '/wp_old_posts_common.php';

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run with WP-CLI: wp eval-file wp_old_posts_orphan_tag_relationships.php key=value ...\n" );
	exit( 1 );
}

if ( ! function_exists( 'old_posts_orphan_tag_relationships_phase' ) ) {
	function old_posts_orphan_tag_relationships_phase() {
		return 'orphan-tag-relationships';
	}
}

if ( ! function_exists( 'old_posts_orphan_tag_relationships_confirmation' ) ) {
	function old_posts_orphan_tag_relationships_confirmation() {
		global $wpdb;

		return 'CONFIRM-' . strtoupper(
			substr(
				sha1( trailingslashit( home_url( '/' ) ) . '|' . $wpdb->prefix . '|' . old_posts_orphan_tag_relationships_phase() ),
				0,
				8
			)
		);
	}
}

if ( ! function_exists( 'old_posts_orphan_tag_relationship_rows' ) ) {
	function old_posts_orphan_tag_relationship_rows() {
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT
				tr.object_id,
				tr.term_taxonomy_id,
				tt.term_id,
				t.name,
				t.slug,
				tt.count AS stored_count
			FROM {$wpdb->term_relationships} AS tr
				INNER JOIN {$wpdb->term_taxonomy} AS tt
					ON tt.term_taxonomy_id = tr.term_taxonomy_id
				INNER JOIN {$wpdb->terms} AS t
					ON t.term_id = tt.term_id
				LEFT JOIN {$wpdb->posts} AS p
					ON p.ID = tr.object_id
			WHERE tt.taxonomy = %s
				AND p.ID IS NULL
			ORDER BY tt.term_id ASC, tr.term_taxonomy_id ASC, tr.object_id ASC",
			'post_tag'
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			old_posts_fail(
				'Could not query orphan tag relationships.',
				array(
					'db_error' => $wpdb->last_error,
				)
			);
		}

		return $rows;
	}
}

if ( ! function_exists( 'old_posts_orphan_tag_relationship_count' ) ) {
	function old_posts_orphan_tag_relationship_count() {
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT COUNT(*)
			FROM {$wpdb->term_relationships} AS tr
				INNER JOIN {$wpdb->term_taxonomy} AS tt
					ON tt.term_taxonomy_id = tr.term_taxonomy_id
				LEFT JOIN {$wpdb->posts} AS p
					ON p.ID = tr.object_id
			WHERE tt.taxonomy = %s
				AND p.ID IS NULL",
			'post_tag'
		);

		return (int) $wpdb->get_var( $sql );
	}
}

if ( ! function_exists( 'old_posts_orphan_tag_relationship_report' ) ) {
	function old_posts_orphan_tag_relationship_report( $rows, $dry_run ) {
		global $wpdb;

		$items             = array();
		$unique_object_ids = array();

		foreach ( $rows as $row ) {
			$term_taxonomy_id = isset( $row['term_taxonomy_id'] ) ? (int) $row['term_taxonomy_id'] : 0;
			$object_id        = isset( $row['object_id'] ) ? (int) $row['object_id'] : 0;

			if ( ! $term_taxonomy_id ) {
				continue;
			}

			if ( ! isset( $items[ $term_taxonomy_id ] ) ) {
				$items[ $term_taxonomy_id ] = array(
					'term_id'              => isset( $row['term_id'] ) ? (int) $row['term_id'] : 0,
					'term_taxonomy_id'     => $term_taxonomy_id,
					'name'                 => isset( $row['name'] ) ? (string) $row['name'] : '',
					'slug'                 => isset( $row['slug'] ) ? (string) $row['slug'] : '',
					'stored_count'         => isset( $row['stored_count'] ) ? (int) $row['stored_count'] : 0,
					'orphan_relationships' => 0,
					'orphan_object_ids'    => array(),
				);
			}

			++$items[ $term_taxonomy_id ]['orphan_relationships'];
			if ( $object_id ) {
				$items[ $term_taxonomy_id ]['orphan_object_ids'][] = $object_id;
				$unique_object_ids[ $object_id ]                  = true;
			}
		}

		foreach ( $items as &$item ) {
			$item['orphan_object_ids'] = array_values(
				array_unique(
					array_filter(
						array_map( 'intval', $item['orphan_object_ids'] )
					)
				)
			);
			sort( $item['orphan_object_ids'] );
		}
		unset( $item );

		$items = array_values( $items );
		usort(
			$items,
			static function ( $a, $b ) {
				if ( (int) $a['term_id'] === (int) $b['term_id'] ) {
					return (int) $a['term_taxonomy_id'] <=> (int) $b['term_taxonomy_id'];
				}

				return (int) $a['term_id'] <=> (int) $b['term_id'];
			}
		);

		$total_orphan_relationships = 0;
		foreach ( $items as $item ) {
			$total_orphan_relationships += (int) $item['orphan_relationships'];
		}

		return array(
			'generated_at' => gmdate( 'c' ),
			'site'         => array(
				'home_url' => home_url( '/' ),
				'site_url' => site_url( '/' ),
			),
			'database'     => array(
				'prefix' => $wpdb->prefix,
				'tables' => array(
					'posts'              => $wpdb->posts,
					'terms'              => $wpdb->terms,
					'term_taxonomy'      => $wpdb->term_taxonomy,
					'term_relationships' => $wpdb->term_relationships,
				),
			),
			'phase'        => old_posts_orphan_tag_relationships_phase(),
			'dry_run'      => (bool) $dry_run,
			'taxonomy'     => 'post_tag',
			'guardrails'   => array(
				'term_relationships_filter' => 'term_taxonomy.taxonomy = post_tag',
				'missing_post_filter'       => 'LEFT JOIN posts ON posts.ID = term_relationships.object_id WHERE posts.ID IS NULL',
				'other_taxonomies_touched'  => false,
			),
			'summary'      => array(
				'affected_terms'           => count( $items ),
				'orphan_relationships'     => $total_orphan_relationships,
				'unique_orphan_object_ids' => count( $unique_object_ids ),
			),
			'items'        => $items,
		);
	}
}

if ( ! function_exists( 'old_posts_delete_orphan_tag_relationships' ) ) {
	function old_posts_delete_orphan_tag_relationships() {
		global $wpdb;

		$sql = $wpdb->prepare(
			"DELETE tr
			FROM {$wpdb->term_relationships} AS tr
				INNER JOIN {$wpdb->term_taxonomy} AS tt
					ON tt.term_taxonomy_id = tr.term_taxonomy_id
				LEFT JOIN {$wpdb->posts} AS p
					ON p.ID = tr.object_id
			WHERE tt.taxonomy = %s
				AND p.ID IS NULL",
			'post_tag'
		);

		$result = $wpdb->query( $sql );
		if ( false === $result ) {
			old_posts_fail(
				'Could not delete orphan tag relationships.',
				array(
					'db_error' => $wpdb->last_error,
				)
			);
		}

		return (int) $result;
	}
}

if ( ! function_exists( 'old_posts_recount_post_tags' ) ) {
	function old_posts_recount_post_tags( $batch_size = 500 ) {
		global $wpdb;

		if ( ! function_exists( 'wp_update_term_count_now' ) ) {
			old_posts_fail( 'WordPress term recount function is unavailable.' );
		}

		$term_taxonomy_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT term_taxonomy_id
				FROM {$wpdb->term_taxonomy}
				WHERE taxonomy = %s
				ORDER BY term_taxonomy_id ASC",
				'post_tag'
			)
		);

		$term_taxonomy_ids = array_values(
			array_filter(
				array_map( 'intval', (array) $term_taxonomy_ids )
			)
		);

		$batch_size = max( 1, (int) $batch_size );
		foreach ( array_chunk( $term_taxonomy_ids, $batch_size ) as $chunk ) {
			$result = wp_update_term_count_now( $chunk, 'post_tag' );
			if ( false === $result ) {
				old_posts_fail(
					'Could not recount post_tag terms.',
					array(
						'term_taxonomy_ids' => $chunk,
					)
				);
			}
		}

		return count( $term_taxonomy_ids );
	}
}

$cli_args = old_posts_collect_runtime_args(
	isset( $args ) && is_array( $args ) ? $args : array(),
	isset( $GLOBALS['argv'] ) && is_array( $GLOBALS['argv'] ) ? $GLOBALS['argv'] : array()
);
$duplicate_cli_args = old_posts_runtime_arg_duplicates();
$phase              = old_posts_orphan_tag_relationships_phase();
$dry_run            = old_posts_bool_arg( isset( $cli_args['dry-run'] ) ? $cli_args['dry-run'] : null, true );
$output_path        = isset( $cli_args['output'] ) ? (string) $cli_args['output'] : old_posts_default_temp_path( 'old-posts-orphan-tag-relationships-report.json' );
$log_path           = isset( $cli_args['log'] ) ? (string) $cli_args['log'] : old_posts_default_temp_path( 'old-posts-orphan-tag-relationships.jsonl' );
$recount_batch_size = isset( $cli_args['recount-batch-size'] ) ? max( 1, (int) $cli_args['recount-batch-size'] ) : 500;
$confirm            = isset( $cli_args['confirm'] ) ? (string) $cli_args['confirm'] : '';

if ( ! empty( $duplicate_cli_args ) ) {
	old_posts_log(
		'warning',
		'Some runtime arguments were provided more than once; the last value will be used.',
		array(
			'duplicates' => $duplicate_cli_args,
		)
	);
}

old_posts_assert_parent_writable( $output_path, 'orphan tag relationships report' );
old_posts_assert_parent_writable( $log_path, 'orphan tag relationships JSONL log' );

if ( '' !== $log_path && file_exists( $log_path ) ) {
	$existing_log_size = filesize( $log_path );
	if ( false !== $existing_log_size && $existing_log_size > 0 ) {
		old_posts_log(
			'warning',
			'The JSONL log file already exists and this execution will append new events to it.',
			array(
				'path'       => $log_path,
				'phase'      => $phase,
				'size_bytes' => (int) $existing_log_size,
			)
		);
	}
}

old_posts_append_jsonl(
	$log_path,
	array(
		'timestamp'          => gmdate( 'c' ),
		'phase'              => $phase,
		'action'             => 'start_execution',
		'status'             => 'started',
		'dry_run'            => $dry_run,
		'output'             => $output_path,
		'recount_batch_size' => $recount_batch_size,
		'duplicate_args'     => $duplicate_cli_args,
	)
);

$rows   = old_posts_orphan_tag_relationship_rows();
$report = old_posts_orphan_tag_relationship_report( $rows, $dry_run );

old_posts_write_json( $output_path, $report );
old_posts_log(
	'info',
	'Orphan post_tag relationship report written.',
	array(
		'output'               => $output_path,
		'affected_terms'       => (int) $report['summary']['affected_terms'],
		'orphan_relationships' => (int) $report['summary']['orphan_relationships'],
		'dry_run'              => $dry_run,
	)
);
old_posts_append_jsonl(
	$log_path,
	array(
		'timestamp'            => gmdate( 'c' ),
		'phase'                => $phase,
		'action'               => 'write_report',
		'status'               => 'ok',
		'dry_run'              => $dry_run,
		'output'               => $output_path,
		'affected_terms'       => (int) $report['summary']['affected_terms'],
		'orphan_relationships' => (int) $report['summary']['orphan_relationships'],
	)
);

$expected_confirmation = old_posts_orphan_tag_relationships_confirmation();
if ( ! $dry_run && $confirm !== $expected_confirmation ) {
	old_posts_append_jsonl(
		$log_path,
		array(
			'timestamp' => gmdate( 'c' ),
			'phase'     => $phase,
			'action'    => 'confirmation_check',
			'status'    => 'missing_or_invalid',
			'dry_run'   => $dry_run,
			'output'    => $output_path,
		)
	);
	old_posts_fail(
		'Missing or invalid confirmation token.',
		array(
			'expected' => $expected_confirmation,
			'phase'    => $phase,
			'output'   => $output_path,
		)
	);
}

if ( $dry_run ) {
	old_posts_log(
		'success',
		'Dry-run finished. No relationships were deleted and no term counts were changed.',
		array(
			'output'               => $output_path,
			'log'                  => $log_path,
			'orphan_relationships' => (int) $report['summary']['orphan_relationships'],
		)
	);
	old_posts_append_jsonl(
		$log_path,
		array(
			'timestamp'            => gmdate( 'c' ),
			'phase'                => $phase,
			'action'               => 'finish_execution',
			'status'               => 'dry-run',
			'dry_run'              => $dry_run,
			'orphan_relationships' => (int) $report['summary']['orphan_relationships'],
		)
	);
	exit( 0 );
}

$before_count = (int) $report['summary']['orphan_relationships'];
$deleted      = old_posts_delete_orphan_tag_relationships();

old_posts_log(
	'info',
	'Orphan post_tag relationships deleted.',
	array(
		'expected_before_delete' => $before_count,
		'deleted_relationships'  => $deleted,
	)
);
old_posts_append_jsonl(
	$log_path,
	array(
		'timestamp'              => gmdate( 'c' ),
		'phase'                  => $phase,
		'action'                 => 'delete_orphan_relationships',
		'status'                 => 'ok',
		'dry_run'                => $dry_run,
		'expected_before_delete' => $before_count,
		'deleted_relationships'  => $deleted,
	)
);

$recounted_terms = old_posts_recount_post_tags( $recount_batch_size );
old_posts_log(
	'info',
	'post_tag terms recounted.',
	array(
		'recounted_term_taxonomy_ids' => $recounted_terms,
	)
);
old_posts_append_jsonl(
	$log_path,
	array(
		'timestamp'                    => gmdate( 'c' ),
		'phase'                        => $phase,
		'action'                       => 'recount_post_tags',
		'status'                       => 'ok',
		'dry_run'                      => $dry_run,
		'recounted_term_taxonomy_ids'  => $recounted_terms,
		'recount_batch_size'           => $recount_batch_size,
	)
);

$remaining_count = old_posts_orphan_tag_relationship_count();
$status          = 0 === $remaining_count ? 'success' : 'warning';

old_posts_log(
	$status,
	'Orphan post_tag relationship cleanup finished.',
	array(
		'output'                      => $output_path,
		'log'                         => $log_path,
		'deleted_relationships'       => $deleted,
		'remaining_orphan_relationships' => $remaining_count,
	)
);
old_posts_append_jsonl(
	$log_path,
	array(
		'timestamp'                      => gmdate( 'c' ),
		'phase'                          => $phase,
		'action'                         => 'finish_execution',
		'status'                         => $status,
		'dry_run'                        => $dry_run,
		'deleted_relationships'          => $deleted,
		'remaining_orphan_relationships' => $remaining_count,
	)
);
