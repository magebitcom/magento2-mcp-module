(function () {
  'use strict';

  // Per-request timeout. A hanging host shouldn't stall the whole run.
  var TIMEOUT_MS = 8000;

  // ---- URL helpers --------------------------------------------------------

  // Turn whatever the user typed into a normalised set of endpoint URLs.
  // Accepts "https://store.com/mcp", "https://store.com", "store.com", etc.
  function parseTarget(raw) {
    var input = (raw || '').trim();
    if (!input) return null;
    if (/\s/.test(input)) return null; // a real URL has no whitespace
    if (!/^https?:\/\//i.test(input)) input = 'https://' + input;

    var u;
    try {
      u = new URL(input);
    } catch (e) {
      return null;
    }
    // Reject single-label garbage like "notaurl"; allow real hosts and localhost.
    if (u.hostname.indexOf('.') === -1 && u.hostname !== 'localhost') return null;

    // basePath = pathname with a trailing "/mcp" (and trailing slash) removed.
    var path = u.pathname.replace(/\/+$/, '');
    path = path.replace(/\/mcp$/i, '');
    var basePath = path; // '' or e.g. '/de'

    var origin = u.origin;
    var base = origin + basePath; // where /mcp lives
    var resourcePath = basePath + '/mcp'; // e.g. '/mcp' or '/de/mcp'

    return {
      origin: origin,
      base: base,
      mcp: base + '/mcp',
      token: base + '/mcp/oauth/token',
      // RFC 9728 inserts the resource path after the well-known segment;
      // fall back to the bare path for servers that don't suffix it.
      prm: [
        origin + '/.well-known/oauth-protected-resource' + resourcePath,
        origin + '/.well-known/oauth-protected-resource'
      ],
      // RFC 8414 — try a store-path-scoped variant first, then the bare path.
      asm: (basePath
        ? [origin + '/.well-known/oauth-authorization-server' + basePath]
        : []
      ).concat([origin + '/.well-known/oauth-authorization-server'])
    };
  }

  function sameSchemeHost(urlA, urlB) {
    try {
      var a = new URL(urlA);
      var b = new URL(urlB);
      return a.protocol === b.protocol && a.host === b.host;
    } catch (e) {
      return false;
    }
  }

  // scheme + host, so a mismatch that's only http-vs-https is still legible.
  function hostOf(url) {
    try {
      var u = new URL(url);
      return u.protocol + '//' + u.host;
    } catch (e) {
      return url;
    }
  }

  // ---- Low-level probes ---------------------------------------------------
  //
  // Browser reality (verified): a page fetch() with redirect:'manual' or
  // redirect:'error' throws unconditionally in Chromium, and a no-cors
  // response is opaque (status 0, redirected:false, url:''), so it can only
  // confirm reachability. Redirect detection therefore rides on the
  // CORS-enabled endpoints (.well-known + token), via response.redirected and
  // the throw a cross-origin redirect-into-a-wall produces.

  function withTimeout(fn) {
    var ctrl = new AbortController();
    var timer = setTimeout(function () { ctrl.abort(); }, TIMEOUT_MS);
    return fn(ctrl.signal).finally(function () { clearTimeout(timer); });
  }

  // Reachability only. opaque ⇒ the host answered; a throw ⇒ DNS/TLS/network
  // failure or the host is down.
  function probeReachable(url, method) {
    return withTimeout(function (signal) {
      return fetch(url, {
        method: method,
        mode: 'no-cors',
        redirect: 'follow',
        signal: signal,
        cache: 'no-store'
      }).then(function () {
        return { reached: true };
      }).catch(function () {
        return { reached: false };
      });
    });
  }

  // Read a CORS-enabled JSON endpoint, trying each candidate URL in turn.
  // Returns {url, redirected, data} for the first that parses, or
  // {error: true} when none could be read cross-origin (missing CORS, an auth
  // gateway, a redirect into one, or the endpoint is absent).
  function fetchJson(candidates) {
    var list = candidates.slice();
    function attempt(i) {
      if (i >= list.length) return Promise.resolve({ error: true });
      return withTimeout(function (signal) {
        return fetch(list[i], { method: 'GET', mode: 'cors', redirect: 'follow', signal: signal, cache: 'no-store' });
      }).then(function (res) {
        return res.json().then(function (json) {
          return { url: res.url, redirected: res.redirected, data: json };
        });
      }).catch(function () {
        return attempt(i + 1);
      });
    }
    return attempt(0);
  }

  // ---- Result helpers -----------------------------------------------------

  function result(status, detail, fix) {
    return { status: status, detail: detail, fix: fix || null };
  }

  var REDIRECT_FIX =
    'A redirect here is usually a store-code / locale rewrite (e.g. "/de/…") ' +
    'or an http→https jump in front of the MCP endpoints. MCP clients send ' +
    'POST requests that can’t survive a redirect, and the redirect target ' +
    'often sits behind HTTP Basic auth — the most common cause of ' +
    '"Authorization failed" after the consent screen. The /mcp and ' +
    '/mcp/oauth/* paths must respond directly, with no redirect.';

  function checkMetadata(res, t, fields, okDetail) {
    if (res.error) {
      return result('warn',
        'Couldn’t read this metadata from the browser.',
        'The endpoint may be missing, lack CORS, sit behind an auth gateway, or redirect into one. Confirm the full flow with the MCP Inspector guide.');
    }
    if (res.redirected) {
      return result('fail',
        'The metadata endpoint redirected to ' + res.url + '.',
        REDIRECT_FIX);
    }
    var data = res.data || {};
    for (var i = 0; i < fields.length; i++) {
      var name = fields[i][0];
      var val = data[fields[i][1]];
      // authorization_servers is an array; take the first entry.
      if (val && typeof val === 'object' && val.length) val = val[0];
      if (val && !sameSchemeHost(val, t.origin)) {
        return result('fail',
          'Advertised ' + name + ' is ' + hostOf(val) + ', not ' + hostOf(t.origin) + '.',
          'Every advertised URL must be this store, on the same scheme. Check Magento’s base_url (Stores → Configuration → Web).');
      }
    }
    return result('pass', okDetail(data));
  }

  // ---- The checks ---------------------------------------------------------

  var CHECKS = [
    {
      id: 'mcp-reachable',
      target: function (t) { return 'POST ' + t.mcp; },
      run: function (t) {
        return probeReachable(t.mcp, 'POST').then(function (r) {
          if (!r.reached) {
            return result('fail',
              'Not reachable from this browser.',
              'Check DNS, the TLS certificate, and that the store is online and served over HTTPS.');
          }
          return result('pass', 'Reachable — the host responded.');
        });
      }
    },
    {
      id: 'prm',
      target: function (t) { return 'GET ' + t.origin + '/.well-known/oauth-protected-resource'; },
      run: function (t) {
        return fetchJson(t.prm).then(function (res) {
          return checkMetadata(res, t,
            [['resource', 'resource'], ['authorization server', 'authorization_servers']],
            function (d) { return 'Accessible and valid. resource = ' + (d.resource || '—'); });
        });
      }
    },
    {
      id: 'asm',
      target: function (t) { return 'GET ' + t.origin + '/.well-known/oauth-authorization-server'; },
      run: function (t) {
        return fetchJson(t.asm).then(function (res) {
          return checkMetadata(res, t,
            [['issuer', 'issuer'], ['authorization_endpoint', 'authorization_endpoint'], ['token_endpoint', 'token_endpoint']],
            function (d) { return 'Accessible and valid. token_endpoint = ' + (d.token_endpoint || '—'); });
        });
      }
    },
    {
      id: 'token',
      target: function (t) { return 'POST ' + t.token; },
      run: function (t) {
        return withTimeout(function (signal) {
          return fetch(t.token, {
            method: 'POST',
            mode: 'cors',
            redirect: 'follow',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'grant_type=authorization_code',
            signal: signal,
            cache: 'no-store'
          });
        }).then(function (res) {
          if (res.redirected) {
            return result('fail',
              'The token endpoint redirected to ' + res.url + '.',
              REDIRECT_FIX);
          }
          return res.json().then(function (json) {
            if (json && json.error) {
              return result('pass',
                'Accessible — the MCP app responded with OAuth error "' + json.error + '" (expected for this empty probe; no redirect, no auth wall).');
            }
            return result('warn',
              'The endpoint answered, but not with a recognisable OAuth error.',
              'Confirm it routes to the MCP token controller, not something else.');
          }).catch(function () {
            return result('warn', 'The endpoint answered, but the body wasn’t JSON.',
              'Something other than the MCP app may be responding here.');
          });
        }).catch(function () {
          return result('fail',
            'The token endpoint didn’t answer as the MCP app.',
            'A cross-origin request failed here. This is almost always a redirect (store-code / locale / http→https) or an HTTP Basic auth gateway intercepting the request before it reaches Magento — the classic "Authorization failed" cause. ' + REDIRECT_FIX);
        });
      }
    }
  ];

  // ---- Rendering ----------------------------------------------------------

  var BADGE = { running: '…', pass: '✓', warn: '!', fail: '✕' };

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text != null) node.textContent = text;
    return node;
  }

  function renderRow(check, target) {
    var li = el('li', 'check');
    li.setAttribute('data-status', 'running');
    li.id = 'check-' + check.id;

    var badge = el('span', 'check__badge');
    badge.textContent = BADGE.running;
    li.appendChild(badge);

    var body = el('div', 'check__body');
    body.appendChild(el('p', 'check__target', check.target(target)));
    body.appendChild(el('p', 'check__detail', 'Running…'));
    li.appendChild(body);
    return li;
  }

  function paintRow(li, res) {
    li.setAttribute('data-status', res.status);
    li.querySelector('.check__badge').textContent = BADGE[res.status];
    var body = li.querySelector('.check__body');
    body.querySelector('.check__detail').textContent = res.detail;
    var existingFix = body.querySelector('.check__fix');
    if (existingFix) body.removeChild(existingFix);
    if (res.fix) body.appendChild(el('p', 'check__fix', res.fix));
  }

  function paintSummary(node, counts) {
    var parts = [];
    parts.push(counts.pass + ' passed');
    if (counts.warn) parts.push(counts.warn + ' warning' + (counts.warn > 1 ? 's' : ''));
    if (counts.fail) parts.push(counts.fail + ' failed');
    var status = counts.fail ? 'fail' : (counts.warn ? 'warn' : 'pass');
    node.setAttribute('data-status', status);
    node.textContent = parts.join(' · ');
    node.hidden = false;
  }

  // ---- Wiring -------------------------------------------------------------

  function run() {
    var input = document.getElementById('mcp-url');
    var error = document.getElementById('input-error');
    var results = document.getElementById('results');
    var summary = document.getElementById('summary');

    var target = parseTarget(input.value);
    if (!target) {
      error.hidden = false;
      error.textContent = 'Enter a valid URL, e.g. https://your-store.com/mcp';
      return;
    }
    error.hidden = true;
    summary.hidden = true;
    results.innerHTML = '';

    var counts = { pass: 0, warn: 0, fail: 0 };
    var pending = CHECKS.length;

    CHECKS.forEach(function (check) {
      var li = renderRow(check, target);
      results.appendChild(li);
      Promise.resolve()
        .then(function () { return check.run(target); })
        .catch(function () {
          return result('fail', 'The check itself failed to run.', null);
        })
        .then(function (res) {
          paintRow(li, res);
          counts[res.status] += 1;
          pending -= 1;
          if (pending === 0) paintSummary(summary, counts);
        });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('diagnose-form');
    if (form) {
      form.addEventListener('submit', function (evt) {
        evt.preventDefault();
        run();
      });
    }
    // Allow ?url=… deep-links so a developer can hand a client a ready link.
    var qp = new URLSearchParams(window.location.search);
    var preset = qp.get('url');
    if (preset) {
      var input = document.getElementById('mcp-url');
      if (input) input.value = preset;
      run();
    }
  });
})();
