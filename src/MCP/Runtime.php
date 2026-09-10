<?php
declare(strict_types=1);

namespace Cybermaps\MCP;

use Cybermaps\Core\EndpointRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Composition root for the optional MCP runtime. */
final class Runtime {
	public function __construct( private readonly Transport $transport ) {}

	/**
	 * Build the runtime without exposing Cybermaps' internal service container.
	 *
	 * @param array<string, callable> $executors Synchronous search/purge/IndexNow adapters.
	 */
	public static function create(
		ResourceReaderInterface $resource_reader,
		CallerContextResolverInterface $caller_resolver,
		array $executors,
		TaskServiceInterface $tasks,
		?ConfirmationServiceInterface $confirmations = null,
		?EndpointRegistry $endpoints = null
	): self {
		if ( null === $confirmations ) {
			$secret = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : ( defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '' );
			if ( '' === $secret ) {
				throw new \RuntimeException( 'A WordPress authentication salt is required for MCP confirmations.' );
			}
			$confirmations = new OneTimeConfirmationService( new WordPressConfirmationStateStore(), $secret );
		}
		$resources = new ResourceRegistry( $endpoints ?? EndpointRegistry::get_instance(), $resource_reader );
		$tools     = new ToolRegistry( $executors, $tasks, $confirmations );
		$server    = new Server( $resources, $tools, $tasks, $confirmations );
		return new self( new Transport( $server, $caller_resolver ) );
	}

	public function register_hooks(): void {
		$this->transport->register_hooks();
	}
}
