<?php
declare(strict_types=1);

namespace Cybermaps\Core;

use Cybermaps\Discovery\BotCategory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central registry for supported crawlers and bots.
 *
 * Request classification is based on explicit User-Agent product tokens. A
 * match is a caller claim, not cryptographic verification of the operator.
 * Robots-only product tokens remain available as policy controls but are
 * deliberately excluded from HTTP request classification.
 */
class CrawlerRegistry {
	/**
	 * Retired control IDs mapped to their current provider-defined agents.
	 *
	 * These aliases keep saved policies from earlier Cybermaps releases active
	 * without presenting obsolete agents as separate controls.
	 *
	 * @var array<string, string>
	 */
	private const LEGACY_ID_MAP = array(
		'anthropic-ai'  => 'claudebot',
		'claude-web'    => 'claude-user',
		'searchgpt-lib' => 'oai-searchbot',
	);

	/**
	 * Get all registered bots.
	 *
	 * @return array<string, BotMetadata> Associative array of bot metadata.
	 */
	public static function get_all(): array {
		static $registry;

		if ( isset( $registry ) ) {
			return $registry;
		}

		$registry = array(
			'gptbot'                           => new BotMetadata(
				'GPTBot',
				'OpenAI',
				'GPTBot',
				BotCategory::AI_TRAINING,
				array(
					'robots' => true,
					'llm'    => true,
				),
				__( 'Crawls public web content that may be used to improve OpenAI generative AI foundation models.', 'cybermaps' )
			),
			'chatgpt-user'                     => new BotMetadata(
				'ChatGPT-User',
				'OpenAI',
				'ChatGPT-User',
				BotCategory::AI_USER,
				array(
					'robots' => true,
					'llm'    => true,
				),
				__( 'Fetches a page in response to a ChatGPT user action; it is not an automatic crawler, and OpenAI says robots.txt rules may not apply.', 'cybermaps' )
			),
			'oai-adsbot'                       => new BotMetadata(
				'OAI-AdsBot',
				'OpenAI',
				'OAI-AdsBot',
				BotCategory::DIAGNOSTIC,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Validates and evaluates advertised destination pages for ChatGPT Ads.', 'cybermaps' )
			),
			'claudebot'                        => new BotMetadata(
				'ClaudeBot',
				'Anthropic',
				'ClaudeBot',
				BotCategory::AI_TRAINING,
				array(
					'robots' => true,
					'llm'    => true,
				),
				__( 'Collects public web content that may be used for Anthropic model development.', 'cybermaps' ),
				array( 'Anthropic-AI' )
			),
			'claude-user'                      => new BotMetadata(
				'Claude-User',
				'Anthropic',
				'Claude-User',
				BotCategory::AI_USER,
				array(
					'robots' => true,
					'llm'    => true,
				),
				__( 'Fetches a page in response to a Claude user request.', 'cybermaps' ),
				array( 'Claude-Web' )
			),
			'claude-searchbot'                 => new BotMetadata(
				'Claude-SearchBot',
				'Anthropic',
				'Claude-SearchBot',
				BotCategory::AI_SEARCH,
				array(
					'robots' => true,
					'llm'    => true,
				),
				__( 'Indexes public web content so it can appear in Claude search results.', 'cybermaps' )
			),
			'google-extended'                  => new BotMetadata(
				'Google-Extended',
				'Google',
				'Google-Extended',
				BotCategory::AI_TRAINING,
				array(
					'robots' => true,
					'llm'    => true,
				),
				__( 'Robots-only product token controlling use of Google-crawled content for Gemini model improvement and grounding; it sends no separate requests.', 'cybermaps' ),
				array(),
				false
			),
			'applebot-extended'                => new BotMetadata(
				'Applebot-Extended',
				'Apple',
				'Applebot-Extended',
				BotCategory::AI_TRAINING,
				array(
					'robots' => true,
					'llm'    => true,
				),
				__( 'Robots-only product token controlling use of Applebot-crawled content for Apple foundation models; it sends no separate requests.', 'cybermaps' ),
				array(),
				false
			),
			'amazonbot'                        => new BotMetadata(
				'Amazonbot',
				'Amazon',
				'Amazonbot',
				BotCategory::AI_TRAINING,
				array(
					'robots' => true,
					'llm'    => true,
				),
				__( 'Crawls for Amazon products and services; collected content may also be used to train Amazon AI models.', 'cybermaps' )
			),
			'amzn-searchbot'                   => new BotMetadata(
				'Amzn-SearchBot',
				'Amazon',
				'Amzn-SearchBot',
				BotCategory::AI_SEARCH,
				array(
					'robots' => true,
					'llm'    => true,
				),
				__( 'Indexes content for Amazon search experiences such as Alexa and Rufus; Amazon says it is not used for generative AI training.', 'cybermaps' )
			),
			'amzn-user'                        => new BotMetadata(
				'Amzn-User',
				'Amazon',
				'Amzn-User',
				BotCategory::AI_USER,
				array(
					'robots' => true,
					'llm'    => true,
				),
				__( 'Fetches current web information in response to an Amazon customer action such as an Alexa or Rufus request.', 'cybermaps' )
			),
			'mistralai-user'                   => new BotMetadata(
				'MistralAI-User',
				'Mistral AI',
				'MistralAI-User',
				BotCategory::AI_USER,
				array(
					'robots' => true,
					'llm'    => true,
				),
				__( 'Fetches a page in response to a user action in Vibe; Mistral says it is not an automatic crawler or used for generative AI training.', 'cybermaps' )
			),
			'mistralai-index'                  => new BotMetadata(
				'MistralAI-Index',
				'Mistral AI',
				'MistralAI-Index',
				BotCategory::AI_SEARCH,
				array(
					'robots' => true,
					'llm'    => true,
				),
				__( 'Indexes pages for Mistral search and Vibe answers; Mistral says the content is not used for generative AI training.', 'cybermaps' )
			),
			'meta-externalagent'               => new BotMetadata(
				'Meta-ExternalAgent',
				'Meta',
				'meta-externalagent',
				BotCategory::AI_TRAINING,
				array(
					'robots' => true,
					'llm'    => true,
				),
				__( 'Crawls for Meta foundation-model training or product improvement through direct content indexing.', 'cybermaps' )
			),
			'meta-webindexer'                  => new BotMetadata(
				'Meta-WebIndexer',
				'Meta',
				'meta-webindexer',
				BotCategory::AI_SEARCH,
				array(
					'robots' => true,
					'llm'    => true,
				),
				__( 'Indexes web content to improve Meta AI search quality, citations, and links.', 'cybermaps' )
			),
			'meta-externalfetcher'             => new BotMetadata(
				'Meta-ExternalFetcher',
				'Meta',
				'meta-externalfetcher',
				BotCategory::AI_USER,
				array(
					'robots' => true,
					'llm'    => true,
				),
				__( 'Fetches individual links at a user\'s request for Meta agentic AI functions and may bypass robots.txt.', 'cybermaps' )
			),
			'meta-externalads'                 => new BotMetadata(
				'Meta-ExternalAds',
				'Meta',
				'meta-externalads',
				BotCategory::DIAGNOSTIC,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Crawls for Meta advertising and other business-product functions.', 'cybermaps' )
			),
			'duckassistbot'                    => new BotMetadata(
				'DuckAssistBot',
				'DuckDuckGo',
				'DuckAssistBot',
				BotCategory::AI_SEARCH,
				array(
					'robots' => true,
					'llm'    => true,
				),
				__( 'Crawls pages in real time for DuckDuckGo AI-assisted answers and citations; DuckDuckGo says the data is not used to train AI models.', 'cybermaps' )
			),
			'cloudflare-ai-search'             => new BotMetadata(
				'Cloudflare AI Search',
				'Cloudflare',
				'Cloudflare-AI-Search',
				BotCategory::AI_SEARCH,
				array(
					'robots' => true,
					'llm'    => true,
				),
				__( 'Indexes a site selected by its owner as a data source for Cloudflare AI Search.', 'cybermaps' )
			),
			'linkupbot'                        => new BotMetadata(
				'LinkupBot',
				'Linkup',
				'LinkupBot',
				BotCategory::AI_SEARCH,
				array(
					'robots' => true,
					'llm'    => true,
				),
				__( 'Indexes public web content for Linkup web search and AI application grounding.', 'cybermaps' )
			),
			'shapbot'                          => new BotMetadata(
				'ShapBot',
				'Parallel Web Systems',
				'ShapBot',
				BotCategory::AI_SEARCH,
				array(
					'robots' => true,
					'llm'    => true,
				),
				__( 'Builds Parallel search indexes for its web APIs and search products.', 'cybermaps' )
			),
			'sofyabot'                         => new BotMetadata(
				'SofyaBot',
				'Sofya',
				'SofyaBot',
				BotCategory::AI_SEARCH,
				array(
					'robots' => true,
					'llm'    => true,
				),
				__( 'Builds Sofya independent web indexes for search APIs used by AI agents.', 'cybermaps' )
			),
			'youbot'                           => new BotMetadata(
				'YouBot',
				'You.com',
				'YouBot',
				BotCategory::AI_SEARCH,
				array(
					'robots' => true,
					'llm'    => true,
				),
				__( 'Indexes public pages for real-time You.com search results.', 'cybermaps' )
			),
			'iboubot'                          => new BotMetadata(
				'IbouBot',
				'IBOU',
				'IbouBot',
				BotCategory::AI_SEARCH,
				array(
					'robots' => true,
					'llm'    => true,
				),
				__( 'Builds the IBOU conversational search index without using crawled data for model training.', 'cybermaps' )
			),
			'ccbot'                            => new BotMetadata(
				'CCBot',
				'Common Crawl',
				'CCBot',
				BotCategory::DATA_CRAWLER,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Builds Common Crawl\'s open web archive, which is made available for independent downstream reuse.', 'cybermaps' )
			),
			'imagesiftbot'                     => new BotMetadata(
				'ImagesiftBot',
				'Imagesift',
				'ImagesiftBot',
				BotCategory::DATA_CRAWLER,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Indexes public images for Imagesift web-intelligence, image search, and retrieval products.', 'cybermaps' )
			),
			'diffbot'                          => new BotMetadata(
				'Diffbot',
				'Diffbot',
				'Diffbot',
				BotCategory::DATA_CRAWLER,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Crawls and extracts structured web data for Diffbot collections and its Knowledge Graph.', 'cybermaps' )
			),
			'perplexitybot'                    => new BotMetadata(
				'PerplexityBot',
				'Perplexity',
				'PerplexityBot',
				BotCategory::AI_SEARCH,
				array(
					'robots' => true,
					'llm'    => true,
				),
				__( 'Indexes pages for Perplexity search and citations; Perplexity says it is not used for foundation-model training.', 'cybermaps' )
			),
			'perplexity-user'                  => new BotMetadata(
				'Perplexity-User',
				'Perplexity',
				'Perplexity-User',
				BotCategory::AI_USER,
				array(
					'robots' => true,
					'llm'    => true,
				),
				__( 'Fetches a page for a specific Perplexity user request rather than indexing or model training; Perplexity says it generally ignores robots.txt.', 'cybermaps' )
			),
			'oai-searchbot'                    => new BotMetadata(
				'OAI-SearchBot',
				'OpenAI',
				'OAI-SearchBot',
				BotCategory::AI_SEARCH,
				array(
					'robots' => true,
					'llm'    => true,
				),
				__( 'Indexes content so it can appear in ChatGPT search results; OpenAI says it is not used to train generative AI foundation models.', 'cybermaps' ),
				array( 'SearchGPT-Lib' )
			),
			'proximic'                         => new BotMetadata(
				'Proximic',
				'Comscore',
				'proximic',
				BotCategory::DATA_CRAWLER,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Comscore crawler used for contextual classification and advertising data products.', 'cybermaps' )
			),
			'archive-org-bot'                  => new BotMetadata(
				'archive.org_bot',
				'Internet Archive',
				'archive.org_bot',
				BotCategory::DATA_CRAWLER,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Archives public web pages for the Internet Archive Wayback Machine.', 'cybermaps' )
			),
			'velen-crawler'                    => new BotMetadata(
				'VelenPublicWebCrawler',
				'Hunter',
				'VelenPublicWebCrawler',
				BotCategory::DATA_CRAWLER,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Collects public web and business data for Hunter datasets and machine-learning systems.', 'cybermaps' )
			),
			'clueweb-crawler'                  => new BotMetadata(
				'ClueWeb Crawler',
				'Carnegie Mellon University',
				'ClueWeb-Crawler',
				BotCategory::DATA_CRAWLER,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Collects web data for the academic ClueWeb research datasets.', 'cybermaps' )
			),
			'turnitin'                         => new BotMetadata(
				'TurnitinBot',
				'Turnitin',
				'TurnitinBot',
				BotCategory::DATA_CRAWLER,
				array(
					'robots' => false,
					'llm'    => false,
				),
				__( 'Recognizes Turnitin content-indexing requests; Turnitin does not document a robots.txt control contract.', 'cybermaps' ),
				array(),
				true,
				array(),
				false
			),
			'googlebot'                        => new BotMetadata(
				'Googlebot',
				'Google',
				'Googlebot',
				BotCategory::SEARCH_ENGINE,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Primary crawler for Google Search.', 'cybermaps' ),
				array( 'Googlebot-Image', 'Googlebot-Video' )
			),
			'googlebot-news'                   => new BotMetadata(
				'Googlebot-News',
				'Google',
				'Googlebot-News',
				BotCategory::SEARCH_ENGINE,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Robots-only product token controlling Google News crawling; it sends no separate requests.', 'cybermaps' ),
				array(),
				false
			),
			'bingbot'                          => new BotMetadata(
				'Bingbot',
				'Microsoft',
				'Bingbot',
				BotCategory::SEARCH_ENGINE,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Primary crawler for Microsoft Bing index.', 'cybermaps' )
			),
			'duckduckbot'                      => new BotMetadata(
				'DuckDuckBot',
				'DuckDuckGo',
				'DuckDuckBot',
				BotCategory::SEARCH_ENGINE,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Crawls the web to improve DuckDuckGo search results and search security.', 'cybermaps' )
			),
			'applebot'                         => new BotMetadata(
				'Applebot',
				'Apple',
				'Applebot',
				BotCategory::SEARCH_ENGINE,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Indexes content for Spotlight, Siri, and Safari, provides current context for Apple generative features, and may support Apple model improvement.', 'cybermaps' )
			),
			'yandexbot'                        => new BotMetadata(
				'YandexBot',
				'Yandex',
				'YandexBot',
				BotCategory::SEARCH_ENGINE,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Primary crawler for Yandex Search.', 'cybermaps' )
			),
			'petalbot'                         => new BotMetadata(
				'PetalBot',
				'Huawei',
				'PetalBot',
				BotCategory::SEARCH_ENGINE,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Indexes content for Petal Search, Huawei Assistant, and Huawei AI Search.', 'cybermaps' )
			),
			'baiduspider'                      => new BotMetadata(
				'Baiduspider',
				'Baidu',
				'Baiduspider',
				BotCategory::SEARCH_ENGINE,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Primary crawler family for Baidu Search.', 'cybermaps' ),
				array( 'Baiduspider-image', 'Baiduspider-video', 'Baiduspider-news' )
			),
			'sogou-spider'                     => new BotMetadata(
				'Sogou Spider',
				'Sogou',
				'Sogou web spider',
				BotCategory::SEARCH_ENGINE,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Primary web crawler for Sogou Search.', 'cybermaps' ),
				array( 'Sogou inst spider', 'Sogouspider' )
			),
			'mojeekbot'                        => new BotMetadata(
				'MojeekBot',
				'Mojeek',
				'MojeekBot',
				BotCategory::SEARCH_ENGINE,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Independent, privacy-oriented search crawler.', 'cybermaps' )
			),
			'qwantify'                         => new BotMetadata(
				'Qwantbot',
				'Qwant',
				'Qwantbot',
				BotCategory::SEARCH_ENGINE,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Current web crawler for Qwant Search, including its news-specific variant.', 'cybermaps' ),
				array( 'Qwantbot-news', 'Qwantify', 'Qwantify-News' )
			),
			'naverbot'                         => new BotMetadata(
				'Yeti',
				'Naver',
				'Yeti',
				BotCategory::SEARCH_ENGINE,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Current primary crawler for Naver Search.', 'cybermaps' ),
				array( 'Naverbot' )
			),
			'coccocbot'                        => new BotMetadata(
				'Cốc Cốc crawlers',
				'Cốc Cốc',
				'coccocbot',
				BotCategory::SEARCH_ENGINE,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Crawler family used to populate the Cốc Cốc Search index.', 'cybermaps' ),
				array( 'coccocbot-web', 'coccocbot-image', 'coccocbot-fast', 'coccocbot-ads', 'coccocbot-shopping' )
			),
			'seekportbot'                      => new BotMetadata(
				'SeekportBot',
				'SISTRIX',
				'SeekportBot',
				BotCategory::SEARCH_ENGINE,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Crawls pages for the Seekport search index operated by SISTRIX.', 'cybermaps' )
			),
			'ahrefsbot'                        => new BotMetadata(
				'AhrefsBot',
				'Ahrefs',
				'AhrefsBot',
				BotCategory::SEO_TOOL,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Builds the Ahrefs marketing-intelligence index and the Yep search index.', 'cybermaps' )
			),
			'ahrefs-site-audit'                => new BotMetadata(
				'AhrefsSiteAudit',
				'Ahrefs',
				'AhrefsSiteAudit',
				BotCategory::SEO_TOOL,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'User-configured crawler for Ahrefs Site Audit technical and on-page analysis.', 'cybermaps' )
			),
			'semrushbot'                       => new BotMetadata(
				'SemrushBot',
				'Semrush',
				'SemrushBot',
				BotCategory::SEO_TOOL,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Builds Semrush web and backlink data used across its marketing-analysis tools.', 'cybermaps' ),
				array( 'SemrushBot-BA' )
			),
			'dotbot'                           => new BotMetadata(
				'DotBot',
				'Moz',
				'DotBot',
				BotCategory::SEO_TOOL,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Builds Moz Link Explorer\'s web and link index.', 'cybermaps' )
			),
			'mj12bot'                          => new BotMetadata(
				'MJ12bot',
				'Majestic',
				'MJ12bot',
				BotCategory::SEO_TOOL,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Majestic\'s distributed crawler for link analysis.', 'cybermaps' )
			),
			'rogerbot'                         => new BotMetadata(
				'Rogerbot',
				'Moz',
				'Rogerbot',
				BotCategory::SEO_TOOL,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Runs Moz Pro Campaign and On-Demand Crawl site audits.', 'cybermaps' )
			),
			'siteauditbot'                     => new BotMetadata(
				'SiteAuditBot',
				'Semrush',
				'SiteAuditBot',
				BotCategory::SEO_TOOL,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'User-configured crawler for Semrush Site Audit technical analysis.', 'cybermaps' )
			),
			'lighthouse'                       => new BotMetadata(
				'Lighthouse',
				'Google',
				'Chrome-Lighthouse',
				BotCategory::SEO_TOOL,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'User-triggered Lighthouse performance, accessibility, best-practices, and SEO audit signature.', 'cybermaps' )
			),
			'gtmetrix'                         => new BotMetadata(
				'GTmetrix',
				'Carbon60',
				'GTmetrix',
				BotCategory::SEO_TOOL,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'User-triggered GTmetrix performance-test signature.', 'cybermaps' )
			),
			'screaming-frog'                   => new BotMetadata(
				'Screaming Frog',
				'Screaming Frog',
				'Screaming Frog SEO Spider',
				BotCategory::SEO_TOOL,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Desktop-based SEO spider for local site crawling.', 'cybermaps' )
			),
			'serpstatbot'                      => new BotMetadata(
				'SerpstatBot',
				'Serpstat',
				'SerpstatBot',
				BotCategory::SEO_TOOL,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Collects backlink and search-analysis data for the Serpstat SEO platform.', 'cybermaps' )
			),
			'dataforseo-bot'                   => new BotMetadata(
				'DataForSEO Bot',
				'DataForSEO',
				'DataForSeoBot',
				BotCategory::SEO_TOOL,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Collects public search and web data for DataForSEO APIs.', 'cybermaps' )
			),
			'hubspot-crawler'                  => new BotMetadata(
				'HubSpot Crawler',
				'HubSpot',
				'HubSpot Crawler',
				BotCategory::SEO_TOOL,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Crawls customer sites for HubSpot SEO and content-management features.', 'cybermaps' )
			),
			'seojuice-searchbot'               => new BotMetadata(
				'SEOJuice SearchBot',
				'SEOJuice',
				'SEOJuice-SearchBot',
				BotCategory::SEO_TOOL,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Crawls pages for SEOJuice internal-link and content analysis.', 'cybermaps' )
			),
			'seranking-backlinksbot'           => new BotMetadata(
				'SE Ranking Backlinks Bot',
				'SE Ranking',
				'SERankingBacklinksBot',
				BotCategory::SEO_TOOL,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Collects backlink data for the SE Ranking SEO platform.', 'cybermaps' )
			),
			'poweredbybot'                     => new BotMetadata(
				'PoweredByBot',
				'Keywords Everywhere',
				'PoweredByBot',
				BotCategory::SEO_TOOL,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Collects public page data for Keywords Everywhere and PoweredBy technology reports.', 'cybermaps' )
			),
			'pingdom'                          => new BotMetadata(
				'Pingdom',
				'SolarWinds',
				'Pingdom.com_bot',
				BotCategory::DIAGNOSTIC,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'HTTP uptime and response-time monitor used by configured Pingdom checks.', 'cybermaps' ),
				array( 'Pingdom.com_bot_version_1.4_', 'Pingdom.com' )
			),
			'uptimerobot'                      => new BotMetadata(
				'UptimeRobot',
				'UptimeRobot',
				'UptimeRobot',
				BotCategory::DIAGNOSTIC,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Website heartbeat and uptime monitoring.', 'cybermaps' )
			),
			'cloudflare-check'                 => new BotMetadata(
				'Cloudflare Health Checks',
				'Cloudflare',
				'Cloudflare-Healthchecks',
				BotCategory::DIAGNOSTIC,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Monitors configured origins for Cloudflare Health Checks.', 'cybermaps' )
			),
			'stripebot'                        => new BotMetadata(
				'Stripebot',
				'Stripe',
				'Stripebot',
				BotCategory::DIAGNOSTIC,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Checks merchant pages for Stripe product, payment, and link-preview features.', 'cybermaps' )
			),
			'knowngood-verifier'               => new BotMetadata(
				'KnownGood Verifier',
				'Known Good',
				'KnownGood-Verifier',
				BotCategory::DIAGNOSTIC,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Verifies public site security and ownership signals for Known Good services.', 'cybermaps' )
			),
			'stripe-merchant-security-scanner' => new BotMetadata(
				'Stripe Merchant Security Scanner',
				'Stripe',
				'Stripe Merchant Security Scanner',
				BotCategory::DIAGNOSTIC,
				array(
					'robots' => false,
					'llm'    => false,
				),
				__( 'Recognizes Stripe merchant security scans, which are not documented as a robots.txt-controlled crawler.', 'cybermaps' ),
				array(),
				true,
				array(),
				false
			),
			'feedfetcher-google'               => new BotMetadata(
				'FeedFetcher-Google',
				'Google',
				'FeedFetcher-Google',
				BotCategory::OTHER,
				array(
					'robots' => false,
					'llm'    => false,
				),
				__( 'Recognizes Google feed-fetching requests, which Google documents as not following robots.txt.', 'cybermaps' ),
				array(),
				true,
				array(),
				false
			),
			'meta-externalhit'                 => new BotMetadata(
				'FacebookExternalHit',
				'Meta',
				'facebookexternalhit',
				BotCategory::SOCIAL,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Fetches content shared in Meta apps such as Facebook, Instagram, and Messenger; security checks may bypass robots.txt.', 'cybermaps' )
			),
			'twitterbot'                       => new BotMetadata(
				'X/Twitter',
				'X Corp',
				'Twitterbot',
				BotCategory::SOCIAL,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Used for link previews and card generation on X.', 'cybermaps' )
			),
			'linkedinbot'                      => new BotMetadata(
				'LinkedInBot',
				'LinkedIn',
				'LinkedInBot',
				BotCategory::SOCIAL,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Used for professional link previews on LinkedIn.', 'cybermaps' )
			),
			'pinterestbot'                     => new BotMetadata(
				'Pinterestbot',
				'Pinterest',
				'Pinterestbot',
				BotCategory::SOCIAL,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Indexes public page metadata and media for Pins and Pinterest link previews; Pinterest says this crawler is not used to train Pinterest Canvas.', 'cybermaps' ),
				array(),
				true,
				array( 'Pinterest/0.2' )
			),
			'whatsapp'                         => new BotMetadata(
				'WhatsApp',
				'Meta',
				'WhatsApp',
				BotCategory::SOCIAL,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Used for link previews in WhatsApp chat threads.', 'cybermaps' )
			),
			'discordbot'                       => new BotMetadata(
				'Discordbot',
				'Discord',
				'Discordbot',
				BotCategory::SOCIAL,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Used for link previews and rich embeds on Discord.', 'cybermaps' )
			),
			'slackbot'                         => new BotMetadata(
				'Slackbot',
				'Slack',
				'Slackbot',
				BotCategory::SOCIAL,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Fetches links and media posted by Slack users for unfurling; Slack says these on-demand robots do not honor robots.txt.', 'cybermaps' ),
				array( 'Slackbot-LinkExpanding', 'Slack-ImgProxy' )
			),
			'telegrambot'                      => new BotMetadata(
				'TelegramBot',
				'Telegram',
				'TelegramBot',
				BotCategory::SOCIAL,
				array(
					'robots' => true,
					'llm'    => false,
				),
				__( 'Used for link previews in Telegram conversations.', 'cybermaps' )
			),
		);

		return $registry;
	}

	/**
	 * Return crawlers that expose a meaningful robots.txt policy control.
	 *
	 * @return array<string, BotMetadata>
	 */
	public static function get_policy_bots(): array {
		return array_filter(
			self::get_all(),
			static fn( BotMetadata $bot ): bool => $bot->supports_robots_policy
		);
	}

	/**
	 * Resolve a retired saved-control ID to its current canonical entry.
	 */
	public static function canonicalize_id( string $id ): string {
		return self::LEGACY_ID_MAP[ $id ] ?? $id;
	}

	/**
	 * Apply retired-ID aliases to a saved override map without mutating storage.
	 *
	 * If both IDs exist, the current canonical value is authoritative. Unknown
	 * extension-owned entries are preserved for forward compatibility.
	 *
	 * @param array<string, mixed> $overrides Stored per-crawler overrides.
	 * @return array<string, mixed>
	 */
	public static function normalize_overrides( array $overrides ): array {
		foreach ( self::LEGACY_ID_MAP as $legacy_id => $canonical_id ) {
			if ( ! array_key_exists( $legacy_id, $overrides ) ) {
				continue;
			}

			if ( ! array_key_exists( $canonical_id, $overrides ) ) {
				$overrides[ $canonical_id ] = $overrides[ $legacy_id ];
			}
			unset( $overrides[ $legacy_id ] );
		}

		return $overrides;
	}

	/**
	 * Whether a crawler category can be named as an AI discovery manifest target.
	 */
	public static function category_supports_manifest_target( string $category ): bool {
		return in_array(
			$category,
			array(
				BotCategory::AI_TRAINING->value,
				BotCategory::AI_SEARCH->value,
				BotCategory::AI_USER->value,
			),
			true
		);
	}

	/**
	 * Whether a crawler can be named as an AI discovery manifest target.
	 */
	public static function supports_manifest_target( BotMetadata $bot ): bool {
		return self::category_supports_manifest_target( $bot->category->value );
	}

	/**
	 * Identify a bot ID from a User-Agent string.
	 *
	 * @param string $ua User-Agent string.
	 * @return string|null Bot ID if found, otherwise null.
	 */
	public static function identify_bot( string $ua ): ?string {
		$match = self::match_user_agent( $ua );

		return $match?->id;
	}

	/**
	 * Match an explicit crawler User-Agent product token.
	 *
	 * User-Agent values are claims, not verified identities. This method avoids
	 * treating bot-name fragments in documentation URLs or prose comments as
	 * product tokens, then selects the longest matching signature so a specific
	 * crawler wins over a more general family token.
	 *
	 * @param string $ua User-Agent string.
	 * @return CrawlerMatch|null Match evidence if found, otherwise null.
	 */
	public static function match_user_agent( string $ua ): ?CrawlerMatch {
		if ( '' === trim( $ua ) ) {
			return null;
		}

		$url_spans             = self::get_url_spans( $ua );
		$best_match            = null;
		$best_signature_length = -1;

		foreach ( self::get_all() as $id => $metadata ) {
			foreach ( $metadata->get_user_agent_signatures() as $signature ) {
				$signature_length = strlen( $signature );
				if (
					$signature_length <= $best_signature_length
					|| ! self::has_product_token( $ua, $signature, $url_spans )
				) {
					continue;
				}

				$best_match            = new CrawlerMatch(
					$id,
					$metadata,
					$signature
				);
				$best_signature_length = $signature_length;
			}
		}

		return $best_match;
	}

	/**
	 * Locate URL spans so documentation links cannot masquerade as bot tokens.
	 *
	 * @param string $ua User-Agent string.
	 * @return array<int, array{start: int, end: int}>
	 */
	private static function get_url_spans( string $ua ): array {
		$matched = preg_match_all(
			'~(?:https?|ftp)://[^\\s<>"\']+~i',
			$ua,
			$urls,
			PREG_OFFSET_CAPTURE
		);

		if ( false === $matched || 0 === $matched ) {
			return array();
		}

		$spans = array();
		foreach ( $urls[0] as $url ) {
			$start   = (int) $url[1];
			$spans[] = array(
				'start' => $start,
				'end'   => $start + strlen( $url[0] ),
			);
		}

		return $spans;
	}

	/**
	 * Determine whether an explicit signature appears as a UA product token.
	 *
	 * @param string                                    $ua        User-Agent string.
	 * @param string                                    $signature Explicit registry signature.
	 * @param array<int, array{start: int, end: int}> $url_spans URL byte ranges.
	 */
	private static function has_product_token( string $ua, string $signature, array $url_spans ): bool {
		$pattern = '~(?<![A-Za-z0-9_./+-])' . preg_quote( $signature, '~' ) . '(?![A-Za-z0-9_.+-])~i';
		$matched = preg_match_all( $pattern, $ua, $occurrences, PREG_OFFSET_CAPTURE );

		if ( false === $matched || 0 === $matched ) {
			return false;
		}

		foreach ( $occurrences[0] as $occurrence ) {
			$offset = (int) $occurrence[1];
			if (
				! self::is_inside_span( $offset, $url_spans )
				&& ! self::is_comment_prose( $ua, $offset, strlen( $occurrence[0] ) )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether a byte offset is inside one of the supplied ranges.
	 *
	 * @param int                                       $offset Byte offset.
	 * @param array<int, array{start: int, end: int}> $spans  Byte ranges.
	 */
	private static function is_inside_span( int $offset, array $spans ): bool {
		foreach ( $spans as $span ) {
			if ( $offset >= $span['start'] && $offset < $span['end'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reject a bot-name mention embedded in prose within a UA comment.
	 *
	 * Real compatible bot tokens begin a parenthesized comment segment, either
	 * immediately after "(" or after a semicolon. Text such as
	 * "(not Amazonbot)" therefore remains unclassified.
	 *
	 * @param string $ua               User-Agent string.
	 * @param int    $offset           Signature byte offset.
	 * @param int    $signature_length Signature byte length.
	 */
	private static function is_comment_prose( string $ua, int $offset, int $signature_length ): bool {
		$before     = substr( $ua, 0, $offset );
		$open_pos   = strrpos( $before, '(' );
		$closed_pos = strrpos( $before, ')' );

		if ( false === $open_pos || ( false !== $closed_pos && $closed_pos > $open_pos ) ) {
			return false;
		}

		$separator_pos = strrpos( $before, ';' );
		$segment_start = (
			false !== $separator_pos
			&& $separator_pos > $open_pos
		) ? $separator_pos + 1 : $open_pos + 1;

		if ( '' !== trim( substr( $ua, $segment_start, $offset - $segment_start ) ) ) {
			return true;
		}

		$segment_end = strpos( $ua, ';', $offset + $signature_length );
		$comment_end = strpos( $ua, ')', $offset + $signature_length );

		if ( false === $segment_end || ( false !== $comment_end && $comment_end < $segment_end ) ) {
			$segment_end = $comment_end;
		}
		if ( false === $segment_end ) {
			$segment_end = strlen( $ua );
		}

		$suffix = trim(
			substr(
				$ua,
				$offset + $signature_length,
				$segment_end - ( $offset + $signature_length )
			)
		);

		if ( '' === $suffix ) {
			return false;
		}

		return 1 !== preg_match(
			"@^(?:/[A-Za-z0-9!#$%&'*+.^_`|~-]+(?:$|[\s)])|\+?https?://[^\s)]+$)@i",
			$suffix
		);
	}

	/**
	 * Get human-readable category labels.
	 *
	 * @return array Mapping of category IDs to labels.
	 */
	public static function get_categories(): array {
		$represented = array();
		foreach ( self::get_all() as $bot ) {
			$represented[ $bot->category->value ] = true;
		}

		$categories = array();
		foreach ( BotCategory::cases() as $category ) {
			if ( ! isset( $represented[ $category->value ] ) ) {
				continue;
			}

			$categories[ $category->value ] = $category->label();
		}
		return $categories;
	}

	/**
	 * Get categories represented by robots-policy-capable crawlers.
	 *
	 * @return array<string, string>
	 */
	public static function get_policy_categories(): array {
		$represented = array();
		foreach ( self::get_policy_bots() as $bot ) {
			$represented[ $bot->category->value ] = true;
		}

		$categories = array();
		foreach ( BotCategory::cases() as $category ) {
			if ( ! isset( $represented[ $category->value ] ) ) {
				continue;
			}

			$categories[ $category->value ] = $category->label();
		}

		return $categories;
	}
}
