/* WP Image Optimizer — Admin JS */
(function($){
  'use strict';

  /* ── Format picker ── */
  $(document).on('click', '.wpio-format-card', function(){
    var val = $(this).data('format');
    $('.wpio-format-card').removeClass('selected');
    $(this).addClass('selected');
    $('input[name="wpio_format"]').val(val);
  });

  /* ── Quality slider ── */
  $(document).on('input', '.wpio-quality-slider', function(){
    var v = $(this).val();
    $(this).css('--val', v + '%');
    $('.wpio-quality-val').text(v);
    $('input[name="wpio_quality"]').val(v);
  });

  /* ── Resize toggle show/hide ── */
  function updateResizeFields(){
    var enabled = $('#wpio_resize_enabled').is(':checked');
    $('#wpio-resize-fields').toggle(enabled);
  }
  $(document).on('change', '#wpio_resize_enabled', updateResizeFields);

  /* ── Method card selection ── */
  $(document).on('change', '.wpio-method-card input[type=radio]', function(){
    $('.wpio-method-card').removeClass('selected');
    $(this).closest('.wpio-method-card').addClass('selected');
  });

  /* ── Bulk Queue ── */
  var running = (typeof wpioData !== 'undefined') ? wpioData.queueRunning : false;
  var pollTimer = null;
  var retryCount = 0;
  var MAX_RETRIES = 5;

  function addLog(msg, type){
    var cls = type || 'log-info';
    var $log = $('#wpio-bulk-log');
    $('<p>').addClass(cls).text(msg).appendTo($log);
    $log[0].scrollTop = $log[0].scrollHeight;
  }

  function updateProgress(done, total, errors){
    var pct = total > 0 ? Math.round((done / total) * 100) : 0;
    $('#wpio-prog-bar').css('width', pct + '%').text(pct + '%');
    $('.wpio-progress-wrap').attr('aria-valuenow', pct);
    $('#wpio-prog-text').text(done + ' / ' + total + ' images processed' + (errors > 0 ? ' · ' + errors + ' errors' : ''));
  }

  function stopRunning(){
    running = false;
    clearTimeout(pollTimer);
    $('#wpio-bulk-start').prop('disabled', false).html('<span>⚡</span> Start Bulk Convert');
    $('#wpio-bulk-cancel').hide();
  }

  function processChunk(){
    $.post(ajaxurl, { action: 'wpio_queue_chunk', _wpnonce: (typeof wpioData !== 'undefined' ? wpioData.nonceChunk : '') }, function(res){
      retryCount = 0;
      if (!res.success) { addLog('Error: ' + (typeof res.data === 'string' ? res.data : 'Unknown error'), 'log-error'); stopRunning(); return; }
      var d = res.data;
      updateProgress(d.progress.done, d.progress.total, d.progress.errors);
      if (d.status === 'done') {
        addLog('All done! Converted: ' + d.progress.done + ' / Errors: ' + d.progress.errors, 'log-ok');
        stopRunning();
      } else if (d.status === 'running') {
        addLog('Chunk done - ' + d.remaining + ' remaining...', 'log-info');
        pollTimer = setTimeout(processChunk, 600);
      } else { stopRunning(); }
    }).fail(function(){
      retryCount++;
      if (retryCount >= MAX_RETRIES) {
        addLog('Too many failures (' + MAX_RETRIES + '). Background cron will continue processing.', 'log-error');
        stopRunning();
      } else {
        addLog('Request failed (attempt ' + retryCount + '/' + MAX_RETRIES + '). Retrying in 5s...', 'log-warn');
        pollTimer = setTimeout(processChunk, 5000);
      }
    });
  }

  $('#wpio-bulk-start').on('click', function(){
    if (running) return;
    running = true;
    retryCount = 0;
    $(this).prop('disabled', true).html('<span>⏳</span> Building queue…');
    $('#wpio-bulk-cancel').show();
    $('#wpio-live-progress').slideDown(200);
    addLog('Scanning all configured folders...', 'log-info');
    $.post(ajaxurl, { action: 'wpio_queue_start', _wpnonce: (typeof wpioData !== 'undefined' ? wpioData.nonceStart : '') }, function(res){
      if (!res.success) { addLog('Error: ' + (typeof res.data === 'string' ? res.data : 'Could not start queue'), 'log-error'); stopRunning(); return; }
      addLog('Queue built: ' + res.data.total + ' images queued', 'log-info');
      if (res.data.total === 0) { addLog('Nothing to convert - all images already optimized!', 'log-ok'); stopRunning(); return; }
      $('#wpio-bulk-start').html('<span>⚡</span> Running…');
      processChunk();
    });
  });

  $('#wpio-bulk-cancel').on('click', function(){
    $.post(ajaxurl, { action: 'wpio_queue_cancel', _wpnonce: (typeof wpioData !== 'undefined' ? wpioData.nonceCancel : '') });
    addLog('Cancelled. Background cron also stopped.', 'log-error');
    stopRunning();
  });

  if (running) { addLog('Resuming from background...', 'log-warn'); processChunk(); }

  /* ── Purge backups ── */
  $('#wpio-purge-backups').on('click', function(){
    if (!confirm('Delete all backups? This cannot be undone.')) return;
    var $btn = $(this);
    $btn.prop('disabled', true).text('Purging…');
    $.post(ajaxurl, { action: 'wpio_delete_backup', scope: 'all', _wpnonce: $btn.data('nonce') }, function(res){
      res.success ? location.reload() : (alert('Error: ' + res.data), $btn.prop('disabled', false).text('🗑 Purge All Backups'));
    });
  });

  /* ── Tab switching (General / Advanced / Delivery) ── */
  $(document).on('click', '.wpio-tab-link', function(e){
    e.preventDefault();
    var pane = $(this).data('pane');
    $('.wpio-nav-tabs a').removeClass('active');
    $(this).addClass('active');
    $('.wpio-tab-pane').hide();
    $('.wpio-tab-pane[data-pane="' + pane + '"]').show();
  });

  /* ── AJAX Settings Save (all tabs at once) ── */
  $(document).on('submit', '#wpio-settings-form', function(e){
    e.preventDefault();
    var $form = $(this);
    var $btn = $form.find('#wpio-save-btn');
    var data = $form.serialize();
    data += '&action=wpio_save_settings';

    $btn.prop('disabled', true).text('Saving…');
    $.post(ajaxurl, data, function(res){
      $btn.prop('disabled', false).text('Save Settings');
      if (res.success) {
        var $notice = $('#wpio-save-notice');
        $notice.text('Settings saved successfully.').stop(true).fadeIn(200);
        setTimeout(function(){ $notice.fadeOut(400); }, 5000);
      } else {
        alert('Error: ' + (res.data || 'Could not save settings.'));
      }
    }).fail(function(){
      $btn.prop('disabled', false).text('Save Settings');
      alert('Error: Request failed. Please try again.');
    });
  });

  /* ── File Tree ── */
  function loadTree(){
    var $wrap = $('#wpio-tree-wrap');
    if (!$wrap.length) return;
    $('#wpio-tree-loading').show();
    $('#wpio-tree-root').hide().empty();
    $.post(ajaxurl, {
      action: 'wpio_folder_tree',
      _wpnonce: (typeof wpioData !== 'undefined' ? wpioData.nonceFolderTree : '')
    }, function(res){
      $('#wpio-tree-loading').hide();
      if (!res.success || !res.data.length) {
        $('#wpio-tree-loading').text('No folders found.').show();
        return;
      }
      var $root = $('#wpio-tree-root');
      $.each(res.data, function(_, node){ $root.append(buildNode(node)); });
      $root.show();
    }).fail(function(){
      $('#wpio-tree-loading').text('Failed to load file tree.').show();
    });
  }
  function buildNode(node){
    var pct = node.total > 0 ? Math.round((node.converted / node.total) * 100) : 0;
    var badge = node.total > 0
      ? '<span style="color:#888;font-size:11px;margin-left:6px;">' + node.converted + '/' + node.total + ' (' + pct + '%)</span>'
      : '<span style="color:#aaa;font-size:11px;margin-left:6px;">empty</span>';
    var $li = $('<li>');
    var label = $('<span style="cursor:pointer;">').html((node.children && node.children.length ? '▸ ' : '  ') + '<strong>' + $('<span>').text(node.name).html() + '</strong>' + badge);
    $li.append(label);
    if (node.children && node.children.length) {
      var $ul = $('<ul class="wpio-tree" style="display:none;margin-left:18px;">');
      $.each(node.children, function(_, child){ $ul.append(buildNode(child)); });
      $li.append($ul);
      label.on('click', function(){
        var open = $ul.is(':visible');
        $ul.slideToggle(150);
        label.html((open ? '▸ ' : '▾ ') + '<strong>' + $('<span>').text(node.name).html() + '</strong>' + badge);
      });
    }
    return $li;
  }
  $(document).on('click', '#wpio-tree-refresh', function(){ loadTree(); });

  /* ── Init on ready ── */
  $(document).ready(function(){
    updateResizeFields();
    var $slider = $('.wpio-quality-slider');
    if ($slider.length) $slider.css('--val', $slider.val() + '%');
    if (running && $('#wpio-live-progress').length) {
      $('#wpio-live-progress').show();
      $('#wpio-bulk-cancel').show();
    }
    loadTree();
  });

})(jQuery);
