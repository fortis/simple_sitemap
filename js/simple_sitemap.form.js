/**
 * @file
 * Attaches simple_sitemap behaviors to the entity form.
 */
(function ($) {

  "use strict";

  Drupal.behaviors.simple_sitemapForm = {
    attach: function (context) {

      // On load.
      // Hide the 'Regenerate sitemap' field to only display it if settings have changed.
      $('.form-item-simple-sitemap-regenerate-now').hide();

      if ($(context).find('#edit-simple-sitemap-index-content-1').is(':checked')) {

        // Show 'Priority' field if 'Index sitemap' is ticked.
        $('.form-item-simple-sitemap-priority').show();
      }
      else {  // Hide 'Priority' field if 'Index sitemap' is empty.
        $('.form-item-simple-sitemap-priority').hide();
      }

      // On change.
      $("#edit-simple-sitemap-index-content").change(function () {
        // Show 'Regenerate sitemap' field if setting has changed.
        $('.form-item-simple-sitemap-regenerate-now').show();
        if ($(context).find('#edit-simple-sitemap-index-content-1').is(':checked')) {
          // Show 'Priority' field if 'Index sitemap' is ticked.
          $('.form-item-simple-sitemap-priority').show();
        }
        else {  // Hide 'Priority' field if 'Index sitemap' is empty.
          $('.form-item-simple-sitemap-priority').hide();
        }
      });
      // Show 'Regenerate sitemap' field if setting has changed.
      $("#edit-simple-sitemap-priority").change(function () {
        $('.form-item-simple-sitemap-regenerate-now').show();
      });
    }
  };
})(jQuery);
