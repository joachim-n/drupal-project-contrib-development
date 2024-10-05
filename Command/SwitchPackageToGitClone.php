<?php

namespace DrupalContribDevelopment\Command;

use Composer\Command\BaseCommand;
use DrupalContribDevelopment\Enum\GitCloneHead;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// Development: this makes symfony var-dumper work.
// See https://github.com/composer/composer/issues/7911
// require_once './vendor/autoload.php';

/**
 * Switches a project to use a git clone of a Drupal contrib module.
 *
 * This does the following:
 *  - Clones the module in the repos/ folder, unless a git clone is there
 *    already.
 *  - Writes a composer.json file to the git clone if the module does not have
 *    one.
 *  - Adds a Composer path repository to the project composer.json.
 *  - Does a `composer update` of the module package so that Composer changes
 *    the package to be installed as a symlink from the git repository.
 *
 * Once this command has been run, you can make any changes you like to the git
 * repository, such as adding an issue fork remote, changing branches, and so
 * on.
 *
 * If you need to perform any Composer operations, you may need to temporarily
 * switch the git repository to the main development branch or the release tag
 * where it was checked out to begin with, in order to satisfy Composer's
 * package version requirements.
 */
class SwitchPackageToGitClone extends BaseCommand {

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this->setName('drupal-contrib:switch-clone')
      ->setAliases(['dc:clone'])
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
    $module_repo_path = 'repos/' . $module_name;
    $original_dir = getcwd();

    // Create the repos folder if necessary.
    if (!file_exists('repos')) {
      mkdir('repos');
    }

    // Determine if the package is installed, whether there is already a
    // git repository for it, and whether Composer has a path repository already
    // defined.
    $is_installed = \Composer\InstalledVersions::isInstalled($package_name);
    $git_repository_exists = file_exists($module_repo_path);
    $composer_repository_defined = isset($composer->getPackage($package_name)->getRepositories()[$module_name]);

    if ($is_installed) {
      $io->text("Switching $module_name to a git clone...");

      // Get the version of the module that the project currently has
      // installed so we can clone the same version. This ensures that
      // Composer will accept to install it.
      $installed_tag = \Composer\InstalledVersions::getReference($package_name);
      $installed_version = \Composer\InstalledVersions::getPrettyVersion($package_name);

      if (preg_match('/(^dev-|-dev$)/', $installed_version)) {
        // If Composer has the module installed from a branch, check out the
        // SHA that's installed, so that we are installing the same version of
        // the module.
        $git_clone_head = GitCloneHead::Sha;
      }
      else {
        $git_clone_head = GitCloneHead::SpecificBranch;
      }
    }
    else {
      $git_clone_head = GitCloneHead::DefaultBranch;

      $io->text("Installing $module_name from a git clone...");
    }

    if ($git_repository_exists) {
      $io->note("Git clone for $module_name already exists in the 'repos' directory.");
    }
    else {
      if ($is_installed) {
        // If the project is already installed in the project with Composer,
        // then clone it to the same version that is currently installed with
        // Composer.
        if ($git_clone_head == GitCloneHead::SpecificBranch) {
          $git_command = "git clone -b {$installed_tag} https://git.drupalcode.org/project/{$module_name}.git";

          $success_message = "Cloned $module_name into the 'repos' directory at $installed_version.";
        }
        else {
          // We can't specific a SHA in the clone command, so we'll switch to
          // that afterwards.
          $git_command = "git clone https://git.drupalcode.org/project/{$module_name}.git";

          $success_message = "Cloned $module_name into the 'repos' directory at $installed_tag.";
        }
      }
      else {
        $git_command = "git clone https://git.drupalcode.org/project/{$module_name}.git";
        $success_message = "Cloned $module_name into the 'repos' directory at the default branch.";

        // TODO: default branch might not be compatible with core!
      }

      // Run the git clone command, capturing STDERR so we can check there were
      // no errors.
      $desc = [
        0 => array('pipe', 'r'), // 0 is STDIN for process
        1 => array('pipe', 'w'), // 1 is STDOUT for process
        2 => array('pipe', 'w'), // 2 is STDERR for process
      ];
      $pipes = [];

      chdir('repos');
      $process = proc_open($git_command, $desc, $pipes);
      chdir($original_dir);

      $out = stream_get_contents($pipes[1]);
      $errors = stream_get_contents($pipes[2]);

      // Clean up.
      fclose($pipes[1]);
      fclose($pipes[2]);
      proc_close($process);

      if (str_contains($errors, 'fatal')) {
        $io->error("Problem cloning from https://git.drupalcode.org/project/{$module_name}.git. Git error output follows:");
        $io->block($errors);

        return 1;
      }

      // Switch to a specific SHA if necessary.
      if ($git_clone_head == GitCloneHead::Sha) {
        chdir($module_repo_path);
        exec("git checkout $installed_tag");

        chdir($original_dir);
      }

      $output->writeln($success_message);

      // Add a git tag at the installed version.
      if ($is_installed) {
        chdir($module_repo_path);
        exec('git tag -f "PROJECT-VERSION"');

        chdir($original_dir);
        $output->writeln("Created a git tag 'PROJECT-VERSION' to mark the version which was installed as a package. You should use this as a branching point for local feature branches to make patches to apply to the project.");
      }
    }

    // Most Drupal modules don't have a composer.json in the git repository,
    // relying instead on drupal.org's packaging system to be installable with
    // Composer. Therefore we need to temporarily write a composer.json file.
    // (The alternative of hooking into Composer to make it see a package at
    // location is theoratically possible, but the details are so gory that SO
    // deleted my question about it:
    // https://stackoverflow.com/questions/77606825/how-to-fake-a-composer-path-repository-for-a-folder-with-no-composer-json?noredirect=1#comment136839320_77606825.)
    if (!file_exists("$module_repo_path/composer.json")) {
      $composer_json = json_encode([
        'name' => $package_name,
        'type' => 'drupal-module',
      ]);
      file_put_contents("$module_repo_path/composer.json", $composer_json);

      $io->note("The $module_name repository does not contain a composer.json file, so writing a temporary one. Do not commit this file to the repository.");
    }

    if (!$composer_repository_defined) {
      // Add a Composer path repository that points to the git clone.
      // Use the JSON version of the `composer config` command so that we can
      // pass the version options if necessary.
      $repositories_command_json_data = [
        'type' => 'path',
        'url' => $module_repo_path,
      ];
      if ($is_installed) {
        // Add the version options.
        $repositories_command_json_data['options']['versions'][$package_name] = $installed_version;
      }
      $repositories_command = "composer config repositories.$module_name '" . json_encode($repositories_command_json_data) . "'";
      exec($repositories_command);

      $output->writeln("Adding a path repository for $module_name to the project composer.json file. You should *not* commit this change.");
    }

    if ($is_installed) {
      $output->writeln("Updating Composer to switch {$package_name} to the git clone path repository.");

      // Switch the project to use the git clone.
      exec("composer update {$package_name}");
    }
    else {
      $output->writeln("Installing {$package_name} from the git clone path repository.");

      exec("composer require {$package_name}:@dev");
    }

    // TODO! Check Composer didn't output any errors!

    $io->success("The project's composer.json is now using $module_name from the git clone. You can now go to the git clone and switch to a feature branch.");

    return 0;
  }

}
