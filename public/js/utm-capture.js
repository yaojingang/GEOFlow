/*!
 * SEOGEO UTM 归因采集片段（utm-capture.js）
 * 作用：抓取落地页 URL 上的 UTM 参数与内部 channel，存入 sessionStorage，
 *       并在表单提交前把它们写入同名隐藏字段，随表单 POST 到后端，
 *       后端写入 lead_submissions.payload._attribution。
 * 依赖：无。部署到各公开落地页 <head> 或 </body> 前即可。
 * 字段：utm_source, utm_medium, utm_campaign, utm_content, utm_term, channel
 */
(function (window, document) {
  'use strict';

  var FIELDS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'channel'];
  var STORE_KEY = 'seogeo_utm_attribution';

  function readQuery() {
    var out = {};
    var search = window.location.search || '';
    if (search.charAt(0) === '?') {
      search = search.slice(1);
    }
    search.split('&').forEach(function (pair) {
      if (!pair) return;
      var eq = pair.indexOf('=');
      var key = eq === -1 ? pair : pair.slice(0, eq);
      var val = eq === -1 ? '' : pair.slice(eq + 1);
      key = decodeURIComponent(key).replace(/^\s+|\s+$/g, '');
      if (FIELDS.indexOf(key) === -1) return;
      val = decodeURIComponent(val).replace(/^\s+|\s+$/g, '').slice(0, 120);
      if (val !== '') out[key] = val;
    });
    return out;
  }

  function loadStored() {
    try {
      return JSON.parse(sessionStorage.getItem(STORE_KEY) || '{}');
    } catch (e) {
      return {};
    }
  }

  function saveStored(data) {
    try {
      sessionStorage.setItem(STORE_KEY, JSON.stringify(data));
    } catch (e) { /* 隐私模式下忽略，退化为本次 URL 读取 */ }
  }

  // 首次触点优先：已有非空值不被后续跳转覆盖。
  function merge(firstTouch, incoming) {
    var merged = {};
    FIELDS.forEach(function (f) {
      merged[f] = (firstTouch[f] || incoming[f] || '');
    });
    return merged;
  }

  function ensureHiddenInputs(form, attribution) {
    FIELDS.forEach(function (name) {
      if (!attribution[name]) return;
      var input = form.querySelector('input[name="' + name + '"]');
      if (!input) {
        input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        form.appendChild(input);
      }
      input.value = attribution[name];
    });
  }

  function attachForms(attribution) {
    var forms = document.querySelectorAll('form');
    Array.prototype.forEach.call(forms, function (form) {
      ensureHiddenInputs(form, attribution);
      form.addEventListener('submit', function () {
        ensureHiddenInputs(form, attribution);
      });
    });
  }

  function init() {
    var attribution = merge(loadStored(), readQuery());
    saveStored(attribution);
    attachForms(attribution);
    // 动态插入的表单（SPA / 延迟渲染）可在其挂载后调用 SeoGeoUtm.attach()。
    window.SeoGeoUtm = {
      attribution: attribution,
      attach: function () { attachForms(attribution); }
    };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
