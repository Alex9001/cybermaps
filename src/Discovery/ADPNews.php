<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Publishes the bounded ADP 3.0 Level 3 news namespace.
 */
final class ADPNews {
	private const MAX_ITEMS        = 100;
	private const MAX_OUTPUT_BYTES = 512 * 1024;
	private const SUMMARY_MAX      = 1024;

	/** @var array<string, array{type:string,frequency:string,ttl:int}> */
	private const ENDPOINTS = array(
		'adp_news_llms'      => array(
			'type'      => 'text/markdown; charset=utf-8',
			'frequency' => 'hourly',
			'ttl'       => 900,
		),
		'adp_news_speakable' => array(
			'type'      => 'application/ld+json; charset=utf-8',
			'frequency' => 'hourly',
			'ttl'       => 900,
		),
		'adp_news_changelog' => array(
			'type'      => 'application/json; charset=utf-8',
			'frequency' => 'on-release',
			'ttl'       => DAY_IN_SECONDS,
		),
		'adp_news_archive'   => array(
			'type'      => 'application/x-ndjson; charset=utf-8',
			'frequency' => 'on-publish',
			'ttl'       => HOUR_IN_SECONDS,
		),
	);

	/** @var array<int, array<string, mixed>>|null */
	private ?array $articles = null;

	public function handle(): void {
		$match       = \Cybermaps\Core\EndpointRegistry::get_instance()->match_path( (string) \Cybermaps\Core\URLManager::get_request_path() );
		$endpoint_id = is_array( $match ) ? (string) ( $match['id'] ?? '' ) : '';
		if ( ! isset( self::ENDPOINTS[ $endpoint_id ] ) ) {
			return;
		}

		$output = $this->get_content( $endpoint_id );
		$config = self::ENDPOINTS[ $endpoint_id ];
		Integrity::send_headers( $output, $config['ttl'] );
		header( 'Content-Type: ' . $config['type'] );
		header( 'X-Update-Frequency: ' . $config['frequency'] );
		if ( 'adp_news_archive' === $endpoint_id ) {
			header( 'X-Total-Records: ' . count( $this->get_articles() ) );
		}
		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
			echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Deliberate protocol response.
		}
		exit;
	}

	public function get_content( string $endpoint_id ): string {
		$output = match ( $endpoint_id ) {
			'adp_news_llms'      => $this->build_llms(),
			'adp_news_speakable' => $this->encode_json( $this->build_speakable() ),
			'adp_news_changelog' => $this->encode_json( $this->build_changelog() ),
			'adp_news_archive'   => $this->build_archive(),
			default              => throw new \InvalidArgumentException( 'Unknown ADP news endpoint.' ),
		};
		if ( strlen( $output ) > self::MAX_OUTPUT_BYTES ) {
			throw new \RuntimeException(
				__( 'An ADP news publication exceeded its safe output limit.', 'cybermaps' ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception becomes escaped status data.
			);
		}
		return $output;
	}

	private function build_llms(): string {
		$site  = $this->site_name();
		$lines = array( '# ' . $site . ' News', '', '> Recent eligible content published by ' . $site . '.', '', '## Recent Content', '' );
		foreach ( $this->get_articles() as $position => $article ) {
			$date    = '' !== $article['publishedAt'] ? ' (' . substr( $article['publishedAt'], 0, 10 ) . ')' : '';
			$lines[] = ( $position + 1 ) . '. **' . $article['headline'] . '**' . $date;
			if ( '' !== $article['summary'] ) {
				$lines[] = '   ' . $article['summary'];
			}
			$lines[] = '   URL: ' . $article['url'];
			$lines[] = '';
		}
		if ( empty( $this->get_articles() ) ) {
			$lines[] = 'No eligible news content is currently published.';
			$lines[] = '';
		}
		$lines[] = '## Archive';
		$lines[] = '';
		$lines[] = 'Bounded machine-readable archive: ' . \Cybermaps\Core\EndpointRegistry::get_instance()->get_url( 'adp_news_archive' );
		$lines[] = '';
		$lines[] = '---';
		$lines[] = '*AI Discovery Protocol 3.0 | News Namespace*';
		return implode( "\n", $lines ) . "\n";
	}

	/** @return array<string, mixed> */
	private function build_speakable(): array {
		$items = array();
		foreach ( $this->get_articles() as $position => $article ) {
			$items[] = array_filter(
				array(
					'@type'         => 'Article',
					'position'      => $position + 1,
					'headline'      => $article['headline'],
					'speakableText' => '' !== $article['summary'] ? $article['summary'] : $article['headline'],
					'url'           => $article['url'],
					'datePublished' => $article['publishedAt'],
					'publisher'     => array(
						'@type' => 'Organization',
						'name'  => $this->site_name(),
					),
				),
				static fn( mixed $value ): bool => '' !== $value
			);
		}
		return array(
			'@context'        => 'https://schema.org',
			'@type'           => 'ItemList',
			'name'            => $this->site_name() . ' Speakable News',
			'numberOfItems'   => count( $items ),
			'itemListElement' => $items,
		);
	}

	/** @return array<string, mixed> */
	private function build_changelog(): array {
		$latest = '';
		foreach ( $this->get_articles() as $article ) {
			$latest = max( $latest, (string) $article['modifiedAt'] );
		}
		$document = array(
			'version'        => '3.0',
			'platform'       => $this->site_name(),
			'currentVersion' => CYBERMAPS_VERSION,
			'changelog'      => array(
				array(
					'version' => CYBERMAPS_VERSION,
					'title'   => 'ADP Level 3 news namespace',
					'type'    => 'feature',
					'changes' => array(
						'Publishes bounded news context and speakable content.',
						'Publishes version metadata and a streaming JSONL archive.',
					),
				),
			),
			'releaseTypes'   => array(
				'feature'  => 'New functionality added',
				'fix'      => 'Bug fixes',
				'breaking' => 'Breaking changes',
				'security' => 'Security updates',
			),
		);
		if ( '' !== $latest ) {
			$document['lastUpdated']          = $latest;
			$document['changelog'][0]['date'] = substr( $latest, 0, 10 );
		}
		return $document;
	}

	private function build_archive(): string {
		$lines = array();
		foreach ( $this->get_articles() as $article ) {
			$lines[] = $this->encode_json(
				array(
					'id'          => 'post_' . $article['id'],
					'type'        => $article['type'],
					'headline'    => $article['headline'],
					'company'     => $this->site_name(),
					'url'         => $article['url'],
					'publishedAt' => $article['publishedAt'],
					'category'    => $article['type'],
					'wordCount'   => $article['wordCount'],
				),
				false
			);
		}
		if ( empty( $lines ) ) {
			$lines[] = $this->encode_json(
				array(
					'type'        => 'archive_metadata',
					'version'     => '3.0',
					'recordCount' => 0,
				),
				false
			);
		}
		return implode( "\n", $lines ) . "\n";
	}

	/** @return array<int, array<string, mixed>> */
	private function get_articles(): array {
		if ( null !== $this->articles ) {
			return $this->articles;
		}
		$this->articles = array();
		foreach ( ( new PublicationInventory() )->iterate_posts() as $post ) {
			$post_id = (int) ( $post->ID ?? 0 );
			if ( $post_id < 1 ) {
				continue;
			}
			$content          = wp_strip_all_tags( (string) ( $post->post_content ?? '' ) );
			$this->articles[] = array(
				'id'          => $post_id,
				'type'        => sanitize_key( (string) ( $post->post_type ?? 'post' ) ),
				'headline'    => PublicationConstraints::bounded_text( sanitize_text_field( (string) get_the_title( $post_id ) ), PublicationConstraints::SEARCH_TITLE_MAX_LENGTH ),
				'summary'     => PublicationConstraints::bounded_text( wp_trim_words( wp_strip_all_tags( (string) get_the_excerpt( $post ) ), 200, '' ), self::SUMMARY_MAX ),
				'url'         => \Cybermaps\Core\URLManager::rewrite_url( (string) get_permalink( $post_id ) ),
				'publishedAt' => $this->iso_date( (string) ( $post->post_date_gmt ?? '' ) ),
				'modifiedAt'  => $this->iso_date( (string) ( $post->post_modified_gmt ?? '' ) ),
				'wordCount'   => $this->word_count( $content ),
			);
			if ( count( $this->articles ) >= self::MAX_ITEMS ) {
				break;
			}
		}
		return $this->articles;
	}

	private function site_name(): string {
		$name = PublicationConstraints::bounded_text( sanitize_text_field( (string) get_bloginfo( 'name' ) ), PublicationConstraints::PUBLICATION_NAME_MAX_LENGTH );
		return '' !== $name ? $name : 'Website';
	}

	private function iso_date( string $mysql_gmt ): string {
		if ( '' === $mysql_gmt || '0000-00-00 00:00:00' === $mysql_gmt ) {
			return '';
		}
		$timestamp = strtotime( $mysql_gmt . ' UTC' );
		return false === $timestamp ? '' : gmdate( 'c', $timestamp );
	}

	private function word_count( string $content ): int {
		$words = preg_split( '/\s+/u', trim( $content ) );
		return is_array( $words ) && '' !== trim( $content ) ? count( array_filter( $words ) ) : 0;
	}

	private function encode_json( mixed $data, bool $pretty = true ): string {
		$output = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | ( $pretty ? JSON_PRETTY_PRINT : 0 ) );
		if ( ! is_string( $output ) ) {
			throw new \RuntimeException(
				__( 'An ADP news publication could not be encoded as JSON.', 'cybermaps' ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception becomes escaped status data.
			);
		}
		return $output;
	}
}
