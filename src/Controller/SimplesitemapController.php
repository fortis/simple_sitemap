<?php
/**
 * @file
 * Contains \Drupal\simple_sitemap\Controller\SimplesitemapController.
 */

namespace Drupal\simple_sitemap\Controller;

/**
 * SimplesitemapController.
 */
class SimplesitemapController {

  /**
   * The sitemap generator.
   *
   * @var \Drupal\simple_sitemap\Simplesitemap
   */
  protected $generator;

  /**
   * SimplesitemapController constructor.
   *
   * @param \Drupal\simple_sitemap\Simplesitemap $generator
   *   The sitemap generator.
   */
  public function __construct($generator) {
    $this->generator = $generator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('simple_sitemap.generator'));
  }

  /**
   * Returns the whole sitemap, a requested sitemap chunk, or the sitemap index file.
   *
   * @param int $chunk_id
   *  Optional ID of the sitemap chunk. If none provided, the first chunk or
   *  the sitemap index is fetched.
   *
   * @return object Response
   *  Returns an XML response.
   */
  public function getSitemap($chunk_id = NULL) {
    $output = $this->generator->getSitemap($chunk_id);
    $output = !$output ? '' : $output;

    // Display sitemap with correct xml header.
//    $response = new CacheableResponse($output, Response::HTTP_OK, ['content-type' => 'application/xml']);
//    $meta_data = $response->getCacheableMetadata();
//    $meta_data->addCacheTags(['simple_sitemap']);
    return $output;
  }
}
