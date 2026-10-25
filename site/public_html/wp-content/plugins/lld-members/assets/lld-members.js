(function () {
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
  document.addEventListener("focusin", function (event) {
    if (event.target.matches("input, textarea, select")) keyboard(true);
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
      if (screenButton) screenButton.disabled = !(harm && panic && mood);
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

  var fail = document.querySelector(".lld-fail");
  if (fail) {
    if (!fail.hasAttribute("tabindex")) fail.setAttribute("tabindex", "-1");
    fail.focus();
  } else if (document.querySelector("main h1")) {
    focusHeading();
  }
})();
