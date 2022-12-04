<?php

declare(strict_types=1);

namespace Mcbeany\PMMPGet;

use Generator;
use Mcbeany\libAsync\libAsync;
use pocketmine\command\CommandSender;
use pocketmine\command\Command;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Internet;
use SOFe\AwaitGenerator\Await;

class PMMPGet extends PluginBase
{
    const URL = "https://poggit.pmmp.io/releases.min.json?fields=id,name,version,tagline,repo_name,artifact_url,last_state_change_date,keywords,api,deps";
    protected static self $instance;
    /** @var PluginManifest[] */
    private array $cachedPlugins = [];
    private bool $caching = false;
    /** @var PluginManifest[][] */
    private array $searchResults = [];

    protected function onEnable(): void
    {
        self::$instance = $this;
        Await::g2c($this->cachePlugins());
    }

    public static function getInstance(): self
    {
        return self::$instance;
    }

    /**
     * @return PluginManifest[]
     */
    public function getCachedPlugins(): array
    {
        return $this->cachedPlugins;
    }

    public function getCachedPlugin(int $id): ?PluginManifest
    {
        return $this->cachedPlugins[$id] ?? null;
    }

    public function isCaching(): bool
    {
        return $this->caching;
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool
    {
        Await::f2c(function () use ($sender, $args) {
            switch (array_shift($args)) {
                case "?":
                case "help":
                    $sender->sendMessage("List of avaliable commands:");
                    $sender->sendMessage("- pmmpget help > Show this list of commands");
                    $sender->sendMessage("- pmmpget update > Update plugin information list");
                    $sender->sendMessage("- pmmpget search <name or keyword> [version] > Search for plugins");
                    $sender->sendMessage("- pmmpget info <id> > View plugin details");
                    $sender->sendMessage("- pmmpget install <id> > Install a plugin");
                    $sender->sendMessage("- pmmpget disable <name> > Disable a plugin");
                    $sender->sendMessage("(`[]` is optional, `<>` is required)");
                    break;
                case "update":
                    $sender->sendMessage("Updating cache...");
                    yield from $this->updateCache();
                    $sender->sendMessage("Updated successfully!");
                    break;
                case "find":
                case "search":
                    if (!isset($args[0])) {
                        $sender->sendMessage("Arguments missing! Use `pmmpget help` to see the usage");
                    } else {
                        $plugins = $this->findPlugins(...$args);
                        if (empty($plugins)) {
                            $sender->sendMessage("No plugins found!");
                        } else {
                            $sender->sendMessage(count($plugins) . " plugins found!");
                            foreach ($plugins as $id => $plugin) {
                                $sender->sendMessage("- " . $id . ". " . $plugin->getRepoName() . ":" . $plugin->getVersion());
                            }
                        }
                    }
                    break;
                case "info":
                    if (!isset($args[0])) {
                        $sender->sendMessage("Arguments missing! Use `pmmpget help` to see the usage");
                    }
                    if (is_numeric($args[0])) {
                        $id = (int)$args[0];
                        $plugin = $this->getCachedPlugin($id);
                        if ($plugin === null) {
                            $sender->sendMessage("Invalid plugin ID! Use `pmmpget search` to see the plugin ID");
                        } else {
                            $sender->sendMessage("About " . $plugin->getRepoName() . " plugin:");
                            $sender->sendMessage("- Poggit ID: " . $id);
                            $sender->sendMessage("- Version: " . $plugin->getVersion());
                            $sender->sendMessage("- Description: " . $plugin->getDescription());
                            $sender->sendMessage("- Last updated: " . date("Y-m-d H:i:s", $plugin->getLastUpdated()));
                            $sender->sendMessage(" - APIs: " . implode(", ", $plugin->getApis()));
                            $sender->sendMessage(" - Dependencies: " . implode(", ", array_map(fn (DependencyManifest $dependency) => $dependency->getName(), $plugin->getDependencies())));
                        }
                    } else {
                        $sender->sendMessage("Plugin ID must be a number! Use `pmmpget search` to see the plugin ID");
                    }
                    break;
                case "install":
                    if (!isset($args[0])) {
                        $sender->sendMessage("Arguments missing! Use `pmmpget help` to see the usage");
                    }
                    if (is_numeric($args[0])) {
                        $id = (int)$args[0];
                        $plugin = $this->getCachedPlugin($id);
                        if ($plugin === null) {
                            $sender->sendMessage("Invalid plugin ID! Use `pmmpget search` to see the plugin ID");
                        } else {
                            $sender->sendMessage("Installing " . $plugin->getRepoName() . ":" . $plugin->getVersion() . " plugin");
                            $bytes = yield from $this->installPlugin($plugin);
                            if ($bytes === false) {
                                $sender->sendMessage("Installation failed!");
                            } else {
                                $sender->sendMessage("Successfully installed plugin! " . $bytes . " bytes written");
                                $sender->sendMessage("Please restart your server for this plugin to work");
                            }
                        }
                    } else {
                        $sender->sendMessage("Plugin ID must be a number");
                    }
                    break;
                case "disable":
                    if (!isset($args[0])) {
                        $sender->sendMessage("Arguments missing! Use `pmmpget help` to see the usage");
                    }
                    $plugin = $this->getServer()->getPluginManager()->getPlugin($args[0]);
                    if ($plugin === null) {
                        $sender->sendMessage("Plugin not found or disabled!");
                    } else {
                        yield from $this->disablePlugin($plugin);
                        $sender->sendMessage("Disabled plugin " . $plugin->getName());
                    }
                    break;
                default:
                    $sender->sendMessage("Usage: pmmpget help");
                    break;
            }
        });
        return true;
    }

    private function cachePlugins(): Generator
    {
        $path = $this->getDataFolder() . ".cache";
        if (file_exists($path)) {
            $encodedData = yield from libAsync::doAsync(fn () => file_get_contents($path));
            $this->loadCache(json_decode(gzuncompress($encodedData), true));
            return;
        }
        yield from $this->updateCache();
    }

    public function updateCache(): Generator
    {
        $this->searchResults = [];
        $this->caching = true;
        $rawData = yield from libAsync::doAsync(fn () => Internet::getURL(self::URL)->getBody());
        $path = $this->getDataFolder() . ".cache";
        Await::g2c(libAsync::doAsync(fn () => file_put_contents($path, gzcompress($rawData))));
        $this->loadCache(json_decode($rawData, true));
        $this->caching = false;
    }

    private function loadCache(array $pluginData): void
    {
        foreach ($pluginData as $data) {
            $this->cachedPlugins[$data["id"]] = new PluginManifest(
                $this,
                $data["id"],
                $data["name"],
                $data["repo_name"],
                $data["version"],
                $data["artifact_url"],
                $data["tagline"],
                $data["last_state_change_date"],
                $data["keywords"],
                array_map(fn (array $api) => $api["from"], $data["api"]),
                array_map(fn (array $dep) => new DependencyManifest(
                    $this,
                    $dep["depRelId"],
                    $dep["name"],
                    $dep["version"],
                    $dep["isHard"],
                ), $data["deps"])
            );
        }
    }

    /**
     * @return PluginManifest[]
     */
    public function findPlugins(string $name, ?string $version = null): array
    {
        $key = $name . ":" . ($version ?? "*");
        if (isset($this->searchResults[$key])) {
            return $this->searchResults[$key];
        }
        $results = [];
        foreach ($this->cachedPlugins as $id => $plugin) {
            if (stripos($plugin->getName(), $name) !== false || in_array($name, $plugin->getKeywords())) {
                if ($version !== null && stripos($plugin->getVersion(), $version) === false) {
                    continue;
                }
                $results[$id] = $plugin;
            }
        }
        $this->searchResults[$key] = $results;
        return $results;
    }

    public function installPlugin(PluginManifest $plugin, bool $force = false): Generator
    {
        $path = $this->getServer()->getDataPath() . "plugins//" . $plugin->getName() . ".phar";
        if (file_exists($path) && !$force) {
            return false;
        }
        $url = $plugin->getContentUrl();
        $bytes = yield from libAsync::doAsync(fn () => file_put_contents($path, file_get_contents($url)));
        if ($bytes === false) {
            return false;
        }
        return $bytes;
    }

    public function disablePlugin(Plugin $plugin): Generator
    {
        $this->getServer()->getPluginManager()->disablePlugin($plugin);
        if (!$this->getConfig()->get("write-blacklist")) {
            return;
        }
        $path = $this->getServer()->getDataPath() . "plugin_list.yml";
        $config = yield from libAsync::doAsync(fn () => yaml_parse_file($path));
        if ($config["mode"] === "blacklist") {
            if (!in_array($plugin->getName(), $config["plugins"])) {
                $config["plugins"][] = $plugin->getName();
            }
        } else {
            unset($config["plugins"][array_search($plugin->getName(), $config["plugins"])]);
        }
        yield from libAsync::doAsync(fn () => yaml_emit_file($path, $config));
    }
}
