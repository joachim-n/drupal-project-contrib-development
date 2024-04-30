<?php

namespace DrupalContribDevelopment\Command;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// ARGH can't do this because of https://github.com/symfony/symfony/issues/39368.
// require_once './vendor/autoload.php';
// include_once './vendor/symfony/var-dumper/Resources/functions/dump.php';

class ApplyGitCloneChangesAsPatch extends BaseCommand {

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this->setName('drupal-contrib:apply-patch-from-branch')
      ->setAliases(['dc:patch'])
      ->addArgument('module', InputArgument::REQUIRED, 'Module name');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    // Development: this makes symfony var-dumper work.
    // See https://github.com/composer/composer/issues/7911
    // But you have to put it in the method code, WTF?
    // $autoload = require_once './vendor/autoload.php';
    // require_once './vendor/symfony/var-dumper/Resources/functions/dump.php';

    $composer = $this->requireComposer();
    $io = new SymfonyStyle($input, $output);

    $module_name = $input->getArgument('module');
    $package_name = "drupal/$module_name";
    $module_repo_path = 'repos/' . $module_name;
    $original_dir = getcwd();

    if (!\Composer\InstalledVersions::isInstalled('cweagans/composer-patches')) {
      $io->error("The cweagans/composer-patches package must be installed to apply a patch.");
      return 1;
    }

    // Determine if the package is installed, whether there is already a
    // git repository for it, and whether Composer has a path repository already
    // defined.
    $is_installed = \Composer\InstalledVersions::isInstalled($package_name);
    $git_repository_exists = file_exists($module_repo_path);
    $composer_repository_defined = isset($composer->getPackage($package_name)->getRepositories()[$module_name]);

    if (!$is_installed) {
      $io->error("The $package_name package is not installed.");
      return 1;
    }

    if (!$git_repository_exists) {
      $io->error("There is no git repository in the /repos folder for the $module_name module.");
      return 1;
    }

    if ($composer_repository_defined) {
      $io->error("The $package_name package is currently installed from git clone. Use the 'drupal-contrib:switch-package' command to switch it to a package before applying a patch.");
      return 1;
    }

    // Get the version of the module that is installed, so we can diff from the
    // feature branch to that, and so get a patch that will apply.
    // A patch from a drupal.org merge request might not apply to the currently
    // installed version, as it will be against the development branch.
    $installed_tag = \Composer\InstalledVersions::getReference($package_name);

    $git_command = "git diff $installed_tag";

    // Run the git diff command, capturing the output.
    $desc = [
      0 => array('pipe', 'r'), // 0 is STDIN for process
      1 => array('pipe', 'w'), // 1 is STDOUT for process
      2 => array('pipe', 'w'), // 2 is STDERR for process
    ];
    $pipes = [];

    chdir($module_repo_path);
    $process = proc_open($git_command, $desc, $pipes);
    chdir($original_dir);

    $git_diff_result = stream_get_contents($pipes[1]);

    // Clean up.
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    if (!str_starts_with($git_diff_result, 'diff')) {
      $io->error("The 'git diff' command did not return a patch.");
      return 1;
    }

    // Get the feature branch for the module git repo.
    chdir($module_repo_path);
    $git_current_branch = exec('git rev-parse --abbrev-ref HEAD');
    chdir($original_dir);

    // Determine the issue number.
    $matches = [];
    preg_match('/^(\d+)/', $git_current_branch, $matches);

    if (empty($matches[1])) {
      $io->error("Unable to determine an issue number from the current git branch in the repository at $module_repo_path. The branch name must begin with a number.");
      return 1;
    }

    $issue_number = $matches[1];

    // Get the issue node title from drupal.org.
    $response = file_get_contents("https://www.drupal.org/api-d7/node/{$issue_number}.json");
    if ($response === FALSE) {
      $io->warning("Failed getting node {$issue_number} from drupal.org.");
      $patch_description = 'todo add description';
    }
    else {
      $issue_node_data = json_decode($response);
      $patch_description = $issue_node_data->title;
    }

    $patch_name = "{$module_name}.{$issue_number}.patch";

    // Create the patches folder if necessary.
    if (!file_exists('patches')) {
      mkdir('patches');
    }

    file_put_contents("patches/{$patch_name}", $git_diff_result);

    $io->text("Writing patch file to patches/{$patch_name} containing diff from the current branch to $installed_tag. You should commit this file to version control.");

    // The key to use in the composer.json patches array.
    $new_patch_key = "{$issue_number} - {$patch_description}";

    // Determine if a patch for this issue is already applied.
    $extra = $composer->getPackage()->getExtra();
    $applied_patches = $extra['patches'][$package_name] ?? [];
    foreach (array_keys($applied_patches) as $patch_key) {
      // TODO: Brittle, use preg_match().
      if (str_contains($patch_key, $issue_number)) {
        $new_patch_key = $patch_key;
      }
    }

    // Need to merge as JSON because the `config` command only supports nesting
    // up to 3 levels.
    // The issue number key can't be just the issue number as that goes wrong;
    // see https://github.com/composer/composer/issues/11945.
    // composer config extra.patches.drupal/typogrify --merge --json '{"3398815 - todo":"patches/typogrify.3398815.patch"}'
    $declare_patch_command = "composer config extra.patches.{$package_name} --merge --json '{\"{$new_patch_key}\":\"patches/{$patch_name}\"}'";
    exec($declare_patch_command);

    $io->text("Adding patch file patches/{$patch_name} to the project composer.json. You should commit this change to version control.");

    $io->text("Updating Composer to apply the patch.");

    exec("composer update --lock");

    $io->text("The patch has been applied.");

    return 0;
  }

}
