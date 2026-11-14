/* Stage 3 form helpers (P3-36 C2 / A8 / D3 / D4). */
(function () {
	var imperial = document.querySelector("[data-lld-imperial]");
	var imperialFields = document.querySelector("[data-lld-imperial-fields]");
	var metricFields = document.querySelector("[data-lld-metric-fields]");
	if (imperial && imperialFields) {
		var paintImperial = function () {
			if (imperial.checked) {
				imperialFields.removeAttribute("hidden");
				if (metricFields) metricFields.setAttribute("hidden", "");
			} else {
				imperialFields.setAttribute("hidden", "");
				if (metricFields) metricFields.removeAttribute("hidden");
			}
		};
		imperial.addEventListener("change", paintImperial);
		paintImperial();
	}

	document.querySelectorAll(".lld-file input[type=file]").forEach(function (input) {
		input.addEventListener("change", function () {
			var name = input.files && input.files[0] ? input.files[0].name : "";
			var out = input.parentNode.querySelector(".lld-file-name");
			if (out) out.textContent = name;
		});
	});

	var library = document.querySelector("[data-lld-library]");
	if (library) {
		var search = library.querySelector("[data-lld-library-search]");
		var filter = "";
		function paintLibrary() {
			var q = (search && search.value ? search.value : "").trim().toLowerCase();
			library.querySelectorAll("[data-lld-library-card]").forEach(function (card) {
				var typeOk = !filter || card.getAttribute("data-lld-type") === filter;
				var title = card.getAttribute("data-lld-title") || "";
				card.hidden = !(typeOk && (!q || title.indexOf(q) !== -1));
			});
			library.querySelectorAll("[data-lld-library-group]").forEach(function (group) {
				group.hidden = !group.querySelector("[data-lld-library-card]:not([hidden])");
			});
		}
		library.querySelectorAll("[data-lld-library-filter]").forEach(function (btn) {
			btn.addEventListener("click", function () {
				filter = btn.getAttribute("data-lld-library-filter") || "";
				library.querySelectorAll("[data-lld-library-filter]").forEach(function (other) {
					var on = other === btn;
					other.classList.toggle("is-active", on);
					other.setAttribute("aria-pressed", on ? "true" : "false");
				});
				paintLibrary();
			});
		});
		if (search) search.addEventListener("input", paintLibrary);
		paintLibrary();
	}

	document.querySelectorAll("[data-lld-stepper]").forEach(function (wrap) {
		var input = document.getElementById(wrap.getAttribute("data-lld-stepper") || "");
		if (!input) return;
		var out = wrap.querySelector("[data-lld-stepper-value]");
		var min = parseInt(input.getAttribute("min") || "0", 10);
		var max = parseInt(input.getAttribute("max") || "40", 10);
		function paint(writeEmpty) {
			var raw = String(input.value || "").trim();
			if ("" === raw && !writeEmpty) {
				if (out) out.textContent = "0";
				return;
			}
			var n = parseInt(raw || "0", 10);
			if (isNaN(n)) n = min;
			if (n < min) n = min;
			if (n > max) n = max;
			input.value = String(n);
			if (out) out.textContent = String(n);
		}
		wrap.querySelectorAll("[data-lld-step]").forEach(function (btn) {
			btn.addEventListener("click", function () {
				var delta = parseInt(btn.getAttribute("data-lld-step") || "0", 10);
				var raw = String(input.value || "").trim();
				var n = "" === raw ? 0 : parseInt(raw, 10);
				if (isNaN(n)) n = 0;
				input.value = String(n + delta);
				paint(true);
			});
		});
		input.addEventListener("input", function () {
			paint(true);
		});
		paint(false);
	});

	document.querySelectorAll("[data-lld-taps]").forEach(function (wrap) {
		var input = document.getElementById(wrap.getAttribute("data-lld-taps") || "");
		if (!input) return;
		function paint() {
			var v = String(input.value || "");
			wrap.querySelectorAll("[data-lld-tap]").forEach(function (btn) {
				var on = btn.getAttribute("data-lld-tap") === v;
				btn.classList.toggle("is-active", on);
				btn.setAttribute("aria-pressed", on ? "true" : "false");
			});
		}
		wrap.querySelectorAll("[data-lld-tap]").forEach(function (btn) {
			btn.addEventListener("click", function () {
				input.value = btn.getAttribute("data-lld-tap") || "";
				paint();
			});
		});
		input.addEventListener("input", paint);
		paint();
	});

	document.querySelectorAll("[data-lld-pills]").forEach(function (wrap) {
		var input = document.getElementById(wrap.getAttribute("data-lld-pills") || "");
		if (!input) return;
		function paint() {
			var v = String(input.value || "");
			wrap.querySelectorAll("[data-lld-pill]").forEach(function (btn) {
				var on = btn.getAttribute("data-lld-pill") === v;
				btn.classList.toggle("is-active", on);
				btn.setAttribute("aria-pressed", on ? "true" : "false");
			});
		}
		wrap.querySelectorAll("[data-lld-pill]").forEach(function (btn) {
			btn.addEventListener("click", function () {
				input.value = btn.getAttribute("data-lld-pill") || "";
				input.dispatchEvent(new Event("change", { bubbles: true }));
				paint();
			});
		});
		input.addEventListener("change", paint);
		paint();
	});

	document.querySelectorAll("[data-lld-swap]").forEach(function (form) {
		var select = form.querySelector("select[name='lld_recipe']");
		if (!select) return;
		form.querySelectorAll("[data-lld-swap-pick]").forEach(function (btn) {
			btn.addEventListener("click", function () {
				select.value = btn.getAttribute("data-lld-swap-pick") || "";
				if (typeof form.requestSubmit === "function") form.requestSubmit();
				else form.submit();
			});
		});
	});
})();
