<?php

require_once __DIR__ . '/wp_old_posts_common.php';

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run with WP-CLI: wp eval-file wp_old_posts_wpml_consistency.php key=value ...\n" );
	exit( 1 );
}

if ( ! function_exists( 'old_posts_wpml_consistency_log_paths' ) ) {
	function old_posts_wpml_consistency_log_paths( $cli_args ) {
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
			old_posts_fail( 'Provide log=, log-glob=, or year= so the script can find the JSONL logs to inspect.' );
		}

		foreach ( $paths as $path ) {
			if ( ! file_exists( $path ) ) {
				old_posts_fail( 'JSONL log not found.', array( 'path' => $path ) );
			}
		}

		return $paths;
	}
}

if ( ! function_exists( 'old_posts_wpml_consistency_confirmation' ) ) {
	function old_posts_wpml_consistency_confirmation( $manifest_path, $log_paths ) {
		return 'CONFIRM-' . strtoupper( substr( sha1( (string) $manifest_path . '|' . implode( ',', (array) $log_paths ) . '|wpml-consistency-repair' ), 0, 8 ) );
	}
}

if ( ! function_exists( 'old_posts_wpml_default_language' ) ) {
	function old_posts_wpml_default_language() {
		if ( ! old_posts_wpml_active() ) {
			return 'und';
		}

		if ( has_filter( 'wpml_default_language' ) ) {
			$lang = apply_filters( 'wpml_default_language', null );
			if ( is_string( $lang ) && '' !== $lang ) {
				return $lang;
			}
		}

		return 'pt';
	}
}

if ( ! function_exists( 'old_posts_wpml_consistency_availability' ) ) {
	function old_posts_wpml_consistency_availability() {
		if ( ! old_posts_wpml_active() ) {
			return array(
				'available' => false,
				'reason'    => 'wpml_not_active',
			);
		}

		if ( ! old_posts_wpml_translations_table_exists() ) {
			return array(
				'available' => false,
				'reason'    => 'wpml_translations_table_missing',
			);
		}

		return array(
			'available' => true,
			'reason'    => '',
		);
	}
}

if ( ! function_exists( 'old_posts_wpml_manifest_maps' ) ) {
	function old_posts_wpml_manifest_maps( $manifest ) {
		$post_map       = array();
		$attachment_map = array();

		foreach ( $manifest['posts'] as $post_record ) {
			$post_id = isset( $post_record['post_id'] ) ? (int) $post_record['post_id'] : 0;
			if ( ! $post_id ) {
				continue;
			}

			$post_map[ $post_id ] = $post_record;
		}

		foreach ( $manifest['attachments'] as $attachment_record ) {
			$attachment_id = isset( $attachment_record['attachment_id'] ) ? (int) $attachment_record['attachment_id'] : 0;
			if ( ! $attachment_id ) {
				continue;
			}

			$attachment_map[ $attachment_id ] = $attachment_record;
		}

		return array(
			'posts'       => $post_map,
			'attachments' => $attachment_map,
		);
	}
}

if ( ! function_exists( 'old_posts_wpml_affected_entries_from_logs' ) ) {
	function old_posts_wpml_affected_entries_from_logs( $log_paths ) {
		$entries = array(
			'posts'       => array(),
			'attachments' => array(),
		);

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
				if ( ! is_array( $entry ) || ! empty( $entry['dry_run'] ) ) {
					continue;
				}

				if ( 'delete-attachments' === ( $entry['phase'] ?? null ) && 'delete_attachment' === ( $entry['action'] ?? null ) ) {
					$attachment_id = isset( $entry['attachment_id'] ) ? (int) $entry['attachment_id'] : 0;
					if ( $attachment_id ) {
						$entries['attachments'][ $attachment_id ] = array(
							'id'       => $attachment_id,
							'phase'    => (string) $entry['phase'],
							'action'   => (string) $entry['action'],
							'status'   => (string) ( $entry['status'] ?? '' ),
							'log_path' => $log_path,
						);
					}
				}

				if ( 'force-delete-posts' === ( $entry['phase'] ?? null ) && 'force_delete_post' === ( $entry['action'] ?? null ) ) {
					$post_id = isset( $entry['post_id'] ) ? (int) $entry['post_id'] : 0;
					if ( $post_id ) {
						$entries['posts'][ $post_id ] = array(
							'id'       => $post_id,
							'phase'    => (string) $entry['phase'],
							'action'   => (string) $entry['action'],
							'status'   => (string) ( $entry['status'] ?? '' ),
							'log_path' => $log_path,
						);
					}
				}
			}

			fclose( $handle );
		}

		ksort( $entries['posts'] );
		ksort( $entries['attachments'] );

		return $entries;
	}
}

if ( ! function_exists( 'old_posts_wpml_affected_groups' ) ) {
	function old_posts_wpml_affected_groups( $manifest_maps, $affected_entries ) {
		$groups              = array();
		$missing_manifest_ids = array(
			'posts'       => array(),
			'attachments' => array(),
		);

		foreach ( $affected_entries['posts'] as $post_id => $entry ) {
			if ( empty( $manifest_maps['posts'][ $post_id ] ) ) {
				$missing_manifest_ids['posts'][] = $post_id;
				continue;
			}

			$post_record = $manifest_maps['posts'][ $post_id ];
			$wpml        = isset( $post_record['wpml'] ) && is_array( $post_record['wpml'] ) ? $post_record['wpml'] : array();
			$trid        = isset( $wpml['trid'] ) ? (int) $wpml['trid'] : 0;
			if ( ! $trid ) {
				continue;
			}

			if ( ! isset( $groups[ $trid ] ) ) {
				$groups[ $trid ] = array(
					'trid'                => $trid,
					'entity_kind'         => 'post',
					'affected_post_ids'   => array(),
					'affected_attachment_ids' => array(),
					'manifest_languages'  => array(),
				);
			}

			$groups[ $trid ]['affected_post_ids'][] = $post_id;
			if ( ! empty( $wpml['translations'] ) && is_array( $wpml['translations'] ) ) {
				$groups[ $trid ]['manifest_languages'] = array_values( array_unique( array_merge( $groups[ $trid ]['manifest_languages'], array_keys( $wpml['translations'] ) ) ) );
			}
		}

		foreach ( $affected_entries['attachments'] as $attachment_id => $entry ) {
			if ( empty( $manifest_maps['attachments'][ $attachment_id ] ) ) {
				$missing_manifest_ids['attachments'][] = $attachment_id;
				continue;
			}

			$attachment_record = $manifest_maps['attachments'][ $attachment_id ];
			$wpml              = isset( $attachment_record['wpml'] ) && is_array( $attachment_record['wpml'] ) ? $attachment_record['wpml'] : array();
			$trid              = isset( $wpml['trid'] ) ? (int) $wpml['trid'] : 0;
			if ( ! $trid ) {
				continue;
			}

			if ( ! isset( $groups[ $trid ] ) ) {
				$groups[ $trid ] = array(
					'trid'                => $trid,
					'entity_kind'         => 'attachment',
					'affected_post_ids'   => array(),
					'affected_attachment_ids' => array(),
					'manifest_languages'  => array(),
				);
			}

			$groups[ $trid ]['affected_attachment_ids'][] = $attachment_id;
			if ( ! empty( $wpml['translations'] ) && is_array( $wpml['translations'] ) ) {
				$groups[ $trid ]['manifest_languages'] = array_values( array_unique( array_merge( $groups[ $trid ]['manifest_languages'], array_keys( $wpml['translations'] ) ) ) );
			}
		}

		foreach ( $groups as &$group ) {
			sort( $group['affected_post_ids'] );
			sort( $group['affected_attachment_ids'] );
			sort( $group['manifest_languages'] );
		}
		unset( $group );

		$missing_manifest_ids['posts']       = array_values( array_unique( array_map( 'intval', $missing_manifest_ids['posts'] ) ) );
		$missing_manifest_ids['attachments'] = array_values( array_unique( array_map( 'intval', $missing_manifest_ids['attachments'] ) ) );
		ksort( $groups );

		return array(
			'groups'               => $groups,
			'missing_manifest_ids' => $missing_manifest_ids,
		);
	}
}

if ( ! function_exists( 'old_posts_wpml_fetch_translation_rows' ) ) {
	function old_posts_wpml_fetch_translation_rows( $trids ) {
		global $wpdb;

		$rows = array();
		$trids = array_values( array_unique( array_filter( array_map( 'intval', $trids ) ) ) );
		if ( empty( $trids ) ) {
			return $rows;
		}

		foreach ( array_chunk( $trids, 250 ) as $trid_chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $trid_chunk ), '%d' ) );
			$sql          = $wpdb->prepare(
				"SELECT translation_id, element_id, element_type, trid, language_code, source_language_code
				FROM {$wpdb->prefix}icl_translations
				WHERE trid IN ($placeholders)
				ORDER BY trid ASC, language_code ASC, element_id ASC",
				$trid_chunk
			);
			$chunk_rows    = $wpdb->get_results( $sql, ARRAY_A );
			foreach ( $chunk_rows as $row ) {
				$trid = (int) $row['trid'];
				if ( ! isset( $rows[ $trid ] ) ) {
					$rows[ $trid ] = array();
				}
				$rows[ $trid ][] = array(
					'translation_id'       => (int) $row['translation_id'],
					'element_id'           => (int) $row['element_id'],
					'element_type'         => (string) $row['element_type'],
					'trid'                 => $trid,
					'language_code'        => (string) $row['language_code'],
					'source_language_code' => null === $row['source_language_code'] ? null : (string) $row['source_language_code'],
				);
			}
		}

		return $rows;
	}
}

if ( ! function_exists( 'old_posts_wpml_existing_post_ids' ) ) {
	function old_posts_wpml_existing_post_ids( $rows_by_trid ) {
		global $wpdb;

		$element_ids = array();
		foreach ( $rows_by_trid as $rows ) {
			foreach ( $rows as $row ) {
				$element_ids[] = (int) $row['element_id'];
			}
		}

		$element_ids = array_values( array_unique( array_filter( $element_ids ) ) );
		$existing    = array();
		if ( empty( $element_ids ) ) {
			return $existing;
		}

		foreach ( array_chunk( $element_ids, 500 ) as $id_chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $id_chunk ), '%d' ) );
			$sql          = $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE ID IN ($placeholders)",
				$id_chunk
			);
			$ids          = $wpdb->get_col( $sql );
			foreach ( $ids as $id ) {
				$existing[ (int) $id ] = true;
			}
		}

		return $existing;
	}
}

if ( ! function_exists( 'old_posts_wpml_choose_original_row' ) ) {
	function old_posts_wpml_choose_original_row( $surviving_rows, $default_language ) {
		if ( empty( $surviving_rows ) ) {
			return null;
		}

		$default_matches = array_values(
			array_filter(
				$surviving_rows,
				static function ( $row ) use ( $default_language ) {
					return (string) $row['language_code'] === (string) $default_language;
				}
			)
		);

		if ( 1 === count( $default_matches ) ) {
			return $default_matches[0];
		}

		$original_rows = array_values(
			array_filter(
				$surviving_rows,
				static function ( $row ) {
					return empty( $row['source_language_code'] );
				}
			)
		);

		if ( 1 === count( $original_rows ) ) {
			return $original_rows[0];
		}

		usort(
			$surviving_rows,
			static function ( $a, $b ) {
				return (int) $a['element_id'] <=> (int) $b['element_id'];
			}
		);

		return $surviving_rows[0];
	}
}

if ( ! function_exists( 'old_posts_wpml_group_analysis' ) ) {
	function old_posts_wpml_group_analysis( $group_meta, $rows, $existing_post_ids, $default_language ) {
		$orphan_rows     = array();
		$surviving_rows  = array();
		$issues          = array();
		$repair_actions  = array(
			'delete_orphan_translation_ids' => array(),
			'set_original_null'             => array(),
			'set_source_language_code'      => array(),
		);
		$repair_blockers = array();

		foreach ( $rows as $row ) {
			if ( isset( $existing_post_ids[ (int) $row['element_id'] ] ) ) {
				$surviving_rows[] = $row;
			} else {
				$orphan_rows[] = $row;
			}
		}

		if ( ! empty( $orphan_rows ) ) {
			$issues[] = 'orphan_translation_rows';
			$repair_actions['delete_orphan_translation_ids'] = array_values( array_map( 'intval', wp_list_pluck( $orphan_rows, 'translation_id' ) ) );
		}

		$language_counts = array();
		$element_types   = array();
		foreach ( $surviving_rows as $row ) {
			$lang = (string) $row['language_code'];
			if ( ! isset( $language_counts[ $lang ] ) ) {
				$language_counts[ $lang ] = 0;
			}
			++$language_counts[ $lang ];
			$element_types[] = (string) $row['element_type'];
		}

		foreach ( $language_counts as $count ) {
			if ( $count > 1 ) {
				$issues[]          = 'duplicate_language_code';
				$repair_blockers[] = 'duplicate_language_code';
				break;
			}
		}

		$element_types = array_values( array_unique( $element_types ) );
		if ( count( $element_types ) > 1 ) {
			$issues[]          = 'mixed_element_types';
			$repair_blockers[] = 'mixed_element_types';
		}

		$original_rows = array_values(
			array_filter(
				$surviving_rows,
				static function ( $row ) {
					return empty( $row['source_language_code'] );
				}
			)
		);

		if ( ! empty( $surviving_rows ) && 0 === count( $original_rows ) ) {
			$issues[] = 'missing_original';
		}

		if ( count( $original_rows ) > 1 ) {
			$issues[] = 'multiple_originals';
		}

		$chosen_original = old_posts_wpml_choose_original_row( $surviving_rows, $default_language );
		if ( $chosen_original ) {
			if ( ! empty( $chosen_original['source_language_code'] ) ) {
				$repair_actions['set_original_null'][] = array(
					'translation_id'       => (int) $chosen_original['translation_id'],
					'element_id'           => (int) $chosen_original['element_id'],
					'language_code'        => (string) $chosen_original['language_code'],
					'source_language_code' => null,
				);
			}

			foreach ( $surviving_rows as $row ) {
				if ( (int) $row['translation_id'] === (int) $chosen_original['translation_id'] ) {
					continue;
				}

				if ( (string) $row['source_language_code'] !== (string) $chosen_original['language_code'] ) {
					$repair_actions['set_source_language_code'][] = array(
						'translation_id' => (int) $row['translation_id'],
						'element_id'     => (int) $row['element_id'],
						'language_code'  => (string) $row['language_code'],
						'from'           => null === $row['source_language_code'] ? null : (string) $row['source_language_code'],
						'to'             => (string) $chosen_original['language_code'],
					);
				}
			}
		}

		if ( empty( $surviving_rows ) && ! empty( $orphan_rows ) ) {
			$issues[] = 'only_orphan_rows';
		}

		if ( $chosen_original ) {
			$expected_source = (string) $chosen_original['language_code'];
			foreach ( $surviving_rows as $row ) {
				if ( (int) $row['translation_id'] === (int) $chosen_original['translation_id'] ) {
					continue;
				}

				if ( (string) $row['source_language_code'] !== $expected_source ) {
					$issues[] = 'source_language_mismatch';
					break;
				}
			}
		}

		$issues          = array_values( array_unique( $issues ) );
		$repair_blockers = array_values( array_unique( $repair_blockers ) );
		$repairable      = empty( $repair_blockers ) && (
			! empty( $repair_actions['delete_orphan_translation_ids'] )
			|| ! empty( $repair_actions['set_original_null'] )
			|| ! empty( $repair_actions['set_source_language_code'] )
		);

		return array(
			'trid'                   => (int) $group_meta['trid'],
			'entity_kind'            => (string) $group_meta['entity_kind'],
			'affected_post_ids'      => array_values( array_map( 'intval', $group_meta['affected_post_ids'] ) ),
			'affected_attachment_ids' => array_values( array_map( 'intval', $group_meta['affected_attachment_ids'] ) ),
			'manifest_languages'     => $group_meta['manifest_languages'],
			'default_language'       => (string) $default_language,
			'issues'                 => $issues,
			'repair_blockers'        => $repair_blockers,
			'repairable'             => $repairable,
			'rows'                   => $rows,
			'surviving_rows'         => $surviving_rows,
			'orphan_rows'            => $orphan_rows,
			'chosen_original'        => $chosen_original,
			'proposed_repairs'       => $repair_actions,
		);
	}
}

if ( ! function_exists( 'old_posts_wpml_apply_repairs' ) ) {
	function old_posts_wpml_apply_repairs( $analysis, $log_path ) {
		global $wpdb;

		$applied  = array(
			'deleted_orphan_translation_ids' => array(),
			'updated_translation_ids'        => array(),
		);
		$failures = array();

		foreach ( $analysis['proposed_repairs']['delete_orphan_translation_ids'] as $translation_id ) {
			$result = $wpdb->delete(
				$wpdb->prefix . 'icl_translations',
				array( 'translation_id' => (int) $translation_id ),
				array( '%d' )
			);

			if ( false === $result ) {
				$failures[] = array(
					'action'         => 'delete_orphan_translation_row',
					'translation_id' => (int) $translation_id,
					'db_error'       => (string) $wpdb->last_error,
				);
				continue;
			}

			$applied['deleted_orphan_translation_ids'][] = (int) $translation_id;
			old_posts_append_jsonl(
				$log_path,
				array(
					'timestamp'      => gmdate( 'c' ),
					'phase'          => 'wpml-consistency',
					'action'         => 'delete_orphan_translation_row',
					'status'         => 'ok',
					'trid'           => (int) $analysis['trid'],
					'translation_id' => (int) $translation_id,
				)
			);
		}

		foreach ( $analysis['proposed_repairs']['set_original_null'] as $repair ) {
			$result = $wpdb->update(
				$wpdb->prefix . 'icl_translations',
				array( 'source_language_code' => null ),
				array( 'translation_id' => (int) $repair['translation_id'] ),
				array( '%s' ),
				array( '%d' )
			);

			if ( false === $result ) {
				$failures[] = array(
					'action'         => 'set_original_null',
					'translation_id' => (int) $repair['translation_id'],
					'db_error'       => (string) $wpdb->last_error,
				);
				continue;
			}

			$applied['updated_translation_ids'][] = (int) $repair['translation_id'];
			old_posts_append_jsonl(
				$log_path,
				array(
					'timestamp'      => gmdate( 'c' ),
					'phase'          => 'wpml-consistency',
					'action'         => 'set_original_null',
					'status'         => 'ok',
					'trid'           => (int) $analysis['trid'],
					'translation_id' => (int) $repair['translation_id'],
					'element_id'     => (int) $repair['element_id'],
					'language_code'  => (string) $repair['language_code'],
				)
			);
		}

		foreach ( $analysis['proposed_repairs']['set_source_language_code'] as $repair ) {
			$result = $wpdb->update(
				$wpdb->prefix . 'icl_translations',
				array( 'source_language_code' => (string) $repair['to'] ),
				array( 'translation_id' => (int) $repair['translation_id'] ),
				array( '%s' ),
				array( '%d' )
			);

			if ( false === $result ) {
				$failures[] = array(
					'action'         => 'set_source_language_code',
					'translation_id' => (int) $repair['translation_id'],
					'db_error'       => (string) $wpdb->last_error,
				);
				continue;
			}

			$applied['updated_translation_ids'][] = (int) $repair['translation_id'];
			old_posts_append_jsonl(
				$log_path,
				array(
					'timestamp'      => gmdate( 'c' ),
					'phase'          => 'wpml-consistency',
					'action'         => 'set_source_language_code',
					'status'         => 'ok',
					'trid'           => (int) $analysis['trid'],
					'translation_id' => (int) $repair['translation_id'],
					'element_id'     => (int) $repair['element_id'],
					'language_code'  => (string) $repair['language_code'],
					'from'           => $repair['from'],
					'to'             => (string) $repair['to'],
				)
			);
		}

		$applied['updated_translation_ids'] = array_values( array_unique( array_map( 'intval', $applied['updated_translation_ids'] ) ) );

		return array(
			'applied'  => $applied,
			'failures' => $failures,
		);
	}
}

$cli_args = old_posts_collect_runtime_args(
	isset( $args ) && is_array( $args ) ? $args : array(),
	isset( $GLOBALS['argv'] ) && is_array( $GLOBALS['argv'] ) ? $GLOBALS['argv'] : array()
);

$manifest_path = isset( $cli_args['manifest'] ) ? (string) $cli_args['manifest'] : old_posts_default_temp_path( 'old-posts-manifest.json' );
$output_path   = isset( $cli_args['output'] ) ? (string) $cli_args['output'] : old_posts_default_temp_path( 'old-posts-wpml-consistency.json' );
$jsonl_path    = isset( $cli_args['jsonl'] ) ? (string) $cli_args['jsonl'] : old_posts_default_temp_path( 'old-posts-wpml-consistency.jsonl' );
$apply_repairs = old_posts_bool_arg( isset( $cli_args['apply'] ) ? $cli_args['apply'] : null, false );
$year_filter   = isset( $cli_args['year'] ) ? (string) $cli_args['year'] : null;
$confirm       = isset( $cli_args['confirm'] ) ? (string) $cli_args['confirm'] : '';
$progress_cfg  = old_posts_progress_config( $cli_args, 25, 30 );
$log_paths     = old_posts_wpml_consistency_log_paths( $cli_args );

$manifest         = old_posts_read_manifest( $manifest_path );
$availability     = old_posts_wpml_consistency_availability();

old_posts_assert_parent_writable( $output_path, 'WPML consistency JSON report' );
old_posts_assert_parent_writable( $jsonl_path, 'WPML consistency JSONL log' );

$expected_confirmation = old_posts_wpml_consistency_confirmation( $manifest_path, $log_paths );
if ( $availability['available'] && $apply_repairs && $confirm !== $expected_confirmation ) {
	old_posts_fail(
		'Missing or invalid confirmation token.',
		array(
			'expected' => $expected_confirmation,
			'phase'    => 'wpml-consistency',
		)
	);
}

$default_language = old_posts_wpml_default_language();

if ( ! $availability['available'] ) {
	$report = array(
		'generated_at'      => gmdate( 'c' ),
		'manifest'          => $manifest_path,
		'log_paths'         => $log_paths,
		'year_filter'       => $year_filter,
		'apply_repairs'     => false,
		'default_language'  => $default_language,
		'wpml_available'    => false,
		'skipped_reason'    => $availability['reason'],
		'summary'           => array(
			'affected_posts_from_logs'       => 0,
			'affected_attachments_from_logs' => 0,
			'affected_trids'                => 0,
			'groups_with_issues'            => 0,
			'repairable_groups'             => 0,
			'applied_groups'                => 0,
			'applied_failures'              => 0,
			'missing_manifest_ids'          => array(
				'posts'       => array(),
				'attachments' => array(),
			),
		),
		'items'             => array(),
	);

	old_posts_write_json( $output_path, $report );
	old_posts_append_jsonl(
		$jsonl_path,
		array(
			'timestamp'      => gmdate( 'c' ),
			'phase'          => 'wpml-consistency',
			'action'         => 'skip_execution',
			'status'         => 'skipped',
			'apply'          => false,
			'year_filter'    => $year_filter,
			'log_paths'      => $log_paths,
			'manifest'       => $manifest_path,
			'default_language' => $default_language,
			'reason'         => $availability['reason'],
		)
	);

	old_posts_log(
		'success',
		'WPML consistency check skipped because WPML is not available on the current site.',
		array(
			'output'        => $output_path,
			'jsonl'         => $jsonl_path,
			'skipped_reason' => $availability['reason'],
		)
	);
	exit( 0 );
}

$manifest_maps    = old_posts_wpml_manifest_maps( $manifest );
$affected_entries = old_posts_wpml_affected_entries_from_logs( $log_paths );
$affected_groups  = old_posts_wpml_affected_groups( $manifest_maps, $affected_entries );
$groups           = $affected_groups['groups'];

old_posts_append_jsonl(
	$jsonl_path,
	array(
		'timestamp'      => gmdate( 'c' ),
		'phase'          => 'wpml-consistency',
		'action'         => 'start_execution',
		'status'         => 'started',
		'apply'          => $apply_repairs,
		'year_filter'    => $year_filter,
		'log_paths'      => $log_paths,
		'manifest'       => $manifest_path,
		'default_language' => $default_language,
	)
);

$rows_by_trid      = old_posts_wpml_fetch_translation_rows( array_keys( $groups ) );
$existing_post_ids = old_posts_wpml_existing_post_ids( $rows_by_trid );
$progress_state    = old_posts_progress_state( $progress_cfg['every'], $progress_cfg['seconds'] );
$report_items      = array();
$issues_found      = 0;
$repairable_count  = 0;
$applied_groups    = 0;
$applied_failures  = 0;

foreach ( $groups as $trid => $group_meta ) {
	$analysis = old_posts_wpml_group_analysis(
		$group_meta,
		isset( $rows_by_trid[ $trid ] ) ? $rows_by_trid[ $trid ] : array(),
		$existing_post_ids,
		$default_language
	);

	if ( ! empty( $analysis['issues'] ) ) {
		++$issues_found;
	}

	if ( ! empty( $analysis['repairable'] ) ) {
		++$repairable_count;
	}

	if ( $apply_repairs && ! empty( $analysis['repairable'] ) ) {
		$apply_result = old_posts_wpml_apply_repairs( $analysis, $jsonl_path );
		$analysis['apply_result'] = $apply_result;
		if ( ! empty( $apply_result['applied']['deleted_orphan_translation_ids'] ) || ! empty( $apply_result['applied']['updated_translation_ids'] ) ) {
			++$applied_groups;
		}
		$applied_failures += count( $apply_result['failures'] );
	} else {
		$analysis['apply_result'] = array(
			'applied'  => array(
				'deleted_orphan_translation_ids' => array(),
				'updated_translation_ids'        => array(),
			),
			'failures' => array(),
		);
	}

	$report_items[] = $analysis;

	old_posts_progress_maybe_log(
		$progress_state,
		'Checking WPML translation consistency.',
		count( $report_items ),
		count( $groups ),
		array(
			'groups_with_issues' => $issues_found,
			'repairable_groups'  => $repairable_count,
			'applied_groups'     => $applied_groups,
		),
		array(
			'phase'    => 'wpml-consistency',
			'log_path' => $jsonl_path,
			'action'   => 'progress_checkpoint',
		)
	);
}

old_posts_progress_maybe_log(
	$progress_state,
	'WPML consistency check complete.',
	count( $groups ),
	count( $groups ),
	array(
		'groups_with_issues' => $issues_found,
		'repairable_groups'  => $repairable_count,
		'applied_groups'     => $applied_groups,
	),
	array(
		'phase'    => 'wpml-consistency',
		'log_path' => $jsonl_path,
		'action'   => 'progress_checkpoint',
		'force'    => true,
	)
);

$report = array(
	'generated_at' => gmdate( 'c' ),
	'manifest'     => $manifest_path,
	'log_paths'    => $log_paths,
	'year_filter'  => $year_filter,
	'apply_repairs' => $apply_repairs,
	'default_language' => $default_language,
	'wpml_available' => true,
	'summary'      => array(
		'affected_posts_from_logs'       => count( $affected_entries['posts'] ),
		'affected_attachments_from_logs' => count( $affected_entries['attachments'] ),
		'affected_trids'                => count( $groups ),
		'groups_with_issues'            => $issues_found,
		'repairable_groups'             => $repairable_count,
		'applied_groups'                => $applied_groups,
		'applied_failures'              => $applied_failures,
		'missing_manifest_ids'          => $affected_groups['missing_manifest_ids'],
	),
	'items'        => $report_items,
);

old_posts_write_json( $output_path, $report );

old_posts_append_jsonl(
	$jsonl_path,
	array(
		'timestamp'      => gmdate( 'c' ),
		'phase'          => 'wpml-consistency',
		'action'         => 'finish_execution',
		'status'         => $applied_failures ? 'warning' : 'success',
		'apply'          => $apply_repairs,
		'affected_trids' => count( $groups ),
		'groups_with_issues' => $issues_found,
		'repairable_groups'  => $repairable_count,
		'applied_groups'     => $applied_groups,
		'applied_failures'   => $applied_failures,
	)
);

old_posts_log(
	$applied_failures ? 'warning' : 'success',
	'WPML consistency check finished.',
	array(
		'output'             => $output_path,
		'jsonl'              => $jsonl_path,
		'apply'              => $apply_repairs,
		'affected_trids'     => count( $groups ),
		'groups_with_issues' => $issues_found,
		'repairable_groups'  => $repairable_count,
		'applied_groups'     => $applied_groups,
		'applied_failures'   => $applied_failures,
	)
);
