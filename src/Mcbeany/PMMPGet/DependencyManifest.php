<?php

declare(strict_types=1);

namespace Mcbeany\PMMPGet;

class DependencyManifest{
  public function __construct(
    private PMMPGet $main,
    private int $id,
    private string $name,
    private string $version,
    private bool $required
  ){
  }

  public function getId(): int{
    return $this->id;
  }

  public function getName(): string{
    return $this->name;
  }

  public function getVersion(): string{
    return $this->version;
  }

  public function isRequired(): bool{
    return $this->required;
  }

  public function asPlugin(): PluginManifest{
    return $this->main->getCachedPlugin($this->id);
  }
}
