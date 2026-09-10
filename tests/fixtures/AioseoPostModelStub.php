<?php
declare(strict_types=1);

namespace AIOSEO\Plugin\Common\Models;

final class Post {
	/** @var array<int,object> */
	public static array $fixtures = array();

	public static function getPost( int $post_id ): object {
		return self::$fixtures[ $post_id ] ?? (object) array(
			'robots_default' => true,
			'robots_noindex' => false,
			'canonical_url'  => '',
		);
	}
}
