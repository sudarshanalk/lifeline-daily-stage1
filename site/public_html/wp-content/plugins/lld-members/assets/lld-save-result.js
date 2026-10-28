/* FR2-23: "Save this result to my profile" below a calculator. The calculator page's link script posts its finished
 * headline, unit, band and version; this fills the hidden form and shows it. When the calculator withdraws its number,
 * the form is emptied and hidden again. Only messages from the calculator frame on this page, from the address it was
 * loaded from (or the Stage 1 configured tool origin, lld_tools_settings.iframe_origin), are accepted. */
(function () {
  var form = document.getElementById("lld-save-result");
  if (!form) return;
  var names = ["lld_headline", "lld_unit", "lld_band", "lld_version"];

  function originOf(address) {
    try { return new URL(address, window.location.href).origin; } catch (e) { return ""; }
  }

  function fromTool(event) {
    if (!event.source) return false;
    var settings = window.lld_tools_settings || {};
    var frames = document.querySelectorAll("iframe.lld-tool-frame");
    for (var i = 0; i < frames.length; i += 1) {
      if (frames[i].contentWindow !== event.source) continue;
      var loaded = originOf(frames[i].getAttribute("src") || "");
      return (loaded !== "" && event.origin === loaded) || (!!settings.iframe_origin && event.origin === settings.iframe_origin);
    }
    return false;
  }

  window.addEventListener("message", function (event) {
    if (!fromTool(event)) return;
    var data = event.data || {};
    if (data.calculator !== form.getAttribute("data-calculator")) return;
    if (data.type === "lld-calculator-result") {
      form.querySelector("[name=lld_headline]").value = String(data.headline || "").slice(0, 64);
      form.querySelector("[name=lld_unit]").value = String(data.unit || "").slice(0, 64);
      form.querySelector("[name=lld_band]").value = String(data.band || "").slice(0, 64);
      form.querySelector("[name=lld_version]").value = String(data.version || "").slice(0, 64);
      form.hidden = false;
    } else if (data.type === "lld-calculator-cleared") {
      names.forEach(function (name) {
        var field = form.querySelector("[name=" + name + "]");
        if (field) field.value = "";
      });
      form.hidden = true;
    }
  });
})();
