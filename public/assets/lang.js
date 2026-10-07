/**
 * 簡繁體切換(lang.js)
 * - 站內資料一律以繁體儲存;簡體為「顯示層」即時轉換(OpenCC),不動資料
 * - 預設:讀 localStorage(site_lang);無設定則依瀏覽器語言自動偵測(zh-CN/SG/MY/Hans → 簡體)
 * - 轉換器優先載入自站 /assets/vendor/opencc-full.js,失敗退 jsDelivr CDN;皆失敗則維持繁體
 * - 動態內容(fetch 後渲染)由 MutationObserver 接手轉換;屬性含 placeholder/title/aria-label/alt
 * - 搜尋:頁面可用 SiteLang.toHant(q) 將簡體關鍵字轉繁體後查詢(資料庫為繁體)
 * 使用:頁面 header nav 放 <a href="#" id="langToggle"></a>,並於本檔之後載入頁面腳本
 */
(function () {
  "use strict";
  var KEY = "site_lang"; // 'hant' | 'hans'
  var ATTRS = ["placeholder", "title", "aria-label", "alt"];

  function detect() {
    try {
      var v = localStorage.getItem(KEY);
      if (v === "hant" || v === "hans") return v;
    } catch (_) {}
    var langs = (navigator.languages && navigator.languages.length)
      ? navigator.languages : [navigator.language || ""];
    for (var i = 0; i < langs.length; i++) {
      var l = String(langs[i]).toLowerCase();
      if (l.indexOf("zh") !== 0) continue;
      return (/hans|cn|sg|my/.test(l)) ? "hans" : "hant";
    }
    return "hant";
  }

  var lang = detect();
  var t2s = null; // 繁→簡(顯示)
  var s2t = null; // 簡→繁(搜尋字串)

  window.SiteLang = {
    get lang() { return lang; },
    /** 簡體模式下把使用者輸入轉繁體(供搜尋);轉換器未就緒則原樣返回 */
    toHant: function (s) { return (lang === "hans" && s2t) ? s2t(String(s)) : s; }
  };

  function wireToggle() {
    var el = document.getElementById("langToggle");
    if (!el) return;
    el.textContent = lang === "hans" ? "繁體" : "简体";
    el.setAttribute("data-no-convert", "1");
    el.title = lang === "hans" ? "切換為繁體中文" : "切换为简体中文";
    el.addEventListener("click", function (e) {
      e.preventDefault();
      try { localStorage.setItem(KEY, lang === "hans" ? "hant" : "hans"); } catch (_) {}
      location.reload(); // 重載即以繁體原文重繪;簡體模式於載入時整頁轉換
    });
  }

  function convertText(node) {
    var c = t2s(node.data);
    if (c !== node.data) node.data = c;
  }

  function convertAttrs(el) {
    for (var i = 0; i < ATTRS.length; i++) {
      var v = el.getAttribute && el.getAttribute(ATTRS[i]);
      if (v) {
        var c = t2s(v);
        if (c !== v) el.setAttribute(ATTRS[i], c);
      }
    }
  }

  function convertTree(root) {
    if (!root) return;
    if (root.nodeType === 3) { convertText(root); return; }
    if (root.nodeType !== 1) return;
    var tag = root.tagName;
    if (tag === "SCRIPT" || tag === "STYLE") return;
    if (root.getAttribute && root.getAttribute("data-no-convert") !== null) return;
    convertAttrs(root);
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT, {
      acceptNode: function (n) {
        if (n.nodeType === 1) {
          if (n.tagName === "SCRIPT" || n.tagName === "STYLE"
              || (n.getAttribute && n.getAttribute("data-no-convert") !== null))
            return NodeFilter.FILTER_REJECT;
          convertAttrs(n);
          return NodeFilter.FILTER_SKIP;
        }
        return NodeFilter.FILTER_ACCEPT;
      }
    });
    var t;
    while ((t = walker.nextNode())) convertText(t);
  }

  function observe() {
    // 冪等寫入(轉換後相同即不寫)避免觀察者自我觸發迴圈
    var mo = new MutationObserver(function (muts) {
      for (var i = 0; i < muts.length; i++) {
        var m = muts[i];
        if (m.type === "characterData") {
          convertText(m.target);
        } else if (m.type === "attributes") {
          var v = m.target.getAttribute(m.attributeName);
          if (v) {
            var c = t2s(v);
            if (c !== v) m.target.setAttribute(m.attributeName, c);
          }
        } else {
          for (var j = 0; j < m.addedNodes.length; j++) convertTree(m.addedNodes[j]);
        }
      }
    });
    mo.observe(document.documentElement, {
      childList: true, subtree: true, characterData: true,
      attributes: true, attributeFilter: ATTRS
    });
  }

  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      var s = document.createElement("script");
      s.src = src;
      s.onload = resolve;
      s.onerror = function () { s.remove(); reject(new Error("load fail: " + src)); };
      document.head.appendChild(s);
    });
  }

  function boot() {
    wireToggle();
    if (lang !== "hans") return;
    loadScript("/assets/vendor/opencc-full.js")
      .catch(function () {
        return loadScript("https://cdn.jsdelivr.net/npm/opencc-js@1.4.1/dist/umd/full.js");
      })
      .then(function () {
        if (!window.OpenCC) return;
        t2s = OpenCC.Converter({ from: "tw", to: "cn" });
        s2t = OpenCC.Converter({ from: "cn", to: "tw" });
        document.documentElement.setAttribute("lang", "zh-Hans");
        convertTree(document.documentElement);
        var tt = t2s(document.title);
        if (tt !== document.title) document.title = tt;
        observe();
        var el = document.getElementById("langToggle");
        if (el) el.textContent = "繁體";
      })
      .catch(function () { /* 轉換器載入失敗:維持繁體顯示 */ });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
