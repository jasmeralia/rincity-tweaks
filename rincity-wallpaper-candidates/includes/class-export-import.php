<?php
defined( 'ABSPATH' ) || exit;

/** Portable JSON backup/restore for wallpaper review state. */
final class RinCWC_Export_Import {

    public const EXPORT_FORMAT = 'rincwc-json-export';
    public const SCHEMA_VERSION = '1.0';

    /** Bind an approval-provenance override to this import, user, and image. */
    public static function approval_token( int $image_id, int $approved_by, string $approved_at ): string {
        return wp_hash( get_current_user_id() . "|{$image_id}|{$approved_by}|{$approved_at}", 'nonce' );
    }

    public static function verify_approval_token( string $token, int $image_id, int $approved_by, string $approved_at ): bool {
        return $token !== '' && hash_equals( self::approval_token( $image_id, $approved_by, $approved_at ), $token );
    }

    /** Build the complete, unfiltered v1 export document. */
    public static function build_export(): array {
        $watermarks     = RinCWC_Data::get_watermarks();
        $watermark_json = [];
        $hash_by_id     = [];

        foreach ( $watermarks as $watermark ) {
            $path = (string) $watermark['file_path'];
            if ( ! is_readable( $path ) ) {
                throw new RuntimeException( 'Watermark file is not readable: ' . basename( $path ) );
            }
            $bytes = file_get_contents( $path );
            if ( $bytes === false ) {
                throw new RuntimeException( 'Could not read watermark file: ' . basename( $path ) );
            }
            $hash = hash( 'sha256', $bytes );
            $hash_by_id[ (int) $watermark['id'] ] = $hash;
            $watermark_json[] = [
                'name'        => (string) $watermark['name'],
                'is_default'  => ! empty( $watermark['is_default'] ),
                'file_name'   => basename( $path ),
                'mime_type'   => 'image/png',
                'sha256'      => $hash,
                'data_base64' => base64_encode( $bytes ),
            ];
        }

        $galleries = self::gallery_metadata_index();
        $overrides = [];
        foreach ( RinCWC_Data::get_gallery_watermarks() as $gallery_id => $watermark_id ) {
            if ( empty( $hash_by_id[ $watermark_id ] ) ) {
                continue;
            }
            $meta = $galleries[ $gallery_id ] ?? [];
            $overrides[] = [
                'gallery_id'      => (int) $gallery_id,
                'gallery_slug'    => (string) ( $meta['gallery_slug'] ?? '' ),
                'watermark_sha256' => $hash_by_id[ $watermark_id ],
            ];
        }

        $cutoffs  = [];
        $settings = RinCWC_Data::get_settings();
        foreach ( (array) ( $settings['excluded_after'] ?? [] ) as $gallery_id => $position ) {
            $gallery_id = (int) $gallery_id;
            $position   = (int) $position;
            if ( ! $gallery_id || $position === 0 ) {
                continue;
            }
            $meta = $galleries[ $gallery_id ] ?? [];
            $cutoffs[] = [
                'gallery_id'    => $gallery_id,
                'gallery_slug'  => (string) ( $meta['gallery_slug'] ?? '' ),
                'gallery_title' => (string) ( $meta['gallery_title'] ?? '' ),
                'position'      => $position,
            ];
        }

        $images = [];
        foreach ( RinCWC_Data::export_rows() as $row ) {
            $image_key = (int) $row['gallery_id'] . ':' . (int) $row['attach_id'];
            $comments  = [];
            foreach ( RinCWC_DB::get_comments( $image_key ) as $comment ) {
                $comments[] = [
                    'user_login' => (string) $comment['user_login'],
                    'body'       => (string) $comment['body'],
                    'created_at' => (string) $comment['created_at'],
                    'updated_at' => (string) $comment['updated_at'],
                ];
            }

            $selection = null;
            if ( ! empty( $row['selection_id'] ) ) {
                $watermark_id = (int) ( $row['wm_file_id'] ?? 0 );
                if ( ! $watermark_id ) {
                    $effective    = RinCWC_Data::get_effective_watermark( (int) $row['gallery_id'] );
                    $watermark_id = (int) ( $effective['id'] ?? 0 );
                }
                $selection = [
                    'crop_variant'      => (string) ( $row['crop_variant'] ?? '' ),
                    'custom_crop_scale' => $row['custom_crop_scale'] !== null ? (float) $row['custom_crop_scale'] : null,
                    'custom_crop_x'     => $row['custom_crop_x'] !== null ? (int) $row['custom_crop_x'] : null,
                    'custom_crop_y'     => $row['custom_crop_y'] !== null ? (int) $row['custom_crop_y'] : null,
                    'wm_corner'         => (string) ( $row['wm_corner'] ?? '' ),
                    'watermark_sha256'  => $hash_by_id[ $watermark_id ] ?? null,
                ];
            }

            $approver = ! empty( $row['approved_by'] ) ? get_userdata( (int) $row['approved_by'] ) : false;
            $images[] = [
                'gallery_id'                    => (int) $row['gallery_id'],
                'attach_id'                     => (int) $row['attach_id'],
                'gallery_slug'                  => (string) $row['gallery_slug'],
                'gallery_title'                 => (string) $row['gallery_title'],
                'filename'                      => self::row_filename( $row ),
                'status'                        => (string) $row['status'],
                'selection'                     => $selection,
                'approved_by_user_login'        => $approver ? (string) $approver->user_login : null,
                'approved_at'                   => $row['approved_at'] ?: null,
                'comments'                      => $comments,
            ];
        }

        return [
            'export_format'               => self::EXPORT_FORMAT,
            'schema_version'              => self::SCHEMA_VERSION,
            'plugin_version'              => RINCWC_VERSION,
            'exported_at'                 => gmdate( DATE_ATOM ),
            'source_site'                 => home_url(),
            'source_hostname'             => self::hostname(),
            'watermarks'                  => $watermark_json,
            'gallery_watermark_overrides' => $overrides,
            'cutoffs'                     => $cutoffs,
            'images'                      => $images,
        ];
    }

    /** Validate and normalize a decoded JSON document. */
    public static function validate_document( $document ) {
        if ( ! is_array( $document ) ) {
            return new WP_Error( 'rincwc_import_json', 'The import must be a JSON object.' );
        }
        if ( ( $document['export_format'] ?? '' ) !== self::EXPORT_FORMAT ) {
            return new WP_Error( 'rincwc_import_format', 'This is not a RinCWC JSON export.' );
        }
        if ( (string) ( $document['schema_version'] ?? '' ) !== self::SCHEMA_VERSION ) {
            return new WP_Error( 'rincwc_import_schema', 'Unsupported export schema version.' );
        }
        foreach ( [ 'watermarks', 'gallery_watermark_overrides', 'cutoffs', 'images' ] as $field ) {
            if ( ! isset( $document[ $field ] ) || ! is_array( $document[ $field ] ) ) {
                return new WP_Error( 'rincwc_import_field', "Missing or invalid {$field} array." );
            }
        }

        $clean = [
            'export_format'               => self::EXPORT_FORMAT,
            'schema_version'              => self::SCHEMA_VERSION,
            'plugin_version'              => sanitize_text_field( (string) ( $document['plugin_version'] ?? '' ) ),
            'exported_at'                 => sanitize_text_field( (string) ( $document['exported_at'] ?? '' ) ),
            'source_site'                 => esc_url_raw( (string) ( $document['source_site'] ?? '' ) ),
            'source_hostname'             => sanitize_text_field( (string) ( $document['source_hostname'] ?? '' ) ),
            'watermarks'                  => [],
            'gallery_watermark_overrides' => [],
            'cutoffs'                     => [],
            'images'                      => [],
        ];

        $default_count = 0;
        foreach ( $document['watermarks'] as $index => $watermark ) {
            if ( ! is_array( $watermark ) ) {
                return new WP_Error( 'rincwc_import_watermark', "Watermark {$index} is invalid." );
            }
            $hash  = strtolower( sanitize_text_field( (string) ( $watermark['sha256'] ?? '' ) ) );
            $data  = (string) ( $watermark['data_base64'] ?? '' );
            $bytes = base64_decode( $data, true );
            if ( ! preg_match( '/^[a-f0-9]{64}$/', $hash ) || $bytes === false || hash( 'sha256', $bytes ) !== $hash ) {
                return new WP_Error( 'rincwc_import_watermark_hash', "Watermark {$index} has invalid data or a hash mismatch." );
            }
            if ( substr( $bytes, 0, 8 ) !== "\x89PNG\r\n\x1a\n" || (string) ( $watermark['mime_type'] ?? '' ) !== 'image/png' ) {
                return new WP_Error( 'rincwc_import_watermark_type', "Watermark {$index} is not a PNG." );
            }
            $clean['watermarks'][] = [
                'name'        => sanitize_text_field( (string) ( $watermark['name'] ?? '' ) ) ?: 'Imported watermark',
                'is_default'  => ! empty( $watermark['is_default'] ),
                'file_name'   => sanitize_file_name( (string) ( $watermark['file_name'] ?? '' ) ) ?: "watermark-{$hash}.png",
                'mime_type'   => 'image/png',
                'sha256'      => $hash,
                'data_base64' => $data,
            ];
            if ( ! empty( $watermark['is_default'] ) ) {
                $default_count++;
            }
        }
        if ( $default_count > 1 ) {
            return new WP_Error( 'rincwc_import_watermark_default', 'The export contains more than one default watermark.' );
        }

        foreach ( $document['gallery_watermark_overrides'] as $index => $override ) {
            if ( ! is_array( $override ) ) {
                return new WP_Error( 'rincwc_import_override', "Gallery watermark override {$index} is invalid." );
            }
            $hash = strtolower( sanitize_text_field( (string) ( $override['watermark_sha256'] ?? '' ) ) );
            if ( (int) ( $override['gallery_id'] ?? 0 ) <= 0 || ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
                return new WP_Error( 'rincwc_import_override', "Gallery watermark override {$index} is invalid." );
            }
            $clean['gallery_watermark_overrides'][] = [
                'gallery_id'       => (int) $override['gallery_id'],
                'gallery_slug'     => sanitize_title( (string) ( $override['gallery_slug'] ?? '' ) ),
                'watermark_sha256' => $hash,
            ];
        }

        foreach ( $document['cutoffs'] as $index => $cutoff ) {
            if ( ! is_array( $cutoff ) || (int) ( $cutoff['gallery_id'] ?? 0 ) <= 0 ) {
                return new WP_Error( 'rincwc_import_cutoff', "Cutoff {$index} is invalid." );
            }
            $position = (int) ( $cutoff['position'] ?? 0 );
            if ( $position === 0 || $position < RinCWC_Data::CUTOFF_EXCLUDE_ALL ) {
                return new WP_Error( 'rincwc_import_cutoff', "Cutoff {$index} has an invalid position." );
            }
            $clean['cutoffs'][] = [
                'gallery_id'    => (int) $cutoff['gallery_id'],
                'gallery_slug'  => sanitize_title( (string) ( $cutoff['gallery_slug'] ?? '' ) ),
                'gallery_title' => sanitize_text_field( (string) ( $cutoff['gallery_title'] ?? '' ) ),
                'position'      => $position,
            ];
        }

        $valid_statuses = [ RinCWC_Data::STATUS_CANDIDATE, RinCWC_Data::STATUS_SELECTED, RinCWC_Data::STATUS_APPROVED ];
        foreach ( $document['images'] as $index => $image ) {
            if ( ! is_array( $image ) || (int) ( $image['gallery_id'] ?? 0 ) <= 0 || (int) ( $image['attach_id'] ?? 0 ) <= 0 ) {
                return new WP_Error( 'rincwc_import_image', "Image {$index} has an invalid identity." );
            }
            $status = strtoupper( sanitize_text_field( (string) ( $image['status'] ?? '' ) ) );
            if ( ! in_array( $status, $valid_statuses, true ) ) {
                return new WP_Error( 'rincwc_import_image_status', "Image {$index} has an invalid status." );
            }
            $selection = null;
            if ( array_key_exists( 'selection', $image ) && $image['selection'] !== null ) {
                if ( ! is_array( $image['selection'] ) ) {
                    return new WP_Error( 'rincwc_import_selection', "Image {$index} has an invalid selection." );
                }
                $source_selection = $image['selection'];
                $wm_hash = $source_selection['watermark_sha256'] ?? null;
                if ( $wm_hash !== null ) {
                    $wm_hash = strtolower( sanitize_text_field( (string) $wm_hash ) );
                    if ( ! preg_match( '/^[a-f0-9]{64}$/', $wm_hash ) ) {
                        return new WP_Error( 'rincwc_import_selection', "Image {$index} has an invalid watermark hash." );
                    }
                }
                $selection = [
                    'crop_variant'      => sanitize_key( (string) ( $source_selection['crop_variant'] ?? '' ) ),
                    'custom_crop_scale' => isset( $source_selection['custom_crop_scale'] ) ? (float) $source_selection['custom_crop_scale'] : null,
                    'custom_crop_x'     => isset( $source_selection['custom_crop_x'] ) ? (int) $source_selection['custom_crop_x'] : null,
                    'custom_crop_y'     => isset( $source_selection['custom_crop_y'] ) ? (int) $source_selection['custom_crop_y'] : null,
                    'wm_corner'         => sanitize_text_field( (string) ( $source_selection['wm_corner'] ?? '' ) ),
                    'watermark_sha256'  => $wm_hash,
                ];
            }

            $comments = [];
            if ( ! isset( $image['comments'] ) || ! is_array( $image['comments'] ) ) {
                return new WP_Error( 'rincwc_import_comments', "Image {$index} has an invalid comments array." );
            }
            foreach ( $image['comments'] as $comment_index => $comment ) {
                if ( ! is_array( $comment ) ) {
                    return new WP_Error( 'rincwc_import_comment', "Image {$index}, comment {$comment_index} is invalid." );
                }
                $created_at = sanitize_text_field( (string) ( $comment['created_at'] ?? '' ) );
                $updated_at = sanitize_text_field( (string) ( $comment['updated_at'] ?? '' ) );
                if ( ! self::valid_mysql_datetime( $created_at ) || ! self::valid_mysql_datetime( $updated_at ) ) {
                    return new WP_Error( 'rincwc_import_comment_time', "Image {$index}, comment {$comment_index} has an invalid timestamp." );
                }
                $comments[] = [
                    'user_login' => sanitize_user( (string) ( $comment['user_login'] ?? '' ), true ),
                    'body'       => sanitize_textarea_field( (string) ( $comment['body'] ?? '' ) ),
                    'created_at' => $created_at,
                    'updated_at' => $updated_at,
                ];
            }

            $approved_at = $image['approved_at'] ?? null;
            if ( $approved_at !== null ) {
                $approved_at = sanitize_text_field( (string) $approved_at );
                if ( ! self::valid_mysql_datetime( $approved_at ) ) {
                    return new WP_Error( 'rincwc_import_approval_time', "Image {$index} has an invalid approval timestamp." );
                }
            }
            $clean['images'][] = [
                'gallery_id'                    => (int) $image['gallery_id'],
                'attach_id'                     => (int) $image['attach_id'],
                'gallery_slug'                  => sanitize_title( (string) ( $image['gallery_slug'] ?? '' ) ),
                'gallery_title'                 => sanitize_text_field( (string) ( $image['gallery_title'] ?? '' ) ),
                'filename'                      => basename( sanitize_text_field( (string) ( $image['filename'] ?? '' ) ) ),
                'status'                        => $status,
                'selection'                     => $selection,
                'approved_by_user_login'        => sanitize_user( (string) ( $image['approved_by_user_login'] ?? '' ), true ) ?: null,
                'approved_at'                   => $approved_at,
                'comments'                      => $comments,
            ];
        }

        return $clean;
    }

    /** Return a no-write diff used by the import review UI. */
    public static function dry_run( array $document ): array {
        $watermark_index = self::local_watermark_index();
        foreach ( $document['watermarks'] as $watermark ) {
            $match = self::find_local_watermark( $watermark, $watermark_index );
            $watermark_index['source_hash_to_id'][ $watermark['sha256'] ] = (int) ( $match['id'] ?? 0 );
        }
        $image_results   = [];
        $comment_results = [
            'comment_add'       => [],
            'comment_unchanged' => [],
            'comment_conflict'  => [],
            'comment_skipped'   => [],
        ];
        $fallbacks = [];

        foreach ( $document['images'] as $source ) {
            $gallery_id = (int) $source['gallery_id'];
            $attach_id  = (int) $source['attach_id'];
            $key        = "{$gallery_id}:{$attach_id}";
            $local      = RinCWC_Data::get_image_by_gallery_attach( $gallery_id, $attach_id );
            $entry      = [
                'image_key'    => $key,
                'gallery_id'   => $gallery_id,
                'attach_id'    => $attach_id,
                'gallery'      => $source['gallery_title'] ?: $source['gallery_slug'],
                'filename'     => $source['filename'],
                'source_status' => $source['status'],
                'changes'      => [],
            ];

            if ( ! $local ) {
                $entry['result'] = 'unmatched';
            } elseif ( self::image_identity_mismatch( $source, $local ) ) {
                $entry['result']         = 'id_mismatch';
                $entry['local_slug']     = (string) $local['gallery_slug'];
                $entry['local_filename'] = self::row_filename( $local );
            } else {
                $state_source = $source;
                if ( $source['status'] === RinCWC_Data::STATUS_APPROVED ) {
                    $resolved_approver = self::resolve_user( $source['approved_by_user_login'] );
                    $state_source['approved_by_user_login'] = $resolved_approver['resolved_login'];
                    if ( $resolved_approver['fallback'] ) {
                        self::add_fallback_note(
                            $fallbacks,
                            'approval',
                            $key,
                            (string) $source['approved_by_user_login'],
                            $resolved_approver['resolved_login']
                        );
                    }
                }
                $source_state = self::source_image_state( $state_source, $watermark_index );
                $local_state  = self::local_image_state( $local, $watermark_index );
                $entry['changes'] = self::state_changes( $source_state, $local_state );

                $local_comments = RinCWC_DB::get_comments( $key );
                $local_pristine = $local['status'] === RinCWC_Data::STATUS_CANDIDATE
                    && ! RinCWC_Data::get_selection( (int) $local['id'] )
                    && empty( $local_comments );
                if ( $local_pristine ) {
                    $entry['result'] = 'would_add';
                } elseif ( $entry['changes'] ) {
                    $entry['result'] = 'would_overwrite';
                } else {
                    $entry['result'] = 'unchanged';
                }
            }
            $image_results[] = $entry;

            if ( $entry['result'] === 'id_mismatch' ) {
                foreach ( $source['comments'] as $comment ) {
                    $comment_results['comment_skipped'][] = self::comment_summary( $source, $comment ) + [
                        'reason' => 'Image ID mismatch',
                    ];
                }
                continue;
            }
            foreach ( $source['comments'] as $comment ) {
                $analysis = self::analyze_comment( $source, $comment, $document['source_hostname'], $fallbacks );
                $comment_results[ $analysis['result'] ][] = $analysis;
            }
        }

        $watermark_results = [];
        foreach ( $document['watermarks'] as $watermark ) {
            $match = self::find_local_watermark( $watermark, $watermark_index );
            $watermark_results[] = [
                'name'            => $watermark['name'],
                'sha256'          => $watermark['sha256'],
                'is_default'      => $watermark['is_default'],
                'result'          => $match ? 'existing' : 'new',
                'matched_by'      => $match['matched_by'] ?? null,
                'content_differs' => ! empty( $match['content_differs'] ),
                'local_sha256'    => $match['local_sha256'] ?? null,
            ];
        }

        return [
            'source' => [
                'site'       => $document['source_site'],
                'hostname'   => $document['source_hostname'],
                'exported_at'=> $document['exported_at'],
                'plugin_version' => $document['plugin_version'],
            ],
            'target_hostname' => self::hostname(),
            'images'          => $image_results,
            'watermarks'      => $watermark_results,
            'comments'        => $comment_results,
            'cutoffs'         => self::cutoff_diff( $document ),
            'gallery_watermark_overrides' => self::gallery_override_diff( $document, $watermark_index ),
            'user_fallbacks'  => array_values( $fallbacks ),
            'counts'          => self::diff_counts( $image_results, $comment_results, $watermark_results ),
        ];
    }

    /** Best-effort, non-transactional DB apply. */
    public static function apply( array $document, array $options ): array {
        $force          = ! empty( $options['force'] );
        $overwrite_keys = array_fill_keys( array_map( 'strval', (array) ( $options['overwrite_image_keys'] ?? [] ) ), true );
        $comment_choices = is_array( $options['comment_choices'] ?? null ) ? $options['comment_choices'] : [];
        $before_diff    = self::dry_run( $document );
        $watermark_ids_before = self::resolved_watermark_ids_for_document( $document );
        $summary        = [
            'watermarks_created' => 0,
            'watermarks_reused'  => 0,
            'cutoffs_applied'    => 0,
            'overrides_applied'  => 0,
            'images_applied'     => 0,
            'images_skipped'     => 0,
            'comments_added'     => 0,
            'comments_imported'  => 0,
            'comments_kept_local'=> 0,
            'errors'             => [],
            'reattributed_users' => $before_diff['user_fallbacks'],
        ];

        $watermarks = self::apply_watermarks_from_document( $document, $summary );
        $hash_to_id = $watermarks['hash_to_id'];

        $gallery_meta = self::gallery_metadata_index();
        foreach ( $document['gallery_watermark_overrides'] as $override ) {
            $gallery_id = (int) $override['gallery_id'];
            $local_meta = $gallery_meta[ $gallery_id ] ?? null;
            if ( ( ! $local_meta && $override['gallery_slug'] )
                || ( $local_meta && $override['gallery_slug'] && $override['gallery_slug'] !== $local_meta['gallery_slug'] ) ) {
                $summary['errors'][] = "Skipped watermark override for gallery {$gallery_id}: gallery is missing or mismatched.";
                continue;
            }
            $watermark_id = (int) ( $hash_to_id[ $override['watermark_sha256'] ] ?? 0 );
            if ( ! $watermark_id ) {
                $summary['errors'][] = "Skipped watermark override for gallery {$gallery_id}: watermark is unavailable.";
                continue;
            }
            $current = RinCWC_Data::get_gallery_watermarks();
            $changed = (int) ( $current[ $gallery_id ] ?? 0 ) !== $watermark_id;
            if ( ! $changed ) {
                continue;
            }
            if ( RinCWC_Data::set_gallery_watermark( $gallery_id, $watermark_id, false ) ) {
                $summary['overrides_applied']++;
            } else {
                $summary['errors'][] = "Could not apply watermark override for gallery {$gallery_id}.";
            }
        }

        foreach ( $document['cutoffs'] as $cutoff ) {
            $gallery_id = (int) $cutoff['gallery_id'];
            $local_meta = $gallery_meta[ $gallery_id ] ?? null;
            if ( RinCWC_Data::get_cutoff( $gallery_id ) === (int) $cutoff['position'] ) {
                continue;
            }
            if ( ( ! $local_meta && $cutoff['gallery_slug'] )
                || ( $local_meta && $cutoff['gallery_slug'] && $cutoff['gallery_slug'] !== $local_meta['gallery_slug'] ) ) {
                $summary['errors'][] = "Skipped cutoff for gallery {$gallery_id}: gallery is missing or mismatched.";
                continue;
            }
            // Export stores last-included; set_gallery_cutoff() accepts first-excluded.
            $position = (int) $cutoff['position'];
            RinCWC_Data::set_gallery_cutoff( $gallery_id, $position > 0 ? $position + 1 : $position );
            $summary['cutoffs_applied']++;
        }

        $diff_by_key = [];
        foreach ( $before_diff['images'] as $entry ) {
            $diff_by_key[ $entry['image_key'] ] = $entry;
        }
        $regenerate      = [];
        $needs_reapprove = [];
        $watermark_ids_after = self::resolved_watermark_ids_for_document( $document );

        foreach ( $document['images'] as $source ) {
            $gallery_id = (int) $source['gallery_id'];
            $attach_id  = (int) $source['attach_id'];
            $key        = "{$gallery_id}:{$attach_id}";
            $diff       = $diff_by_key[ $key ] ?? [ 'result' => 'unmatched' ];
            $should_apply = $diff['result'] === 'would_add'
                || ( $diff['result'] === 'would_overwrite' && ( $force || isset( $overwrite_keys[ $key ] ) ) )
                || self::unchanged_image_needs_regeneration( $source, $diff, $watermark_ids_before, $watermark_ids_after, $key );

            if ( ! $should_apply ) {
                $summary['images_skipped']++;
                continue;
            }
            $local = RinCWC_Data::get_image_by_gallery_attach( $gallery_id, $attach_id );
            if ( ! $local || self::image_identity_mismatch( $source, $local ) ) {
                $summary['images_skipped']++;
                continue;
            }

            $image_id = (int) $local['id'];
            $ok       = true;
            if ( $source['status'] === RinCWC_Data::STATUS_APPROVED
                && ( $source['selection'] === null
                    || empty( $source['selection']['crop_variant'] )
                    || empty( $source['selection']['wm_corner'] ) ) ) {
                $summary['errors'][] = "Could not apply {$key}: an approved image needs a crop and watermark corner.";
                continue;
            }
            if ( $source['selection'] === null ) {
                $ok = RinCWC_Data::delete_selection( $image_id );
            } else {
                $selection = $source['selection'];
                $wm_hash   = $selection['watermark_sha256'];
                if ( $wm_hash && empty( $hash_to_id[ $wm_hash ] ) ) {
                    $summary['errors'][] = "Could not apply {$key}: referenced watermark is unavailable.";
                    continue;
                }
                $ok = RinCWC_Data::upsert_selection( $image_id, [
                    'crop_variant'      => $selection['crop_variant'],
                    'custom_crop_scale' => $selection['custom_crop_scale'],
                    'custom_crop_x'     => $selection['custom_crop_x'],
                    'custom_crop_y'     => $selection['custom_crop_y'],
                    'wm_corner'         => $selection['wm_corner'],
                    'wm_file_id'        => $wm_hash ? (int) $hash_to_id[ $wm_hash ] : null,
                    'wm_applied'        => 0,
                ] );
            }
            if ( ! $ok ) {
                $summary['errors'][] = "Could not apply selection for {$key}.";
                continue;
            }

            $target_status = $source['status'] === RinCWC_Data::STATUS_APPROVED
                ? RinCWC_Data::STATUS_SELECTED
                : $source['status'];
            if ( ! RinCWC_Data::set_status( $image_id, $target_status ) ) {
                $summary['errors'][] = "Could not apply status for {$key}.";
                continue;
            }
            $summary['images_applied']++;

            if ( $source['selection'] !== null && in_array( $source['status'], [ RinCWC_Data::STATUS_SELECTED, RinCWC_Data::STATUS_APPROVED ], true ) ) {
                $job = [
                    'image_id'        => $image_id,
                    'gallery_id'      => $gallery_id,
                    'attach_id'       => $attach_id,
                    'gallery_title'   => (string) $local['gallery_title'],
                    'position'        => (int) $local['position'],
                    'needs_watermark' => ! empty( $source['selection']['wm_corner'] ),
                    'needs_reapprove' => $source['status'] === RinCWC_Data::STATUS_APPROVED,
                ];
                if ( $job['needs_reapprove'] ) {
                    if ( empty( $source['approved_at'] ) ) {
                        $summary['errors'][] = "Cannot restore approval for {$key}: approval timestamp is missing.";
                        $job['needs_reapprove'] = false;
                    } else {
                        $resolved = self::resolve_user( $source['approved_by_user_login'] );
                        $job['approved_by'] = $resolved['user_id'];
                        $job['approved_at'] = $source['approved_at'];
                        $job['approved_by_user_login'] = $resolved['resolved_login'];
                        $job['approval_token'] = self::approval_token( $image_id, $resolved['user_id'], $source['approved_at'] );
                        $needs_reapprove[] = $image_id;
                        if ( $resolved['fallback'] ) {
                            self::add_fallback_note(
                                $summary['reattributed_users'],
                                'approval',
                                $key,
                                (string) $source['approved_by_user_login'],
                                $resolved['resolved_login']
                            );
                        }
                    }
                }
                $regenerate[] = $job;
            }
        }

        foreach ( $before_diff['comments']['comment_add'] as $comment ) {
            if ( RinCWC_DB::import_comment( $comment['image_key'], (int) $comment['resolved_user_id'], $comment['import_body'], $comment['created_at'], $comment['import_updated_at'] ) ) {
                $summary['comments_added']++;
            } else {
                $summary['errors'][] = 'Could not add comment ' . $comment['conflict_id'] . '.';
            }
        }
        foreach ( $before_diff['comments']['comment_conflict'] as $comment ) {
            $choice = $force ? 'import' : sanitize_key( (string) ( $comment_choices[ $comment['conflict_id'] ] ?? 'local' ) );
            if ( $choice !== 'import' ) {
                $summary['comments_kept_local']++;
                continue;
            }
            if ( RinCWC_DB::import_update_comment( (int) $comment['local_id'], (int) $comment['resolved_user_id'], $comment['import_body'], $comment['import_updated_at'] ) ) {
                $summary['comments_imported']++;
            } else {
                $summary['errors'][] = 'Could not resolve comment conflict ' . $comment['conflict_id'] . '.';
            }
        }

        $deduped_fallbacks = [];
        foreach ( $summary['reattributed_users'] as $fallback ) {
            self::add_fallback_note(
                $deduped_fallbacks,
                (string) $fallback['entity'],
                (string) $fallback['image_key'],
                (string) $fallback['source_login'],
                (string) $fallback['attributed_to']
            );
        }
        $summary['reattributed_users'] = array_values( $deduped_fallbacks );
        return [
            'ok'              => empty( $summary['errors'] ),
            'summary'         => $summary,
            'regenerate'      => $regenerate,
            'needs_reapprove' => $needs_reapprove,
        ];
    }

    private static function apply_watermarks_from_document( array $document, array &$summary ): array {
        $index       = self::local_watermark_index();
        $hash_to_id  = [];
        $default_id  = 0;
        wp_mkdir_p( RINCWC_WATERMARKS_DIR );

        foreach ( $document['watermarks'] as $watermark ) {
            $match = self::find_local_watermark( $watermark, $index );
            if ( $match ) {
                $id = (int) $match['id'];
                $summary['watermarks_reused']++;
            } else {
                $bytes = base64_decode( $watermark['data_base64'], true );
                $source_filename = $watermark['file_name'];
                if ( strtolower( pathinfo( $source_filename, PATHINFO_EXTENSION ) ) !== 'png' ) {
                    $source_filename = pathinfo( $source_filename, PATHINFO_FILENAME ) . '.png';
                }
                $filename = wp_unique_filename( RINCWC_WATERMARKS_DIR, $source_filename );
                $path = trailingslashit( RINCWC_WATERMARKS_DIR ) . $filename;
                if ( $bytes === false || file_put_contents( $path, $bytes, LOCK_EX ) === false ) {
                    $summary['errors'][] = 'Could not write imported watermark ' . $watermark['name'] . '.';
                    continue;
                }
                $id = RinCWC_Data::add_watermark( $watermark['name'], $path, false );
                if ( ! $id ) {
                    wp_delete_file( $path );
                    $summary['errors'][] = 'Could not register imported watermark ' . $watermark['name'] . '.';
                    continue;
                }
                $summary['watermarks_created']++;
                $index['by_hash'][ $watermark['sha256'] ] = [
                    'id' => $id, 'name' => $watermark['name'], 'file_path' => $path,
                ];
                $index['by_name'][ strtolower( $watermark['name'] ) ] = $index['by_hash'][ $watermark['sha256'] ];
            }
            $hash_to_id[ $watermark['sha256'] ] = $id;
            if ( $watermark['is_default'] ) {
                $default_id = $id;
            }
        }

        if ( $default_id ) {
            $current_default = RinCWC_Data::get_default_watermark();
            if ( (int) ( $current_default['id'] ?? 0 ) !== $default_id
                && ! RinCWC_Data::set_default_watermark( $default_id, false ) ) {
                $summary['errors'][] = 'Could not set the imported default watermark.';
            }
        }
        return [ 'hash_to_id' => $hash_to_id ];
    }

    /**
     * Snapshot each imported image's locally resolved watermark. A non-zero
     * selection wm_file_id is an explicit pin; only an empty pin resolves through
     * the gallery override/default hierarchy and can move when that context changes.
     */
    private static function resolved_watermark_ids_for_document( array $document ): array {
        $resolved = [];
        foreach ( $document['images'] as $source ) {
            $gallery_id = (int) $source['gallery_id'];
            $attach_id  = (int) $source['attach_id'];
            $key        = "{$gallery_id}:{$attach_id}";
            $local      = RinCWC_Data::get_image_by_gallery_attach( $gallery_id, $attach_id );
            if ( ! $local || self::image_identity_mismatch( $source, $local ) ) {
                continue;
            }
            $selection = RinCWC_Data::get_selection( (int) $local['id'] );
            if ( ! $selection ) {
                continue;
            }
            $watermark_id = (int) ( $selection['wm_file_id'] ?? 0 );
            if ( ! $watermark_id ) {
                $effective    = RinCWC_Data::get_effective_watermark( $gallery_id );
                $watermark_id = (int) ( $effective['id'] ?? 0 );
            }
            $resolved[ $key ] = $watermark_id;
        }
        return $resolved;
    }

    /** True only when an otherwise-unchanged selection's own resolution moved. */
    private static function unchanged_image_needs_regeneration( array $source, array $diff, array $before, array $after, string $key ): bool {
        return $diff['result'] === 'unchanged'
            && $source['selection'] !== null
            && in_array( $source['status'], [ RinCWC_Data::STATUS_SELECTED, RinCWC_Data::STATUS_APPROVED ], true )
            && array_key_exists( $key, $before )
            && array_key_exists( $key, $after )
            && $before[ $key ] !== $after[ $key ];
    }

    private static function analyze_comment( array $image, array $comment, string $source_hostname, array &$fallbacks ): array {
        $key      = (int) $image['gallery_id'] . ':' . (int) $image['attach_id'];
        $resolved = self::resolve_user( $comment['user_login'] );
        if ( $resolved['fallback'] ) {
            self::add_fallback_note( $fallbacks, 'comment', $key, $comment['user_login'], $resolved['resolved_login'] );
        }
        $local = RinCWC_DB::find_import_comment( $key, $resolved['user_id'], $comment['created_at'] );
        $base  = self::comment_summary( $image, $comment ) + [
            'conflict_id'       => hash( 'sha256', $key . '|' . $comment['user_login'] . '|' . $comment['created_at'] ),
            'resolved_user_id'  => $resolved['user_id'],
            'resolved_login'    => $resolved['resolved_login'],
            'reattributed'      => $resolved['fallback'],
            'import_body'       => $comment['body'],
            'import_hostname'   => $source_hostname,
            'import_updated_at' => $comment['updated_at'],
        ];
        if ( ! $local ) {
            return $base + [ 'result' => 'comment_add' ];
        }
        if ( (string) $local['body'] === (string) $comment['body'] ) {
            return $base + [ 'result' => 'comment_unchanged', 'local_id' => (int) $local['id'] ];
        }
        return $base + [
            'result'           => 'comment_conflict',
            'local_id'         => (int) $local['id'],
            'local_body'       => (string) $local['body'],
            'local_hostname'   => self::hostname(),
            'local_updated_at' => (string) $local['updated_at'],
        ];
    }

    private static function comment_summary( array $image, array $comment ): array {
        return [
            'image_key'   => (int) $image['gallery_id'] . ':' . (int) $image['attach_id'],
            'gallery'     => $image['gallery_title'] ?: $image['gallery_slug'],
            'filename'    => $image['filename'],
            'user_login'  => $comment['user_login'],
            'created_at'  => $comment['created_at'],
        ];
    }

    private static function resolve_user( ?string $login ): array {
        $user = $login ? get_user_by( 'login', $login ) : false;
        $fallback = false;
        if ( ! $user ) {
            $user     = wp_get_current_user();
            $fallback = true;
        }
        return [
            'user_id'       => (int) $user->ID,
            'resolved_login'=> (string) $user->user_login,
            'fallback'      => $fallback,
        ];
    }

    private static function add_fallback_note( array &$notes, string $entity, string $image_key, string $source_login, string $resolved_login ): void {
        $key = hash( 'sha256', "{$entity}|{$image_key}|{$source_login}|{$resolved_login}" );
        $notes[ $key ] = [
            'entity'         => $entity,
            'image_key'      => $image_key,
            'source_login'   => $source_login,
            'attributed_to'  => $resolved_login,
        ];
    }

    private static function source_image_state( array $source, array $watermark_index ): array {
        $selection = $source['selection'];
        if ( $selection !== null && $selection['watermark_sha256'] ) {
            $selection['resolved_watermark_id'] = (int) ( $watermark_index['source_hash_to_id'][ $selection['watermark_sha256'] ] ?? 0 );
        } elseif ( $selection !== null ) {
            $selection['resolved_watermark_id'] = 0;
        }
        return [
            'status'         => $source['status'],
            'selection'      => self::comparable_selection( $selection ),
            'approved_by'    => $source['status'] === RinCWC_Data::STATUS_APPROVED ? (string) $source['approved_by_user_login'] : '',
            'approved_at'    => $source['status'] === RinCWC_Data::STATUS_APPROVED ? (string) $source['approved_at'] : '',
        ];
    }

    private static function local_image_state( array $local, array $watermark_index ): array {
        $selection = RinCWC_Data::get_selection( (int) $local['id'] );
        if ( $selection ) {
            $watermark_id = (int) ( $selection['wm_file_id'] ?? 0 );
            if ( ! $watermark_id ) {
                $effective    = RinCWC_Data::get_effective_watermark( (int) $local['gallery_id'] );
                $watermark_id = (int) ( $effective['id'] ?? 0 );
            }
            $selection['resolved_watermark_id'] = $watermark_id;
        }
        $approver = ! empty( $local['approved_by'] ) ? get_userdata( (int) $local['approved_by'] ) : false;
        return [
            'status'      => (string) $local['status'],
            'selection'   => self::comparable_selection( $selection ),
            'approved_by' => $local['status'] === RinCWC_Data::STATUS_APPROVED && $approver ? (string) $approver->user_login : '',
            'approved_at' => $local['status'] === RinCWC_Data::STATUS_APPROVED ? (string) $local['approved_at'] : '',
        ];
    }

    private static function comparable_selection( ?array $selection ): ?array {
        if ( $selection === null ) {
            return null;
        }
        return [
            'crop_variant'      => (string) ( $selection['crop_variant'] ?? '' ),
            'custom_crop_scale' => isset( $selection['custom_crop_scale'] ) ? round( (float) $selection['custom_crop_scale'], 6 ) : null,
            'custom_crop_x'     => isset( $selection['custom_crop_x'] ) ? (int) $selection['custom_crop_x'] : null,
            'custom_crop_y'     => isset( $selection['custom_crop_y'] ) ? (int) $selection['custom_crop_y'] : null,
            'wm_corner'         => (string) ( $selection['wm_corner'] ?? '' ),
            'watermark_id'      => (int) ( $selection['resolved_watermark_id'] ?? $selection['wm_file_id'] ?? 0 ),
        ];
    }

    private static function state_changes( array $source, array $local ): array {
        $changes = [];
        foreach ( [ 'status', 'selection', 'approved_by', 'approved_at' ] as $field ) {
            if ( $source[ $field ] !== $local[ $field ] ) {
                $changes[] = [
                    'field'  => $field,
                    'local'  => $local[ $field ],
                    'import' => $source[ $field ],
                ];
            }
        }
        return $changes;
    }

    private static function image_identity_mismatch( array $source, array $local ): bool {
        return ( $source['gallery_slug'] && $source['gallery_slug'] !== (string) $local['gallery_slug'] )
            || ( $source['filename'] && $source['filename'] !== self::row_filename( $local ) );
    }

    private static function cutoff_diff( array $document ): array {
        $galleries = self::gallery_metadata_index();
        $out       = [];
        foreach ( $document['cutoffs'] as $cutoff ) {
            $gallery_id = (int) $cutoff['gallery_id'];
            $local      = $galleries[ $gallery_id ] ?? null;
            $local_position = RinCWC_Data::get_cutoff( $gallery_id );
            $result     = 'change';
            if ( $local_position === (int) $cutoff['position'] ) {
                $result = 'unchanged';
            } elseif ( ! $local ) {
                $result = 'unmatched';
            } elseif ( $cutoff['gallery_slug'] && $cutoff['gallery_slug'] !== $local['gallery_slug'] ) {
                $result = 'id_mismatch';
            }
            $out[] = $cutoff + [
                'result'         => $result,
                'local_position' => $local_position,
            ];
        }
        return $out;
    }

    private static function gallery_override_diff( array $document, array $watermark_index ): array {
        $galleries = self::gallery_metadata_index();
        $current   = RinCWC_Data::get_gallery_watermarks();
        $out       = [];
        foreach ( $document['gallery_watermark_overrides'] as $override ) {
            $gallery_id = (int) $override['gallery_id'];
            $local      = $galleries[ $gallery_id ] ?? null;
            $resolved_id = (int) ( $watermark_index['source_hash_to_id'][ $override['watermark_sha256'] ] ?? 0 );
            $result = 'change';
            if ( $resolved_id && (int) ( $current[ $gallery_id ] ?? 0 ) === $resolved_id ) {
                $result = 'unchanged';
            } elseif ( ! $local ) {
                $result = 'unmatched';
            } elseif ( $override['gallery_slug'] && $override['gallery_slug'] !== $local['gallery_slug'] ) {
                $result = 'id_mismatch';
            }
            $out[] = $override + [
                'result'                => $result,
                'local_watermark_id'    => (int) ( $current[ $gallery_id ] ?? 0 ),
                'resolved_watermark_id' => $resolved_id,
            ];
        }
        return $out;
    }

    private static function diff_counts( array $images, array $comments, array $watermarks ): array {
        $counts = [];
        foreach ( $images as $image ) {
            $counts[ $image['result'] ] = ( $counts[ $image['result'] ] ?? 0 ) + 1;
        }
        foreach ( $comments as $result => $rows ) {
            $counts[ $result ] = count( $rows );
        }
        $counts['watermarks_new']      = count( array_filter( $watermarks, fn( $row ) => $row['result'] === 'new' ) );
        $counts['watermarks_existing'] = count( $watermarks ) - $counts['watermarks_new'];
        return $counts;
    }

    private static function local_watermark_index(): array {
        $index = [ 'by_hash' => [], 'by_name' => [], 'hash_by_id' => [] ];
        foreach ( RinCWC_Data::get_watermarks() as $watermark ) {
            $id   = (int) $watermark['id'];
            $path = (string) $watermark['file_path'];
            $hash = is_readable( $path ) ? hash_file( 'sha256', $path ) : false;
            $indexed = $watermark + [ 'local_sha256' => $hash ?: null ];
            if ( $hash ) {
                $index['by_hash'][ $hash ] = $indexed;
                $index['hash_by_id'][ $id ] = $hash;
            }
            $index['by_name'][ strtolower( (string) $watermark['name'] ) ] = $indexed;
        }
        return $index;
    }

    private static function find_local_watermark( array $watermark, array $index ): ?array {
        $hash = (string) ( $watermark['sha256'] ?? '' );
        if ( $hash && isset( $index['by_hash'][ $hash ] ) ) {
            return $index['by_hash'][ $hash ] + [
                'matched_by'      => 'sha256',
                'content_differs' => false,
            ];
        }
        $name = strtolower( (string) ( $watermark['name'] ?? '' ) );
        if ( $name && isset( $index['by_name'][ $name ] ) ) {
            $match      = $index['by_name'][ $name ];
            $local_hash = (string) ( $match['local_sha256'] ?? '' );
            return $match + [
                'matched_by'      => 'name',
                'content_differs' => $hash !== '' && $local_hash !== '' && ! hash_equals( $hash, $local_hash ),
            ];
        }
        return null;
    }

    private static function gallery_metadata_index(): array {
        $out = [];
        foreach ( RinCWC_Data::source_galleries() as $gallery ) {
            $out[ (int) $gallery['gallery_id'] ] = $gallery;
        }
        foreach ( RinCWC_Data::envira_galleries() as $gallery ) {
            $gallery_id = (int) $gallery->ID;
            if ( ! isset( $out[ $gallery_id ] ) ) {
                $out[ $gallery_id ] = [
                    'gallery_id'    => $gallery_id,
                    'gallery_slug'  => (string) $gallery->post_name,
                    'gallery_title' => (string) $gallery->post_title,
                ];
            }
        }
        return $out;
    }

    private static function row_filename( array $row ): string {
        $path = (string) ( $row['original_path'] ?? '' );
        if ( $path ) {
            return basename( $path );
        }
        $url_path = wp_parse_url( (string) ( $row['src_url'] ?? '' ), PHP_URL_PATH );
        return $url_path ? basename( rawurldecode( $url_path ) ) : '';
    }

    private static function valid_mysql_datetime( string $value ): bool {
        $date = DateTime::createFromFormat( '!Y-m-d H:i:s', $value );
        return $date && $date->format( 'Y-m-d H:i:s' ) === $value;
    }

    private static function hostname(): string {
        return sanitize_text_field( (string) ( gethostname() ?: php_uname( 'n' ) ) );
    }
}
