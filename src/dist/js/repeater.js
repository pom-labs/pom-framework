/**
 * @var pom_framework_repeater Object containing the translation strings.
 */

jQuery(function($) {
  const dependencyUtils = window.pomatioDependencies || {};
  const handleFieldVisibility = dependencyUtils.handleFieldVisibility || function() {};
  const initializeFieldVisibility = dependencyUtils.initializeFieldVisibility || function() {};

  /**
   * Manage dependent fields on page load.
   */
  initializeFieldVisibility();

  let $refresh_code_editors = function($scope) {
    const $editors = $scope && $scope.length ? $scope.find('.CodeMirror') : $('.CodeMirror');

    $editors.each(function(i, el) {
      if (el.CodeMirror) {
        el.CodeMirror.refresh();
      }
    });
  };

  /**
   * Toggle the repeater
   */
  $(document).on('click', '.repeater .title', function() {
    const $repeater = $(this).closest('.repeater');

    $repeater.toggleClass('closed');
    $refresh_code_editors($repeater);
  });

  /**
   * Toggle all direct repeater items in a wrapper.
   */
  $(document).on('click', '.open-all-repeaters, .close-all-repeaters', function(e) {
    e.preventDefault();

    const $this = $(this);
    const $wrapper = $this.closest('.repeater-wrapper');
    const $repeaters = $wrapper.children('.repeater');

    if ($this.hasClass('open-all-repeaters')) {
      $repeaters.removeClass('closed');
    }
    else {
      $repeaters.addClass('closed');
    }

    $refresh_code_editors($wrapper);
  });

  $(document).on('click touchstart', '.repeater-identifier', function(event) {
    event.preventDefault();
    event.stopPropagation();

    const identifier = $(this).text().trim();

    if (!identifier) {
      return;
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(identifier);
      return;
    }

    const $tempInput = $('<textarea>');
    $tempInput.val(identifier);
    $(this).append($tempInput);
    $tempInput[0].select();
    document.execCommand('copy');
    $tempInput.remove();
  });

  let $set_repeater_identifier = function($repeater) {
    let $identifier_holder = $repeater.find('.title .repeater-identifier');

    if (!$identifier_holder.length) {
      return;
    }

    let $identifier = $repeater.find('input[name="repeater_identifier"]').val();

    if ($identifier) {
      $identifier_holder.text(' - ID: ' + $identifier);
    }
    else {
      $identifier_holder.text('');
    }
  };

  let $decode_repeater_value = function($value) {
    if (typeof $value !== 'string' || $value.trim() === '') {
      return {};
    }

    try {
      let $decoded = JSON.parse($value);
      return $decoded && typeof $decoded === 'object' ? $decoded : {};
    }
    catch (error) {
      return {};
    }
  };

  let $preserve_nested_repeaters = function($source_item, $target_item) {
    if (!$source_item || typeof $source_item !== 'object') {
      return;
    }

    Object.keys($source_item).forEach(function($key) {
      if (Object.prototype.hasOwnProperty.call($target_item, $key)) {
        return;
      }

      let $field_value = $source_item[$key];
      if ($field_value && typeof $field_value === 'object' && $field_value.type === 'repeater') {
        $target_item[$key] = $field_value;
      }
    });
  };

  let $update_repeater = function($wrapper) {
    let $repeater_elements = $wrapper.children('.repeater');
    let $is_child_repeater = $wrapper.parents('.repeater-wrapper').length > 0;
    let $existing_value = $decode_repeater_value($wrapper.children('.repeater-value').val());

    let $value = {};

    // For each repeater element
    for (let $i = 0; $i < $repeater_elements.length; $i++) {
      let $is_default = $repeater_elements[$i].classList.contains('default');
      let $repeater_type = $is_default ? 'default' : 'new';

      $set_repeater_identifier($($repeater_elements[$i]));

      let $repeater_fields = $repeater_elements[$i].querySelectorAll('input, select, textarea');
      let $obj = {};

      let $font_input_val = {};
      let $trbl_fields_handled = new Set();
      let $checkbox_groups_handled = new Set();

      // For each field inside the repeater element
      for (let $i2 = 0; $i2 < $repeater_fields.length; $i2++) {
        let $field = $repeater_fields[$i2];
        let $field_name = $field.getAttribute('data-base-name') || $field.getAttribute('name');
        let $is_child_repeater_field = $($field).parents('.repeater-wrapper').length > 1;

        /**
         * Exclude config hidden field and
         * inner repeater fields in main repeater.
         */
        if ($field_name === 'config' || (!$is_child_repeater && $is_child_repeater_field)) {
          continue;
        }

        if ($field.type === 'hidden' && $field.disabled) {
          continue;
        }

        if ($field_name === 'repeater_identifier' || $field_name === 'default_values') {
          $obj[$field_name] = $field.value.trim();
        }
        else {
          if ($field.getAttribute('data-type') === 'trbl') {
            let $base_name = $field.getAttribute('data-base-name') || $field_name;

            if ($base_name && $base_name.includes('[')) {
              $base_name = $base_name.split('[')[0];
            }

            if ($trbl_fields_handled.has($base_name)) {
              continue;
            }

            $trbl_fields_handled.add($base_name);

            const $trbl_wrapper = $($field).closest('.pomatio-trbl');
            const $sides = ['top', 'right', 'bottom', 'left'];
            const $trbl_value = {
              'sync': $trbl_wrapper.find('.pomatio-trbl__sync-state').val() === 'yes' ? 'yes' : 'no',
            };

            for (const $side of $sides) {
              const $side_value = $trbl_wrapper.find(`.pomatio-trbl__value[name$="[${$side}][value]"]`).first().val();
              const $side_unit = $trbl_wrapper.find(`[name$="[${$side}][unit]"]`).first().val();

              $trbl_value[$side] = {
                'value': $side_value ? $side_value.trim() : '',
                'unit': $side_unit || '',
              };
            }

            $obj[$base_name] = {
              'value': $trbl_value,
              'type': 'trbl'
            };

            continue;
          }

          if ($field.getAttribute('data-type') === 'checkbox' && $field.type === 'checkbox' && $field_name && $field_name.endsWith('[]')) {
            let $base_name = ($field.getAttribute('data-base-name') || $field_name).replace(/\[\]$/, '');

            if ($checkbox_groups_handled.has($base_name)) {
              continue;
            }

            $checkbox_groups_handled.add($base_name);

            let $checked_values = Array.from($repeater_elements[$i].querySelectorAll('input[type="checkbox"][data-type="checkbox"]'))
              .filter(function($checkbox) {
                let $checkbox_name = $checkbox.getAttribute('data-base-name') || $checkbox.getAttribute('name') || '';
                return $checkbox_name.replace(/\[\]$/, '') === $base_name && $checkbox.checked;
              })
              .map(function($checkbox) {
                return $checkbox.value.trim();
              });

            $obj[$base_name] = {
              'value': $checked_values,
              'type': 'checkbox'
            };

            continue;
          }

          if ($field.type === 'radio' && !$field.checked) {
            continue;
          }

          let $field_value = $field.value.trim();
          if ($field.getAttribute('data-type') === 'checkbox' || $field.getAttribute('data-type') === 'toggle') {
            $field_value = $($field).is(':checked') ? 'yes' : 'no';
          }

          if ($field.getAttribute('data-type') === 'select' && $($field).prop('multiple')) {
            $field_value = $($field).val().join(',');
          }

          if ($field.getAttribute('data-type') === 'font_picker') {
            let $font_type_match = $field_name.match(/\[([^\]]+)\]$/);
            if ($font_type_match) {
              let $font_type = $font_type_match[1];
              $field_name = $field_name.split('[')[0];

              if (!$font_input_val.hasOwnProperty($font_type)) {
                $font_input_val[$font_type] = $field_value;
              }

              $field_value = $font_input_val;
            }
          }

          $obj[$field_name] = {
            'value': $field_value,
            'type': $field.getAttribute('data-type') || ''
          };
        }

        /**
         * Manage dependent fields.
         */
        handleFieldVisibility($field);
      }

      if (!$is_child_repeater) {
        $($repeater_elements[$i]).find('.repeater-wrapper').each(function() {
          if ($(this).closest('.repeater')[0] !== $repeater_elements[$i]) {
            return;
          }

          const $child_value_input = $(this).children('.repeater-value').last();
          const $child_repeater_name = $child_value_input.attr('name');

          if (!$child_repeater_name) {
            return;
          }

          $obj[$child_repeater_name] = {
            'value': $decode_repeater_value($child_value_input.val()),
            'type': 'repeater'
          };
        });
      }

      if (!$is_child_repeater && Array.isArray($existing_value[$repeater_type])) {
        $preserve_nested_repeaters($existing_value[$repeater_type][$i], $obj);
      }

      /**
       * If the type exists add it, if it doesn't exist, create it first.
       */
      if ($value.hasOwnProperty($repeater_type)) {
        $value[$repeater_type].push($obj);
      }
      else {
        $value[$repeater_type] = [];
        $value[$repeater_type].push($obj);
      }

      $wrapper.children('.repeater-value').val(JSON.stringify($value));

      /**
       * Appends the content of possible child repeaters to the parent repeater.
       */
      if ($is_child_repeater) {
        const $parent_repeater_item = $wrapper.closest('.repeater');
        const $parent_index = $parent_repeater_item.parent().children('.repeater').index($parent_repeater_item);
        const $child_repeater_name = $wrapper.find('.repeater-value').attr('name');
        if (!$child_repeater_name) {
          continue;
        }

        const $parent_repeater = $wrapper.parents('.repeater-wrapper').last();
        const $parent_repeater_type = $parent_repeater_item.hasClass('default') ? 'default' : 'new';
        let $parent_value = $decode_repeater_value($parent_repeater.find('.repeater-value').last().val());

        if (!Array.isArray($parent_value[$parent_repeater_type])) {
          $parent_value[$parent_repeater_type] = [];
        }

        if (
          !Object.prototype.hasOwnProperty.call($parent_value[$parent_repeater_type], $parent_index) ||
          !$parent_value[$parent_repeater_type][$parent_index] ||
          typeof $parent_value[$parent_repeater_type][$parent_index] !== 'object'
        ) {
          $parent_value[$parent_repeater_type][$parent_index] = {};
        }

        $parent_value[$parent_repeater_type][$parent_index][$child_repeater_name] = {
          'value': $value,
          'type': 'repeater'
        };

        $parent_repeater.find('.repeater-value').last().val(JSON.stringify($parent_value));
      }
    }
  };

  /**
   * Add new repeater element.
   */
  $(document).on('click', '.add-new-repeater-item', function(e) {
    e.preventDefault();

    let $this = $(this);

    let $wrapper = $this.closest('.repeater-wrapper');
    let $limit = parseInt($wrapper.attr('data-limit'));
    let $item_count = $wrapper.find('> .repeater').length;

    if ($item_count >= $limit) {
      if (!$('.repeater-limit-warning').length) {
        $this.after('<span class="repeater-limit-warning">' + pom_framework_repeater.limit + '</span>');
      }

      setTimeout(function() {
        $('.repeater-limit-warning').remove();
      }, 2000);

      return;
    }

    let $spinner = $this.siblings('.repeater-spinner');

    $spinner.show();

    let $config = $this.siblings('[name="config"]').val();

    $.ajax({
      url: pom_framework_repeater.ajax_url,
      type: 'POST',
      data: {
        action: 'pom_framework_get_repeater_item_html',
        nonce: pom_framework_repeater.nonce,
        config: $config,
        items: $item_count
      },
      success: function($response) {
        $this.before($response);
        $spinner.hide();
      }
    });

    // Load custom fields as Select2 or color picker.
    $(document).ajaxComplete(function() {
      if (typeof $.fn.select2 !== 'undefined') {
        $('.pom-framework-select.multiple').select2();
      }

      if (typeof $.fn.wpColorPicker !== 'undefined') {
        $('.pom-framework-color-picker').wpColorPicker();
      }

      initializeFieldVisibility();
    });
  });

  /**
   * Delete repeater element.
   *
   * If it is a parent repeater, we directly remove it from the value using its index.
   * If it's a child repeater, we update the value of the child repeater and then call the function to update the parent repeater.
   * TODO: Delete file from server on delete when repeater has code fields.
   */
  $(document).on('click', '.repeater-wrapper .delete', function(e) {
    e.preventDefault();

    let $execute = confirm(pom_framework_repeater.delete_repeater);
    if (!$execute) {
      return;
    }

    const $this = $(this);
    const $repeater_type = $this.closest('.repeater').hasClass('default') ? 'default' : 'new';
    const $wrapper = $this.closest('.repeater-wrapper');

    const $is_child_repeater = $wrapper.parents('.repeater-wrapper').length > 0;

    if (!$is_child_repeater) {
      const $repeater = $this.closest('.repeater');
      const $repeater_index = $wrapper.children('.repeater').index($repeater);
      let $repeater_value = $wrapper.find('.repeater-value').last().val();

      $repeater_value = JSON.parse($repeater_value);
      $repeater_value[$repeater_type].splice($repeater_index, 1);

      $wrapper.find('.repeater-value').last().val(JSON.stringify($repeater_value));
      $repeater.remove();

      $update_repeater($wrapper);

      return;
    }

    $wrapper.find('.repeater-value').val('');
    $this.closest('.repeater').remove();

    $update_repeater($wrapper);
  });

  /**
   * Update repeater value.
   */
  $(document).on('change', '.repeater-wrapper input, .repeater-wrapper textarea, .repeater-wrapper select', function() {
    const $this = $(this);
    $update_repeater($this.closest('.repeater-wrapper'));
  });

  // Fix for Icon Picker fields
  $(document).on('input', '.repeater-wrapper input[data-type="icon_picker"]', function() {
    const $this = $(this);
    $update_repeater($this.closest('.repeater-wrapper'));
  });

  /**
   * Make WordPress Color Picker repeater compatible.
   */
  if (typeof $.fn.wpColorPicker !== 'undefined') {
    $('.pom-framework-color-picker').wpColorPicker({
      change: function(event, ui) {
        let $color = ui.color.toString();
        let $this = $(event.target);
        $this.val($color);
        $update_repeater($this.closest('.repeater-wrapper'));
      }
    });

    $(document).ajaxComplete(function() {
      $('.pom-framework-color-picker').wpColorPicker({
        change: function(event, ui) {
          let $color = ui.color.toString();
          let $this = $(event.target);
          $this.val($color);
          $update_repeater($this.closest('.repeater-wrapper'));
        }
      });
    });
  }

  /**
   * Make repeater elements sortable.
   */
  if (typeof $.fn.sortable !== 'undefined') {
    $('.repeater-wrapper.sortable').sortable({
      items: '> .repeater',
      update: function(event, ui) {
        $update_repeater($(this));
      }
    });
  }

  /**
   * Update repeater title live.
   */
  let $get_repeater_title_value = function($element) {
    if ($element.is('select')) {
      let $selected = $element.find('option:selected');

      if ($selected.length > 0) {
        let $titles = $selected
          .map(function() {
            return $(this).text().trim();
          })
          .get()
          .filter(function(text) {
            return text !== '';
          });

        return $titles.join(', ');
      }

      return '';
    }

    return $element.val();
  };

  let $update_repeater_title = function($element) {
    let $title_holder = $element.closest('.repeater').find('.title span').first();
    let $value = $get_repeater_title_value($element);

    if ($value) {
      $title_holder.text(' - ' + $value);
    }
    else {
      $title_holder.text('');
    }
  };

  $(document).on('keyup change', '.repeater .use-for-title', function() {
    $update_repeater_title($(this));
  });

  /**
   * Add repeater title on page load.
   */
  let $append_to_title = function() {
    $('.repeater-wrapper .use-for-title').each(function(i, v) {
      $update_repeater_title($(this));
    });

    $('.repeater-wrapper .repeater').each(function() {
      $set_repeater_identifier($(this));
    });
  };
  $append_to_title();
  $(document).ajaxComplete(function() {
    $append_to_title();
  });

  /**
   * Restore repeater inputs to default value.
   */
  $(document).on('click', '.restore-default', function(e) {
    e.preventDefault();

    let $this = $(this);
    let $wrapper = $this.closest('.repeater-wrapper');
    let $repeater_defaults = $this.closest('.repeater').find('input[name="default_values"').val();
    if ($repeater_defaults) {
      let $decoded = JSON.parse($repeater_defaults);
      Object.keys($decoded).map(function($key) {
        let $value = $decoded[$key].value;

        $this.closest('.repeater').find('input, select, checkbox, textarea').each(function() {
          let $field_name = this.getAttribute('name');
          if ($field_name === $key) {
            this.value = $value;
          }
        });
      });

      $update_repeater($wrapper);
    }
  });

  /**
   * Restore all repeater defaults.
   */
  $(document).on('click', '.restore-repeater-defaults', function(e) {
    e.preventDefault();

    if (!confirm(pom_framework_repeater.restore_msg)) {
      return;
    }

    let $this = $(this);
    let $wrapper = $this.closest('.repeater-wrapper');

    let $spinner = $this.closest('.repeater-wrapper').find('.repeater-spinner');
    $spinner.show();

    $wrapper.children('.repeater.default').remove();

    $.ajax({
      url: ajaxurl,
      type: 'POST',
      data: {
        action: 'pom_framework_restore_repeater_defaults',
        nonce: pom_framework_repeater.nonce,
        defaults: $this.attr('data-defaults'),
        fields: $this.attr('data-fields'),
        title: $this.attr('data-title')
      },
      success: function($response) {
        let $bulk_actions = $wrapper.children('.repeater-bulk-actions').first();
        if ($bulk_actions.length) {
          $bulk_actions.after($response);
        }
        else {
          $wrapper.prepend($response);
        }

        $spinner.hide();

        $update_repeater($wrapper);
      }
    });
  });

  /**
   * Duplicates a repeater generating a new identifier for it.
   */
  $(document).on('click', '.clone-repeater', function(e) {
    e.preventDefault();

    let $this = $(this);
    let $repeater = $this.closest('.repeater');
    let $wrapper = $this.closest('.repeater-wrapper');
    let $clone = $repeater.clone();

    /**
     * Generate repeater new identifier.
     */
    $clone.find('input[name="repeater_identifier"]').val($generate_random_string(10, false));
    $set_repeater_identifier($clone);

    /**
     * If the repeater has code fields replace their id's in order to render Codemirror with unique IDs.
     */
    let $code_editor = $clone.find('.pom-framework-code-editor-html, .pom-framework-code-editor-js, .pom-framework-code-editor-css, .pom-framework-code-editor-json');
    for (let $i = 0; $i < $code_editor.length; $i++) {
      $code_editor[$i].id = $generate_random_string(10, false);
      let $cloned_editor = $($code_editor[$i]);

      // Remove cloned editor markup so the textarea remains usable without CodeMirror.
      $cloned_editor.next('.CodeMirror').remove();
      $cloned_editor.removeClass('codemirror-rendered').show();

      if (
        typeof settings === 'undefined' ||
        !settings.codeMirrorSettings ||
        typeof wp === 'undefined' ||
        !wp.codeEditor ||
        typeof wp.codeEditor.initialize !== 'function'
      ) {
        continue;
      }

      wp.codeEditor.initialize($code_editor[$i], settings.codeMirrorSettings);
      $cloned_editor.addClass('codemirror-rendered');
    }

    /**
     * A cloned repeater can never be a default, so we change its key.
     */
    if ($clone.hasClass('default')) {
      $clone = $clone.removeClass('default').addClass('new');
      $clone.find('input[name="default_values"]').remove();
    }

    $repeater.after($clone);

    $update_repeater($wrapper);
  });

  /**
   * Generate a random string.
   *
   * The same helper exists but in PHP.
   * @see POM_Framework_Helper::generate_random_string()
   *
   * @param $length
   * @param $numbers
   * @returns {string}
   */
  let $generate_random_string = function($length = 10, $numbers = true) {
    const $number_string = $numbers ? '0123456789' : '';
    const $characters = $number_string + 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    let $randomString = '';
    for (let $i = 0; $i < $length; $i++) {
      $randomString += $characters[Math.floor(Math.random() * $characters.length)];
    }

    return $randomString;
  };

});
