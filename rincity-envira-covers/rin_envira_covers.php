<?php
/**
 * rin_envira_covers.php
 *
 * WP-CLI eval-file script.
 *
 * Change in this revision:
 *  - Output filenames are prefixed with publish date:
 *      YYYY-MM-DD-<set-name>.<ext>
 *    so files sort chronologically.
 */

if ( ! defined('WP_CLI') || ! WP_CLI ) {
  fwrite(STDERR, "This script must be run via WP-CLI eval-file.\n");
  exit(1);
}

class Rin_Envira_Covers_Eval {

  private const EXCLUDED_TERM = 'dustrat';

  private string $post_type = '';
  private array  $taxonomies = [];
  private bool   $dry_run = false;
  private int    $limit = 0;
  private string $out_dir = '';
  private bool   $debug = false;

  public function run(): void {
    $this->dry_run = getenv('RIN_DRY_RUN') ? true : false;
    $this->debug   = getenv('RIN_DEBUG') ? true : false;
    $this->limit   = (int)(getenv('RIN_LIMIT') ?: 0);

    $uploads = wp_get_upload_dir();
    $default_out = rtrim((string)$uploads['basedir'], '/') . '/Rin_Covers';
    $this->out_dir = rtrim((string)(getenv('RIN_OUT') ?: $default_out), '/');

    $this->post_type = $this->detect_envira_gallery_post_type();
    if ($this->post_type === '') {
      WP_CLI::error("Could not detect Envira gallery post type.");
    }

    $this->taxonomies = $this->detect_envira_taxonomies($this->post_type);

    WP_CLI::log("Envira gallery post type: {$this->post_type}");
    WP_CLI::log("Output dir: {$this->out_dir}");

    if (!$this->dry_run && !is_dir($this->out_dir)) {
      if (!wp_mkdir_p($this->out_dir)) {
        WP_CLI::error("Failed to create output directory: {$this->out_dir}");
      }
    }

    $ids = get_posts([
      'post_type'      => $this->post_type,
      'post_status'    => 'publish',
      'posts_per_page' => ($this->limit > 0 ? $this->limit : -1),
      'orderby'        => 'date',
      'order'          => 'DESC',
      'fields'         => 'ids',
      'no_found_rows'  => true,
    ]);

    WP_CLI::log("Found " . count($ids) . " published galleries.");

    $manifest = [];

    foreach ($ids as $post_id) {
      $title = get_the_title($post_id) ?: 'Untitled';
      $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
      $title = $this->normalize_quotes($title);

      if (strtolower($title) === 'bonus content') {
        WP_CLI::log("Skipping (Bonus Content): {$title}");
        continue;
      }

      if ($this->has_excluded_term($post_id)) {
        WP_CLI::log("Skipping (Dustrat): {$title}");
        continue;
      }

      $image = $this->get_first_image($post_id);
      if (!$image) {
        WP_CLI::warning("No first image for: {$title}");
        continue;
      }

      [$img_url, $attachment_id] = $image;

      $img_url = $this->prefer_unscaled_url($img_url);
      $ext     = $this->pick_extension($img_url, $attachment_id);

      $date_iso  = get_post_time('Y-m-d', false, $post_id);
      $safe_name = sanitize_file_name($title);
      if ($safe_name === '') $safe_name = 'untitled';

      $base = "{$date_iso}-{$safe_name}";
      $base_path = "{$this->out_dir}/{$base}{$ext}";
      $skip_download = file_exists($base_path);
      $dest = $skip_download ? $base_path : $this->unique_dest($this->out_dir, $base, $ext);

      $set_url  = get_permalink($post_id);
      $published = get_post_time('c', false, $post_id);
      $tags     = $this->build_tags($post_id);
      $envira_categories = $this->build_category_names($post_id);

      if ($this->dry_run) {
        if ($skip_download) {
          WP_CLI::log("[DRY] Exists, skipping: " . basename($dest));
        } else {
          WP_CLI::log("[DRY] {$title} -> " . basename($dest));
        }
      } else {
        if ($skip_download) {
          WP_CLI::log("Exists, skipping: " . basename($dest));
        } else {
          $tmp = download_url($img_url);
          if (is_wp_error($tmp)) {
            WP_CLI::warning("Download failed for {$title}");
            continue;
          }
          rename($tmp, $dest);
          WP_CLI::log("Saved: " . basename($dest));
        }
      }

      $manifest[] = [
        'filename'       => basename($dest),
        'set_name'       => $title,
        'date_published' => $published,
        'set_url'        => $set_url,
        'tags'           => $tags,
        'envira_categories' => $envira_categories,
      ];
    }

    $manifest_path = "{$this->out_dir}/manifest.json";
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($this->dry_run) {
      WP_CLI::log("[DRY] Would write manifest: {$manifest_path}");
      WP_CLI::success("Dry run complete.");
      return;
    }

    file_put_contents($manifest_path, $json . "\n");
    WP_CLI::success("Wrote manifest: {$manifest_path}");
  }

  private function detect_envira_gallery_post_type(): string {
    foreach (['envira_gallery', 'envira'] as $pt) {
      if (post_type_exists($pt)) return $pt;
    }
    return '';
  }

  private function detect_envira_taxonomies(string $pt): array {
    return get_object_taxonomies($pt) ?: [];
  }

  private function has_excluded_term(int $post_id): bool {
    foreach ($this->taxonomies as $tax) {
      $terms = get_the_terms($post_id, $tax);
      if (is_wp_error($terms) || !$terms) continue;
      foreach ($terms as $t) {
        if (strtolower($t->slug) === self::EXCLUDED_TERM) return true;
      }
    }
    return false;
  }

  private function build_tags(int $post_id): string {
    $tags = [];
    foreach ($this->taxonomies as $tax) {
      $terms = get_the_terms($post_id, $tax);
      if (!$terms || is_wp_error($terms)) continue;
      foreach ($terms as $t) {
        $slug = strtolower($t->slug);
        $slug = str_replace('-', '', $slug);
        if ($slug !== '') {
          $tags[] = '#' . $slug;
        }
      }
    }
    return implode(' ', array_unique($tags));
  }

  private function build_category_names(int $post_id): array {
    $category_names = [];
    $non_tag_names = [];

    foreach ($this->taxonomies as $tax) {
      $terms = get_the_terms($post_id, $tax);
      if (!$terms || is_wp_error($terms)) continue;

      $tax_lc = strtolower((string)$tax);
      $is_tag_tax = strpos($tax_lc, 'tag') !== false;
      $is_category_tax = strpos($tax_lc, 'cat') !== false;

      foreach ($terms as $t) {
        $name = html_entity_decode((string)$t->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $name = $this->normalize_quotes(trim($name));
        if ($name === '') continue;

        if (!$is_tag_tax) {
          $non_tag_names[] = $name;
        }
        if ($is_category_tax && !$is_tag_tax) {
          $category_names[] = $name;
        }
      }
    }

    $selected = !empty($category_names) ? $category_names : $non_tag_names;
    return array_values(array_unique($selected));
  }

  private function normalize_quotes(string $text): string {
    $map = [
      '“' => '"',
      '”' => '"',
      '„' => '"',
      '«' => '"',
      '»' => '"',
      '’' => "'",
      '‘' => "'",
      '‚' => "'",
      '`' => "'",
    ];
    return strtr($text, $map);
  }

  private function get_first_image(int $post_id): ?array {
    $data = get_post_meta($post_id, '_eg_gallery_data', true);
    if (!empty($data['gallery']) && is_array($data['gallery'])) {
      foreach ($data['gallery'] as $item) {
        if (!empty($item['id'])) {
          return [wp_get_attachment_url((int)$item['id']), (int)$item['id']];
        }
        if (!empty($item['src'])) {
          return [$item['src'], null];
        }
      }
    }
    return null;
  }

  private function prefer_unscaled_url(string $url): string {
    return preg_replace('/-scaled(?=\.)|-\\d+x\\d+(_c)?(?=\.)/i', '', $url);
  }

  private function pick_extension(string $url, ?int $id): string {
    $ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
    return $ext ? '.' . strtolower($ext) : '.img';
  }

  private function unique_dest(string $dir, string $base, string $ext): string {
    $p = "{$dir}/{$base}{$ext}";
    $i = 2;
    while (file_exists($p)) {
      $p = "{$dir}/{$base}-{$i}{$ext}";
      $i++;
    }
    return $p;
  }
}

(new Rin_Envira_Covers_Eval())->run();
