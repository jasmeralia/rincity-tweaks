<?php
defined( 'ABSPATH' ) || exit;

final class RinCity_Gallery_Favorites_Repository {

    private const SAFETY_LIMIT = 2000;

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'rincity_gallery_favorites';
    }

    public function get_favorite( int $amember_user_id, int $gallery_id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, amember_user_id, gallery_id, note, note_updated_at, created_at, updated_at
                 FROM {$this->table()}
                 WHERE amember_user_id = %d AND gallery_id = %d
                 LIMIT 1",
                $amember_user_id,
                $gallery_id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Add a gallery to favorites. Returns the stored row, or null on DB error.
     * Idempotent: if already favorited and note is empty, returns the existing row unchanged.
     * If already favorited and note is provided, updates the note.
     */
    public function add_favorite( int $amember_user_id, int $gallery_id, string $note = '' ): ?array {
        global $wpdb;

        $existing = $this->get_favorite( $amember_user_id, $gallery_id );
        if ( $existing ) {
            if ( $note !== '' ) {
                return $this->update_note( $amember_user_id, $gallery_id, $note );
            }
            return $existing;
        }

        $now    = current_time( 'mysql', true );
        $result = $wpdb->insert(
            $this->table(),
            [
                'amember_user_id' => $amember_user_id,
                'gallery_id'      => $gallery_id,
                'note'            => $note,
                'note_updated_at' => $note !== '' ? $now : null,
                'created_at'      => $now,
                'updated_at'      => $now,
                'wp_user_id'      => get_current_user_id() ?: null,
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%d' ]
        );

        if ( $result === false ) {
            // Duplicate key race — fetch the row that beat us.
            return $this->get_favorite( $amember_user_id, $gallery_id );
        }

        return $this->get_favorite( $amember_user_id, $gallery_id );
    }

    public function remove_favorite( int $amember_user_id, int $gallery_id ): bool {
        global $wpdb;

        $deleted = $wpdb->delete(
            $this->table(),
            [
                'amember_user_id' => $amember_user_id,
                'gallery_id'      => $gallery_id,
            ],
            [ '%d', '%d' ]
        );

        return $deleted !== false && $deleted > 0;
    }

    public function update_note( int $amember_user_id, int $gallery_id, string $note ): ?array {
        global $wpdb;

        $now = current_time( 'mysql', true );

        $wpdb->update(
            $this->table(),
            [
                'note'            => $note,
                'note_updated_at' => $now,
                'updated_at'      => $now,
            ],
            [
                'amember_user_id' => $amember_user_id,
                'gallery_id'      => $gallery_id,
            ],
            [ '%s', '%s', '%s' ],
            [ '%d', '%d' ]
        );

        return $this->get_favorite( $amember_user_id, $gallery_id );
    }

    /**
     * Returns all favorites for the member, up to SAFETY_LIMIT.
     * LEFT JOIN so rows survive gallery deletion; deleted/unpublished galleries
     * are returned as available=false so the user can still remove them.
     */
    public function list_favorites( int $amember_user_id ): array {
        global $wpdb;

        $table = $this->table();
        $posts = $wpdb->posts;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    f.gallery_id,
                    f.note,
                    f.note_updated_at,
                    f.created_at      AS favorite_added_at,
                    p.post_title,
                    p.post_date       AS gallery_published_at,
                    p.post_status
                 FROM {$table} f
                 LEFT JOIN {$posts} p ON p.ID = f.gallery_id
                 WHERE f.amember_user_id = %d
                 ORDER BY f.created_at DESC
                 LIMIT %d",
                $amember_user_id,
                self::SAFETY_LIMIT
            ),
            ARRAY_A
        );

        if ( ! $rows ) {
            return [];
        }

        $result = [];
        foreach ( $rows as $row ) {
            $gallery_id = (int) $row['gallery_id'];
            $available  = ( $row['post_status'] === 'publish' );

            $result[] = [
                'gallery_id'           => $gallery_id,
                'title'                => $available ? (string) $row['post_title'] : 'Unavailable gallery',
                'url'                  => $available ? (string) get_permalink( $gallery_id ) : null,
                'cover_image_url'      => $available ? $this->get_cover_image_url( $gallery_id ) : null,
                'gallery_published_at' => $row['gallery_published_at'],
                'favorite_added_at'    => $row['favorite_added_at'],
                'note'                 => (string) ( $row['note'] ?? '' ),
                'note_updated_at'      => $row['note_updated_at'],
                'available'            => $available,
            ];
        }

        return $result;
    }

    public function count_favorites_for_gallery( int $gallery_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table()} WHERE gallery_id = %d",
                $gallery_id
            )
        );
    }

    public function is_valid_gallery( int $gallery_id ): bool {
        $post = get_post( $gallery_id );
        return $post !== null && $post->post_type === RinCity_Current_Gallery::POST_TYPE;
    }

    /**
     * Returns the cover thumbnail URL for an Envira gallery using the same approach as the
     * album page (album-page.php): reads the first image src from _eg_gallery_data and
     * transforms -scaled.ext → -scaled-320x400_c.ext.
     */
    private function get_cover_image_url( int $gallery_id ): ?string {
        $gallery_data = get_post_meta( $gallery_id, '_eg_gallery_data', true );
        if ( ! is_array( $gallery_data ) || empty( $gallery_data['gallery'] ) ) {
            return null;
        }

        $first = reset( $gallery_data['gallery'] );
        $src   = $first['src'] ?? null;
        if ( ! $src || ! is_string( $src ) ) {
            return null;
        }

        $ext     = pathinfo( $src, PATHINFO_EXTENSION );
        $cropped = preg_replace(
            '/-scaled\.' . preg_quote( $ext, '/' ) . '$/i',
            "-scaled-320x400_c.{$ext}",
            $src
        );

        return $cropped ?: $src;
    }
}
