<?php

/**
 * @file
 * Contains \Drupal\simple_sitemap\Form\SimplesitemapEntitiesForm.
 */

namespace Drupal\simple_sitemap\Form;

use Drupal\simple_sitemap\Form;
use Drupal\simple_sitemap\Simplesitemap;

/**
 * SimplesitemapSettingsFrom
 */
class SimplesitemapEntitiesForm {

  /**
   * {@inheritdoc}
   */
  public function getFormID() {
    return 'simple_sitemap_entities_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm($form, $form_state) {

    $generator = new Simplesitemap();

    $form['simple_sitemap_entities']['entities'] = [
      '#title' => t('Sitemap entities'),
      '#type' => 'fieldset',
      '#markup' => '<p>' . t("Simple XML sitemap settings will be added only to entity forms of entity types enabled here. For all entity types featuring bundles (e.g. <em>node</em>) sitemap settings have to be set on their bundle pages (e.g. <em>page</em>).") . '</p>',
    ];

    //@todo;
//    $form['#attached']['library'][] = 'simple_sitemap/sitemapEntities';
//    $form['#attached']['drupalSettings']['simple_sitemap'] = ['all_entities' => [], 'atomic_entities' => []];

    $entity_type_labels = [];
    foreach (Simplesitemap::getSitemapEntityTypes() as $entity_type_id => $entity_type) {
      $entity_type_labels[$entity_type_id] = $entity_type['label'] ?: $entity_type_id;
    }
    asort($entity_type_labels);

    $f = new Form();

    foreach ($entity_type_labels as $entity_type_id => $entity_type_label) {
      $form['simple_sitemap_entities']['entities'][$entity_type_id] = [
        '#type' => 'fieldset',
        '#title' => $entity_type_label,
        '#collapsed' => !$generator->entityTypeIsEnabled($entity_type_id),
        '#collapsible' => TRUE,
      ];
      $form['simple_sitemap_entities']['entities'][$entity_type_id][$entity_type_id . '_enabled'] = [
        '#type' => 'checkbox',
        '#title' => t('Enable @entity_type_label <em>(@entity_type_id)</em> support',
          ['@entity_type_label' => strtolower($entity_type_label), '@entity_type_id' => $entity_type_id]),
        '#description' => t('Sitemap settings for this entity type can be set on its bundle pages and overridden on its entity pages.'),
        '#default_value' => $generator->entityTypeIsEnabled($entity_type_id),
      ];
      $form['#attached']['drupalSettings']['simple_sitemap']['all_entities'][] = str_replace('_', '-', $entity_type_id);
      if (Simplesitemap::entityTypeIsAtomic($entity_type_id)) {
        $form['simple_sitemap_entities']['entities'][$entity_type_id][$entity_type_id . '_enabled']['#description'] = t('Sitemap settings for this entity type can be set below and overridden on its entity pages.');
        $f->setEntityCategory('bundle');
        $f->setEntityTypeId($entity_type_id);
        $f->setBundleName($entity_type_id);
        $f->displayEntitySettings($form['simple_sitemap_entities']['entities'][$entity_type_id][$entity_type_id . '_settings'],
          TRUE);
        $form['#attached']['drupalSettings']['simple_sitemap']['atomic_entities'][] = str_replace('_', '-',
          $entity_type_id);
      }
    }
    $f->displayRegenerateNow($form['simple_sitemap_entities']['entities']);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(&$form, &$form_state) {
    $generator = new Simplesitemap();
    $values = $form_state['values'];
    foreach ($values as $field_name => $value) {
      if (substr($field_name, -strlen('_enabled')) == '_enabled') {
        $entity_type_id = substr($field_name, 0, -8);
        if ($value) {
          $generator->enableEntityType($entity_type_id);
          if (Simplesitemap::entityTypeIsAtomic($entity_type_id)) {
            $generator->setBundleSettings($entity_type_id, $entity_type_id, [
              'index' => TRUE,
              'priority' => $values[$entity_type_id . '_simple_sitemap_priority'],
            ]);
          }
        }
        else {
          $generator->disableEntityType($entity_type_id);
        }
      }
    }

    // Regenerate sitemaps according to user setting.
    if ($values['simple_sitemap_regenerate_now']) {
      $generator->generateSitemap();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['simple_sitemap.settings'];
  }

}
