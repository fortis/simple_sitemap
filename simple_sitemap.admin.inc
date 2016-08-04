<?php

use Drupal\simple_sitemap\Form\SimplesitemapCustomLinksForm;
use Drupal\simple_sitemap\Form\SimplesitemapEntitiesForm;
use Drupal\simple_sitemap\Form\SimplesitemapSettingsForm;

function simple_sitemap_settings($form, &$form_state) {
  $form['simple_sitemap_settings']['regenerate'] = [
    '#title' => t('Regenerate sitemap'),
    '#type' => 'fieldset',
    '#markup' => '<p>' . t('This will regenerate the XML sitemap for all languages.') . '</p>',
  ];

  $form['simple_sitemap_settings']['regenerate']['regenerate_submit'] = [
    '#type' => 'submit',
    '#value' => t('Regenerate sitemap'),
    '#submit' => ['simple_sitemap_settings_regenerate_submit'],
    '#validate' => [], // Skip form-level validator.
  ];

  $form_state['formBase'] = new SimplesitemapSettingsForm();
  $form = $form_state['formBase']->buildForm($form, $form_state);
  $form['#validate'][] = 'simple_sitemap_settings_validate';
  $form['#submit'][] = 'simple_sitemap_settings_submit';
  return system_settings_form($form);
}

function simple_sitemap_settings_entities($form, &$form_state) {
  $form_state['formBase'] = new SimplesitemapEntitiesForm();
  $form = $form_state['formBase']->buildForm($form, $form_state);
  $form['#submit'][] = 'simple_sitemap_settings_submit';
  return system_settings_form($form);
}

function simple_sitemap_settings_custom($form, &$form_state) {
  $form_state['formBase'] = new SimplesitemapCustomLinksForm();
  $form = $form_state['formBase']->buildForm($form, $form_state);
  $form['#validate'][] = 'simple_sitemap_settings_validate';
  $form['#submit'][] = 'simple_sitemap_settings_submit';
  return system_settings_form($form);
}

function simple_sitemap_settings_validate($form, &$form_state) {
  $form_state['formBase']->validateForm($form, $form_state);
}

function simple_sitemap_settings_submit($form, &$form_state) {
  $form_state['formBase']->submitForm($form, $form_state);
}

function simple_sitemap_settings_regenerate_submit($form, &$form_state) {
  $form_state['formBase']->generateSitemap($form, $form_state);
}
