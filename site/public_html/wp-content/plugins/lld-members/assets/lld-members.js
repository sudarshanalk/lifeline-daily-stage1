(function () {
  // Screen Design section 4: after moving screens, focus goes to the new screen's main heading.
  function focusHeading() {
    var heading = document.querySelector("main h1");
    if (!heading) return;
    if (!heading.hasAttribute("tabindex")) heading.setAttribute("tabindex", "-1");
    heading.focus();
  }

  document.querySelectorAll(".lld-eye").forEach(function (button) {
    button.addEventListener("click", function () {
      var input = document.getElementById(button.getAttribute("aria-controls"));
      if (!input) return;
      var show = input.getAttribute("type") === "password";
      input.setAttribute("type", show ? "text" : "password");
      button.textContent = show ? button.getAttribute("data-lld-hide") : button.getAttribute("data-lld-show");
      button.setAttribute("aria-pressed", show ? "true" : "false");
    });
  });

  document.querySelectorAll("[data-lld-table-toggle]").forEach(function (button) {
    button.addEventListener("click", function () {
      var table = button.parentElement.querySelector("table");
      if (!table) return;
      var open = table.hasAttribute("hidden");
      if (open) table.removeAttribute("hidden");
      else table.setAttribute("hidden", "");
      button.setAttribute("aria-expanded", open ? "true" : "false");
      button.textContent = open ? "Hide table" : "Show as table";
    });
  });

  document.querySelectorAll("[data-lld-open]").forEach(function (button) {
    button.addEventListener("click", function () {
      var dialog = document.getElementById(button.getAttribute("data-lld-open"));
      if (!dialog || typeof dialog.showModal !== "function") return;
      dialog.showModal();
      var close = dialog.querySelector("[data-lld-close]");
      if (close) close.focus();
      dialog.addEventListener("close", function () { button.focus(); }, { once: true });
    });
  });

  document.querySelectorAll("[data-lld-close]").forEach(function (button) {
    button.addEventListener("click", function () {
      var dialog = button.closest("dialog");
      if (dialog) dialog.close();
    });
  });

  var timer = document.querySelector("[data-lld-timer]");
  if (timer) {
    var count = timer.querySelector(".lld-timer-count");
    var left = 30;
    var handle = 0;
    function paint() {
      var m = Math.floor(left / 60);
      var s = left % 60;
      count.textContent = m + ":" + (s < 10 ? "0" : "") + s;
    }
    timer.querySelector("[data-lld-timer-start]").addEventListener("click", function () {
      if (handle) return;
      count.textContent = "Exercise started. " + count.textContent;
      handle = window.setInterval(function () {
        left -= 1;
        if (left === 5 || left === 0) {
          count.textContent = left === 0 ? "Finished" : String(left);
        } else {
          paint();
        }
        if (left <= 0) window.clearInterval(handle);
      }, 1000);
    });
    timer.querySelector("[data-lld-timer-pause]").addEventListener("click", function () {
      window.clearInterval(handle);
      handle = 0;
      paint();
    });
  }

  function keyboard(on) {
    document.body.classList.toggle("lld-keyboard", on);
  }
  // NFR2-04: the focused item is shown whole. WebKit leaves a field that is only partly on screen where it is when
  // Tab reaches it (for example a textarea at the bottom edge), so it is brought fully into view.
  function keepInView(el) {
    if (!el || el === document.body || typeof el.getBoundingClientRect !== "function" || typeof el.scrollIntoView !== "function") return;
    var box = el.getBoundingClientRect();
    var tall = window.innerHeight || document.documentElement.clientHeight;
    if (box.top >= 0 && box.bottom <= tall) return;
    try {
      el.scrollIntoView({ block: "nearest", inline: "nearest" });
    } catch (e) {
      el.scrollIntoView(false);
    }
  }
  document.addEventListener("focusin", function (event) {
    if (event.target.matches("input, textarea, select")) keyboard(true);
    keepInView(event.target);
  });
  document.addEventListener("focusout", function () {
    window.setTimeout(function () {
      var active = document.activeElement;
      if (!active || !active.matches("input, textarea, select")) keyboard(false);
    }, 0);
  });

  window.addEventListener("offline", function () {
    var banner = document.getElementById("lld-offline");
    if (banner) banner.hidden = false;
  });
  window.addEventListener("online", function () {
    var banner = document.getElementById("lld-offline");
    if (banner) banner.hidden = true;
  });

  function limitGoals() {
    var boxes = Array.prototype.slice.call(document.querySelectorAll("[data-lld-goal]"));
    var chosen = boxes.filter(function (item) { return item.checked; }).length;
    boxes.forEach(function (item) {
      if (!item.checked) item.disabled = chosen >= 3;
    });
    var note = document.getElementById("lld-goal-limit");
    if (note) note.hidden = chosen < 3;
  }
  document.querySelectorAll("[data-lld-goal]").forEach(function (box) {
    box.addEventListener("change", limitGoals);
  });
  if (document.querySelector("[data-lld-goal]")) limitGoals();

  var screenForm = document.querySelector("[data-lld-screen]");
  if (screenForm) {
    var screenButton = screenForm.querySelector("button");
    function screenReady() {
      var harm = screenForm.querySelector("input[name='lld_harm']:checked");
      var panic = screenForm.querySelector("input[name='lld_panic']:checked");
      var mood = screenForm.querySelector("input[name='lld_mood']:checked");
      // Yes to self-harm overrides every other answer (FR2-38), so it never waits for the other two.
      if (screenButton) screenButton.disabled = !((harm && harm.value === "yes") || (harm && panic && mood));
    }
    screenForm.addEventListener("change", screenReady);
    screenReady();
  }

  var imperial = document.querySelector("[data-lld-imperial]");
  var imperialFields = document.querySelector("[data-lld-imperial-fields]");
  if (imperial && imperialFields) {
    function paintImperial() {
      if (imperial.checked) imperialFields.removeAttribute("hidden");
      else imperialFields.setAttribute("hidden", "");
    }
    imperial.addEventListener("change", paintImperial);
    paintImperial();
  }

  // Shopping-list ticks stay on this device and browser only (UI-3), one set per meal week.
  var shopping = document.querySelector("[data-lld-shopping]");
  if (shopping) {
    var shopWeek = shopping.getAttribute("data-lld-shop-week") || "";
    shopping.querySelectorAll("input[data-lld-tick]").forEach(function (box) {
      var key = "lld-shop:" + shopWeek + ":" + box.getAttribute("data-lld-tick");
      try {
        box.checked = window.localStorage.getItem(key) === "1";
      } catch (e) {}
      box.addEventListener("change", function () {
        try {
          if (box.checked) window.localStorage.setItem(key, "1");
          else window.localStorage.removeItem(key);
        } catch (e) {}
      });
    });
  }

  var ready = document.getElementById("lld-ready");
  var session = document.getElementById("lld-session");
  var timer = document.getElementById("lld-timer");
  var phase = document.getElementById("lld-phase");
  if (ready && session && timer && phase) {
    var handle = null;
    var queue = (timer.getAttribute("data-steps") || "").split(";").filter(Boolean);
    var step = 0;
    function showStep() {
      var parts = (queue[step] || "0|0").split("|");
      timer.textContent = parts[0];
      timer.setAttribute("data-left", parts[0]);
      timer.setAttribute("data-rest", parts[1] || "0");
      timer.setAttribute("data-mode", "exercise");
      phase.textContent = "exercise";
      var items = session.querySelectorAll("[data-lld-step]");
      var name = document.getElementById("lld-exercise");
      var how = document.getElementById("lld-how");
      for (var i = 0; i < items.length; i += 1) {
        if (i === step) items[i].setAttribute("aria-current", "step");
        else items[i].removeAttribute("aria-current");
      }
      if (items[step] && name) name.textContent = items[step].getAttribute("data-name");
      if (items[step] && how) how.textContent = items[step].getAttribute("data-how");
    }
    showStep();
    ready.addEventListener("click", function () {
      var advice = document.getElementById("lld-safety");
      if (advice) advice.hidden = true;
      session.hidden = false;
    });
    var start = document.getElementById("lld-start");
    var pause = document.getElementById("lld-pause");
    function tick() {
      var left = Number(timer.getAttribute("data-left")) - 1;
      var mode = timer.getAttribute("data-mode");
      if (left > 0) {
        timer.setAttribute("data-left", String(left));
        timer.textContent = String(left);
        return;
      }
      if (mode === "exercise") {
        var rest = timer.getAttribute("data-rest") || "0";
        timer.setAttribute("data-mode", "rest");
        timer.setAttribute("data-left", rest);
        timer.textContent = rest;
        phase.textContent = "rest";
        return;
      }
      step += 1;
      if (step < queue.length) {
        showStep();
        return;
      }
      if (handle) clearInterval(handle);
      handle = null;
      timer.textContent = "0";
      phase.textContent = "done";
    }
    if (start) {
      start.addEventListener("click", function () {
        if (handle) return;
        handle = setInterval(tick, 1000);
      });
    }
    if (pause) {
      pause.addEventListener("click", function () {
        if (handle) clearInterval(handle);
        handle = null;
      });
    }
  }

  // FR2-23: when the calculator withdraws its result, the save form is emptied and hidden so the old number cannot be saved.
  var saveForm = document.getElementById("lld-save-result");
  if (saveForm) {
    window.addEventListener("message", function (event) {
      if (event.origin !== window.location.origin) return;
      var data = event.data || {};
      if (data.type !== "lld-calculator-cleared" || data.calculator !== saveForm.getAttribute("data-calculator")) return;
      ["lld_headline", "lld_unit", "lld_band", "lld_version"].forEach(function (name) {
        var field = saveForm.querySelector("[name=" + name + "]");
        if (field) field.value = "";
      });
      saveForm.hidden = true;
    });
  }

  var fail = document.querySelector(".lld-fail");
  if (fail) {
    if (!fail.hasAttribute("tabindex")) fail.setAttribute("tabindex", "-1");
    fail.focus();
  } else if (document.querySelector("main h1")) {
    focusHeading();
  }
})();
