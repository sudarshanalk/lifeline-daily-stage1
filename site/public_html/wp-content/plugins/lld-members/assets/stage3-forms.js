/* Account hub, Library and tap-control helpers (P3-36 C2 / A8 / D3 / D4). */
(function () {
	// A page can carry this file twice (enqueued by the hub, and beside a form): bind once.
	if (window.lldStage3Forms) return;
	window.lldStage3Forms = true;

	// This file can come before the forms it serves (the meal plan), so a choice made on a swap field is noted
	// from the first moment, and the controls are set up once the page is parsed.
	document.addEventListener("change", function (event) {
		var el = event.target;
		if (el && el.name === "lld_recipe" && el.setAttribute) el.setAttribute("data-lld-chosen", "1");
	});

	function init() {
		document.querySelectorAll(".lld-file input[type=file]").forEach(function (input) {
			input.addEventListener("change", function () {
				var name = input.files && input.files[0] ? input.files[0].name : "";
				var out = input.parentNode.querySelector(".lld-file-name");
				if (out) out.textContent = name;
			});
		});

		(function () {
			var root = document.querySelector("[data-lld-library]");
			if (!root) return;
			var search = root.querySelector("[data-lld-library-search]");
			var status = root.querySelector("[data-lld-library-status]");
			var filter = "";

			function paint() {
				var q = (search && search.value ? search.value : "").trim().toLowerCase();
				root.querySelectorAll("[data-lld-library-card]").forEach(function (card) {
					var typeOk = !filter || card.getAttribute("data-lld-type") === filter;
					var title = card.getAttribute("data-lld-title") || "";
					var textOk = !q || title.indexOf(q) !== -1;
					card.hidden = !(typeOk && textOk);
				});
				root.querySelectorAll("[data-lld-library-group]").forEach(function (group) {
					var any = group.querySelector("[data-lld-library-card]:not([hidden])");
					group.hidden = !any;
				});
				if (status) {
					var shown = root.querySelector("[data-lld-library-card]:not([hidden])");
					var filtering = !!(q || filter);
					var none = shown
						? ""
						: filtering
							? status.getAttribute("data-lld-library-none") || ""
							: status.getAttribute("data-lld-library-empty") || status.getAttribute("data-lld-library-none") || "";
					if (status.textContent !== none) status.textContent = none;
				}
			}

			root.querySelectorAll("[data-lld-library-filter]").forEach(function (btn) {
				btn.addEventListener("click", function () {
					filter = btn.getAttribute("data-lld-library-filter") || "";
					root.querySelectorAll("[data-lld-library-filter]").forEach(function (other) {
						var on = other === btn;
						other.classList.toggle("is-active", on);
						other.setAttribute("aria-pressed", on ? "true" : "false");
					});
					paint();
				});
			});
			if (search) search.addEventListener("input", paint);
			paint();
		})();

		// Tap controls (D3 / D4). The server sends each form field visible and its control hidden; here the control
		// takes over and the field is kept in the form, out of sight, so the same names and values are posted.
		function tuck(input) {
			var holder = input.closest("p");
			if (holder) {
				holder.classList.add("lld-visually-hidden");
				holder.setAttribute("aria-hidden", "true");
			}
			input.tabIndex = -1;
		}

		function field(wrap, attr) {
			var input = document.getElementById(wrap.getAttribute(attr) || "");
			if (!input) return null;
			tuck(input);
			(wrap.closest(".lld-control") || wrap).hidden = false;
			return input;
		}

		document.querySelectorAll("[data-lld-stepper]").forEach(function (wrap) {
			var input = field(wrap, "data-lld-stepper");
			if (!input) return;
			var out = wrap.querySelector("[data-lld-stepper-value]");
			var min = parseInt(input.getAttribute("min") || "0", 10);
			var max = parseInt(input.getAttribute("max") || "40", 10);
			function paint() {
				var n = parseInt(String(input.value || "").trim(), 10);
				var text = isNaN(n) ? "0" : String(n);
				if (out && out.textContent !== text) out.textContent = text;
			}
			wrap.querySelectorAll("[data-lld-step]").forEach(function (btn) {
				btn.addEventListener("click", function () {
					var n = parseInt(String(input.value || "").trim(), 10);
					if (isNaN(n)) n = 0;
					n += parseInt(btn.getAttribute("data-lld-step") || "0", 10);
					input.value = String(Math.min(max, Math.max(min, n)));
					paint();
				});
			});
			input.addEventListener("input", paint);
			paint();
		});

		// One choice from a row of buttons. Where the form saves a blank (sex), tapping the chosen one again clears it.
		function choiceRow(rowAttr, btnAttr, canClear) {
			document.querySelectorAll("[" + rowAttr + "]").forEach(function (wrap) {
				var input = field(wrap, rowAttr);
				if (!input) return;
				function paint() {
					var v = String(input.value || "");
					wrap.querySelectorAll("[" + btnAttr + "]").forEach(function (btn) {
						var on = btn.getAttribute(btnAttr) === v;
						btn.classList.toggle("is-active", on);
						btn.setAttribute("aria-pressed", on ? "true" : "false");
					});
				}
				wrap.querySelectorAll("[" + btnAttr + "]").forEach(function (btn) {
					btn.addEventListener("click", function () {
						var v = btn.getAttribute(btnAttr) || "";
						input.value = canClear && String(input.value || "") === v ? "" : v;
						paint();
					});
				});
				input.addEventListener("input", paint);
				input.addEventListener("change", paint);
				paint();
			});
		}
		choiceRow("data-lld-taps", "data-lld-tap", false);
		choiceRow("data-lld-pills", "data-lld-pill", true);

		// Meal swap: Swap opens the list of alternatives, and a tap on one saves it. If the field already holds a
		// choice (it was changed directly), Swap saves that choice as before.
		document.querySelectorAll("form[data-lld-swap]").forEach(function (form) {
			var select = form.querySelector("select[name='lld_recipe']");
			var list = form.querySelector("[data-lld-swap-panel]");
			var swap = form.querySelector("button[type=submit]");
			if (!select || !list || !swap) return;
			tuck(select);
			if (!list.id) list.id = "lld_swap_list_" + (form.getAttribute("data-lld-swap") || "");
			swap.setAttribute("aria-controls", list.id);
			swap.setAttribute("aria-expanded", "false");
			form.addEventListener("submit", function (event) {
				if (select.hasAttribute("data-lld-chosen")) return;
				event.preventDefault();
				list.hidden = !list.hidden;
				swap.setAttribute("aria-expanded", list.hidden ? "false" : "true");
				if (!list.hidden) {
					var first = list.querySelector("[data-lld-swap-pick]");
					if (first) first.focus();
				}
			});
			list.querySelectorAll("[data-lld-swap-pick]").forEach(function (btn) {
				btn.addEventListener("click", function () {
					select.value = btn.getAttribute("data-lld-swap-pick") || "";
					select.setAttribute("data-lld-chosen", "1");
					if (typeof form.requestSubmit === "function") form.requestSubmit(swap);
					else form.submit();
				});
			});
		});
	}

	if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
	else init();
})();
