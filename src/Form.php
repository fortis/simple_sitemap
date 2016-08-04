<?php
/**
 * @file
 * Contains \Drupal\simple_sitemap\Form.
 */

namespace Drupal\simple_sitemap;

/**
 * Form class.
 */
class Form {

  const PRIORITY_DEFAULT = 0.5;
  const PRIORITY_HIGHEST = 10;
  const PRIORITY_DIVIDER = 10;
  private static $skipFormOperations = ['delete', 'cancel'];
  private static $valuesToCheck = [
    'simple_sitemap_index_content',
    'simple_sitemap_priority',
    'simple_sitemap_regenerate_now',
  ];
  public $alteringForm;
  public $entityCategory;
  public $entityTypeId;
  public $bundleName;
  public $instanceId;
  private $formState;
  private $generator;

  /**
   * Form constructor.
   */
  function __construct($form_state = NULL) {

    $this->formState = $form_state;
    $this->entityCategory = NULL;
    $this->alteringForm = TRUE;
    $this->generator = new Simplesitemap();

    // @todo
//    if (!is_null($this->formState)) {
//      $this->getEntityDataFromFormEntity();
//      $this->assertAlteringForm();
//    }
  }

  /**
   * Checks if simple_sitemap values have been changed after submitting the form.
   * To be used in an entity form submit.
   *
   * @return bool
   *  TRUE if simple_sitemap form values have been altered by the user.
   */
  public static function valuesChanged($form, $values) {
    foreach (self::$valuesToCheck as $field_name) {
      if (isset($values[$field_name]) && $values[$field_name] != $form['simple_sitemap'][$field_name]['#default_value']) {
        return TRUE;
      }
    }
    return FALSE;
  }

  public static function isValidPriority($priority) {
    return !is_numeric($priority) || $priority < 0 || $priority > 1 ? FALSE : TRUE;
  }

  public function setEntityCategory($entity_category) {
    $this->entityCategory = $entity_category;
  }

  public function setEntityTypeId($entity_type_id) {
    $this->entityTypeId = $entity_type_id;
  }

  public function setBundleName($bundle_name) {
    $this->bundleName = $bundle_name;
  }

  public function setInstanceId($instance_id) {
    $this->instanceId = $instance_id;
  }

  public function displayRegenerateNow(&$form_fragment) {
    $form_fragment['simple_sitemap_regenerate_now'] = [
      '#type' => 'checkbox',
      '#title' => t('Regenerate sitemap after hitting <em>Save</em>'),
      '#description' => t('This setting will regenerate the whole sitemap including the above changes.'),
      '#default_value' => FALSE,
    ];
    if ($this->generator->getSetting('cron_generate')) {
      $form_fragment['simple_sitemap_regenerate_now']['#description'] .= '</br>' . t('Otherwise the sitemap will be regenerated on the next cron run.');
    }
  }

  public function displayEntitySettings(&$form_fragment, $multiple = FALSE) {
    $prefix = $multiple ? $this->entityTypeId . '_' : '';

    if ($this->entityCategory == 'instance') {
      $bundle_settings = $this->generator->getBundleSettings($this->entityTypeId, $this->bundleName);
      $settings = !is_null($this->instanceId) ? $this->generator->getEntityInstanceSettings($this->entityTypeId,
        $this->instanceId) : $bundle_settings;
    }
    else {
      $settings = $this->generator->getBundleSettings($this->entityTypeId, $this->bundleName);
    }
    $index = isset($settings['index']) ? $settings['index'] : 0;
    $priority = isset($settings['priority']) ? $settings['priority'] : self::PRIORITY_DEFAULT;
    $bundle_name = !empty($this->bundleName) ? $this->bundleName : t('undefined');

    if (!$multiple) {
      $form_fragment[$prefix . 'simple_sitemap_index_content'] = [
        '#type' => 'radios',
        '#default_value' => $index,
        '#options' => [
          0 => $this->entityCategory == 'instance' ? t('Do not index this @bundle entity',
            ['@bundle' => $bundle_name]) : t('Do not index entities of this type'),
          1 => $this->entityCategory == 'instance' ? t('Index this @bundle entity',
            ['@bundle' => $bundle_name]) : t('Index entities of this type'),
        ],
      ];
      if ($this->entityCategory == 'instance' && isset($bundle_settings['index'])) {
        $form_fragment[$prefix . 'simple_sitemap_index_content']['#options'][$bundle_settings['index']] .= ' <em>(' . t('Default') . ')</em>';
      }
    }

    if ($this->entityCategory == 'instance') {
      $priority_description = t('The priority this @bundle entity will have in the eyes of search engine bots.',
        ['@bundle' => $bundle_name]);
    }
    else {
      $priority_description = t('The priority entities of this type will have in the eyes of search engine bots.');
    }
    $form_fragment[$prefix . 'simple_sitemap_priority'] = [
      '#type' => 'select',
      '#title' => t('Priority'),
      '#description' => $priority_description,
      '#default_value' => $priority,
      '#options' => self::getPrioritySelectValues(),
    ];
    if ($this->entityCategory == 'instance' && isset($bundle_settings['priority'])) {
      $form_fragment[$prefix . 'simple_sitemap_priority']['#options'][(string) $bundle_settings['priority']] .= ' (' . t('Default') . ')';
    }
  }

  /**
   * Gets the values needed to display the priority dropdown setting.
   *
   * @return array $options
   */
  public static function getPrioritySelectValues() {
    $options = [];
    foreach (range(0, self::PRIORITY_HIGHEST) as $value) {
      $value = self::formatPriority($value / self::PRIORITY_DIVIDER);
      $options[$value] = $value;
    }
    return $options;
  }

  public static function formatPriority($priority) {
    return number_format((float) $priority, 1, '.', '');
  }

  /**
   * Gets new entity Id after entity creation.
   * To be used in an entity form submit.
   *
   * @return int entity ID.
   */
  public function getFormEntityId() {
    return $this->formState->getFormObject()->getEntity()->id();
  }

  private function assertAlteringForm() {

    // Do not alter the form if user lacks certain permissions.
    if (!\Drupal::currentUser()->hasPermission('administer sitemap settings')) {
      $this->alteringForm = FALSE;
    }

    // Do not alter the form if it is irrelevant to sitemap generation.
    elseif (empty($this->entityCategory)) {
      $this->alteringForm = FALSE;
    }

    // Do not alter the form if entity is not enabled in sitemap settings.
    elseif (!$this->generator->entityTypeIsEnabled($this->entityTypeId)) {
      $this->alteringForm = FALSE;
    }

    // Do not alter the form, if sitemap is disabled for the entity type of this
    // entity instance.
    elseif ($this->entityCategory == 'instance'
      && !$this->generator->bundleIsIndexed($this->entityTypeId, $this->bundleName)
    ) {
      $this->alteringForm = FALSE;
    }
  }

  /**
   * Checks if this particular form is a bundle form, or a bundle instance form
   * and gathers sitemap settings from the database.
   *
   * @return bool
   *  TRUE if this is a bundle or bundle instance form, FALSE otherwise.
   */
  private function getEntityDataFromFormEntity() {
    $form_entity = $this->getFormEntity();
    if ($form_entity !== FALSE) {
      $entity_type_id = $form_entity->getEntityTypeId();
      $sitemap_entity_types = Simplesitemap::getSitemapEntityTypes();
      if (isset($sitemap_entity_types[$entity_type_id])) {
        $this->entityCategory = 'instance';
      }
      else {
        foreach ($sitemap_entity_types as $sitemap_entity) {
          if ($sitemap_entity->getBundleEntityType() == $entity_type_id) {
            $this->entityCategory = 'bundle';
            break;
          }
        }
      }

      // Menu fix.
      $this->entityCategory = is_null($this->entityCategory) && $entity_type_id == 'menu' ? 'bundle' : $this->entityCategory;

      switch ($this->entityCategory) {
        case 'bundle':
          $this->entityTypeId = Simplesitemap::getBundleEntityTypeId($form_entity);
          $this->bundleName = $form_entity->id();
          $this->instanceId = NULL;
          break;

        case 'instance':
          $this->entityTypeId = $entity_type_id;
          $this->bundleName = Simplesitemap::getEntityInstanceBundleName($entity_type_id, $form_entity);
          $this->instanceId = !empty($form_entity->id()) ? $form_entity->id() : NULL; // New menu link's id is '' instead of NULL, hence checking for empty.
          break;

        default:
          return FALSE;
      }
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Gets the object entity of the form if available.
   *
   * @return object $entity or FALSE if non-existent or if form operation is
   *  'delete'.
   */
  private function getFormEntity() {
    $form_object = $this->formState->getFormObject();
    if (!is_null($form_object)
      && method_exists($form_object, 'getEntity')
      && !in_array($form_object->getOperation(), self::$skipFormOperations)
    ) {
      return $form_object->getEntity();
    }
    return FALSE;
  }
}
