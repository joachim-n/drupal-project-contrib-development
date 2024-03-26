# Drupal Contrib Development

This is a set of tools for developing Drupal contrib modules and themes in the
context of a project.

## Features

### Swich to clone command

This is a Composer command which switches a Drupal module from being installed
normally to using a git clone that is symlinked into the project.

This allows testing and developing a contrib module in the context of a project.

For example, suppose your Widgets.Com website is using the drupal_cats module,
but you have found a bug in it. You can make changes to the drupal_cats module
files, but they are not under version control, so you cannot make a MR on
drupal.org from them. You could copy them to a separate git clone of
drupal_cats, but that quickly becomes tedious with multiple files. Furthermore,
your project's installed copy of drupal_cats is at a fixed release (hopefully!),
and the code you want to fix may have changed in the latest HEAD.

The Switch to clone command makes this all simple to do:

1. Your project has the drupal/drupal_cats package installed with Composer.
2. Do `composer drupal-contrib-switch-clone drupal_cats`. This does the
   following:
    1. The drupal_cats git repository is cloned into the ./repos folder in your
       project.
    2. A Composer path repository is added to composer.json, which points to
       this git clone.
    3. Composer is updated to use the drupal/drupal_cats from this repository.
       This creates a symlink from the ./repos/drupal_cats folder into the
       project's ./modules/contrib folder, replacing the previously installed
       version of the drupal_cats module.
3. You can now use the git clone of drupal_cats as normal:
    * Make a feature branch for your fix
    * Check out a fork branch from drupal.org to evaluate a fix
    * Test your code in the context of your project.
    * Push your changes to a drupal.org merge request.

When your fix is ready, remove the 'drupal_cats' path repository from your
composer.json, and do `composer update drupal/drupal_cats` to switch your
project back to using the distribution version of the drupal/drupal_cats
package.

If you need to perform any Composer operations in the meantime, you may need to
temporarily switch the git repository to the main development branch or the
release tag where it was checked out to begin with, in order to satisfy
Composer's package version requirements.

## Installation

Install with Composer:

```
composer require joachim-n/drupal-project-contrib-development
```

## Roadmap

* Add a command to switch the package back to distribution version.
* Add a command to create a local patch from a feature branch and add it to
  Composer.
* Add other useful things.
