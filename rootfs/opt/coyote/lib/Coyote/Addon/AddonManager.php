<?php

namespace Coyote\Addon;

/**
 * Manages the discovery, loading, and lifecycle of Coyote add-ons.
 */
class AddonManager
{
    /** @var string Directory where add-ons are installed */
    private string $addonDir;

    /** @var array<string, AddonInterface> Loaded add-ons indexed by ID */
    private array $addons = [];

    /** @var array<string, array> Cached addon.json manifests */
    private array $manifests = [];

    /**
     * Create a new AddonManager instance.
     *
     * @param string $addonDir Path to the add-ons directory
     */
    public function __construct(string $addonDir = '/opt/coyote/addons')
    {
        $this->addonDir = $addonDir;
    }

    /**
     * Discover and load all installed add-ons.
     *
     * @return void
     */
    public function discoverAddons(): void
    {
        if (!is_dir($this->addonDir)) {
            return;
        }

        foreach (scandir($this->addonDir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $manifestPath = $this->addonDir . '/' . $entry . '/addon.json';
            if (file_exists($manifestPath)) {
                $this->loadAddon($entry);
            }
        }
    }

    /**
     * Load a specific add-on by its directory name.
     *
     * @param string $addonName Directory name of the add-on
     * @return bool True if loaded successfully
     */
    public function loadAddon(string $addonName): bool
    {
        $manifestPath = $this->addonDir . '/' . $addonName . '/addon.json';

        if (!file_exists($manifestPath)) {
            return false;
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (!$manifest) {
            return false;
        }

        $this->manifests[$addonName] = $manifest;

        // Load the add-on class if specified
        if (isset($manifest['files']['addon-class'])) {
            $classFile = $this->addonDir . '/' . $addonName . '/' . $manifest['files']['addon-class'];
            if (file_exists($classFile)) {
                require_once $classFile;

                // Instantiate the add-on class
                // Class name is derived from manifest name
                $className = $this->getAddonClassName($manifest);
                if (class_exists($className)) {
                    $addon = new $className();
                    if ($addon instanceof AddonInterface) {
                        $this->addons[$addon->getId()] = $addon;
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Get a loaded add-on by ID.
     *
     * @param string $id Add-on identifier
     * @return AddonInterface|null The add-on or null if not found
     */
    public function getAddon(string $id): ?AddonInterface
    {
        return $this->addons[$id] ?? null;
    }

    /**
     * Get all loaded add-ons.
     *
     * @return array<string, AddonInterface>
     */
    public function getAddons(): array
    {
        return $this->addons;
    }

    /**
     * Get the manifest for an add-on.
     *
     * @param string $addonName Add-on directory name
     * @return array|null Manifest data or null if not found
     */
    public function getManifest(string $addonName): ?array
    {
        return $this->manifests[$addonName] ?? null;
    }

    /**
     * Initialize all loaded add-ons.
     *
     * @return void
     */
    public function initializeAll(): void
    {
        foreach ($this->addons as $addon) {
            $addon->initialize();
        }
    }

    /**
     * Derive the add-on class name from its manifest.
     *
     * @param array $manifest Add-on manifest
     * @return string Fully qualified class name
     */
    private function getAddonClassName(array $manifest): string
    {
        $name = $manifest['name'] ?? '';

        // Convert coyote-firewall to Coyote\Firewall\FirewallAddon
        if (preg_match('/^coyote-(\w+)$/', $name, $matches)) {
            $component = ucfirst($matches[1]);
            return "Coyote\\{$component}\\{$component}Addon";
        }

        return '';
    }
}
