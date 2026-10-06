<?php
/** Release guard for the deliberately limited remote capability contract. */
declare(strict_types=1);
$root = dirname(__DIR__);
define('ABSPATH', $root . '/');
require_once $root . '/src/Autoloader.php';
\Cybermaps\Autoloader::register();
$expected = json_decode(file_get_contents($root . '/docs/dev/mcp-contract.json'), true, 512, JSON_THROW_ON_ERROR);
$actual = array(
    'server_id' => \Cybermaps\MCP\WordPressIntegration::SERVER_ID,
    'namespace' => \Cybermaps\MCP\WordPressIntegration::REST_NAMESPACE,
    'route' => \Cybermaps\MCP\WordPressIntegration::REST_ROUTE,
    'minimum_adapter_version' => \Cybermaps\MCP\AdapterDependency::MINIMUM_VERSION,
    'tools' => \Cybermaps\MCP\WordPressIntegration::TOOLS,
    'resource_ids' => \Cybermaps\MCP\ResourceAbilities::IDS,
);
$errors = array();
if ($actual !== $expected) $errors[] = 'Remote surface differs from the reviewed MCP contract. Review functionality and policy before changing the contract.';
$owners = array('src/Core/AbilityKernel.php', 'src/MCP/ResourceAbilities.php');
$allowed = array('AdapterDependency.php', 'ResourceAbilities.php', 'WordPressIntegration.php');
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src')) as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $path = substr($file->getPathname(), strlen($root) + 1);
    $source = file_get_contents($file->getPathname());
    if (preg_match('/\b(?:wp_set_current_user|wp_get_abilities|execute_tool)\s*\(/', $source)) $errors[] = 'Generic remote authority or identity switching is prohibited: ' . $path;
    if (preg_match('/\bwp_register_ability\s*\(/', $source) && !in_array($path, $owners, true)) $errors[] = 'Unreviewed ability registration owner: ' . $path;
    if (str_starts_with($path, 'src/MCP/') && !in_array(basename($path), $allowed, true)) $errors[] = 'Unreviewed MCP implementation: ' . $path;
    if (preg_match('/\b(?:mcp_mode|agent_registration_mode)\b/', $source) && !in_array($path, array('src/Core/MCPMigration.php', 'src/Admin/Settings/Sanitizers/SettingsDiscoveryNormalizer.php'), true)) $errors[] = 'Retired MCP configuration may only be deleted or converted by cleanup: ' . $path;
    if (str_contains($source, 'upgrade_oauth_schema')) $errors[] = 'Retired OAuth upgrade placeholder is prohibited: ' . $path;
    if (str_contains($source, 'Cybermaps\\MCP\\OAuth\\')) $errors[] = 'Retired MCP OAuth implementation referenced: ' . $path;
}
foreach ($errors as $error) fwrite(STDERR, $error . PHP_EOL);
if ($errors) exit(1);
echo "Reviewed MCP surface: one owned read-only tool, bounded public resources, optional external adapter; no generic ability bridge.\n";
