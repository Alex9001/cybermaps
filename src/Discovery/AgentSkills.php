<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Publishes the Agent Skills discovery index draft.
 */
final class AgentSkills {
	public const INDEX_PATH = '/.well-known/agent-skills/index.json';

	/**
	 * Serve the discovery index.
	 */
	public function handle(): void {
		if ( self::INDEX_PATH !== \Cybermaps\Core\URLManager::get_request_path() ) {
			return;
		}

		$output = $this->get_json_content();
		Integrity::send_headers( $output );
		header( 'Content-Type: application/json; charset=utf-8' );
		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
			\Cybermaps\Core\ProtocolOutput::emit( $output, 'json' );
		}
		exit;
	}

	/**
	 * Return the canonical index body used by dynamic and static delivery.
	 */
	public function get_json_content(): string {
		return \Cybermaps\Core\ProtocolOutput::json( $this->get_index_data() );
	}

	/**
	 * Build the 0.2.0 draft discovery index and bind it to the exact skill bytes.
	 *
	 * @return array<string,mixed>
	 */
	public function get_index_data(): array {
		$skill = ( new Capabilities() )->get_skill_markdown();

		return array(
			'$schema' => 'https://schemas.agentskills.io/discovery/0.2.0/schema.json',
			'skills'  => array(
				array(
					'name'        => Capabilities::SKILL_NAME,
					'description' => Capabilities::SKILL_DESCRIPTION,
					'type'        => 'skill-md',
					'url'         => \Cybermaps\Core\URLManager::get_home_url( Capabilities::CANONICAL_PATH ),
					'digest'      => 'sha256:' . \hash( 'sha256', $skill ),
				),
			),
		);
	}
}
