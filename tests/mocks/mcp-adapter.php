<?php
namespace WP\MCP\Core;
final class McpAdapter {
    public array $calls = array();
    public function create_server( ...$arguments ) { $this->calls[] = $arguments; return $this; }
}
namespace WP\MCP\Transport;
final class HttpTransport {}
