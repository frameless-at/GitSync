/**
 * GitSync – "Link Module" / "Install Module" page logic
 *
 * Expects a global GitSyncConfig object with:
 *   moduleData, ajaxUrl, changeLabel, noReposLabel, searchingLabel
 */

// Shared repo picker: renders a clickable list, calls onSelect(url) when picked
function GitSyncPicker(container, hiddenInput, submitBtn, changeLabel, labels) {
    var self = this;
    this.container = container;
    this.hidden = hiddenInput;
    this.btn = submitBtn;
    this.changeLabel = changeLabel;
    this.labels = labels;
    this.onResearch = null;

    if (this.btn) this.btn.closest('.Inputfield').classList.add('gitsync-hidden-btn');

    this.showResults = function(data) {
        self.reset();
        if (!data.length) {
            self.container.innerHTML = '<span style="color:#888">' + self.labels.noRepos + '</span>';
            return;
        }
        var html = '<div style="border:1px solid #ddd;border-radius:3px;max-height:300px;overflow-y:auto">';
        for (var i = 0; i < data.length; i++) {
            var r = data[i];
            html += '<div class="gitsync-result gitsync-selectable" data-url="' + r.url + '" '
                + 'style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #eee">'
                + '<strong>' + r.full_name + '</strong></div>';
        }
        html += '</div>';
        self.container.innerHTML = html;
        self.container.querySelectorAll('.gitsync-selectable').forEach(function(el) {
            el.addEventListener('mouseenter', function() { el.style.background = '#f0f4ff'; });
            el.addEventListener('mouseleave', function() { el.style.background = ''; });
            el.addEventListener('click', function() { self.select(el.getAttribute('data-url')); });
        });
    };

    this.select = function(url) {
        self.hidden.value = url;
        self.container.innerHTML = '<span style="color:#2e7d32"><i class="fa fa-check"></i> <a href="' + url + '" target="_blank" style="color:#2e7d32">' + url + '</a></span>'
            + ' <a href="#" class="gitsync-change" style="margin-left:8px">' + self.changeLabel + '</a>';
        self.container.querySelector('.gitsync-change').addEventListener('click', function(e) {
            e.preventDefault();
            if (self.onResearch) self.onResearch();
        });
        if (self.btn) self.btn.closest('.Inputfield').classList.remove('gitsync-hidden-btn');
    };

    this.reset = function() {
        self.hidden.value = '';
        self.container.innerHTML = '';
        if (self.btn) self.btn.closest('.Inputfield').classList.add('gitsync-hidden-btn');
    };

    this.showSpinner = function() {
        self.reset();
        self.container.innerHTML = '<span style="color:#888"><i class="fa fa-spinner fa-spin"></i> ' + self.labels.searching + '</span>';
    };

    this.showError = function(msg) {
        self.reset();
        self.container.innerHTML = '<span style="color:#c00">' + msg + '</span>';
    };
}

// --- Link installed module ---
(function() {
    var cfg = window.GitSyncConfig;
    if (!cfg) return;

    var radios = document.querySelectorAll('input[name=module_source]');
    var select = document.getElementById('gitsync-module-select');
    if (!radios.length || !select) return;

    var labels = { noRepos: cfg.noReposLabel, searching: cfg.searchingLabel };
    var picker = new GitSyncPicker(
        document.getElementById('gitsync-repo-status'),
        document.getElementById('gitsync-repo-url'),
        document.getElementById('gitsync-link-submit'),
        cfg.changeLabel,
        labels
    );
    picker.onResearch = function() { select.dispatchEvent(new Event('change')); };

    var allOptions = [];
    for (var i = 1; i < select.options.length; i++) {
        allOptions.push({
            value: select.options[i].value,
            text: select.options[i].text,
            core: select.options[i].getAttribute('data-core') === '1'
        });
    }

    function rebuildSelect() {
        var showCore = (function() {
            for (var i = 0; i < radios.length; i++) if (radios[i].checked) return radios[i].value;
            return 'site';
        })() === 'core';
        var cur = select.value;
        while (select.options.length > 1) select.remove(1);
        for (var i = 0; i < allOptions.length; i++) {
            if (allOptions[i].core !== showCore) continue;
            var opt = new Option(allOptions[i].text, allOptions[i].value);
            opt.setAttribute('data-core', allOptions[i].core ? '1' : '0');
            select.add(opt);
        }
        select.value = cur;
        if (!select.value) select.selectedIndex = 0;
        picker.reset();
    }

    radios.forEach(function(r) { r.addEventListener('change', rebuildSelect); });
    rebuildSelect();

    select.addEventListener('change', function() {
        var cls = select.value;
        picker.reset();
        if (!cls) return;
        var info = cfg.moduleData[cls];
        if (info && info.href) { picker.select(info.href); return; }

        picker.showSpinner();
        fetch(cfg.ajaxUrl + '&search=' + encodeURIComponent(cls), {
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.error) { picker.showError(data.error); return; }
            picker.showResults(data);
        })
        .catch(function(err) { picker.showError(err.message); });
    });
})();
