<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

enum BotCategory: string {
	case AI_TRAINING      = 'ai-training';
	case AI_SEARCH        = 'ai-search';
	case AI_USER          = 'ai-user';
	case DATA_CRAWLER     = 'data-crawler';
	case SEARCH_ENGINE    = 'search-engine';
	case SOCIAL           = 'social';
	case SEO_TOOL         = 'seo-tool';
	case DIAGNOSTIC       = 'diagnostic';
	case UNREGISTERED_BOT = 'unregistered-bot';
	case OTHER            = 'other';

	public function label(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- PHPCompatibility 9 does not recognize enum instance methods; enums require PHP 8.1+.
		return $this->ai_label() ?? $this->general_label();
	}

	private function ai_label(): ?string {
		return match ( $this ) { // phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- PHPCompatibility 9 does not recognize enum instance methods; enums require PHP 8.1+.
			self::AI_TRAINING      => __( 'AI Training', 'cybermaps' ),
			self::AI_SEARCH        => __( 'AI Search', 'cybermaps' ),
			self::AI_USER          => __( 'AI User Fetches', 'cybermaps' ),
			self::DATA_CRAWLER     => __( 'Data Crawlers', 'cybermaps' ),
			self::SEARCH_ENGINE    => __( 'Search', 'cybermaps' ),
			default                => null,
		};
	}

	private function general_label(): string {
		return match ( $this ) { // phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- PHPCompatibility 9 does not recognize enum instance methods; enums require PHP 8.1+.
			self::SOCIAL           => __( 'Social', 'cybermaps' ),
			self::SEO_TOOL         => __( 'SEO Tools', 'cybermaps' ),
			self::DIAGNOSTIC       => __( 'Diagnostics', 'cybermaps' ),
			self::UNREGISTERED_BOT => __( 'Unregistered', 'cybermaps' ),
			self::OTHER            => __( 'Other', 'cybermaps' ),
			default                => __( 'Other', 'cybermaps' ),
		};
	}
}
