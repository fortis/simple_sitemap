<?php
/**
 * @file
 * Contains \Drupal\simple_sitemap\Batch.
 *
 * Helper functions for the Drupal batch API.
 * @see https://api.drupal.org/api/drupal/core!includes!form.inc/group/batch/8
 */

namespace Drupal\simple_sitemap;

class Batch {
  const PATH_DOES_NOT_EXIST = "The path @faulty_path has been omitted from the XML sitemap, as it does not exist.";
  const PATH_DOES_NOT_EXIST_OR_NO_ACCESS = "The path @faulty_path has been omitted from the XML sitemap as it either does not exist, or it is not accessible to anonymous users.";
  const BATCH_INIT_MESSAGE = 'Initializing batch...';
  const BATCH_ERROR_MESSAGE = 'An error has occurred. This may result in an incomplete XML sitemap.';
  const BATCH_PROGRESS_MESSAGE = 'Processing @current out of @total link types.';
  const ANONYMOUS_USER_ID = 0;
  private $batch;
  private $batchInfo;

  function __construct() {
    $this->batch = [
      'title' => t('Generating XML sitemap'),
      'init_message' => t(self::BATCH_INIT_MESSAGE),
      'error_message' => t(self::BATCH_ERROR_MESSAGE),
      'progress_message' => t(self::BATCH_PROGRESS_MESSAGE),
      'operations' => [],
      'finished' => [__CLASS__, 'finishGeneration'],
      // __CLASS__ . '::finishGeneration' not working possibly due to a drush error.
    ];
  }

  /**
   * Batch callback function which generates urls to entity paths.
   *
   * @param array $entity_info
   * @param array $batch_info
   * @param array &$context
   *
   * @see https://api.drupal.org/api/drupal/core!includes!form.inc/group/batch/8
   */
  public static function generateBundleUrls($entity_info, $batch_info, &$context) {
    $query = new \EntityFieldQuery($entity_info['entity_type_name']);
    $query->entityCondition('entity_type', $entity_info['entity_type_name']);

    if (!empty($entity_info['keys']['id'])) {
      $query->propertyOrderBy($entity_info['keys']['id'], 'ASC');
    }

    if (!empty($entity_info['keys']['bundle'])) {
      $query->entityCondition('bundle', $entity_info['bundle_name']);
    }

    if (!empty($entity_info['keys']['status'])) {
      $query->propertyCondition($entity_info['keys']['status'], 1);
    }

    // Initialize batch if not done yet.
    if (self::needsInitialization($context)) {
      $count_query = clone $query;
      self::InitializeBatch($batch_info, $count_query->count()->execute(), $context);
    }

    // Creating a query limited to n=batch_process_limit entries.
    if (self::isBatch($batch_info)) {
      $query->range($context['sandbox']['progress'], $batch_info['batch_process_limit']);
    }

    $results = $query->execute();
    if (!empty($results)) {
      $languages = language_list('enabled')[1];
      $anon_user = user_load(self::ANONYMOUS_USER_ID);
      $entities = entity_load($entity_info['entity_type_name'], array_keys($results[$entity_info['entity_type_name']]));

      foreach ($entities as $entity_id => $entity) {
        if (self::isBatch($batch_info)) {
          self::SetCurrentId($entity_id, $context); //todo: move outside of this loop
        }

        // Overriding entity settings if it has been overridden on entity edit page...
        if (isset($batch_info['entity_types'][$entity_info['entity_type_name']][$entity_info['bundle_name']]['entities'][$entity_id]['index'])) {

          // Skipping entity if it has been excluded on entity edit page.
          if (!$batch_info['entity_types'][$entity_info['entity_type_name']][$entity_info['bundle_name']]['entities'][$entity_id]['index']) {
            continue;
          }
          // Otherwise overriding priority settings for this entity.
          $priority = $batch_info['entity_types'][$entity_info['entity_type_name']][$entity_info['bundle_name']]['entities'][$entity_id]['priority'];
        }

        //@todo: repair.
//        switch ($entity_info['entity_type_name']) {
//          case 'menu_link_content': // Loading url object for menu links.
//            if (!$entity->isEnabled())
//              continue;
//            $url_object = $entity->getUrlObject();
//            break;
//          default: // Loading url object for other entities.
//            $url_object = $entity_info['uri callback']();
//        }

        $uri_info = $entity_info['uri callback']($entity);

        // Do not include path if anonymous users do not have access to it.
        $url = url($uri_info['path'], ['absolute' => TRUE]);
        if (!simple_sitemap_check_url($url)) {
          continue;
        }

        // Do not include path if it already exists.
        $path = drupal_get_path_alias($uri_info['path']);
        if ($batch_info['remove_duplicates'] && self::pathProcessed($path, $context)) {
          continue;
        }

        $urls = [];
        foreach ($languages as $language) {
          $urls[$language->language] = url($path, [
            'language' => $language,
            'absolute' => TRUE,
          ]);
        }

        $context['results']['generate'][] = [
          'path' => $path,
          'urls' => $urls,
          'entity_info' => ['entity_type' => $entity_info['entity_type_name'], 'id' => $entity_id],
          'lastmod' => method_exists($entity, 'getChangedTime') ? date_iso8601($entity->getChangedTime()) : NULL,
          'priority' => isset($priority) ? $priority : (isset($entity_info['bundle_settings']['priority']) ? $entity_info['bundle_settings']['priority'] : NULL),
        ];
        $priority = NULL;
      }
    }

    if (self::isBatch($batch_info)) {
      self::setProgressInfo($context);
    }
    self::processSegment($context, $batch_info);
  }

  private static function needsInitialization($context) {
    return empty($context['sandbox']);
  }

  private static function InitializeBatch($batch_info, $max, &$context) {
    $context['results']['generate'] = !empty($context['results']['generate']) ? $context['results']['generate'] : [];
    if (self::isBatch($batch_info)) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['current_id'] = 0;
      $context['sandbox']['max'] = $max;
      $context['results']['processed_paths'] = !empty($context['results']['processed_paths']) ? $context['results']['processed_paths'] : [];
    }
  }

  private static function isBatch($batch_info) {
    return $batch_info['from'] != 'nobatch';
  }

  private static function SetCurrentId($id, &$context) {
    $context['sandbox']['progress']++;
    $context['sandbox']['current_id'] = $id;
    $context['results']['link_count'] = !isset($context['results']['link_count']) ? 1 : $context['results']['link_count'] + 1; //Not used ATM.
  }

  private static function pathProcessed($path, &$context) {
    $path_pool = isset($context['results']['processed_paths']) ? $context['results']['processed_paths'] : [];
    if (in_array($path, $path_pool)) {
      return TRUE;
    }
    $context['results']['processed_paths'][] = $path;
    return FALSE;
  }

  private static function setProgressInfo(&$context) {
    if ($context['sandbox']['progress'] != $context['sandbox']['max']) {
      // Providing progress info to the batch API.
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
      // Adding processing message after finishing every batch segment.
      end($context['results']['generate']);
      $last_key = key($context['results']['generate']);
      if (!empty($context['results']['generate'][$last_key]['path'])) {
        $context['message'] = t("Processing path @current out of @max: @path", [
          '@current' => $context['sandbox']['progress'],
          '@max' => $context['sandbox']['max'],
          '@path' => check_plain($context['results']['generate'][$last_key]['path']),
        ]);
      }
    }
  }

  private static function processSegment(&$context, $batch_info) {
    if (!empty($batch_info['max_links']) && count($context['results']['generate']) >= $batch_info['max_links']) {
      $chunks = array_chunk($context['results']['generate'], $batch_info['max_links']);
      foreach ($chunks as $i => $chunk_links) {
        if (count($chunk_links) == $batch_info['max_links']) {
          $remove_sitemap = empty($context['results']['chunk_count']);
          SitemapGenerator::generateSitemap($chunk_links, $remove_sitemap);
          $context['results']['chunk_count'] = !isset($context['results']['chunk_count']) ? 1 : $context['results']['chunk_count'] + 1;
          $context['results']['generate'] = array_slice($context['results']['generate'], count($chunk_links));
        }
      }
    }
  }

  /**
   * Batch function which generates urls to custom paths.
   *
   * @param array $custom_paths
   * @param array $batch_info
   * @param array &$context
   *
   * @see https://api.drupal.org/api/drupal/core!includes!form.inc/group/batch/8
   */
  public static function generateCustomUrls($custom_paths, $batch_info, &$context) {

    $languages = language_list('enabled')[1];
    $anon_user = user_load(self::ANONYMOUS_USER_ID);

    // Initialize batch if not done yet.
    if (self::needsInitialization($context)) {
      self::InitializeBatch($batch_info, count($custom_paths), $context);
    }

    foreach ($custom_paths as $i => $custom_path) {
      if (self::isBatch($batch_info)) {
        self::SetCurrentId($i, $context);
      }
      if (!simple_sitemap_is_valid_url($custom_path['path'],
        TRUE)
      ) { //todo: Change to different function, as this also checks if current user has access. The user however varies depending if process was started from the web interface or via cron/drush.
        self::registerError(self::PATH_DOES_NOT_EXIST_OR_NO_ACCESS, ['@faulty_path' => $custom_path['path']],
          'warning');
        continue;
      }
      $url_object = Url::fromUserInput($custom_path['path'], ['absolute' => TRUE]);

      if (!$url_object->access($anon_user)) {
        continue;
      }

      $path = $url_object->getInternalPath();
      if ($batch_info['remove_duplicates'] && self::pathProcessed($path, $context)) {
        continue;
      }

      $urls = [];
      foreach ($languages as $language) {
        $url_object->setOption('language', $language);
        $urls[$language->getId()] = $url_object->toString();
      }

      $context['results']['generate'][] = [
        'path' => $path,
        'urls' => $urls,
        'priority' => isset($custom_path['priority']) ? $custom_path['priority'] : NULL,
      ];
    }
    if (self::isBatch($batch_info)) {
      self::setProgressInfo($context);
    }
    self::processSegment($context, $batch_info);
  }

  /**
   * Logs and displays an error.
   *
   * @param $message
   *  Untranslated message.
   * @param array $substitutions (optional)
   *  Substitutions (placeholder => substitution) which will replace placeholders
   *  with strings.
   * @param string $type (optional)
   *  Message type (status/warning/error).
   */
  private static function registerError($message, $substitutions = [], $type = 'error') {
    $message = strtr(t($message), $substitutions);
    \Drupal::logger('simple_sitemap')->notice($message);
    drupal_set_message($message, $type);
  }

  public static function generateFrontpageUrl($data = [], $batch_info, &$context) {
    // Initialize batch if not done yet.
    if (self::needsInitialization($context)) {
      self::InitializeBatch($batch_info, 1, $context);
    }

    if (self::isBatch($batch_info)) {
      self::SetCurrentId(1, $context);
    }

    // Do not include path if anonymous users do not have access to it.
    $url = url('/', ['absolute' => TRUE]);
    if (!simple_sitemap_check_url($url)) {
      return;
    }

    // Do not include path if it already exists.
    $path = drupal_get_path_alias('/');
    if ($batch_info['remove_duplicates'] && self::pathProcessed($path, $context)) {
      return;
    }

    $urls = [];
    $languages = language_list('enabled')[1];
    foreach ($languages as $language) {
      $urls[$language->language] = url($path, [
        'language' => $language,
        'absolute' => TRUE,
      ]);
    }

    $context['results']['generate'][] = [
      'path' => $path,
      'urls' => $urls,
      'entity_info' => [],
      'lastmod' => NULL,
      'priority' => '1.0',
    ];

    if (self::isBatch($batch_info)) {
      self::setProgressInfo($context);
    }
    self::processSegment($context, $batch_info);
  }

  public function setBatchInfo($batch_info) {
    $this->batchInfo = $batch_info;
  }

  /**
   * Starts the batch process depending on where it was requested from.
   */
  public function start() {
    switch ($this->batchInfo['from']) {

      case 'form':
        batch_set($this->batch);
        break;

      case 'drush':
        batch_set($this->batch);
        $this->batch =& batch_get();
        $this->batch['progressive'] = FALSE;
        drush_log(t(self::BATCH_INIT_MESSAGE), 'status');
        drush_backend_batch_process();
        break;

      case 'backend':
        batch_set($this->batch);
        $this->batch =& batch_get();
        $this->batch['progressive'] = FALSE;
        batch_process(); //todo: Does not take advantage of batch API and eventually runs out of memory on very large sites.
        break;

      case 'nobatch':
        $context = [];
        foreach ($this->batch['operations'] as $i => $operation) {
          $operation[1][] = &$context;
          call_user_func_array($operation[0], $operation[1]);
        }
        self::finishGeneration(TRUE, $context['results'], []);
        break;
    }
  }

  /**
   * Callback function called by the batch API when all operations are finished.
   *
   * @see https://api.drupal.org/api/drupal/core!includes!form.inc/group/batch/8
   */
  public static function finishGeneration($success, $results, $operations) {
    if ($success) {
      $remove_sitemap = empty($results['chunk_count']);
      if (!empty($results['generate']) || $remove_sitemap) {
        SitemapGenerator::generateSitemap($results['generate'], $remove_sitemap);
      }
      //@todo;
//      Cache::invalidateTags(['simple_sitemap']);
      drupal_set_message(t("The <a href='@url' target='_blank'>XML sitemap</a> has been regenerated for all languages.",
        ['@url' => $GLOBALS['base_url'] . '/sitemap.xml']));
    }
    else {
      //todo: register error
    }
  }

  /**
   * Adds an operation to the batch.
   *
   * @param string $processing_method
   * @param array $data
   */
  public function addOperation($processing_method, $data = []) {
    $this->batch['operations'][] = [
      __CLASS__ . '::' . $processing_method,
      [$data, $this->batchInfo],
    ];
  }
}
