<?php
declare(strict_types=1);

namespace Cybermaps\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Bounded raw template inventory; never builds or renders WordPress blocks. */
final class StoredTemplateSource {
	public const MAX_TEMPLATES     = 1000;
	public const MAX_CONTENT_BYTES = 1048576;
	private bool $complete         = true;

	public function complete(): bool {
		return $this->complete;
	}

	/** @return \Generator<int,array{slug:string,content:string}> */
	public function templates(): \Generator {
		$this->complete = true;
		$seen           = array();
		foreach ( $this->stored_ids() as $id ) {
			$post = $this->stored_post( array( 'post__in' => array( $id ) ), 'wp_template', get_stylesheet() );
			if ( null === $post ) {
				continue;
			}
			$seen[ (string) $post->post_name ] = true;
			$content                           = $this->bounded_content( (string) $post->post_content );
			if ( null !== $content ) {
				yield array(
					'slug'    => (string) $post->post_name,
					'content' => $content,
				);
			}
		}
		yield from $this->theme_templates( $seen );
		yield from $this->registered_templates( $seen );
	}

	/** @return int[] */
	private function stored_ids(): array {
		$ids = $this->query_posts(
			array(
				'fields'         => 'ids',
				'posts_per_page' => self::MAX_TEMPLATES + 1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			),
			'wp_template',
			get_stylesheet()
		);
		if ( count( $ids ) > self::MAX_TEMPLATES ) {
			$this->complete = false;
		}
		return array_map( 'intval', array_slice( $ids, 0, self::MAX_TEMPLATES ) );
	}

	/** @return object|null */
	private function stored_post( array $args, string $type, string $theme ): ?object {
		$posts = $this->query_posts( array_merge( $args, array( 'posts_per_page' => 1 ) ), $type, $theme );
		return is_object( $posts[0] ?? null ) ? $posts[0] : null;
	}

	/** @return array<int,mixed> */
	private function query_posts( array $args, string $type, string $theme ): array {
		global $wpdb;
		$wpdb->last_error = '';
		$posts            = get_posts(
			array_merge(
				array(
					'post_type'              => $type,
					'post_status'            => 'publish',
					'no_found_rows'          => true,
					'cache_results'          => false,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'tax_query'              => array(
						array(
							'taxonomy' => 'wp_theme',
							'field'    => 'name',
							'terms'    => $theme,
						),
					),
				),
				$args
			)
		);
		if ( ! empty( $wpdb->last_error ) || ! is_array( $posts ) ) {
			$this->complete = false;
			return array();
		}
		return $posts;
	}

	public function part( string $slug, string $theme ): ?string {
		if ( strlen( $slug ) > 200 || strlen( $theme ) > 200 ) {
			$this->complete = false;
			return null;
		}
		$post = $this->stored_post( array( 'post_name__in' => array( $slug ) ), 'wp_template_part', $theme );
		if ( null !== $post ) {
			return $this->bounded_content( (string) $post->post_content );
		}
		return $this->theme_part( $slug, $theme );
	}

	private function bounded_content( string $content ): ?string {
		if ( strlen( $content ) > self::MAX_CONTENT_BYTES ) {
			$this->complete = false;
			return null;
		}
		return $content;
	}

	/** Already registered objects are read literally, without applying block hooks. */
	private function registered_templates( array &$seen ): \Generator {
		if ( ! class_exists( '\WP_Block_Templates_Registry' ) ) {
			return;
		}
		$remaining = self::MAX_TEMPLATES;
		foreach ( \WP_Block_Templates_Registry::get_instance()->get_all_registered() as $template ) {
			if ( --$remaining < 0 ) {
				$this->complete = false;
				return;
			}
			$slug = (string) $template->slug;
			if ( isset( $seen[ $slug ] ) ) {
				continue;
			}
			if ( count( $seen ) >= self::MAX_TEMPLATES ) {
				$this->complete = false;
				return;
			}
			$seen[ $slug ] = true;
			$content       = $this->bounded_content( (string) $template->content );
			if ( null !== $content ) {
				yield array(
					'slug'    => $slug,
					'content' => $content,
				);
			}
		}
	}

	/** Enumerate local theme names before reading any template body. */
	private function theme_templates( array &$seen ): \Generator {
		$filesystem = $this->filesystem();
		if ( null === $filesystem ) {
			$this->complete = false;
			return;
		}
		$budget = array(
			'entries'  => self::MAX_TEMPLATES,
			'bytes'    => 262144,
			'deadline' => microtime( true ) + 2,
		);
		foreach ( $this->theme_directories( 'wp_template' ) as $directory ) {
			$files = array();
			$this->collect_theme_names( $directory, '', $filesystem, $budget, $files, 0 );
			foreach ( $files as $slug => $path ) {
				if ( isset( $seen[ $slug ] ) ) {
					continue;
				}
				if ( count( $seen ) >= self::MAX_TEMPLATES ) {
					$this->complete = false;
					return;
				}
				$seen[ $slug ] = true;
				$content       = $this->file_content( $path, $filesystem );
				if ( null !== $content ) {
					yield array(
						'slug'    => $slug,
						'content' => $content,
					);
				}
			}
		}
	}

	/** Metadata-only local iterator: WordPress has no bounded directory listing API. */
	private function collect_theme_names( string $directory, string $prefix, object $filesystem, array &$budget, array &$files, int $depth ): void {
		if ( ! $filesystem->is_dir( $directory ) ) {
			return;
		}
		if ( $depth > 8 ) {
			$this->complete = false;
			return;
		}
		try {
			foreach ( new \DirectoryIterator( $directory ) as $entry ) {
				if ( $entry->isDot() ) {
					continue;
				}
				$name = $entry->getFilename();
				if ( ! $this->admit_name( $prefix . $name, $budget ) ) {
					return;
				}
				if ( $entry->isLink() ) {
					$this->complete = false;
					continue;
				}
				$path = $directory . '/' . $name;
				if ( $filesystem->is_dir( $path ) ) {
					$this->collect_theme_names( $path, $prefix . $name . '/', $filesystem, $budget, $files, $depth + 1 );
				} elseif ( str_ends_with( strtolower( $name ), '.html' ) && $filesystem->is_file( $path ) ) {
					$files[ substr( $prefix . $name, 0, -5 ) ] = $path;
				}
			}
		} catch ( \UnexpectedValueException ) {
			$this->complete = false;
		}
	}

	private function admit_name( string $name, array &$budget ): bool {
		if ( $budget['entries'] < 1 || strlen( $name ) > min( 200, $budget['bytes'] ) || microtime( true ) > $budget['deadline'] ) {
			$this->complete = false;
			return false;
		}
		--$budget['entries'];
		$budget['bytes'] -= strlen( $name );
		return true;
	}

	/** @return string[] Child theme precedes parent; paths are never request-derived. */
	private function theme_directories( string $type, string $requested_theme = '' ): array {
		$themes      = array(
			get_stylesheet() => get_stylesheet_directory(),
			get_template()   => get_template_directory(),
		);
		$directories = array();
		foreach ( $themes as $theme => $base ) {
			if ( '' !== $requested_theme && $requested_theme !== $theme ) {
				continue;
			}
			$folders = get_block_theme_folders( $theme );
			$folder  = $folders[ $type ] ?? '';
			if ( ! in_array( $folder, array( 'templates', 'block-templates', 'parts', 'block-template-parts' ), true ) ) {
				$this->complete = false;
				continue;
			}
			$directory = $base . '/' . $folder;
			$root      = realpath( $base );
			$resolved  = realpath( $directory );
			if ( false !== $resolved && ( false === $root || ! str_starts_with( $resolved, $root . DIRECTORY_SEPARATOR ) ) ) {
				$this->complete = false;
				continue;
			}
			$directories[] = $directory;
		}
		return array_unique( $directories );
	}

	private function filesystem(): ?object {
		global $wp_filesystem;
		if ( ! is_object( $wp_filesystem ) ) {
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			if ( ! WP_Filesystem() ) {
				return null;
			}
		}
		return 'direct' === ( $wp_filesystem->method ?? '' ) ? $wp_filesystem : null;
	}

	private function file_content( string $path, object $filesystem ): ?string {
		$size = $filesystem->size( $path );
		if ( ! is_int( $size ) || $size > self::MAX_CONTENT_BYTES || ! $filesystem->is_readable( $path ) ) {
			$this->complete = false;
			return null;
		}
		$content = $filesystem->get_contents( $path );
		if ( ! is_string( $content ) ) {
			$this->complete = false;
			return null;
		}
		return $this->bounded_content( $content );
	}

	private function theme_part( string $slug, string $theme ): ?string {
		$filesystem = $this->filesystem();
		if ( null === $filesystem || ! in_array( $theme, array( get_stylesheet(), get_template() ), true ) ) {
			$this->complete = false;
			return null;
		}
		foreach ( $this->theme_directories( 'wp_template_part', get_stylesheet() === $theme ? '' : $theme ) as $directory ) {
			$root = realpath( $directory );
			$path = realpath( $directory . '/' . $slug . '.html' );
			if ( false === $root || false === $path || ! str_starts_with( $path, $root . DIRECTORY_SEPARATOR ) ) {
				continue;
			}
			if ( $filesystem->is_file( $path ) ) {
				return $this->file_content( $path, $filesystem );
			}
		}
		$this->complete = false;
		return null;
	}
}
