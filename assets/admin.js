/* global jQuery, PAGEFORGE */
(function($){
  var pfI18n = (typeof PAGEFORGE !== 'undefined' && PAGEFORGE.i18n) ? PAGEFORGE.i18n : {};
  function t(key){ return pfI18n[key] || key; }
  function fmt(key){
    var text = t(key);
    var args = Array.prototype.slice.call(arguments, 1);
    args.forEach(function(value, index){
      var numbered = new RegExp('%' + (index + 1) + '\\$s', 'g');
      text = text.replace(numbered, value);
      text = text.replace('%s', value);
    });
    return text;
  }
  function log(msg){ $('#pf-log').append('<div>> '+ msg +'</div>').scrollTop(999999); }

  function toggleAiProviderFields(){
    var defaultProvider = (typeof PAGEFORGE !== 'undefined' && PAGEFORGE.active_provider) ? PAGEFORGE.active_provider : 'openai';
    var provider = $('#pf-ai-provider').val() || defaultProvider;
    $('[data-ai-provider-section]').hide();
    $('[data-ai-provider-section="'+ provider +'"]').show();
  }

  $(document).on('change', '#pf-ai-provider', toggleAiProviderFields);
  $(toggleAiProviderFields);

  // Secret fields never carry the real stored value; they hold an
  // "unchanged" sentinel so a plain save leaves the credential alone.
  // Clear that sentinel the moment the admin interacts with the field so
  // typing replaces it instead of appending to invisible placeholder text.
  var PF_SECRET_UNCHANGED = '__pageforge_unchanged__';
  $(document).on('focus input', '.pf-secret-input', function(){
    if (this.value === PF_SECRET_UNCHANGED) { this.value = ''; }
  });
  $(document).on('click', '.pf-secret-remove', function(){
    var name = $(this).data('pf-secret-clear');
    var $field = $('.pf-secret-input[name="' + name + '"]');
    $field.val('').trigger('focus');
    $(this).closest('td').find('.pf-secret-status').text(t('notConfigured') || 'Not configured').removeClass('ok').addClass('warn');
    $(this).remove();
  });

  $(document).on('submit', '#pf-settings-form', function(e){
    e.preventDefault();
    $.post(PAGEFORGE.ajax, {
      action: 'pageforge_save_settings',
      nonce: PAGEFORGE.nonce,
      data: $(this).serialize()
    }, function(res){
      if(res.success){ alert(t('settingsSaved')); location.reload(); } else { alert(t('errorPrefix') + ' ' + res.data); }
    });
  });

  // ---------------- MCP / AI Clients (Connections) ----------------
  //
  // Two authentication models, deliberately never mixed in this UI:
  //
  //  * Remote connectors (ChatGPT, Claude) authenticate with OAuth. PageForge
  //    shows them the server URL and readiness; it mints nothing, because these
  //    clients discover OAuth from the endpoint and never accept a pasted
  //    token. The old "Connect" button minted a token here, which is why the
  //    connection could never complete.
  //
  //  * Every other client supplies an Authorization header itself, so it gets a
  //    Personal Access Token.

  var PF_MCP = (window.PAGEFORGE && PAGEFORGE.mcp) || {};

  // Config presets. Each uses the shape that client documents for an HTTP MCP
  // server; the label names the file it belongs in so the operator is not left
  // guessing where to paste it. Only clients that accept a custom Authorization
  // header appear here at all.
  function pfMcpConfigSnippets(token, endpoint){
    var auth = { 'X-PageForge-MCP-API-Key': token };
    return [
      {
        id: 'vscode',
        label: 'VS Code / GitHub Copilot',
        hint: '.vscode/mcp.json',
        body: JSON.stringify({
          servers: { pageforge: { type: 'http', url: endpoint, headers: auth } }
        }, null, 2)
      },
      {
        id: 'cursor',
        label: 'Cursor',
        hint: '.cursor/mcp.json',
        body: JSON.stringify({
          mcpServers: { pageforge: { url: endpoint, headers: auth } }
        }, null, 2)
      },
      {
        id: 'claude_code',
        label: 'Claude Code',
        hint: '.mcp.json',
        body: JSON.stringify({
          mcpServers: { pageforge: { type: 'http', url: endpoint, headers: auth } }
        }, null, 2)
      },
      {
        id: 'generic',
        label: t('mcpGenericClient') || 'Generic MCP client',
        hint: 'streamable HTTP',
        body: JSON.stringify({
          mcpServers: { pageforge: { transport: 'streamable-http', url: endpoint, headers: auth } }
        }, null, 2)
      }
    ];
  }

  function pfMcpAnnounce(message){
    $('#pf-mcp-live').text('');
    // Re-setting after a tick makes screen readers announce a repeated message.
    window.setTimeout(function(){ $('#pf-mcp-live').text(message); }, 50);
  }

  function pfMcpCopy(text, announcement){
    function done(ok){
      pfMcpAnnounce(ok
        ? (announcement || (t('copied') || 'Copied to clipboard'))
        : (t('copyFailed') || 'Copy failed. Select the text and copy it manually.'));
    }
    if(navigator.clipboard && navigator.clipboard.writeText){
      navigator.clipboard.writeText(text).then(function(){ done(true); }, function(){ done(false); });
      return;
    }
    done(false);
  }

  // ---- dialog focus management -------------------------------------------
  var pfMcpLastFocus = null;

  function pfMcpOpenDialog(id){
    pfMcpLastFocus = document.activeElement;
    var $dialog = $('#' + id).prop('hidden', false);
    var $box = $dialog.find('.pf-mcp-dialog-box').first();
    $box.attr('tabindex', '-1').trigger('focus');
    return $dialog;
  }

  function pfMcpCloseDialog($dialog){
    $dialog.prop('hidden', true);
    if(pfMcpLastFocus && pfMcpLastFocus.focus){ pfMcpLastFocus.focus(); }
    pfMcpLastFocus = null;
  }

  // Trap Tab inside an open dialog, and close on Escape.
  $(document).on('keydown', '.pf-mcp-dialog', function(e){
    if(e.key === 'Escape'){
      e.preventDefault();
      $(this).find('.pf-mcp-dialog-close').first().trigger('click');
      return;
    }
    if(e.key !== 'Tab'){ return; }
    var focusable = $(this).find('a[href], button:not([disabled]), input:not([disabled]), [tabindex="0"]').filter(':visible');
    if(!focusable.length){ return; }
    var first = focusable[0];
    var last = focusable[focusable.length - 1];
    if(e.shiftKey && document.activeElement === first){ e.preventDefault(); last.focus(); }
    else if(!e.shiftKey && document.activeElement === last){ e.preventDefault(); first.focus(); }
  });

  // ---- personal access tokens --------------------------------------------
  function pfMcpStatusClass(state){
    if(state === 'in_use'){ return 'ok'; }
    if(state === 'revoked' || state === 'expired'){ return 'bad'; }
    return 'warn';
  }

  function pfRenderMcpCredentials(list){
    var $out = $('#pf-mcp-credential-list');
    if(!list || !list.length){
      $out.html('<p class="pf-mcp-empty">' + escHtml(t('mcpNoTokens') || 'No personal access tokens yet.') + '</p>');
      return;
    }
    var rows = list.map(function(c){
      var status = (c.status && c.status.state) || 'never_used';
      // The label comes from the server so "never used" is never rendered as
      // "connected": a credential only becomes a connection once a client has
      // authenticated with it.
      var statusText = (c.status && c.status.label) || '';
      var lastUsed = c.last_used ? new Date(c.last_used * 1000).toLocaleString() : (t('never') || 'Never');
      var isDead = status === 'revoked' || status === 'expired';
      return '<div class="pf-mcp-credential-row" data-id="' + escHtml(c.id) + '">' +
        '<div class="pf-mcp-credential-main">' +
          '<strong>' + escHtml(c.label) + '</strong>' +
          '<span class="pf-badge ' + pfMcpStatusClass(status) + '">' + escHtml(statusText) + '</span>' +
        '</div>' +
        '<div class="pf-mcp-credential-meta">' +
          '<span>' + escHtml(c.client) + '</span>' +
          '<span><code>' + escHtml(c.fingerprint || '') + '</code></span>' +
          '<span>' + escHtml((c.scopes || []).join(', ')) + '</span>' +
          '<span>' + escHtml(t('lastActivity') || 'Last activity') + ': ' + escHtml(lastUsed) + '</span>' +
        '</div>' +
        (isDead ? '' :
          '<div class="pf-mcp-credential-actions">' +
            '<button type="button" class="button-link pf-mcp-rotate" data-id="' + escHtml(c.id) + '">' + escHtml(t('rotate') || 'Rotate') + '</button>' +
            '<button type="button" class="button-link pf-mcp-revoke" data-id="' + escHtml(c.id) + '">' + escHtml(t('revoke') || 'Revoke') + '</button>' +
          '</div>') +
      '</div>';
    });
    $out.html(rows.join(''));
  }

  function pfRenderMcpGrants(list){
    var $out = $('#pf-mcp-grant-list');
    if(!$out.length){ return; }
    if(!list || !list.length){
      $out.html('<p class="pf-mcp-empty">' + escHtml(t('mcpNoGrants') || 'No AI client has completed an OAuth connection yet.') + '</p>');
      return;
    }
    var rows = list.map(function(g){
      var issued = g.issued ? new Date(g.issued * 1000).toLocaleString() : '';
      var expires = g.expires ? new Date(g.expires * 1000).toLocaleString() : '';
      return '<div class="pf-mcp-credential-row" data-family="' + escHtml(g.family) + '">' +
        '<div class="pf-mcp-credential-main">' +
          '<strong>' + escHtml(g.client_name || g.client_id) + '</strong>' +
          '<span class="pf-badge ok">' + escHtml(t('mcpGrantActive') || 'Connected') + '</span>' +
        '</div>' +
        '<div class="pf-mcp-credential-meta">' +
          '<span><code>' + escHtml(g.fingerprint) + '</code></span>' +
          '<span>' + escHtml((g.scopes || []).join(', ')) + '</span>' +
          '<span>' + escHtml(t('mcpGrantIssued') || 'Approved') + ': ' + escHtml(issued) + '</span>' +
          '<span>' + escHtml(t('mcpGrantExpires') || 'Expires') + ': ' + escHtml(expires) + '</span>' +
        '</div>' +
        '<div class="pf-mcp-credential-actions">' +
          '<button type="button" class="button-link pf-mcp-grant-revoke" data-family="' + escHtml(g.family) + '">' + escHtml(t('mcpDisconnect') || 'Disconnect') + '</button>' +
        '</div>' +
      '</div>';
    });
    $out.html(rows.join(''));
  }

  function pfRenderMcpOauthClients(list){
    var $out = $('#pf-mcp-oauth-client-list');
    if(!$out.length){ return; }
    if(!list || !list.length){
      $out.html('<p class="pf-mcp-empty">' + escHtml(t('mcpNoOauthClients') || 'No OAuth clients yet. Most connectors do not need one.') + '</p>');
      return;
    }
    $out.html(list.map(function(c){
      return '<div class="pf-mcp-credential-row" data-client-id="' + escHtml(c.client_id) + '">' +
        '<div class="pf-mcp-credential-main">' +
          '<strong>' + escHtml(c.client_name) + '</strong>' +
          '<span class="pf-badge ' + (c.revoked ? 'bad' : 'ok') + '">' + escHtml(c.revoked ? (t('revoked') || 'Revoked') : (t('active') || 'Active')) + '</span>' +
        '</div>' +
        '<div class="pf-mcp-credential-meta">' +
          '<span><code>' + escHtml(c.client_id) + '</code></span>' +
          '<span>' + escHtml(c.type) + '</span>' +
          '<span>' + escHtml((c.redirect_uris || []).join(' ')) + '</span>' +
        '</div>' +
        (c.revoked ? '' :
          '<div class="pf-mcp-credential-actions">' +
            '<button type="button" class="button-link pf-mcp-client-revoke" data-client-id="' + escHtml(c.client_id) + '">' + escHtml(t('revoke') || 'Revoke') + '</button>' +
          '</div>') +
      '</div>';
    }).join(''));
  }

  function pfShowMcpOauthClient(data){
    $('#pf-mcp-client-id').val(data.client_id || '');
    $('#pf-mcp-client-secret').val(data.client_secret || '');
    $('#pf-mcp-client-redirect').text((data.redirect_uris || []).join(' '));
    pfMcpOpenDialog('pf-mcp-client-dialog');
    pfMcpAnnounce(t('mcpOauthClientCreated') || 'OAuth client created. Copy the secret now; it cannot be shown again.');
  }

  function pfLoadMcpCredentials(){
    $.post(PAGEFORGE.ajax, { action: 'pageforge_mcp_list_credentials', nonce: PAGEFORGE.nonce }, function(res){
      if(res && res.success){
        pfRenderMcpCredentials(res.data.credentials);
        pfRenderMcpGrants(res.data.grants);
        pfRenderMcpOauthClients(res.data.oauth_clients);
        pfMcpRenderClientStates(res.data.client_states || {});
      }
    });
  }

  // Connector state is evidence-based: "Connected" only appears when PageForge
  // has actually observed an authenticated request from that client.
  function pfMcpRenderClientStates(states){
    $('[data-pf-mcp-state-for]').each(function(){
      var $el = $(this);
      var state = states[$el.data('pf-mcp-state-for')];
      if(!state){ return; }
      $el.text(state.label).attr('class', 'pf-mcp-client-state pf-badge ' + (state.badge || 'warn'));
    });
  }

  function pfShowMcpToken(token, label, scopes){
    var endpoint = PF_MCP.endpoint || $('#pf-mcp-endpoint').text();
    $('#pf-mcp-token-value').val(token);
    $('#pf-mcp-token-label').text(label || '');
    $('#pf-mcp-token-scopes').text((scopes || []).join(', '));

    var snippets = pfMcpConfigSnippets(token, endpoint);
    var $tabs = $('#pf-mcp-config-tabs').empty();
    var $snippet = $('#pf-mcp-config-snippet');

    snippets.forEach(function(preset, index){
      var $tab = $('<button type="button" class="button pf-mcp-config-tab" role="tab"></button>')
        .text(preset.label)
        .attr('title', preset.hint)
        .attr('aria-selected', index === 0 ? 'true' : 'false');
      if(index === 0){ $tab.addClass('is-active'); $snippet.text(preset.body); }
      $tab.on('click', function(){
        $tabs.find('.pf-mcp-config-tab').removeClass('is-active').attr('aria-selected', 'false');
        $tab.addClass('is-active').attr('aria-selected', 'true');
        $snippet.text(preset.body);
        pfMcpAnnounce(preset.label + ' ' + (t('mcpConfigShown') || 'configuration shown'));
      });
      $tabs.append($tab);
    });

    pfMcpOpenDialog('pf-mcp-token-dialog');
    pfMcpAnnounce(t('mcpTokenCreated') || 'Personal access token created. Copy it now; it cannot be shown again.');
  }

  // ---- contextual help sidebar -------------------------------------------
  //
  // The right column used to be empty. It now shows the setup steps for
  // whichever client the operator picked, so the instructions are readable
  // alongside the endpoint rather than only inside a modal.
  function pfMcpShowHelp(client, label){
    var preset = (PF_MCP.connectors && PF_MCP.connectors[client]) || {};
    var $detail = $('#pf-mcp-help-detail');
    if(!$detail.length){ return; }

    $('#pf-mcp-help-icon').attr('src', preset.icon || '').attr('alt', '');
    $('#pf-mcp-help-title').text((t('mcpSetUp') || 'Set up') + ' ' + label);
    $('#pf-mcp-help-intro').text(preset.intro || '');
    $('#pf-mcp-help-caveat').text(preset.caveat || '');

    var $steps = $('#pf-mcp-help-steps').empty();
    (preset.steps || []).forEach(function(step){
      $steps.append($('<li></li>').text(step));
    });

    $('#pf-mcp-help-default').prop('hidden', true);
    $detail.prop('hidden', false);
  }

  function pfMcpResetHelp(){
    $('#pf-mcp-help-detail').prop('hidden', true);
    $('#pf-mcp-help-default').prop('hidden', false);
  }

  $(document).on('click', '#pf-mcp-help-back', function(){ pfMcpResetHelp(); });

  // ---- remote connector setup -------------------------------------------
  function pfMcpOpenOauthSetup(client, label){
    var preset = (PF_MCP.connectors && PF_MCP.connectors[client]) || {};
    $('#pf-mcp-oauth-title').text((t('mcpSetUp') || 'Set up') + ' ' + label);
    $('#pf-mcp-oauth-icon').attr('src', preset.icon || '').attr('alt', label);
    $('#pf-mcp-oauth-intro').text(preset.intro || '');
    $('#pf-mcp-oauth-caveat').text(preset.caveat || '');
    $('#pf-mcp-oauth-endpoint').text(PF_MCP.endpoint || '');
    $('#pf-mcp-oauth-docs').attr('href', preset.docs || '#');

    var $steps = $('#pf-mcp-oauth-steps').empty();
    (preset.steps || []).forEach(function(step){
      $steps.append($('<li></li>').text(step));
    });

    var $readiness = $('#pf-mcp-oauth-readiness').text(t('checking') || 'Checking…');
    pfMcpOpenDialog('pf-mcp-oauth-dialog');
    pfMcpOauthTest($readiness);
  }

  // Tests PageForge's own OAuth discovery chain. It deliberately does NOT
  // claim the client is connected: that can only be known once the client
  // authenticates, and is reported separately from the grant list.
  function pfMcpOauthTest($target){
    var $readiness = $target || $('#pf-mcp-oauth-readiness');
    $readiness.text(t('checking') || 'Checking…').attr('class', '');
    $.post(PAGEFORGE.ajax, { action: 'pageforge_mcp_oauth_status', nonce: PAGEFORGE.nonce }, function(res){
      if(!res || !res.success){
        $readiness.text(t('mcpReadinessUnknown') || 'PageForge could not verify its own OAuth discovery.').attr('class', 'pf-badge warn');
        return;
      }
      $readiness.text(res.data.label).attr('class', 'pf-badge ' + res.data.badge);
      pfMcpAnnounce(res.data.label);
    });
  }

  // ---- events ------------------------------------------------------------
  $(document).on('click', '.pf-mcp-dialog-close', function(){
    var $dialog = $(this).closest('.pf-mcp-dialog');
    // Clear one-time secrets from the DOM on close.
    $dialog.find('#pf-mcp-token-value, #pf-mcp-client-id, #pf-mcp-client-secret').val('');
    $dialog.find('#pf-mcp-config-snippet').text('');
    pfMcpCloseDialog($dialog);
    pfLoadMcpCredentials();
  });

  $(document).on('click', '[data-pf-copy-input]', function(){
    pfMcpCopy($('#' + $(this).data('pf-copy-input')).val(), t('copied') || 'Copied to clipboard');
  });

  $(document).on('click', '#pf-mcp-create-oauth-client', function(){
    var name = window.prompt(t('mcpOauthClientNamePrompt') || 'Name for this OAuth client (for example: Claude connector)');
    if(name === null){ return; }
    var redirect = window.prompt(t('mcpOauthClientRedirectPrompt') || 'Redirect URI the connector gave you (https://..., or http://127.0.0.1:PORT/... for a local client)');
    if(redirect === null || !redirect){ return; }
    $.post(PAGEFORGE.ajax, {
      action: 'pageforge_mcp_create_oauth_client',
      nonce: PAGEFORGE.nonce,
      name: name,
      redirect_uris: [redirect]
    }, function(res){
      if(res && res.success){ pfShowMcpOauthClient(res.data); }
      else { alert(t('errorPrefix') + ' ' + (res && res.data)); }
    });
  });

  $(document).on('click', '.pf-mcp-client-revoke', function(){
    var id = $(this).data('client-id');
    if(!window.confirm(t('mcpOauthClientRevokeConfirm') || 'Revoke this OAuth client? Any connector using it loses access immediately.')) return;
    $.post(PAGEFORGE.ajax, { action: 'pageforge_mcp_revoke_oauth_client', nonce: PAGEFORGE.nonce, client_id: id }, function(res){
      if(res && res.success){ pfLoadMcpCredentials(); }
    });
  });

  $(document).on('click', '#pf-mcp-copy-token', function(){
    pfMcpCopy($('#pf-mcp-token-value').val(), t('mcpTokenCopied') || 'Token copied to clipboard');
  });

  $(document).on('click', '#pf-mcp-copy-config', function(){
    pfMcpCopy($('#pf-mcp-config-snippet').text(), t('mcpConfigCopied') || 'Configuration copied to clipboard');
  });

  $(document).on('click', '[data-pf-copy-target]', function(){
    pfMcpCopy($('#' + $(this).data('pf-copy-target')).text(), t('mcpUrlCopied') || 'Server URL copied to clipboard');
  });

  $(document).on('click', '#pf-mcp-oauth-test', function(){ pfMcpOauthTest(); });

  $(document).on('change', '#pf-mcp-enable', function(){
    var enabled = $(this).is(':checked');
    $.post(PAGEFORGE.ajax, { action: 'pageforge_mcp_toggle', nonce: PAGEFORGE.nonce, enabled: enabled ? 1 : 0 }, function(res){
      if(res && res.success){
        $('#pf-mcp-state-badge').text(enabled ? (t('enabled') || 'Enabled') : (t('disabled') || 'Disabled'))
          .toggleClass('ok', enabled).toggleClass('warn', !enabled);
        pfLoadMcpCredentials();
      }
    });
  });

  $(document).on('click', '.pf-mcp-oauth-setup', function(){
    var client = $(this).data('pf-mcp-client');
    var label = $(this).data('pf-mcp-label');
    // The sidebar keeps the steps visible after the dialog is dismissed.
    pfMcpShowHelp(client, label);
    pfMcpOpenOauthSetup(client, label);
  });

  // Selecting the "other clients" card explains the personal access token
  // route in the sidebar, which is a different mechanism from OAuth.
  $(document).on('click', '.pf-mcp-create-pat', function(){
    pfMcpShowHelp('other', t('mcpOtherClients') || 'other MCP clients');
  });

  $(document).on('click', '.pf-mcp-create-pat', function(){
    var client = $(this).data('pf-mcp-client');
    var label = $(this).data('pf-mcp-label');
    $.post(PAGEFORGE.ajax, {
      action: 'pageforge_mcp_create_credential',
      nonce: PAGEFORGE.nonce,
      client: client,
      label: label
    }, function(res){
      if(res && res.success){ pfShowMcpToken(res.data.token, res.data.label || label, res.data.scopes); }
      else { alert(t('errorPrefix') + ' ' + (res && res.data)); }
    });
  });

  $(document).on('click', '.pf-mcp-rotate', function(){
    var id = $(this).data('id');
    if(!window.confirm(t('mcpRotateConfirm') || 'Rotate this token? The old token stops working immediately.')) return;
    $.post(PAGEFORGE.ajax, { action: 'pageforge_mcp_rotate_credential', nonce: PAGEFORGE.nonce, id: id }, function(res){
      if(res && res.success){ pfShowMcpToken(res.data.token, res.data.label || '', res.data.scopes); }
    });
  });

  $(document).on('click', '.pf-mcp-revoke', function(){
    var id = $(this).data('id');
    if(!window.confirm(t('mcpRevokeConfirm') || 'Revoke this token? The client will lose access immediately.')) return;
    $.post(PAGEFORGE.ajax, { action: 'pageforge_mcp_revoke_credential', nonce: PAGEFORGE.nonce, id: id }, function(res){
      if(res && res.success){ pfLoadMcpCredentials(); }
    });
  });

  $(document).on('click', '.pf-mcp-grant-revoke', function(){
    var family = $(this).data('family');
    if(!window.confirm(t('mcpGrantRevokeConfirm') || 'Disconnect this client? It will need to be authorized again.')) return;
    $.post(PAGEFORGE.ajax, { action: 'pageforge_mcp_revoke_grant', nonce: PAGEFORGE.nonce, family: family }, function(res){
      if(res && res.success){ pfLoadMcpCredentials(); }
    });
  });

  if($('#pf-mcp-credential-list').length){ pfLoadMcpCredentials(); }

  // Data Sources page
  $(document).on('submit', '#pf-upload-form', function(e){
    e.preventDefault();
    var fd = new FormData(this);
    fd.append('action', 'pageforge_upload_csv');
    fd.append('nonce', PAGEFORGE.nonce);
    $.ajax({ url: PAGEFORGE.ajax, method: 'POST', data: fd, processData:false, contentType:false })
      .done(function(res){
        if(res.success){ $('#pf-upload-result').html(t('uploadedPrefix') + ' ' + escHtml(res.data.title)); loadCSVs(); }
        else alert(t('uploadFailedPrefix') + ' ' + res.data);
      });
  });

  function loadCSVs(){
    var $tbody = $('#pf-csv-table tbody');
    var $select = $('#pf-csv');
    var hasTable = $tbody.length;
    var hasSelect = $select.length;
    if(!hasTable && !hasSelect) return;

    if(hasTable) $tbody.empty();
    if(hasSelect) { $select.empty().append('<option value="">' + escHtml(t('loading')) + '</option>'); }

    $.post(PAGEFORGE.ajax, { action:'pageforge_list_csv', nonce: PAGEFORGE.nonce }, function(res){
      // Clear previous entries before repopulating to avoid duplicates
      if(hasTable) $tbody.empty();
      if(hasSelect) $select.empty();

      if(!res || res.success !== true){
        var msg = t('errorPrefix') + ' ' + (res && res.data ? res.data : t('unableToLoadCsvs'));
        if(hasTable) $tbody.append('<tr><td colspan="2">'+ msg +'</td></tr>');
        if(hasSelect) $select.append('<option value="">'+ msg +'</option>');
        return;
      }

      var items = Array.isArray(res.data) ? res.data : [];
      if(!items.length){
        if(hasTable) $tbody.append('<tr><td colspan="2">' + escHtml(t('noCsvsYetTable')) + '</td></tr>');
        if(hasSelect) $select.append('<option value="">' + escHtml(t('noCsvsYet')) + '</option>');
        return;
      }

      if(hasSelect) $select.append('<option value="">' + escHtml(t('selectCsv')) + '</option>');
      items.forEach(function(i){
        var title = i.title || t('untitled');
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

  $(document).on('change', '#pageforge-template-post-type', function(){
    var type = $(this).val() || $(this).attr('data-global-type');
    $('#pageforge-parent-page-row').prop('hidden', type !== 'page');
    $('#pageforge_parent_page_id').prop('disabled', type !== 'page');
  });
  $(document).on('change', '#pf-template', function(){
    var id = $(this).val();
    var output = $('#pf-generation-destination');
    output.text('');
    if (!id) return;
    $.post(PAGEFORGE.ajax, {action:'pageforge_template_meta', nonce:PAGEFORGE.nonce, template_id:id}, function(res){
      if (String($('#pf-template').val()) !== String(id)) return;
      output.text(res.success ? res.data.destination : res.data);
    });
  });

  if ($('#pf-template').val()) $('#pf-template').trigger('change');

  // Generate Pages
  $(document).on('click', '#pf-start', function(){
    var template_id = $('#pf-template').val();
    var csv_id = $('#pf-csv').val();
    var status = $('#pf-status').val();
    var mode = $('#pf-mode').val();
    if(!template_id || !csv_id){ alert(t('selectTemplateAndCsv')); return; }
    log(t('startingGeneration'));
    $.post(PAGEFORGE.ajax, { action:'pageforge_start_generation', nonce: PAGEFORGE.nonce, template_id, csv_id, status, mode }, function(res){
      if(res.success){
        if(res.data.notice) log(res.data.notice);
        if(res.data.processed !== undefined) log(t('processedPrefix') + ' ' + res.data.processed);
        if(res.data.queued !== undefined) log(t('queuedRowsPrefix') + ' ' + res.data.queued);
        if(res.data.errors && res.data.errors.length) log(t('errorsPrefix') + ' ' + res.data.errors.join('; '));
      } else {
        log(t('errorPrefix') + ' ' + res.data);
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
    if(!post_id){ alert(t('selectPostFirst')); return; }
    var $out = $('#pf-ai-meta-output');
    setOutput($out, '<div class="pf-loading">' + escHtml(t('generating')) + '</div>');
    $.post(PAGEFORGE.ajax, { action:'pageforge_ai_generate_meta', nonce: PAGEFORGE.nonce, post_id: post_id }, function(res){
      if(res && res.success){
        var d = res.data;
        setOutput($out,
          '<div class="pf-result"><div><strong>' + escHtml(t('saved')) + '</strong> ' + escHtml(t('forPost')) + ' #'+ d.post_id +'</div>'+
          '<div class="pf-kv"><div><span>' + escHtml(t('title')) + '</span><code>'+ escHtml(d.meta_title) +'</code></div>'+
          '<div><span>' + escHtml(t('description')) + '</span><code>'+ escHtml(d.meta_description) +'</code></div>'+
          (d.focus_keyword ? '<div><span>' + escHtml(t('keyword')) + '</span><code>'+ escHtml(d.focus_keyword) +'</code></div>' : '') +
          (d.og_title ? '<div><span>' + escHtml(t('openGraphTitle')) + '</span><code>'+ escHtml(d.og_title) +'</code></div>' : '') +
          (d.integration_label ? '<div><span>' + escHtml(t('integration')) + '</span><code>'+ escHtml(d.integration_label) +'</code></div>' : '') +
          (d.metadata_warning ? '<div class="pf-error">' + escHtml(t('metadataWarning')) + ' ' + escHtml(d.metadata_warning) + '</div>' : '') +
          '</div></div>'
        );
      } else {
        setOutput($out, '<div class="pf-error">'+ escHtml(res && res.data ? res.data : t('errorPrefix')) +'</div>');
      }
    }).fail(function(){
      setOutput($out, '<div class="pf-error">'+ escHtml(t('requestFailed')) +'</div>');
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
      $('#pf-ai-meta-bulk-progress').text(text || t('dash'));
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
      bulkSetProgress(fmt('workingOffset', bulkOffset));
      var req;
      try {
        req = bulkRequest();
      } catch(e) {
        bulkLog('<span class="pf-error"><strong>' + escHtml(t('errorPrefix')) + '</strong> ' + escHtml(t('bulkStartFailed')) + '</span>');
        bulkRunning = false;
        $('#pf-ai-meta-bulk-start').prop('disabled', false);
        $('#pf-ai-meta-bulk-stop').prop('disabled', true);
        bulkSetProgress(t('stoppedJsError'));
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
            bulkLog('<strong>' + escHtml(t('ok')) + '</strong> ' + escHtml(t('post')) + ' #' + escHtml(x.post_id) + ' ' + escHtml(t('savedLower')));
          });
          errs.forEach(function(x){
            bulkLog('<span class="pf-error"><strong>' + escHtml(t('errorPrefix')) + '</strong> ' + escHtml(t('post')) + ' #' + escHtml(x.post_id) + ': ' + escHtml(x.error) + '</span>');
          });

          bulkSetProgress(fmt('processedProgress', Math.min(bulkOffset, bulkTotal), (bulkTotal || '?')));
          if(d.done){
            bulkRunning = false;
            $('#pf-ai-meta-bulk-start').prop('disabled', false);
            $('#pf-ai-meta-bulk-stop').prop('disabled', true);
            bulkSetProgress(t('done'));
            return;
          }
          // Next batch
          setTimeout(bulkTick, 250);
        } else {
          var err = (res && res.data) ? res.data : t('errorPrefix');
          bulkLog('<span class="pf-error"><strong>' + escHtml(t('errorPrefix')) + '</strong> ' + escHtml(err) + '</span>');
          bulkRunning = false;
          $('#pf-ai-meta-bulk-start').prop('disabled', false);
          $('#pf-ai-meta-bulk-stop').prop('disabled', true);
          bulkSetProgress(t('stoppedError'));
        }
      }).fail(function(){
        bulkLog('<span class="pf-error"><strong>' + escHtml(t('errorPrefix')) + '</strong> ' + escHtml(t('requestFailed')) + '</span>');
        bulkRunning = false;
        $('#pf-ai-meta-bulk-start').prop('disabled', false);
        $('#pf-ai-meta-bulk-stop').prop('disabled', true);
        bulkSetProgress(t('stoppedRequestFailed'));
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
      bulkLog(t('starting'));
      $('#pf-ai-meta-bulk-start').prop('disabled', true);
      $('#pf-ai-meta-bulk-stop').prop('disabled', false);
      bulkSetProgress(t('starting'));
      bulkTick();
    });

    $(document).on('click', '#pf-ai-meta-bulk-stop', function(e){
      e.preventDefault();
      bulkRunning = false;
      $('#pf-ai-meta-bulk-start').prop('disabled', false);
      $('#pf-ai-meta-bulk-stop').prop('disabled', true);
      bulkSetProgress(t('stopped'));
    });
  })();

  $(document).on('click', '#pf-ai-blog-generate', function(){
    var topic = $('#pf-blog-topic').val();
    if(!topic){ alert(t('enterTopic')); return; }
    var $out = $('#pf-ai-blog-output');
    setOutput($out, '<div class="pf-loading">' + escHtml(t('generatingDraft')) + '</div>');
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
          '<div class="pf-result"><div><strong>' + escHtml(t('draftCreated')) + '</strong> (#'+ d.post_id +')</div>'+
          '<div><a class="button button-small" href="'+ escHtml(d.edit_url) +'">' + escHtml(t('editPost')) + '</a></div>'+
          (d.status ? '<div class="pf-note">' + escHtml(t('status')) + ': ' + escHtml(d.status) + '</div>' : '') +
          (d.integration_label ? '<div class="pf-note">' + escHtml(t('integration')) + ': ' + escHtml(d.integration_label) + '</div>' : '') +
          (d.notice ? '<div class="pf-note">' + escHtml(d.notice) + '</div>' : '') +
          (d.metadata_warning ? '<div class="pf-error">' + escHtml(t('metadataWarning')) + ' ' + escHtml(d.metadata_warning) + '</div>' : '') +
          (d.thumbnail_id ? '<div class="pf-note">' + escHtml(fmt('featuredImageAttached', d.thumbnail_id)) + '</div>' : '') +
          '</div>'
        );
      } else {
        setOutput($out, '<div class="pf-error">'+ escHtml(res && res.data ? res.data : t('errorPrefix')) +'</div>');
      }
    }).fail(function(){
      setOutput($out, '<div class="pf-error">'+ escHtml(t('requestFailed')) +'</div>');
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
    setOutput($out, '<div class="pf-loading">' + escHtml(t('generatingReport')) + '</div>');
    $('#pf-ai-seo-download-json, #pf-ai-seo-download-md').prop('disabled', true);
    $.post(PAGEFORGE.ajax, {
      action: 'pageforge_ai_seo_report',
      nonce: PAGEFORGE.nonce,
      include_urls: $('#pf-seo-include-urls').is(':checked') ? 1 : 0,
      post_types: ['page','post','pageforge_page']
    }, function(res){
      if(res && res.success && res.data && res.data.data && typeof res.data.data.markdown === 'string' && res.data.data.markdown.trim()){
        lastSeo = res.data;
        var md = (lastSeo.data && lastSeo.data.markdown) ? lastSeo.data.markdown : '';
        setOutput($out,
          '<div class="pf-result">'+
          '<div class="pf-note">' + escHtml(t('generatedAt')) + ' '+ new Date((lastSeo.generated_at||0)*1000).toLocaleString() +'</div>'+
          '<pre class="pf-pre">'+ escHtml(md || JSON.stringify(lastSeo.data || {}, null, 2)) +'</pre>'+
          '</div>'
        );
        $('#pf-ai-seo-download-json, #pf-ai-seo-download-md').prop('disabled', false);
      } else {
        var responseError = res && res.data ? res.data : t('invalidSeoReportResponse');
        setOutput($out, '<div class="pf-error">'+ escHtml(typeof responseError === 'string' ? responseError : JSON.stringify(responseError)) +'</div>');
      }
    }).fail(function(xhr){
      var message = t('seoReportRequestFailed');
      if(xhr && xhr.responseJSON && xhr.responseJSON.data){
        message = typeof xhr.responseJSON.data === 'string'
          ? xhr.responseJSON.data
          : JSON.stringify(xhr.responseJSON.data);
      } else if(xhr && xhr.status){
        message += ' HTTP ' + xhr.status + '.';
      }
      setOutput($out, '<div class="pf-error">'+ escHtml(message) +'</div>');
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
    if(!Array.isArray(msgs) || !msgs.length) return t('newSitePlan');
    var text = '';
    for(var i=0;i<msgs.length;i++){
      if(msgs[i].role === 'user' && msgs[i].content){ text = String(msgs[i].content); break; }
    }
    if(!text && msgs[0] && msgs[0].content){
      text = String(msgs[0].content);
    }
    text = text.replace(/\s+/g,' ').trim();
    if(text.length > 60) text = text.substring(0,57) + '...';
    return text || t('newSitePlan');
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
          title: t('newSitePlan'),
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
    pfChatState.threads.forEach(function(threadItem){ if(threadItem.id === id) thread = threadItem; });
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
    if(d < 60) return t('justNow');
    if(d < 3600) return fmt('minAgo', Math.round(d/60));
    if(d < 86400) return fmt('hrAgo', Math.round(d/3600));
    var days = Math.round(d/86400);
    return days === 1 ? fmt('dayAgo', days) : fmt('daysAgo', days);
  }

  function pfRenderConversationList(){
    var $list = $('#pf-ai-conversation-list');
    if(!$list.length || !pfChatState) return;
    $list.empty();
    (pfChatState.threads || []).forEach(function(threadItem){
      var active = threadItem.id === pfChatState.active_id;
      var updated = threadItem.updated_at || threadItem.created_at || Math.floor(Date.now()/1000);
      var meta = fmt('updated', pfFormatRelativeTime(updated));
      var cls = 'pf-ai-conversation' + (active ? ' is-active' : '');
      $list.append(
        '<button type="button" class="'+ cls +'" data-thread-id="'+ escHtml(threadItem.id || '') +'">'+
          '<div class="pf-ai-conversation-title">'+ escHtml(threadItem.title || t('conversation')) +'</div>'+
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
        title: t('newSitePlan'),
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
        var who = m.role === 'assistant' ? t('assistant') : t('you');
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
      if(!thread.title || thread.title === t('newSitePlan')){
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
          var err = (res && res.data) ? res.data : t('errorPrefix');
          msg = {role:'assistant', content: t('errorPrefix') + ' ' + String(err), error:true};
        }
        thread.messages.push(msg);
        thread.updated_at = Math.floor(Date.now()/1000);
        pfUpsertThread(thread);
        pfRenderConversationList();
        pfRenderChat();
      }).fail(function(){
        var msg = {role:'assistant', content: t('plannerRequestFailed'), error:true};
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

