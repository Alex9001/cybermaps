<?php
declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable, local registry of external standards used by Cybermaps.
 *
 * This is deliberately data-only: it never performs network discovery at
 * runtime. A profile's conformance notes describe the installed contract and
 * can therefore be emitted safely into developer documentation.
 */
readonly class StandardsRegistry {
	public const REVIEWED_AT = '2026-08-30';

	/** @var array<string, array<string, mixed>> */
	private array $profiles;

	public function __construct() {
		$this->profiles = self::build_profiles();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		$profiles = array();
		foreach ( $this->profiles as $id => $profile ) {
			$profile['id'] = $id;
			$profiles[]    = $profile;
		}

		return $profiles;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get( string $id ): ?array {
		return $this->profiles[ $id ] ?? null;
	}

	/**
	 * @return string[]
	 */
	public function ids(): array {
		return array_keys( $this->profiles );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function build_profiles(): array {
		$reviewed = self::REVIEWED_AT;
		return array(
			'openapi-3.2.0'             => self::profile(
				'OpenAPI Specification',
				'OpenAPI Initiative',
				'https://spec.openapis.org/oas/v3.2.0.html',
				'3.2.0',
				'canonical',
				'implemented',
				$reviewed,
				array(
					'canonical_media_type' => 'application/vnd.oai.openapi+json;version=3.2',
					'json_schema_dialect'  => 'https://spec.openapis.org/oas/3.2/dialect/base',
				)
			),
			'openapi-3.1.2'             => self::profile( 'OpenAPI Specification', 'OpenAPI Initiative', 'https://spec.openapis.org/oas/v3.1.2.html', '3.1.2', 'compatibility', 'implemented', $reviewed, array( 'negotiated_by' => 'Accept or ?version=3.1.2' ) ),
			'llms-txt-v2'               => self::profile( 'llms.txt', 'llmstxt.org', 'https://llmstxt.org/changes.html', '2', 'proposal', 'implemented', $reviewed ),
			'markdown-for-agents-2026'  => self::profile(
				'Markdown for Agents',
				'Cloudflare',
				'https://developers.cloudflare.com/fundamentals/reference/markdown-for-agents/',
				'2026-07-13',
				'vendor-convention',
				'implemented',
				$reviewed,
				array(
					'negotiated_by' => 'Accept: text/markdown',
					'renderer'      => 'bounded stored WordPress content; not rendered-HTML conversion',
				)
			),
			'agent-skills-0.2.0'        => self::profile( 'Agent Skills and Discovery', 'agentskills.io / Cloudflare', 'https://agentskills.io/specification', '0.2.0', 'draft', 'implemented', $reviewed, array( 'discovery_uri' => 'https://github.com/cloudflare/agent-skills-discovery-rfc' ) ),
			'adp-3.0-cybermaps-level-3' => self::profile( 'AI Discovery Protocol', 'BuddySpuds', 'https://github.com/BuddySpuds/AI-Discovery-Protocol/blob/main/SPECIFICATION.md', '3.0', 'proposal', 'profiled', $reviewed, array( 'profile' => 'Cybermaps Level 3; supported resources are explicitly registry-backed; Level 4 is not claimed' ) ),
			'mcp-2026-07-28'            => self::profile( 'Model Context Protocol', 'MCP Steering Group', 'https://modelcontextprotocol.io/specification/2026-07-28', '2026-07-28', 'recommendation', 'implemented', $reviewed, array( 'transport' => 'stateless Streamable HTTP POST' ) ),
			'aipref-vocab-07'           => self::profile( 'AI Preferences Vocabulary', 'IETF AIPREF', 'https://datatracker.ietf.org/doc/draft-ietf-aipref-vocab-07/', 'vocab-07', 'draft', 'partial', $reviewed, array( 'publication' => 'opt-in dual publication with Content-Signal' ) ),
			'aipref-attachment-draft'   => self::profile( 'AI Preferences HTTP and robots attachment', 'IETF AIPREF', 'https://ietf-wg-aipref.github.io/drafts/draft-ietf-aipref-attach.html', 'draft', 'draft', 'partial', $reviewed ),
			'websub-2026'               => self::profile( 'WebSub', 'W3C', 'https://www.w3.org/TR/websub/', '2026 Recommendation', 'recommendation', 'implemented', $reviewed, array( 'requirement' => 'one rel=self and at least one rel=hub link' ) ),
			'json-feed-1.1'             => self::profile( 'JSON Feed', 'JSON Feed', 'https://www.jsonfeed.org/version/1.1/', '1.1', 'specification', 'implemented', $reviewed ),
			'rfc-9309'                  => self::profile( 'Robots Exclusion Protocol', 'IETF', 'https://www.rfc-editor.org/rfc/rfc9309', 'RFC 9309', 'standard', 'implemented', $reviewed ),
			'rfc-9457'                  => self::profile( 'Problem Details for HTTP APIs', 'IETF', 'https://www.rfc-editor.org/rfc/rfc9457', 'RFC 9457', 'standard', 'implemented', $reviewed ),
			'rfc-9530'                  => self::profile( 'HTTP Content-Digest', 'IETF', 'https://www.rfc-editor.org/rfc/rfc9530', 'RFC 9530', 'standard', 'implemented', $reviewed ),
			'rfc-9727'                  => self::profile( 'API Catalog', 'IETF', 'https://www.rfc-editor.org/rfc/rfc9727', 'RFC 9727', 'standard', 'implemented', $reviewed ),
			'indexnow'                  => self::profile( 'IndexNow', 'IndexNow', 'https://www.indexnow.org/documentation', 'current', 'protocol', 'implemented', $reviewed, array( 'batch_limit' => 10000 ) ),
		);
	}

	/**
	 * @param array<string, mixed> $conformance
	 * @return array<string, mixed>
	 */
	private static function profile( string $title, string $authority, string $spec_uri, string $version, string $maturity, string $support, string $reviewed_at, array $conformance = array() ): array {
		return array(
			'title'       => $title,
			'authority'   => $authority,
			'spec_uri'    => $spec_uri,
			'version'     => $version,
			'maturity'    => $maturity,
			'support'     => $support,
			'reviewed_at' => $reviewed_at,
			'conformance' => $conformance,
		);
	}
}
