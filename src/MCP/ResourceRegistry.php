<?php
declare(strict_types=1);

namespace Cybermaps\MCP;

use Cybermaps\Core\ConfigurationStore;
use Cybermaps\Core\EndpointRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Exact resource allowlist derived from public path publications. */
final class ResourceRegistry {
	public function __construct(
		private readonly EndpointRegistry $endpoints,
		private readonly ResourceReaderInterface $reader
	) {}

	/** @return array<int, array<string, string>> */
	public function list(): array {
		$resources = array();
		foreach ( $this->definitions() as $id => $definition ) {
			$resources[] = array(
				'name'        => $id,
				'uri'         => $this->endpoints->get_url( $id ),
				'title'       => (string) ( $definition['label'] ?? $id ),
				'description' => (string) ( $definition['description'] ?? '' ),
				'mimeType'    => (string) ( $definition['type'] ?? 'text/plain' ),
			);
		}
		return $resources;
	}

	/** @return array{uri: string, mimeType: string, text: string}|null */
	public function read( string $uri ): ?array {
		foreach ( $this->definitions() as $id => $definition ) {
			$canonical = $this->endpoints->get_url( $id );
			if ( $uri !== $canonical ) {
				continue;
			}
			$publication = $this->reader->read( $id, $definition );
			if ( null === $publication ) {
				return null;
			}
			return array(
				'uri'      => $canonical,
				'mimeType' => $publication['mime_type'],
				'text'     => $publication['body'],
			);
		}
		return null;
	}

	/** @return array<string, array<string, mixed>> */
	private function definitions(): array {
		$settings    = ConfigurationStore::settings();
		$definitions = array();
		foreach ( $this->endpoints->get_path_publications() as $id => $definition ) {
			if ( empty( $definition['advertise'] ) || ! $this->endpoints->is_enabled( (string) $id, $settings ) ) {
				continue;
			}
			$definitions[ (string) $id ] = $definition;
		}
		ksort( $definitions, SORT_STRING );
		return $definitions;
	}
}
