// Error log screen: filters, refresh, copy, clear. Mirrors anonymousSalesPlugin's own log screen.
(function () {
  var ajaxUrl = window.TybThemeEditorAdmin.ajaxUrl
  var refreshNonce = window.TybThemeEditorAdmin.errorLogNonce || ''
  var levelFilter = document.getElementById('tyb_theme_editor_log_level_filter')
  var searchInput = document.getElementById('tyb_theme_editor_log_search')
  var rows = Array.prototype.slice.call(document.querySelectorAll('.tyb-theme-editor-log-row'))
  var rowsContainer = document.getElementById('tyb_theme_editor_log_rows')
  var countLabel = document.getElementById('tyb_theme_editor_log_count')
  var selectVisibleBtn = document.getElementById('tyb_theme_editor_log_select_visible')
  var clearSelectionBtn = document.getElementById('tyb_theme_editor_log_clear_selection')
  var refreshBtn = document.getElementById('tyb_theme_editor_log_refresh')
  var copySelectedBtn = document.getElementById('tyb_theme_editor_log_copy_selected')
  var copyAllBtn = document.getElementById('tyb_theme_editor_log_copy_all')
  var clearBtn = document.getElementById('tyb_theme_editor_clear_error_log_btn')

  var escapeHtml = function (text) {
    return String(text || '').replace(/[&<>"']/g, function (char) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]
    })
  }

  var badgeStyleByLevel = function (level) {
    var key = String(level || 'UNKNOWN').toUpperCase()
    if (key === 'ERROR') return 'background:#fbeaea;color:#8b0000;border:1px solid #f1b5b5;'
    if (key === 'WARNING') return 'background:#fff7e6;color:#8a5800;border:1px solid #f0d195;'
    if (key === 'INFO') return 'background:#eaf4ff;color:#0b4f8a;border:1px solid #b8d8f5;'
    if (key === 'DEBUG') return 'background:#f2f2f4;color:#4d4d57;border:1px solid #d4d4dc;'
    return 'background:#f7f7f7;color:#555;border:1px solid #dcdcde;'
  }

  var refreshRowsCache = function () {
    rows = Array.prototype.slice.call(document.querySelectorAll('.tyb-theme-editor-log-row'))
  }

  var renderRows = function (entries) {
    if (!rowsContainer) return
    if (!entries || !entries.length) {
      rowsContainer.innerHTML = ''
      refreshRowsCache()
      applyFilters()
      return
    }
    var html = ''
    entries.forEach(function (entry, index) {
      var level = String((entry && entry.level) || 'UNKNOWN').toUpperCase()
      var line = String((entry && entry.line) || '')
      html += '<tr class="tyb-theme-editor-log-row" data-level="' + escapeHtml(level) + '" data-line="' + escapeHtml(line) + '">'
      html +=
        '<td><label style="display:flex;gap:6px;align-items:center;"><input type="checkbox" class="tyb-theme-editor-log-select" /><span>' +
        String(index + 1) +
        '</span></label></td>'
      html +=
        '<td><span style="display:inline-block;min-width:72px;text-align:center;font-weight:700;border-radius:999px;padding:2px 8px;' +
        badgeStyleByLevel(level) +
        '">' +
        escapeHtml(level) +
        '</span></td>'
      html += '<td><code style="display:block;white-space:pre-wrap;word-break:break-word;color:#8b0000;">' + escapeHtml(line) + '</code></td></tr>'
    })
    rowsContainer.innerHTML = html
    refreshRowsCache()
    applyFilters()
  }

  var fallbackCopy = function (text) {
    var helper = document.createElement('textarea')
    helper.value = text
    helper.setAttribute('readonly', 'readonly')
    helper.style.position = 'fixed'
    helper.style.opacity = '0'
    helper.style.pointerEvents = 'none'
    document.body.appendChild(helper)
    helper.select()
    var ok = false
    try {
      ok = document.execCommand('copy')
    } catch (e) {
      ok = false
    }
    document.body.removeChild(helper)
    window.alert(ok ? 'Copied to clipboard.' : 'Copy failed. Please copy manually.')
  }

  var copyText = function (text) {
    if (!text) {
      window.alert('There is no log content to copy.')
      return
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard
        .writeText(text)
        .then(function () {
          window.alert('Copied to clipboard.')
        })
        .catch(function () {
          fallbackCopy(text)
        })
      return
    }
    fallbackCopy(text)
  }

  var getVisibleRows = function () {
    return rows.filter(function (row) {
      return row.style.display !== 'none'
    })
  }

  var applyFilters = function () {
    var level = (levelFilter && levelFilter.value) || 'ALL'
    var query = ((searchInput && searchInput.value) || '').trim().toLowerCase()
    var visible = 0
    rows.forEach(function (row) {
      var rowLevel = (row.getAttribute('data-level') || 'UNKNOWN').toUpperCase()
      var line = (row.getAttribute('data-line') || '').toLowerCase()
      var levelMatch = level === 'ALL' || rowLevel === level
      var queryMatch = query === '' || line.indexOf(query) !== -1
      var show = levelMatch && queryMatch
      row.style.display = show ? '' : 'none'
      if (show) visible++
    })
    if (countLabel) countLabel.textContent = 'Visible: ' + visible + ' / ' + rows.length
  }

  if (levelFilter) levelFilter.addEventListener('change', applyFilters)
  if (searchInput) searchInput.addEventListener('input', applyFilters)
  if (selectVisibleBtn)
    selectVisibleBtn.addEventListener('click', function () {
      getVisibleRows().forEach(function (row) {
        var box = row.querySelector('.tyb-theme-editor-log-select')
        if (box) box.checked = true
      })
    })
  if (clearSelectionBtn)
    clearSelectionBtn.addEventListener('click', function () {
      rows.forEach(function (row) {
        var box = row.querySelector('.tyb-theme-editor-log-select')
        if (box) box.checked = false
      })
    })
  if (refreshBtn)
    refreshBtn.addEventListener('click', function () {
      refreshBtn.disabled = true
      refreshBtn.textContent = 'Refreshing...'
      var body = new URLSearchParams()
      body.append('action', 'tyb_theme_editor_refresh_error_log')
      body.append('nonce', refreshNonce)
      fetch(ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString(),
      })
        .then(function (response) {
          return response.json()
        })
        .then(function (payload) {
          if (!payload || payload.success !== true) throw new Error('Refresh failed')
          renderRows(Array.isArray(payload.data && payload.data.entries) ? payload.data.entries : [])
        })
        .catch(function () {
          window.alert('Could not refresh log preview.')
        })
        .finally(function () {
          refreshBtn.disabled = false
          refreshBtn.textContent = 'Refresh'
        })
    })
  if (copySelectedBtn)
    copySelectedBtn.addEventListener('click', function () {
      var selectedLines = []
      rows.forEach(function (row) {
        var box = row.querySelector('.tyb-theme-editor-log-select')
        if (box && box.checked) selectedLines.push(row.getAttribute('data-line') || '')
      })
      copyText(selectedLines.join('\n'))
    })
  if (copyAllBtn)
    copyAllBtn.addEventListener('click', function () {
      var allLines = rows.map(function (row) {
        return row.getAttribute('data-line') || ''
      })
      copyText(allLines.join('\n'))
    })
  if (clearBtn)
    clearBtn.addEventListener('click', function (event) {
      var confirmed = window.confirm('Clearing the error log is not recommended. Are you sure you want to clear it?')
      if (!confirmed) event.preventDefault()
    })

  applyFilters()
})()
