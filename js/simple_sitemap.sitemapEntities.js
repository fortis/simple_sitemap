/**
 * @file
 * Attaches simple_sitemap behaviors to the sitemap entities form.
 */
(function ($) {

  "use strict";

  Drupal.behaviors.simple_sitemapSitemapEntities = {
    attach: function (context, settings) {
      var allEntities = settings.simple_sitemap.all_entities;
      var atomicEntities = settings.simple_sitemap.atomic_entities;
      var initiallyChecked = [];
      var removingSettingsWarning = Drupal.t("<strong>Warning:</strong> This entity type's sitemap settings including per-entity overrides will be deleted after hitting <em>Save</em>.");

      // On load.
      // Hide the 'Regenerate sitemap' field to only display it if settings have changed.
      $('.form-item-simple-sitemap-regenerate-now').hide();

      // Get all entities that are enabled on form load.
      $.each(allEntities, function (index, value) {
        var enabledId = '#edit-' + value + '-enabled';
        if ($(context).find(enabledId).is(':checked')) {
          initiallyChecked.push(value);
        }
      });

      // Show priority settings if atomic entity enabled on form load.
      $.each(atomicEntities, function (index, value) {
        var enabledId = '#edit-' + value + '-enabled';
        var priorityId = '.form-item-' + value + '-simple-sitemap-priority';
        if ($(context).find(enabledId).is(':checked')) {
          // Show 'Priority' field if 'Index sitemap' is ticked.
          $(priorityId).show();
        }
        else {  // Hide 'Priority' field if 'Index sitemap' is empty.
          $(priorityId).hide();
        }
      });

      // On change.
      $.each(allEntities, function (index, value) {
        var enabledId = '#edit-' + value + '-enabled';
        $(enabledId).change(function () {
          if ($(context).find(enabledId).is(':checked')) {
            $('#warning-' + value).remove();
          }
          else {
            if ($.inArray(value, initiallyChecked) != -1) {
              $('.form-item-' + value + '-enabled')
                .append("<div id='warning-" + value + "'>"
                  + removingSettingsWarning + "</div>");

            }
          }
          // Show 'Regenerate sitemap' field if setting has changed.
          $('.form-item-simple-sitemap-regenerate-now').show();
        });
      });

      $.each(atomicEntities, function (index, value) {
        var enabledId = '#edit-' + value + '-enabled';
        var priorityId = '.form-item-' + value + '-simple-sitemap-priority';

        $(enabledId).change(function () {
          if ($(context).find(enabledId).is(':checked')) {
            // Show 'Priority' field if 'Index sitemap' is ticked.
            $(priorityId).show();
          }
          else {  // Hide 'Priority' field if 'Index sitemap' is empty.
            $(priorityId).hide();
          }
        });
        // Show 'Regenerate sitemap' field if setting has changed.
        $(priorityId).change(function () {
          $('.form-item-simple-sitemap-regenerate-now').show();
        });
      });
    }
  };
})(jQuery);
