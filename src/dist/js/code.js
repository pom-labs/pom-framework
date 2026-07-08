/**
 * @var settings // Code editor settings object.
 */

jQuery(function($) {

  if (
    typeof settings === 'undefined' ||
    !settings.codeMirrorSettings ||
    typeof wp === 'undefined' ||
    !wp.codeEditor ||
    typeof wp.codeEditor.initialize !== 'function'
  ) {
    return;
  }

  const getCodeMirrorSettings = function(mode) {
    const codeMirrorSettings = $.extend(true, {}, settings.codeMirrorSettings);

    codeMirrorSettings.codemirror = $.extend({}, codeMirrorSettings.codemirror, {
      lineWrapping: false,
      mode: mode
    });

    return codeMirrorSettings;
  };

  let $render_code_editor = function() {
    const editors = {
      'css': 'text/css',
      'js': 'application/javascript',
      'json': 'application/json',
      'html': 'text/html'
    };

    for (const [editorClass, mode] of Object.entries(editors)) {
      $(`textarea.form-control.pom-framework-code-editor-${editorClass}`).each(function() {
        let $this = $(this);

        if ($this.hasClass('codemirror-rendered')) {
          return;
        }

        let $editor = wp.codeEditor.initialize($this, getCodeMirrorSettings(mode));

        // Whenever something changes, update the main textarea so there's no issues when saving
        $editor.codemirror.on('change', function(cm, change) {
          cm.save();
          $this.trigger('change');
        });

        $this.addClass('codemirror-rendered');
      });
    }
  };

  $render_code_editor();

  /**
   * Adjustment so that code editors added by repeaters render correctly.
   */
  $(document).ajaxComplete(function() {
    $render_code_editor();
  });

});
