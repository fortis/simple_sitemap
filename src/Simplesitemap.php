<?php
/**
 * @file
 * Contains \Drupal\simple_sitemap\Simplesitemap.
 */

namespace Drupal\simple_sitemap;

/**
 * Simplesitemap class.
 *
 * Main module class.
 */
class Simplesitemap {

  private static $allowed_link_settings = [
    'entity' => ['index', 'priority'],
    'custom' => ['priority'],
  ];
  private $config_factory;
  private $config;

  /**
   * Simplesitemap constructor.
   */
  public function __construct($config = []) {
    $config = empty($config) ? variable_get('simple_sitemap') : $config;
    $this->config_factory = $config;
    $this->config = $config['settings'];
  }

  public static function getBundleEntityTypeId($entity) {
    return $entity->getEntityTypeId() == 'menu'
      ? 'menu_link_content' : $entity->getEntityType()->getBundleOf(); // Menu fix.
  }

  public static function entityTypeIsAtomic($entity_type_id) {
//    if ($entity_type_id == 'menu_link_content') // Menu fix.
//      return FALSE;
    $sitemap_entity_types = self::getSitemapEntityTypes();
    if (isset($sitemap_entity_types[$entity_type_id])) {
      $entity_type = $sitemap_entity_types[$entity_type_id];
      if (empty($entity_type['bundleable'])) {
        return TRUE;
      }
      return FALSE;
    }
    return FALSE; //todo: throw exception
  }

  /**
   * Returns objects of entity types that can be indexed by the sitemap.
   *
   * @return array
   *  Objects of entity types that can be indexed by the sitemap.
   */
  public static function getSitemapEntityTypes() {
    $entity_types = entity_get_info();
    $exclude_entities = variable_get('simple_sitemap_exclude_entities', [
      'taxonomy_vocabulary' => [],
      'file' => [],
      'better_watchdog_ui_watchdog' => [],
    ]);
    $entity_types = array_diff_key($entity_types, $exclude_entities);
    return $entity_types;
  }

  /**
   * Enables sitemap support for an entity type. Enabled entity types show
   * sitemap settings on their bundles. If an enabled entity type does not
   * featured bundles (e.g. 'user'), it needs to be set up with
   * setBundleSettings() as well.
   *
   * @param string $entity_type_id
   *  Entity type id like 'node'.
   *
   * @return bool
   *  TRUE if entity type has been enabled, FALSE if it was not.
   */
  public function enableEntityType($entity_type_id) {
    $entity_types = $this->getConfig('entity_types');
    if (empty($entity_types[$entity_type_id])) {
      $entity_types[$entity_type_id] = [];
      $this->saveConfig('entity_types', $entity_types);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Gets a specific sitemap configuration from the configuration storage.
   *
   * @param string $key
   *  Configuration key, like 'entity_types'.
   * @return mixed
   *  The requested configuration.
   */
  public function getConfig($key) {
    return $this->config_factory[$key];
  }

  /**
   * Saves a specific sitemap configuration to db.
   *
   * @param string $key
   *  Configuration key, like 'entity_types'.
   * @param mixed $value
   *  The configuration to be saved.
   */
  public function saveConfig($key, $value) {
    $this->config_factory[$key] = $value;
    $this->config = $this->config_factory['settings'];
    variable_set('simple_sitemap', $this->config_factory);
  }

  /**
   * Disables sitemap support for an entity type. Disabling support for an
   * entity type deletes its sitemap settings permanently and removes sitemap
   * settings from entity forms.
   *
   * @param string $entity_type_id
   *  Entity type id like 'node'.
   *
   * @return bool
   *  TRUE if entity type has been disabled, FALSE if it was not.
   */
  public function disableEntityType($entity_type_id) {
    $entity_types = $this->getConfig('entity_types');
    if (isset($entity_types[$entity_type_id])) {
      unset($entity_types[$entity_type_id]);
      $this->saveConfig('entity_types', $entity_types);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Sets sitemap settings for a bundle less entity type (e.g. user) or a bundle
   * of an entity type (e.g. page).
   *
   * @param string $entity_type_id
   *  Entity type id like 'node' the bundle belongs to.
   * @param string $bundle_name
   *  Name of the bundle. NULL if entity type has no bundles.
   * @param array $settings
   *  An array of sitemap settings for this bundle/entity type.
   *  Example: ['index' => TRUE, 'priority' => 0.5]
   */
  public function setBundleSettings($entity_type_id, $bundle_name = NULL, $settings) {
    $bundle_name = !empty($bundle_name) ? $bundle_name : $entity_type_id;
    $entity_types = $this->getConfig('entity_types');
    $this->addLinkSettings('entity', $settings, $entity_types[$entity_type_id][$bundle_name]);
    $this->saveConfig('entity_types', $entity_types);
  }

  private function addLinkSettings($type, $settings, &$target) {
    foreach ($settings as $setting_key => $setting) {
      if (in_array($setting_key, self::$allowed_link_settings[$type])) {
        switch ($setting_key) {
          case 'priority':
            if (!Form::isValidPriority($setting)) {
              // todo: register error
              continue;
            }
            break;
          //todo: add index check
        }
        $target[$setting_key] = $setting;
      }

    }
  }

  public function setEntityInstanceSettings($entity_type_id, $id, $settings) {
    $entity_types = $this->getConfig('entity_types');
    $entity = \Drupal::entityTypeManager()->getStorage($entity_type_id)->load($id);
    $bundle_name = self::getEntityInstanceBundleName($entity_type_id, $entity);
    if (isset($entity_types[$entity_type_id][$bundle_name])) {

      // Check if overrides are different from bundle setting before saving.
      $override = FALSE;
      foreach ($settings as $key => $setting) {
        if ($setting != $entity_types[$entity_type_id][$bundle_name][$key]) {
          $override = TRUE;
          break;
        }
      }
      if ($override) { //Save overrides for this entity if something is different.
        $this->addLinkSettings('entity', $settings, $entity_types[$entity_type_id][$bundle_name]['entities'][$id]);
      }
      else { // Else unset override.
        unset($entity_types[$entity_type_id][$bundle_name]['entities'][$id]);
      }
      $this->saveConfig('entity_types', $entity_types);
      return TRUE;
    }
    return FALSE;
  }

  public static function getEntityInstanceBundleName($entity_type, $entity) {
    list(, , $bundle) = entity_extract_ids($entity_type, $entity);
    return $bundle;
  }

  public function bundleIsIndexed($entity_type_id, $bundle_name) {
    $settings = $this->getBundleSettings($entity_type_id, $bundle_name);
    return !empty($settings['index']);
  }

  public function getBundleSettings($entity_type_id, $bundle_name) {
    $entity_types = $this->getConfig('entity_types');
    if (isset($entity_types[$entity_type_id][$bundle_name])) {
      $settings = $entity_types[$entity_type_id][$bundle_name];
      unset($settings['entities']);
      return $settings;
    }
    return FALSE;
  }

  public function entityTypeIsEnabled($entity_type_id) {
    $entity_types = $this->getConfig('entity_types');
    return isset($entity_types[$entity_type_id]);
  }

  public function getEntityInstanceSettings($entity_type_id, $id) {
    $entity_types = $this->getConfig('entity_types');
    $entity = \Drupal::entityTypeManager()->getStorage($entity_type_id)->load($id);
    $bundle_name = self::getEntityInstanceBundleName($entity_type_id, $entity);
    if (isset($entity_types[$entity_type_id][$bundle_name]['entities'][$id])) {
      return $entity_types[$entity_type_id][$bundle_name]['entities'][$id];
    }
    else {
      return $this->getBundleSettings($entity_type_id, $bundle_name);
    }
  }

  public function addCustomLink($path, $settings) {
    if (!simple_sitemap_is_valid_url($path)) {
      return FALSE; // todo: log error
    }

    if ($path[0] != '/') {
      return FALSE; // todo: log error
    }

    $custom_links = $this->getConfig('custom');
    foreach ($custom_links as $key => $link) {
      if ($link['path'] == $path) {
        $link_key = $key;
        break;
      }
    }
    $link_key = isset($link_key) ? $link_key : count($custom_links);
    $custom_links[$link_key]['path'] = $path;
    $this->addLinkSettings('entity', $settings, $custom_links[$link_key]);
    $this->saveConfig('custom', $custom_links);
    return TRUE;
  }

  public function getCustomLink($path) {
    $custom_links = $this->getConfig('custom');
    foreach ($custom_links as $key => $link) {
      if ($link['path'] == $path) {
        return $custom_links[$key];
      }
    }
    return FALSE;
  }

  public function removeCustomLink($path) {
    $custom_links = $this->getConfig('custom');
    foreach ($custom_links as $key => $link) {
      if ($link['path'] == $path) {
        unset($custom_links[$key]);
        $custom_links = array_values($custom_links);
        $this->saveConfig('custom', $custom_links);
        return TRUE;
      }
    }
    return FALSE;
  }

  public function removeCustomLinks() {
    $this->saveConfig('custom', []);
  }

  /**
   * Returns the whole sitemap, a requested sitemap chunk,
   * or the sitemap index file.
   *
   * @param int $chunk_id
   *
   * @return string $sitemap
   *  If no sitemap id provided, either a sitemap index is returned, or the
   *  whole sitemap, if the amount of links does not exceed the max links setting.
   *  If a sitemap id is provided, a sitemap chunk is returned.
   */
  public function getSitemap($chunk_id = NULL) {
    $chunks = $this->fetchSitemapChunks();
    if (is_null($chunk_id) || !isset($chunks[$chunk_id])) {

      // Return sitemap index, if there are multiple sitemap chunks.
      if (count($chunks) > 1) {
        return $this->getSitemapIndex($chunks);
      }
      else { // Return sitemap if there is only one chunk.
        if (isset($chunks[1])) {
          return $chunks[1]->sitemap_string;
        }
        return FALSE;
      }
    }
    else { // Return specific sitemap chunk.
      return $chunks[$chunk_id]->sitemap_string;
    }
  }

  private function fetchSitemapChunks() {
    return db_query("SELECT * FROM {simple_sitemap}")->fetchAllAssoc('id');
  }

  /**
   * Generates and returns the sitemap index as string.
   *
   * @param array $chunks
   *  Sitemap chunks which to generate the index from.
   *
   * @return string
   *  The sitemap index.
   */
  private function getSitemapIndex($chunks) {
    $generator = new SitemapGenerator($this);
    return $generator->generateSitemapIndex($chunks);
  }

  /**
   * Generates the sitemap for all languages and saves it to the db.
   *
   * @param string $from
   *  Can be 'form', 'cron', 'drush' or 'nobatch'.
   *  This decides how the batch process is to be run.
   */
  public function generateSitemap($from = 'form') {
    $generator = new SitemapGenerator($this);
    $generator->setGenerateFrom($from);
    $generator->startGeneration();
  }

  /**
   * Gets a specific sitemap setting.
   *
   * @param string $name
   *  Name of the setting, like 'max_links'.
   *
   * @return mixed
   *  The current setting from db or FALSE if setting does not exist.
   */
  public function getSetting($name) {
    $settings = $this->getConfig('settings');
    return isset($settings[$name]) ? $settings[$name] : FALSE;
  }

  /**
   * Saves a specific sitemap setting to db.
   *
   * @param $name
   *  Setting name, like 'max_links'.
   * @param $setting
   *  The setting to be saved.
   */
  public function saveSetting($name, $setting) {
    $settings = $this->getConfig('settings');
    $settings[$name] = $setting;
    $this->saveConfig('settings', $settings);
  }

  /**
   * Returns a 'time ago' string of last timestamp generation.
   *
   * @return mixed
   *  Formatted timestamp of last sitemap generation, otherwise FALSE.
   */
  public function getGeneratedAgo() {
    $chunks = $this->fetchSitemapChunks();
    if (isset($chunks[1]->sitemap_created)) {
      return format_interval(REQUEST_TIME - $chunks[1]->sitemap_created);
    }
    return FALSE;
  }
}
