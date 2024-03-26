<?php

namespace DrupalContribDevelopment\Enum;

/**
 * Describes the HEAD at which a package's git repository should be cloned.
 */
Enum GitCloneHead {

  /**
   * Clone without specifying a HEAD, which gets the default branch.
   */
  case DefaultBranch;

  /**
   * Clone to a specific branch or tag.
   */
  case SpecificBranch;

  /**
   * Clone to a specific commit.
   */
  case Sha;

}
