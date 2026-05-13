(function () {
  'use strict';

  var STEPS = ['install', 'agent', 'configure'];
  // 'claude-desktop' is temporarily disabled pending Linux verification.
  var AGENTS = ['claude-web', 'claude-code', 'cursor', 'chatgpt', 'other'];

  function parseHash() {
    var raw = (window.location.hash || '').replace(/^#/, '');
    if (!raw) return { step: 'install', agent: null };

    if (raw.indexOf('configure-') === 0) {
      var agent = raw.slice('configure-'.length);
      if (AGENTS.indexOf(agent) !== -1) return { step: 'configure', agent: agent };
      return { step: 'agent', agent: null };
    }

    if (STEPS.indexOf(raw) !== -1) return { step: raw, agent: null };
    return { step: 'install', agent: null };
  }

  function render() {
    var state = parseHash();
    var currentIndex = STEPS.indexOf(state.step);

    var sections = document.querySelectorAll('[data-section]');
    for (var i = 0; i < sections.length; i++) {
      var name = sections[i].getAttribute('data-section');
      sections[i].setAttribute('data-active', name === state.step ? 'true' : 'false');
    }

    var stepItems = document.querySelectorAll('[data-step-item]');
    for (var j = 0; j < stepItems.length; j++) {
      var stepName = stepItems[j].getAttribute('data-step-item');
      var stepIdx = STEPS.indexOf(stepName);
      var s = 'pending';
      if (stepIdx < currentIndex) s = 'complete';
      if (stepIdx === currentIndex) s = 'active';
      stepItems[j].setAttribute('data-state', s);
      if (s === 'active') {
        stepItems[j].setAttribute('aria-current', 'step');
      } else {
        stepItems[j].removeAttribute('aria-current');
      }
    }

    if (state.step === 'configure') {
      var details = document.querySelectorAll('[data-agent]');
      for (var k = 0; k < details.length; k++) {
        var agentName = details[k].getAttribute('data-agent');
        details[k].setAttribute('data-active', agentName === state.agent ? 'true' : 'false');
      }
    }

    document.body.setAttribute('data-current-step', state.step);
    window.scrollTo({ top: 0, behavior: 'instant' in window ? 'instant' : 'auto' });
  }

  function goTo(hash) {
    if (window.location.hash === hash) {
      render();
    } else {
      window.location.hash = hash;
    }
  }

  function onClick(evt) {
    var target = evt.target.closest('[data-goto]');
    if (target) {
      evt.preventDefault();
      goTo('#' + target.getAttribute('data-goto'));
      return;
    }

    var card = evt.target.closest('[data-agent-pick]');
    if (card) {
      evt.preventDefault();
      goTo('#configure-' + card.getAttribute('data-agent-pick'));
      return;
    }

    var copyBtn = evt.target.closest('[data-copy]');
    if (copyBtn) {
      var sel = copyBtn.getAttribute('data-copy');
      var src = document.getElementById(sel);
      if (!src) return;
      var text = src.textContent.replace(/ /g, ' ').trim();
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () {
          markCopied(copyBtn);
        }).catch(function () {
          legacyCopy(text, copyBtn);
        });
      } else {
        legacyCopy(text, copyBtn);
      }
      return;
    }

    var tabBtn = evt.target.closest('[data-tab]');
    if (tabBtn) {
      var group = tabBtn.getAttribute('data-tab-group');
      var name = tabBtn.getAttribute('data-tab');
      var buttons = document.querySelectorAll('[data-tab-group="' + group + '"]');
      for (var b = 0; b < buttons.length; b++) {
        buttons[b].setAttribute('aria-selected', buttons[b] === tabBtn ? 'true' : 'false');
      }
      var panels = document.querySelectorAll('[data-tab-panel-group="' + group + '"]');
      for (var p = 0; p < panels.length; p++) {
        var panelName = panels[p].getAttribute('data-tab-panel');
        panels[p].setAttribute('data-active', panelName === name ? 'true' : 'false');
      }
    }
  }

  function legacyCopy(text, btn) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'absolute';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try {
      document.execCommand('copy');
      markCopied(btn);
    } catch (e) {
      btn.textContent = 'Press Ctrl+C';
    }
    document.body.removeChild(ta);
  }

  function markCopied(btn) {
    var original = btn.getAttribute('data-label') || btn.textContent;
    if (!btn.getAttribute('data-label')) btn.setAttribute('data-label', original);
    btn.textContent = 'Copied';
    btn.setAttribute('data-copied', 'true');
    setTimeout(function () {
      btn.textContent = btn.getAttribute('data-label');
      btn.removeAttribute('data-copied');
    }, 2000);
  }

  function buildInstallCommand() {
    var output = document.getElementById('cmd-install');
    if (!output) return;
    var checked = document.querySelectorAll('#featurelist input[type="checkbox"]:checked');
    var packages = ['magebitcom/magento2-mcp-module'];
    var modules = ['Magebit_Mcp'];
    for (var i = 0; i < checked.length; i++) {
      packages.push(checked[i].getAttribute('data-package'));
      modules.push(checked[i].getAttribute('data-name'));
    }

    var composerLine;
    if (packages.length === 1) {
      composerLine = 'composer require ' + packages[0];
    } else {
      composerLine = 'composer require ' + packages.join(' \\\n  ');
    }

    var enableLine = 'bin/magento module:enable ' + modules.join(' \\\n  ');
    output.textContent = composerLine + '\n\n' + enableLine + '\n\nbin/magento setup:upgrade';
  }

  function onFeatureChange(evt) {
    if (evt.target.matches && evt.target.matches('#featurelist input[type="checkbox"]')) {
      buildInstallCommand();
    }
  }

  document.addEventListener('click', onClick);
  document.addEventListener('change', onFeatureChange);
  window.addEventListener('hashchange', render);
  document.addEventListener('DOMContentLoaded', function () {
    render();
    buildInstallCommand();
  });
})();
