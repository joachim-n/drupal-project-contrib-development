<?php

namespace DrupalContribDevelopment\Command;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class SwitchPackageToDownload extends BaseCommand {

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this->setName('drupal-contrib:switch-package')
      ->setAliases(['dc:package'])
      ->addArgument('module', InputArgument::REQUIRED, 'Module name');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $composer = $this->requireComposer();
    $io = new SymfonyStyle($input, $output);

    $module_name = $input->getArgument('module');
    $package_name = "drupal/$module_name";

    $is_installed = \Composer\InstalledVersions::isInstalled($package_name);
    $composer_repository_defined = isset($composer->getPackage($package_name)->getRepositories()[$module_name]);

    if (!$is_installed) {
      $io->error("The $package_name package is not installed.");
      return 1;
    }

    if (!$composer_repository_defined) {
      $io->error("There is no Composer repository defined for the $module_name module.");
      return 1;
    }

    // Remove the repository from composer.json.
    $repositories_command = "composer config --unset repositories.$module_name";
    exec($repositories_command);

    // Switch the project to use the Composer repository version.
    exec("composer update {$package_name}");

    return 0;
  }

}
