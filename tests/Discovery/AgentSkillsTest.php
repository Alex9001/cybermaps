<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\AgentSkills;
use Cybermaps\Discovery\Capabilities;

final class AgentSkillsTest extends \WP_UnitTestCase {
	public function test_discovery_index_digest_matches_exact_canonical_skill_bytes(): void {
		$skill = ( new Capabilities() )->get_skill_markdown();
		$index = ( new AgentSkills() )->get_index_data();
		$item  = $index['skills'][0];

		$this->assertSame( 'https://schemas.agentskills.io/discovery/0.2.0/schema.json', $index['$schema'] );
		$this->assertSame( Capabilities::SKILL_NAME, $item['name'] );
		$this->assertSame( Capabilities::SKILL_DESCRIPTION, $item['description'] );
		$this->assertSame( 'skill-md', $item['type'] );
		$this->assertStringEndsWith( Capabilities::CANONICAL_PATH, $item['url'] );
		$this->assertSame( 'sha256:' . hash( 'sha256', $skill ), $item['digest'] );
	}
}
