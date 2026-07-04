<?php
defined( 'ABSPATH' ) || exit;

final class RinCWC_CSV {

    private const SEL_FIELDS = [
        'gallery_id', 'gallery_slug', 'gallery_title', 'attach_id',
        'position', 'total', 'filename', 'selected_crop',
        'wm_corner', 'wm_applied', 'custom_crop_offset',
    ];

    // Read wallpaper_db.csv as flat array of rows.
    public static function read_db_flat(): array {
        return self::read_csv_flat( RINCWC_DB_CSV );
    }

    // Read wallpaper_db.csv keyed [gallery_id][attach_id].
    public static function read_db(): array {
        $rows = [];
        foreach ( self::read_csv_flat( RINCWC_DB_CSV ) as $r ) {
            $rows[ $r['gallery_id'] ][ $r['attach_id'] ] = $r;
        }
        return $rows;
    }

    // Read wallpaper_selections.csv keyed [gallery_id][attach_id].
    public static function read_selections(): array {
        $rows = [];
        foreach ( self::read_csv_flat( RINCWC_SEL_CSV ) as $r ) {
            $rows[ $r['gallery_id'] ][ $r['attach_id'] ] = $r;
        }
        return $rows;
    }

    // Add or update a selection row. $data must contain gallery_id + attach_id.
    public static function upsert_selection( array $data ): bool {
        $path = RINCWC_SEL_CSV;
        $dir  = dirname( $path );
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        $fh = fopen( $path, file_exists( $path ) ? 'r+' : 'w+' );
        if ( ! $fh ) return false;
        if ( ! flock( $fh, LOCK_EX ) ) { fclose( $fh ); return false; }

        // Read existing content.
        $headers = fgetcsv( $fh );
        $rows    = [];
        if ( $headers ) {
            if ( ! in_array( 'custom_crop_offset', $headers, true ) ) {
                $headers[] = 'custom_crop_offset';
            }
            while ( ( $row = fgetcsv( $fh ) ) !== false ) {
                $r      = array_combine( $headers, array_pad( $row, count( $headers ), '' ) );
                $rows[] = $r;
            }
        } else {
            $headers = self::SEL_FIELDS;
        }

        // Upsert.
        $found = false;
        foreach ( $rows as &$r ) {
            if ( $r['gallery_id'] === (string) $data['gallery_id'] &&
                 $r['attach_id']  === (string) $data['attach_id'] ) {
                foreach ( $data as $k => $v ) {
                    if ( in_array( $k, $headers, true ) ) $r[ $k ] = (string) $v;
                }
                $found = true;
                break;
            }
        }
        unset( $r );
        if ( ! $found ) {
            $new = array_fill_keys( $headers, '' );
            foreach ( $data as $k => $v ) {
                if ( in_array( $k, $headers, true ) ) $new[ $k ] = (string) $v;
            }
            $rows[] = $new;
        }

        // Write back atomically via a temp buffer.
        $buf = fopen( 'php://memory', 'r+' );
        fputcsv( $buf, $headers );
        foreach ( $rows as $r ) {
            fputcsv( $buf, array_map( fn( $k ) => $r[ $k ] ?? '', $headers ) );
        }
        rewind( $buf );
        ftruncate( $fh, 0 );
        rewind( $fh );
        stream_copy_to_stream( $buf, $fh );
        fclose( $buf );

        flock( $fh, LOCK_UN );
        fclose( $fh );
        return true;
    }

    // Remove a selection row.
    public static function remove_selection( string $gallery_id, string $attach_id ): bool {
        $path = RINCWC_SEL_CSV;
        if ( ! file_exists( $path ) ) return true;

        $fh = fopen( $path, 'r+' );
        if ( ! $fh ) return false;
        if ( ! flock( $fh, LOCK_EX ) ) { fclose( $fh ); return false; }

        $headers = fgetcsv( $fh );
        $rows    = [];
        if ( $headers ) {
            while ( ( $row = fgetcsv( $fh ) ) !== false ) {
                $r = array_combine( $headers, array_pad( $row, count( $headers ), '' ) );
                if ( $r['gallery_id'] !== $gallery_id || $r['attach_id'] !== $attach_id ) {
                    $rows[] = $r;
                }
            }
        }

        ftruncate( $fh, 0 );
        rewind( $fh );
        if ( $headers ) {
            fputcsv( $fh, $headers );
            foreach ( $rows as $r ) {
                fputcsv( $fh, array_map( fn( $k ) => $r[ $k ] ?? '', $headers ) );
            }
        }
        flock( $fh, LOCK_UN );
        fclose( $fh );
        return true;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private static function read_csv_flat( string $path ): array {
        if ( ! file_exists( $path ) ) return [];
        $fh = fopen( $path, 'r' );
        if ( ! $fh ) return [];
        $headers = fgetcsv( $fh );
        if ( ! $headers ) { fclose( $fh ); return []; }
        $rows = [];
        while ( ( $row = fgetcsv( $fh ) ) !== false ) {
            if ( count( $row ) < count( $headers ) ) {
                $row = array_pad( $row, count( $headers ), '' );
            }
            $rows[] = array_combine( $headers, array_slice( $row, 0, count( $headers ) ) );
        }
        fclose( $fh );
        return $rows;
    }
}
