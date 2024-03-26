<?php

namespace DrupalContribDevelopment;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use DrupalContribDevelopment\Command\SwitchPackageToGitClone;

/**
 * Defines our commands to Composer.
 */
class CommandProvider implements CommandProviderCapability {

  /**
   * {@inheritdoc}
   */
  public function getCommands() {
    return [
      new SwitchPackageToGitClone(),
    ];
  }

}
