/* Account hub and Library helpers (P3-36 C2 / A8). */
(function () {
	document.querySelectorAll(".lld-file input[type=file]").forEach(function (input) {
		input.addEventListener("change", function () {
			var name = input.files && input.files[0] ? input.files[0].name : "";
			var out = input.parentNode.querySelector(".lld-file-name");
			if (out) out.textContent = name;
		});
	});

	var root = document.querySelector("[data-lld-library]");
	if (!root) return;
	var search = root.querySelector("[data-lld-library-search]");
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
