<?php

declare(strict_types=1);

namespace Mcbeany\PMMPGet;

use pocketmine\plugin\ApiVersion;
use pocketmine\utils\VersionString;

class PluginManifest{
  /**
   * @param string[] $keywords
   * @param string[] $apis
   * @param DependencyManifest[] $dependencies
   */
  public function __construct(
    private PMMPGet $main,
    private int $id,
    private string $name,
    private string $repoName,
    private string $version,
    private string $contentUrl,
    private string $description,
    private int $lastUpdated,
    private array $keywords,
    private array $apis,
    private array $dependencies
  ){
  }

  public function getId(): int{
    return $this->id;
  }

  public function getName(): string{
    return $this->name;
  }

  public function getRepoName(): string{
    return $this->repoName;
  }

  public function getVersion(): string{
    return $this->version;
  }

  public function getContentUrl(): string{
    return $this->contentUrl;
  }

  public function getDescription(): string{
    return $this->description;
  }

  public function getLastUpdated(): int{
    return $this->lastUpdated;
  }

  /**
   * @return string[]
   */
  public function getKeywords(): array{
    return $this->keywords;
  }

  /**
   * @return string[]
   */
  public function getApis(): array{
    return $this->apis;
  }

  /**
   * @return DependencyManifest[]
   */
  public function getDependencies(): array{
    return $this->dependencies;
  }

  public function isCompatible(bool $withDependencies = false): bool{
    if($withDependencies){
      foreach($this->dependencies as $dependency){
        $version = new VersionString($dependency->getVersion());
        $plugin = $this->main->getServer()->getPluginManager()->getPlugin($dependency->getName());
        if(($plugin == null && $dependency->isRequired()) || $version->compare(new VersionString($plugin->getDescription()->getVersion()), true) > 0){
          return false;
        }
      }
    }
    return ApiVersion::isCompatible($this->main->getServer()->getApiVersion(), $this->apis);
  }
}
