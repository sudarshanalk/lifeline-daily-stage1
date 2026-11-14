/* Shows the chosen profile photo's file name next to the styled picker. */
(function () {
	document.querySelectorAll(".lld-file input[type=file]").forEach(function (input) {
		input.addEventListener("change", function () {
			var name = input.files && input.files[0] ? input.files[0].name : "";
			var out = input.parentNode.querySelector(".lld-file-name");
			if (out) out.textContent = name;
		});
	});
})();
