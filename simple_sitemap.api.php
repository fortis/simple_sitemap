<?php
/**
 * @file
 * Hooks provided by the Simple XML sitemap module.
 */


/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alter the generated link data before the sitemap is saved.
 * This hook gets invoked for every sitemap chunk generated.
 *
 * @param array &$links
 *  Array containing multilingual links generated for each path to be indexed.
 */
function hook_simple_sitemap_links_alter(&$links) {
  // Remove German links for all paths in the hreflang sitemap.
  foreach ($links as &$link) {
    unset($link['urls']['de']);
  }
}

/**
 * @} End of "addtogroup hooks".
 */

