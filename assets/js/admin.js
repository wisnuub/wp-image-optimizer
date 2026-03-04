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

  function addLog(msg, type){
    var cls = type || 'log-info';
    var $log = $('#wpio-bulk-log');
    $log.append('<p class="' + cls + '">' + msg + '</p>');
    $log[0].scrollTop = $log[0].scrollHeight;
  }

  function updateProgress(done, total, errors){
    var pct = total > 0 ? Math.round((done / total) * 100) : 0;
    $('#wpio-prog-bar').css('width', pct + '%').text(pct + '%');
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
      if (!res.success) { addLog('Error: ' + res.data, 'log-error'); stopRunning(); return; }
      var d = res.data;
      updateProgress(d.progress.done, d.progress.total, d.progress.errors);
      if (d.status === 'done') {
        addLog('✔ All done! Converted: ' + d.progress.done + ' · Errors: ' + d.progress.errors, 'log-ok');
        stopRunning();
      } else if (d.status === 'running') {
        addLog('🟢 Chunk done · ' + d.remaining + ' remaining…', 'log-info');
        pollTimer = setTimeout(processChunk, 600);
      } else { stopRunning(); }
    }).fail(function(){
      addLog('⚠ Request failed — background cron will continue. Retrying in 5s…', 'log-warn');
      pollTimer = setTimeout(processChunk, 5000);
    });
  }

  $('#wpio-bulk-start').on('click', function(){
    if (running) return;
    running = true;
    $(this).prop('disabled', true).html('<span>⏳</span> Building queue…');
    $('#wpio-bulk-cancel').show();
    $('#wpio-live-progress').slideDown(200);
    addLog('🔍 Scanning all configured folders…', 'log-info');
    $.post(ajaxurl, { action: 'wpio_queue_start', _wpnonce: (typeof wpioData !== 'undefined' ? wpioData.nonceStart : '') }, function(res){
      if (!res.success) { addLog('Error: ' + res.data, 'log-error'); stopRunning(); return; }
      addLog('📋 Queue built: ' + res.data.total + ' images queued', 'log-info');
      if (res.data.total === 0) { addLog('✔ Nothing to convert — all images already optimized!', 'log-ok'); stopRunning(); return; }
      $('#wpio-bulk-start').html('<span>⚡</span> Running…');
      processChunk();
    });
  });

  $('#wpio-bulk-cancel').on('click', function(){
    $.post(ajaxurl, { action: 'wpio_queue_cancel', _wpnonce: (typeof wpioData !== 'undefined' ? wpioData.nonceCancel : '') });
    addLog('✕ Cancelled. Background cron also stopped.', 'log-error');
    stopRunning();
  });

  if (running) { addLog('⏳ Resuming from background…', 'log-warn'); processChunk(); }

  /* ── Purge backups ── */
  $('#wpio-purge-backups').on('click', function(){
    if (!confirm('Delete all backups? This cannot be undone.')) return;
    var $btn = $(this);
    $btn.prop('disabled', true).text('Purging…');
    $.post(ajaxurl, { action: 'wpio_delete_backup', scope: 'all', _wpnonce: $btn.data('nonce') }, function(res){
      res.success ? location.reload() : (alert('Error: ' + res.data), $btn.prop('disabled', false).text('🗑 Purge All Backups'));
    });
  });

  /* ── Init on ready ── */
  $(document).ready(function(){
    updateResizeFields();
    var $slider = $('.wpio-quality-slider');
    if ($slider.length) $slider.css('--val', $slider.val() + '%');
    if (running && $('#wpio-live-progress').length) {
      $('#wpio-live-progress').show();
      $('#wpio-bulk-cancel').show();
    }
  });

})(jQuery);
