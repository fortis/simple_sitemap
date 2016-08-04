<?php
/**
 * @file
 * Contains \Drupal\simple_sitemap\SitemapGenerator.
 *
 * Generates a sitemap for entities and custom links.
 */

namespace Drupal\simple_sitemap;

use XMLWriter;

/**
 * SitemapGenerator class.
 */
class SitemapGenerator {

  const XML_VERSION = '1.0';
  const ENCODING = 'UTF-8';
  const XMLNS = 'http://www.sitemaps.org/schemas/sitemap/0.9';
  const XMLNS_XHTML = 'http://www.w3.org/1999/xhtml';

  private $generator;
  private $links;
  private $generateFrom;

  function __construct($generator) {
    $this->generator = $generator;
    $this->links = [];
    $this->generateFrom = 'form';
  }

  /**
   * Wrapper method which takes links along with their options, lets other
   * modules alter the links and then generates and saves the sitemap.
   *
   * @param array $links
   *  All links with their multilingual versions and settings.
   * @param bool $remove_sitemap
   *  Remove old sitemap from database before inserting the new one.
   */
  public static function generateSitemap($links, $remove_sitemap = FALSE) {
    // Invoke alter hook.
    drupal_alter('simple_sitemap_links', $links);
    $values = [
      'id' => $remove_sitemap ? 1 : db_query('SELECT MAX(id) FROM {simple_sitemap}')->fetchField() + 1,
      'sitemap_string' => self::generateSitemapChunk($links),
      'sitemap_created' => REQUEST_TIME,
    ];
    if ($remove_sitemap) {
      db_truncate('simple_sitemap')->execute();
    }
    db_insert('simple_sitemap')->fields($values)->execute();
  }

  /**
   * Generates and returns a sitemap chunk.
   *
   * @param array $links
   *  All links with their multilingual versions and settings.
   *
   * @return string sitemap chunk
   */
  private static function generateSitemapChunk($links) {
    $default_language_id = language_default()->language;

    $writer = new XMLWriter();
    $writer->openMemory();
    $writer->setIndent(TRUE);
    $writer->startDocument(self::XML_VERSION, self::ENCODING);

    $path = drupal_get_path('module', 'simple_sitemap') . '/simple_sitemap.xsl';
    $writer->writePi('xml-stylesheet', 'type="text/xsl" href="' . $path . '"');

    $writer->startElement('urlset');
    $writer->writeAttribute('xmlns', self::XMLNS);
    $writer->writeAttribute('xmlns:xhtml', self::XMLNS_XHTML);

    $options['headers']['User-Agent'] = 'Drupal (+http://drupal.org/)';
    $options['headers']['Range'] = 'bytes=0-1024';
    $options['method'] = 'GET';
    $options['max_redirects'] = 0;

    foreach ($links as $link) {
      $writer->startElement('url');

      // Adding url to standard language.
      $writer->writeElement('loc', $link['urls'][$default_language_id]);

      // Adding alternate urls (other languages) if any.
      if (count($link['urls']) > 1) {
        foreach ($link['urls'] as $language_id => $localised_url) {
          $writer->startElement('xhtml:link');
          $writer->writeAttribute('rel', 'alternate');
          $writer->writeAttribute('hreflang', $language_id);
          $writer->writeAttribute('href', $localised_url);
          $writer->endElement();
        }
      }
      if (isset($link['priority'])) { // Add priority if any.
        $writer->writeElement('priority', $link['priority']);
      }
      if (isset($link['lastmod'])) { // Add lastmod if any.
        $writer->writeElement('lastmod', $link['lastmod']);
      }
      $writer->endElement();
    }
    $writer->endElement();
    $writer->endDocument();
    return $writer->outputMemory();
  }

  public function setGenerateFrom($from) {
    $this->generateFrom = $from;
  }

  /**
   * Adds all operations to the batch and starts it.
   */
  public function startGeneration() {
    $batch = new Batch();
    $batch->setBatchInfo([
      'from' => $this->generateFrom,
      'batch_process_limit' => !empty($this->generator->getSetting('batch_process_limit'))
        ? $this->generator->getSetting('batch_process_limit') : NULL,
      'max_links' => $this->generator->getSetting('max_links'),
      'remove_duplicates' => $this->generator->getSetting('remove_duplicates'),
      'entity_types' => $this->generator->getConfig('entity_types'),
    ]);
    // Add frontpage link generating operation.
    $batch->addOperation('generateFrontpageUrl');
    // Add custom link generating operation.
    $batch->addOperation('generateCustomUrls', $this->getCustomUrlsData());

    // Add entity link generating operations.
    foreach ($this->getEntityTypeData() as $data) {
      $batch->addOperation('generateBundleUrls', $data);
    }
    $batch->start();
  }

  /**
   * Returns a batch-ready data array for custom link generation.
   *
   * @return array $data
   *  Data to be processed.
   */
  private function getCustomUrlsData() {
    $link_generator = new CustomLinkGenerator();
    return $link_generator->getCustomPaths($this->generator->getConfig('custom'));
  }

  /**
   * Collects entity metadata for entities that are set to be indexed
   * and returns an array of batch-ready data sets for entity link generation.
   *
   * @return array $operations.
   */
  private function getEntityTypeData() {
    $data_sets = [];
    $sitemap_entity_types = Simplesitemap::getSitemapEntityTypes();
    $entity_types = $this->generator->getConfig('entity_types');
    foreach ($entity_types as $entity_type_name => $bundles) {
      if (isset($sitemap_entity_types[$entity_type_name])) {
        $keys = $sitemap_entity_types[$entity_type_name]['entity keys'];
//        $keys['bundle'] = $entity_type_name == 'menu_link_content' ? 'menu_name' : $keys['bundle']; // Menu fix.
        foreach ($bundles as $bundle_name => $bundle_settings) {
          if ($bundle_settings['index']) {
            $data_sets[] = [
              'bundle_settings' => $bundle_settings,
              'bundle_name' => $bundle_name,
              'entity_type_name' => $entity_type_name,
              'keys' => $keys,
              'uri callback' => $sitemap_entity_types[$entity_type_name]['uri callback'],
            ];
          }
        }
      }
    }
    return $data_sets;
  }

  /**
   * Generates and returns the sitemap index for all sitemap chunks.
   *
   * @param array $chunks
   *  All sitemap chunks keyed by the chunk ID.
   *
   * @return string sitemap index
   */
  public function generateSitemapIndex($chunks) {
    $writer = new XMLWriter();
    $writer->openMemory();
    $writer->setIndent(TRUE);
    $writer->startDocument(self::XML_VERSION, self::ENCODING);

    $writer->startElement('sitemapindex');
    $writer->writeAttribute('xmlns', self::XMLNS);

    foreach ($chunks as $chunk_id => $chunk_data) {
      $writer->startElement('sitemap');
      $writer->writeElement('loc', $GLOBALS['base_url'] . '/sitemaps/' . $chunk_id . '/' . 'sitemap.xml');
      $writer->writeElement('lastmod', date_iso8601($chunk_data->sitemap_created));
      $writer->endElement();
    }
    $writer->endElement();
    $writer->endDocument();
    return $writer->outputMemory();
  }
}

