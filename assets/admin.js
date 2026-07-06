/* global jQuery, PAGEFORGE */
(function($){
  function log(msg){ $('#pf-log').append('<div>> '+ msg +'</div>').scrollTop(999999); }

  function toggleAiProviderFields(){
    var defaultProvider = (typeof PAGEFORGE !== 'undefined' && PAGEFORGE.active_provider) ? PAGEFORGE.active_provider : 'openai';
    var provider = $('#pf-ai-provider').val() || defaultProvider;
    $('[data-ai-provider-section]').hide();
    $('[data-ai-provider-section="'+ provider +'"]').show();
  }

  $(document).on('change', '#pf-ai-provider', toggleAiProviderFields);
  $(toggleAiProviderFields);

  $(document).on('submit', '#pf-settings-form', function(e){
    e.preventDefault();
    $.post(PAGEFORGE.ajax, {
      action: 'pageforge_save_settings',
      nonce: PAGEFORGE.nonce,
      data: $(this).serialize()
    }, function(res){
      if(res.success){ alert('Settings saved'); location.reload(); } else { alert('Error: '+res.data); }
    });
  });

  // Data Sources page
  $(document).on('submit', '#pf-upload-form', function(e){
    e.preventDefault();
    var fd = new FormData(this);
    fd.append('action', 'pageforge_upload_csv');
    fd.append('nonce', PAGEFORGE.nonce);
    $.ajax({ url: PAGEFORGE.ajax, method: 'POST', data: fd, processData:false, contentType:false })
      .done(function(res){
        if(res.success){ $('#pf-upload-result').html('Uploaded: '+res.data.title); loadCSVs(); }
        else alert('Upload failed: '+res.data);
      });
  });

  function loadCSVs(){
    var $tbody = $('#pf-csv-table tbody');
    var $select = $('#pf-csv');
    var hasTable = $tbody.length;
    var hasSelect = $select.length;
    if(!hasTable && !hasSelect) return;

    if(hasTable) $tbody.empty();
    if(hasSelect) { $select.empty().append('<option value="">Loading…</option>'); }

    $.post(PAGEFORGE.ajax, { action:'pageforge_list_csv', nonce: PAGEFORGE.nonce }, function(res){
      // Clear previous entries before repopulating to avoid duplicates
      if(hasTable) $tbody.empty();
      if(hasSelect) $select.empty();

      if(!res || res.success !== true){
        var msg = 'Error: '+ (res && res.data ? res.data : 'Unable to load CSVs');
        if(hasTable) $tbody.append('<tr><td colspan="2">'+ msg +'</td></tr>');
        if(hasSelect) $select.append('<option value="">'+ msg +'</option>');
        return;
      }

      var items = Array.isArray(res.data) ? res.data : [];
      if(!items.length){
        if(hasTable) $tbody.append('<tr><td colspan="2">No CSVs yet.</td></tr>');
        if(hasSelect) $select.append('<option value="">No CSVs yet</option>');
        return;
      }

      if(hasSelect) $select.append('<option value="">Select CSV</option>');
      items.forEach(function(i){
        var title = i.title || 'Untitled';
        var url = i.url || '';
        if(hasTable) $tbody.append('<tr><td>'+ title +'</td><td><code>'+ url +'</code></td></tr>');
        if(hasSelect) $select.append('<option value="'+ i.id +'">'+ title +'</option>');
      });
    });
  }
  if($('#pf-csv-table').length || $('#pf-csv').length) loadCSVs();

  $(document).on('click', '#pf-download-sample', function(){
    $.post(PAGEFORGE.ajax, { action:'pageforge_sample_csv', nonce: PAGEFORGE.nonce }, function(res){
      if(res.success){
        var blob = new Blob([res.data.content], {type: 'text/csv'});
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url; a.download = res.data.filename; a.click(); URL.revokeObjectURL(url);
      }
    });
  });

  // Generate Pages
  $(document).on('click', '#pf-start', function(){
    var template_id = $('#pf-template').val();
    var csv_id = $('#pf-csv').val();
    var status = $('#pf-status').val();
    var mode = $('#pf-mode').val();
    if(!template_id || !csv_id){ alert('Select template and CSV'); return; }
    log('Starting generation...');
    $.post(PAGEFORGE.ajax, { action:'pageforge_start_generation', nonce: PAGEFORGE.nonce, template_id, csv_id, status, mode }, function(res){
      if(res.success){
        if(res.data.notice) log(res.data.notice);
        if(res.data.processed !== undefined) log('Processed: '+res.data.processed);
        if(res.data.queued !== undefined) log('Queued rows: '+res.data.queued);
        if(res.data.errors && res.data.errors.length) log('Errors: '+res.data.errors.join('; '));
      } else {
        log('Error: ' + res.data);
      }
    });
  });

  // Templates table export (simple JSON export of meta and content)
  $(document).on('click', '.pf-export-template', function(){
    var id = $(this).data('id');
    $.post(PAGEFORGE.ajax, { action:'pageforge_template_meta', nonce: PAGEFORGE.nonce, template_id: id }, function(res){
      if(res.success){
        var payload = res.data; payload.id = id; payload.content = '';
        // Fetch content via REST if available
        try {
          fetch(window.location.origin + '/wp-json/wp/v2/pageforge_template/'+id).then(r=>r.json()).then(function(o){
            payload.content = (o && o.content && o.content.rendered) ? o.content.rendered : '';
            var blob = new Blob([JSON.stringify(payload,null,2)], {type:'application/json'});
            var url = URL.createObjectURL(blob); var a = document.createElement('a');
            a.href = url; a.download = 'template-'+id+'.json'; a.click(); URL.revokeObjectURL(url);
          });
        } catch(e){
          var blob = new Blob([JSON.stringify(payload,null,2)], {type:'application/json'});
          var url = URL.createObjectURL(blob); var a = document.createElement('a');
          a.href = url; a.download = 'template-'+id+'.json'; a.click(); URL.revokeObjectURL(url);
        }
      }
    });
  });

  // ---------------- AI Tools ----------------
  function setOutput($el, html){ $el.html(html || '').show(); }
  function escHtml(s){ return String(s||'').replace(/[&<>"']/g, function(m){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]); }); }

  // Basic markdown renderer (uses marked.js if available; falls back to plain text)
  function mdToHtml(md){
    md = String(md || '');
    try {
      if(window.marked && typeof window.marked.parse === 'function') return window.marked.parse(md);
      if(window.marked && typeof window.marked === 'function') return window.marked(md);
    } catch(e) {}
    return '<pre class="pf-pre">' + escHtml(md) + '</pre>';
  }

  var searchTimer = null;
  $(document).on('input', '#pf-post-search', function(){
    var q = $(this).val();
    clearTimeout(searchTimer);
    if(!q || q.length < 2){ $('#pf-post-results').hide().empty(); return; }
    searchTimer = setTimeout(function(){
      $.post(PAGEFORGE.ajax, { action:'pageforge_ai_search_posts', nonce: PAGEFORGE.nonce, q: q }, function(res){
        if(!res || res.success !== true){ $('#pf-post-results').hide().empty(); return; }
        var items = Array.isArray(res.data) ? res.data : [];
        if(!items.length){ $('#pf-post-results').hide().empty(); return; }
        var html = items.map(function(i){
          return '<div class="pf-search-item" data-id="'+ i.id +'">'+
            '<strong>#'+ i.id +'</strong> '+ escHtml(i.title) +
            ' <span class="pf-search-meta">'+ escHtml(i.type) +' • '+ escHtml(i.status) +'</span>'+
          '</div>';
        }).join('');
        $('#pf-post-results').html(html).show();
      });
    }, 250);
  });

  $(document).on('click', '.pf-search-item', function(){
    var id = $(this).data('id');
    $('#pf-post-id').val(id);
    $('#pf-post-results').hide().empty();
  });

  $(document).on('click', '#pf-ai-meta-generate', function(){
    var post_id = parseInt($('#pf-post-id').val(), 10) || 0;
    if(!post_id){ alert('Select a post first'); return; }
    var $out = $('#pf-ai-meta-output');
    setOutput($out, '<div class="pf-loading">Generating…</div>');
    $.post(PAGEFORGE.ajax, { action:'pageforge_ai_generate_meta', nonce: PAGEFORGE.nonce, post_id: post_id }, function(res){
      if(res && res.success){
        var d = res.data;
        setOutput($out,
          '<div class="pf-result"><div><strong>Saved</strong> for Post #'+ d.post_id +'</div>'+
          '<div class="pf-kv"><div><span>Title</span><code>'+ escHtml(d.meta_title) +'</code></div>'+
          '<div><span>Description</span><code>'+ escHtml(d.meta_description) +'</code></div>'+
          (d.focus_keyword ? '<div><span>Keyword</span><code>'+ escHtml(d.focus_keyword) +'</code></div>' : '') +
          '</div></div>'
        );
      } else {
        setOutput($out, '<div class="pf-error">'+ escHtml(res && res.data ? res.data : 'Error') +'</div>');
      }
    });
  });

  // ---------------- Meta Bulk Optimization ----------------
  (function(){
    var bulkRunning = false;
    var bulkOffset = 0;
    var bulkTotal = 0;

    function bulkLog(html){
      var $out = $('#pf-ai-meta-bulk-output');
      if(!$out.length) return;
      $out.append('<div>' + html + '</div>');
      $out.scrollTop(999999);
    }

    function bulkSetProgress(text){
      $('#pf-ai-meta-bulk-progress').text(text || '—');
    }

    function bulkRequest(){
      if(typeof PAGEFORGE === 'undefined' || !PAGEFORGE.ajax || !PAGEFORGE.nonce){
        return $.Deferred().reject().promise();
      }
      var limit = parseInt($('#pf-bulk-limit').val(), 10) || 5;
      if(limit < 1) limit = 1;
      if(limit > 10) limit = 10;
      var pt = $('#pf-bulk-post-type').val() || 'page';
      var st = $('#pf-bulk-status').val() || 'publish';
      return $.post(PAGEFORGE.ajax, {
        action: 'pageforge_ai_generate_meta_bulk',
        nonce: PAGEFORGE.nonce,
        offset: bulkOffset,
        limit: limit,
        status: st,
        post_types: [pt]
      });
    }

    function bulkTick(){
      if(!bulkRunning) return;
      bulkSetProgress('Working… (offset ' + bulkOffset + ')');
      var req;
      try {
        req = bulkRequest();
      } catch(e) {
        bulkLog('<span class="pf-error"><strong>Error</strong> Bulk request failed to start (JS error).</span>');
        bulkRunning = false;
        $('#pf-ai-meta-bulk-start').prop('disabled', false);
        $('#pf-ai-meta-bulk-stop').prop('disabled', true);
        bulkSetProgress('Stopped (JS error).');
        return;
      }
      req.done(function(res){
        if(!bulkRunning) return;
        if(res && res.success){
          var d = res.data || {};
          bulkTotal = parseInt(d.total, 10) || bulkTotal || 0;
          bulkOffset = parseInt(d.next_offset, 10) || (bulkOffset + 1);

          var succ = (d.results && Array.isArray(d.results.success)) ? d.results.success : [];
          var errs = (d.results && Array.isArray(d.results.errors)) ? d.results.errors : [];

          succ.forEach(function(x){
            bulkLog('<strong>OK</strong> Post #' + escHtml(x.post_id) + ' saved');
          });
          errs.forEach(function(x){
            bulkLog('<span class="pf-error"><strong>Error</strong> Post #' + escHtml(x.post_id) + ': ' + escHtml(x.error) + '</span>');
          });

          bulkSetProgress('Processed ' + Math.min(bulkOffset, bulkTotal) + ' / ' + (bulkTotal || '?') + '…');
          if(d.done){
            bulkRunning = false;
            $('#pf-ai-meta-bulk-start').prop('disabled', false);
            $('#pf-ai-meta-bulk-stop').prop('disabled', true);
            bulkSetProgress('Done.');
            return;
          }
          // Next batch
          setTimeout(bulkTick, 250);
        } else {
          var err = (res && res.data) ? res.data : 'Error';
          bulkLog('<span class="pf-error"><strong>Error</strong> ' + escHtml(err) + '</span>');
          bulkRunning = false;
          $('#pf-ai-meta-bulk-start').prop('disabled', false);
          $('#pf-ai-meta-bulk-stop').prop('disabled', true);
          bulkSetProgress('Stopped (error).');
        }
      }).fail(function(){
        bulkLog('<span class="pf-error"><strong>Error</strong> Request failed (nonce / permissions / network).</span>');
        bulkRunning = false;
        $('#pf-ai-meta-bulk-start').prop('disabled', false);
        $('#pf-ai-meta-bulk-stop').prop('disabled', true);
        bulkSetProgress('Stopped (request failed).');
      });
    }

    $(document).on('click', '#pf-ai-meta-bulk-start', function(e){
      e.preventDefault();
      if(bulkRunning) return;
      bulkRunning = true;
      bulkOffset = 0;
      bulkTotal = 0;
      $('#pf-ai-meta-bulk-output').empty();
      // Immediate UI feedback so it doesn't look like nothing happened.
      bulkLog('Starting…');
      $('#pf-ai-meta-bulk-start').prop('disabled', true);
      $('#pf-ai-meta-bulk-stop').prop('disabled', false);
      bulkSetProgress('Starting…');
      bulkTick();
    });

    $(document).on('click', '#pf-ai-meta-bulk-stop', function(e){
      e.preventDefault();
      bulkRunning = false;
      $('#pf-ai-meta-bulk-start').prop('disabled', false);
      $('#pf-ai-meta-bulk-stop').prop('disabled', true);
      bulkSetProgress('Stopped.');
    });
  })();

  $(document).on('click', '#pf-ai-blog-generate', function(){
    var topic = $('#pf-blog-topic').val();
    if(!topic){ alert('Enter a topic'); return; }
    var $out = $('#pf-ai-blog-output');
    setOutput($out, '<div class="pf-loading">Generating draft…</div>');
    $.post(PAGEFORGE.ajax, {
      action: 'pageforge_ai_generate_blog',
      nonce: PAGEFORGE.nonce,
      topic: topic,
      audience: $('#pf-blog-audience').val(),
      keywords_raw: $('#pf-blog-keywords').val(),
      content_length: $('#pf-blog-length').val(),
      post_type: $('#pf-blog-post-type').val(),
      status: $('#pf-blog-status').val(),
      include_image: $('#pf-blog-image').is(':checked') ? 1 : 0
    }, function(res){
      if(res && res.success){
        var d = res.data;
        setOutput($out,
          '<div class="pf-result"><div><strong>Draft created</strong> (#'+ d.post_id +')</div>'+
          '<div><a class="button button-small" href="'+ escHtml(d.edit_url) +'">Edit Post</a></div>'+
          (d.thumbnail_id ? '<div class="pf-note">Featured image attached (attachment #'+ d.thumbnail_id +')</div>' : '') +
          '</div>'
        );
      } else {
        setOutput($out, '<div class="pf-error">'+ escHtml(res && res.data ? res.data : 'Error') +'</div>');
      }
    });
  });

  var lastSeo = null;
  function downloadText(filename, text, type){
    var blob = new Blob([text], {type: type || 'text/plain'});
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url; a.download = filename; a.click();
    URL.revokeObjectURL(url);
  }

  $(document).on('click', '#pf-ai-seo-generate', function(){
    var $out = $('#pf-ai-seo-output');
    setOutput($out, '<div class="pf-loading">Generating report…</div>');
    $('#pf-ai-seo-download-json, #pf-ai-seo-download-md').prop('disabled', true);
    $.post(PAGEFORGE.ajax, {
      action: 'pageforge_ai_seo_report',
      nonce: PAGEFORGE.nonce,
      include_urls: $('#pf-seo-include-urls').is(':checked') ? 1 : 0,
      post_types: ['page','post','pageforge_page']
    }, function(res){
      if(res && res.success){
        lastSeo = res.data;
        var md = (lastSeo.data && lastSeo.data.markdown) ? lastSeo.data.markdown : '';
        setOutput($out,
          '<div class="pf-result">'+
          '<div class="pf-note">Generated at: '+ new Date((lastSeo.generated_at||0)*1000).toLocaleString() +'</div>'+
          '<pre class="pf-pre">'+ escHtml(md || JSON.stringify(lastSeo.data || {}, null, 2)) +'</pre>'+
          '</div>'
        );
        $('#pf-ai-seo-download-json, #pf-ai-seo-download-md').prop('disabled', false);
      } else {
        setOutput($out, '<div class="pf-error">'+ escHtml(res && res.data ? res.data : 'Error') +'</div>');
      }
    });
  });

  $(document).on('click', '#pf-ai-seo-download-json', function(){
    if(!lastSeo) return;
    downloadText('pageforge-seo-report.json', JSON.stringify(lastSeo, null, 2), 'application/json');
  });
  $(document).on('click', '#pf-ai-seo-download-md', function(){
    if(!lastSeo) return;
    var md = (lastSeo.data && lastSeo.data.markdown) ? lastSeo.data.markdown : '';
    downloadText('pageforge-seo-report.md', md || '', 'text/markdown');
  });

  // ---------------- Site Planner (Chat UI) ----------------
  var pfThreadsKey = 'pageforge_site_planner_threads_v1';
  var pfLegacyChatKey = 'pageforge_site_planner_v1';
  var pfChatState = null;

  function pfLoadLegacyMessages(){
    try {
      var raw = localStorage.getItem(pfLegacyChatKey);
      var data = raw ? JSON.parse(raw) : null;
      if(data && Array.isArray(data.messages)) return data.messages;
    } catch(e) {}
    return [];
  }

  function pfLoadThreadsState(){
    try {
      var raw = localStorage.getItem(pfThreadsKey);
      if(!raw) return null;
      var data = JSON.parse(raw);
      if(!data || !Array.isArray(data.threads)) return null;
      return data;
    } catch(e) {
      return null;
    }
  }

  function pfSaveThreadsState(state){
    try {
      localStorage.setItem(pfThreadsKey, JSON.stringify(state || {active_id:null, threads:[]}));
    } catch(e) {}
  }

  function pfDeriveTitleFromMessages(msgs){
    if(!Array.isArray(msgs) || !msgs.length) return 'New site plan';
    var text = '';
    for(var i=0;i<msgs.length;i++){
      if(msgs[i].role === 'user' && msgs[i].content){ text = String(msgs[i].content); break; }
    }
    if(!text && msgs[0] && msgs[0].content){
      text = String(msgs[0].content);
    }
    text = text.replace(/\s+/g,' ').trim();
    if(text.length > 60) text = text.substring(0,57) + '…';
    return text || 'New site plan';
  }

  function pfEnsureThreadsState(){
    var state = pfLoadThreadsState();
    if(!state || !Array.isArray(state.threads)){
      state = {active_id:null, threads:[]};
    }
    if(!state.threads.length){
      var legacy = pfLoadLegacyMessages();
      var now = Math.floor(Date.now()/1000);
      if(legacy && legacy.length){
        var tid = 't-'+ now;
        state.active_id = tid;
        state.threads.push({
          id: tid,
          title: pfDeriveTitleFromMessages(legacy),
          created_at: now,
          updated_at: now,
          messages: legacy
        });
        try { localStorage.removeItem(pfLegacyChatKey); } catch(e) {}
      } else {
        var id = 't-'+ now;
        state.active_id = id;
        state.threads.push({
          id: id,
          title: 'New site plan',
          created_at: now,
          updated_at: now,
          messages: []
        });
      }
      pfSaveThreadsState(state);
    }
    return state;
  }

  function pfGetActiveThread(){
    if(!pfChatState || !Array.isArray(pfChatState.threads)) return null;
    var id = pfChatState.active_id;
    var thread = null;
    pfChatState.threads.forEach(function(t){ if(t.id === id) thread = t; });
    if(!thread && pfChatState.threads.length){
      thread = pfChatState.threads[0];
      pfChatState.active_id = thread.id;
      pfSaveThreadsState(pfChatState);
    }
    return thread;
  }

  function pfUpsertThread(thread){
    if(!pfChatState) return;
    var found = false;
    pfChatState.threads = (pfChatState.threads || []).map(function(t){
      if(t.id === thread.id){ found = true; return thread; }
      return t;
    });
    if(!found){ pfChatState.threads.push(thread); }
    pfSaveThreadsState(pfChatState);
  }

  function pfFormatRelativeTime(ts){
    var now = Math.floor(Date.now()/1000);
    var d = Math.max(0, now - (parseInt(ts,10) || 0));
    if(d < 60) return 'just now';
    if(d < 3600) return Math.round(d/60) + ' min ago';
    if(d < 86400) return Math.round(d/3600) + ' hr ago';
    var days = Math.round(d/86400);
    return days + (days === 1 ? ' day ago' : ' days ago');
  }

  function pfRenderConversationList(){
    var $list = $('#pf-ai-conversation-list');
    if(!$list.length || !pfChatState) return;
    $list.empty();
    (pfChatState.threads || []).forEach(function(t){
      var active = t.id === pfChatState.active_id;
      var updated = t.updated_at || t.created_at || Math.floor(Date.now()/1000);
      var meta = 'Updated ' + pfFormatRelativeTime(updated);
      var cls = 'pf-ai-conversation' + (active ? ' is-active' : '');
      $list.append(
        '<button type="button" class="'+ cls +'" data-thread-id="'+ escHtml(t.id || '') +'">'+
          '<div class="pf-ai-conversation-title">'+ escHtml(t.title || 'Conversation') +'</div>'+
          '<div class="pf-ai-conversation-meta">'+ escHtml(meta) +'</div>'+
        '</button>'
      );
    });
  }

  function pfRenderChat(){
    var $log = $('#pf-chat-log');
    var $empty = $('#pf-ai-empty-state');
    if(!$log.length) return;
    $log.empty();
    var thread = pfGetActiveThread();
    var messages = (thread && Array.isArray(thread.messages)) ? thread.messages : [];
    if(!messages.length){
      if($empty.length) $empty.prop('hidden', false);
      return;
    }
    if($empty.length) $empty.prop('hidden', true);
    messages.forEach(function(m){
      var role = m.role === 'assistant' ? 'assistant' : 'user';
      var wrapperCls = 'pf-msg ' + (role === 'assistant' ? 'is-assistant' : 'is-user');
      var bubbleCls = 'pf-bubble';
      if(m.error){ wrapperCls += ' pf-msg-error'; bubbleCls += ' pf-bubble--error'; }
      var html = role === 'assistant' ? mdToHtml(m.content) : '<div>' + escHtml(m.content) + '</div>';
      $log.append('<div class="'+ wrapperCls +'"><div class="'+ bubbleCls +'">'+ html +'</div></div>');
    });
    $log.scrollTop(999999);
  }

  function plannerRequest(messages){
    $('#pf-chat-typing').show();
    $('#pf-chat-send').prop('disabled', true);
    var includeUrls = $('#pf-chat-include-urls').is(':checked') ? 1 : 0;
    return $.post(PAGEFORGE.ajax, {
      action: 'pageforge_ai_site_planner',
      nonce: PAGEFORGE.nonce,
      messages: JSON.stringify(messages || []),
      include_urls: includeUrls,
      post_types: ['page','post','pageforge_page']
    }).always(function(){
      $('#pf-chat-typing').hide();
      $('#pf-chat-send').prop('disabled', false);
    });
  }

  function initSitePlanner(){
    if(!$('#pf-site-planner').length) return;

    pfChatState = pfEnsureThreadsState();
    pfRenderConversationList();
    pfRenderChat();

    var $input = $('#pf-chat-input');
    if($input.length){
      var maxHeight = 180;
      function autoSize(){
        this.style.height = 'auto';
        var newH = Math.min(maxHeight, this.scrollHeight || 0);
        if(newH < 46) newH = 46;
        this.style.height = newH + 'px';
      }
      $input.on('input', autoSize);
      if($input[0]) autoSize.call($input[0]);
      $input.on('keydown', function(e){
        if(e.key === 'Enter' && !e.shiftKey){
          e.preventDefault();
          $('#pf-chat-send').click();
        }
      });
    }

    $(document).on('click', '#pf-chat-new, #pf-ai-new-chat', function(e){
      e.preventDefault();
      if(!pfChatState) return;
      var now = Math.floor(Date.now()/1000);
      var tid = 't-'+ now +'-'+ Math.floor(Math.random()*1000);
      var thread = {
        id: tid,
        title: 'New site plan',
        created_at: now,
        updated_at: now,
        messages: []
      };
      pfChatState.threads.unshift(thread);
      pfChatState.active_id = tid;
      pfSaveThreadsState(pfChatState);
      pfRenderConversationList();
      pfRenderChat();
      if($input.length){ $input.val('').trigger('input').focus(); }
    });

    $(document).on('click', '.pf-ai-conversation', function(){
      var id = $(this).data('thread-id');
      if(!id || !pfChatState) return;
      pfChatState.active_id = String(id);
      pfSaveThreadsState(pfChatState);
      pfRenderConversationList();
      pfRenderChat();
    });

    $(document).on('click', '#pf-chat-export', function(e){
      e.preventDefault();
      var thread = pfGetActiveThread();
      var messages = thread && Array.isArray(thread.messages) ? thread.messages : [];
      var md = messages.map(function(m){
        var who = m.role === 'assistant' ? 'Assistant' : 'You';
        return '## ' + who + '\n\n' + String(m.content||'') + '\n';
      }).join('\n');
      var slug = (thread && thread.title ? String(thread.title) : 'site-plan').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/(^-|-$)/g,'');
      var filename = 'pageforge-site-plan-' + slug + '.md';
      downloadText(filename, md, 'text/markdown');
    });

    $(document).on('click', '.pf-chip', function(){
      var p = $(this).data('prompt');
      if(!p) return;
      var $in = $('#pf-chat-input');
      var cur = $in.val();
      $in.val((cur ? (cur + "\n\n") : '') + p).focus();
    });

    $(document).on('click', '#pf-chat-send', function(e){
      e.preventDefault();
      if($(this).prop('disabled')) return;
      var thread = pfGetActiveThread();
      if(!thread) return;
      var text = String($('#pf-chat-input').val() || '').trim();
      if(!text){ $('#pf-chat-input').focus(); return; }
      $('#pf-chat-input').val('').trigger('input');
      var now = Math.floor(Date.now()/1000);
      thread.messages = (thread.messages || []).concat([{role:'user', content:text}]);
      thread.updated_at = now;
      if(!thread.title || thread.title === 'New site plan'){
        thread.title = pfDeriveTitleFromMessages(thread.messages);
      }
      pfUpsertThread(thread);
      pfRenderConversationList();
      pfRenderChat();

      plannerRequest(thread.messages).done(function(res){
        var msg;
        if(res && res.success && res.data && res.data.reply_markdown){
          msg = {role:'assistant', content: res.data.reply_markdown};
        } else {
          var err = (res && res.data) ? res.data : 'Error';
          msg = {role:'assistant', content: 'Error: ' + String(err), error:true};
        }
        thread.messages.push(msg);
        thread.updated_at = Math.floor(Date.now()/1000);
        pfUpsertThread(thread);
        pfRenderConversationList();
        pfRenderChat();
      }).fail(function(){
        var msg = {role:'assistant', content: 'Error: Request failed. Check network / API key.', error:true};
        thread.messages.push(msg);
        thread.updated_at = Math.floor(Date.now()/1000);
        pfUpsertThread(thread);
        pfRenderConversationList();
        pfRenderChat();
      });
    });
  }

  initSitePlanner();
})(jQuery);

