/**
 * Lifeline Daily — Stage 3 Food & Health Logging Front-End Controller (P3-36 B1 & B2).
 *
 * Talks strictly to REST API endpoints under /wp-json/lld-members/v1/me/...
 */
(function () {
	'use strict';

	function parseConfig(el, attr) {
		var raw = el ? el.getAttribute(attr) : '';
		if (!raw) {
			return null;
		}
		try {
			return JSON.parse(raw);
		} catch (e) {
			return null;
		}
	}

	function esc(value) {
		var s = value === null || value === undefined ? '' : String(value);
		return s
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function safeUrl(value) {
		var s = String(value || '').trim();
		if ((s.indexOf('/') === 0 && s.indexOf('//') !== 0) || s.indexOf('https://') === 0 || s.indexOf('http://') === 0) {
			return s;
		}
		return '#';
	}

	function fmtNum(val, decimals) {
		if (val === null || val === undefined || isNaN(Number(val))) {
			return '0';
		}
		var n = Number(val);
		if (!decimals) {
			return Math.round(n).toLocaleString();
		}
		return Number(n.toFixed(decimals)).toLocaleString();
	}

	function shiftDate(ymd, deltaDays) {
		var parts = String(ymd || '').split('-').map(Number);
		if (parts.length !== 3) {
			return ymd;
		}
		var d = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2] + deltaDays));
		return d.toISOString().slice(0, 10);
	}

	function mondayOf(ymd) {
		var parts = String(ymd || '').split('-').map(Number);
		if (parts.length !== 3) {
			return ymd;
		}
		var d = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2]));
		var day = d.getUTCDay(); // 0=Sun..6=Sat
		var diff = day === 0 ? -6 : 1 - day;
		d.setUTCDate(d.getUTCDate() + diff);
		return d.toISOString().slice(0, 10);
	}

	function apiRequest(cfg, path, options) {
		var opts = options || {};
		var headers = Object.assign(
			{
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce || ''
			},
			opts.headers || {}
		);
		return fetch(cfg.api.replace(/\/$/, '') + '/' + path.replace(/^\//, ''), {
			method: opts.method || 'GET',
			credentials: 'same-origin',
			headers: headers,
			body: opts.body ? JSON.stringify(opts.body) : undefined
		})
			.then(function (res) {
				return res
					.json()
					.catch(function () {
						return {};
					})
					.then(function (data) {
						return { ok: res.ok, status: res.status, data: data || {} };
					});
			})
			.catch(function () {
				return { ok: false, status: 0, data: { message: 'Network error. Please try again.' } };
			});
	}

	function errMsg(res, fallback) {
		if (res && res.data) {
			if (res.data.message) {
				return String(res.data.message);
			}
			if (res.data.error) {
				return String(res.data.error);
			}
		}
		return fallback || 'Could not complete that request.';
	}

	function trapDialogFocus(modalWrap, ev) {
		if (ev.key !== 'Tab' || !modalWrap || modalWrap.hidden) {
			return;
		}
		var dialog = modalWrap.querySelector('[role="dialog"]') || modalWrap;
		var all = dialog.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])');
		var focusable = [];
		all.forEach(function (node) {
			if (node.offsetParent !== null || node.getClientRects().length > 0) {
				focusable.push(node);
			}
		});
		if (!focusable.length) {
			return;
		}
		var first = focusable[0];
		var last = focusable[focusable.length - 1];
		if (!dialog.contains(document.activeElement)) {
			ev.preventDefault();
			first.focus();
			return;
		}
		if (ev.shiftKey && document.activeElement === first) {
			ev.preventDefault();
			last.focus();
		} else if (!ev.shiftKey && document.activeElement === last) {
			ev.preventDefault();
			first.focus();
		}
	}

	/* ============================ B1: FOOD SCREEN ============================ */
	function initFood(root) {
		var cfg = parseConfig(root, 'data-lld-config');
		if (!cfg) {
			return;
		}

		var features = cfg.features || {};
		var state = {
			date: cfg.date,
			day: cfg.initialDay || null,
			meal: 'breakfast',
			edMode: !!cfg.edMode,
			kidneyMode: !!cfg.kidneyMode,
			pickedFood: null,
			recipeIngredients: [],
			recipes: [],
			editingRecipeId: null,
			favourites: [],
			recentFoods: [],
			customFoods: [],
			catalog: {},
			lastFocus: null,
			searchTimer: null
		};

		var els = {
			status: root.querySelector('[data-lld-food-status]'),
			dateInput: root.querySelector('[data-lld-food-date-input]'),
			copyDateInput: root.querySelector('[data-lld-copy-date]'),
			locked: root.querySelector('[data-lld-food-locked]'),
			lockedMsg: root.querySelector('[data-lld-food-locked-msg]'),
			summary: root.querySelector('[data-lld-food-summary]'),
			summaryBody: root.querySelector('[data-lld-food-summary-body]'),
			mealsWrap: root.querySelector('[data-lld-food-meals]'),
			earlierDay: root.querySelector('[data-lld-earlier-day]'),
			weekWrap: root.querySelector('[data-lld-food-week]'),
			weekRange: root.querySelector('[data-lld-week-range]'),
			weekBody: root.querySelector('[data-lld-food-week-body]'),
			targetsPanel: root.querySelector('[data-lld-targets-panel]'),
			targetForm: root.querySelector('[data-lld-target-form]'),
			targetMsg: root.querySelector('[data-lld-target-msg]'),
			modal: root.querySelector('[data-lld-add-modal]'),
			modalMeal: root.querySelector('[data-lld-modal-meal]'),
			modalMsg: root.querySelector('[data-lld-modal-msg]'),
			searchInput: root.querySelector('[data-lld-search-input]'),
			searchResults: root.querySelector('[data-lld-search-results]'),
			portionPicker: root.querySelector('[data-lld-portion-picker]'),
			pickedName: root.querySelector('[data-lld-picked-name]'),
			pickedSource: root.querySelector('[data-lld-picked-source]'),
			pickedAttr: root.querySelector('[data-lld-picked-attribution]'),
			portionPresets: root.querySelector('[data-lld-portion-presets]'),
			portionGrams: root.querySelector('[data-lld-portion-grams]'),
			portionPreview: root.querySelector('[data-lld-portion-preview]'),
			recentResults: root.querySelector('[data-lld-recent-results]'),
			favouritesResults: root.querySelector('[data-lld-favourites-results]'),
			modalRecipes: root.querySelector('[data-lld-modal-recipes]'),
			customForm: root.querySelector('[data-lld-custom-form]'),
			recipeForm: root.querySelector('[data-lld-recipe-form]'),
			recipeSubmit: root.querySelector('[data-lld-recipe-submit]'),
			recipeCancelEdit: root.querySelector('[data-lld-cancel-recipe-edit]'),
			recipeIngQ: root.querySelector('[data-lld-recipe-ing-q]'),
			recipeIngG: root.querySelector('[data-lld-recipe-ing-g]'),
			recipeIngHits: root.querySelector('[data-lld-recipe-ing-hits]'),
			recipePicked: root.querySelector('[data-lld-recipe-picked]'),
			recipeMsg: root.querySelector('[data-lld-recipe-msg]'),
			recipesList: root.querySelector('[data-lld-recipes-list]'),
			prefsForm: root.querySelector('[data-lld-prefs-form]'),
			prefsMsg: root.querySelector('[data-lld-prefs-msg]'),
			suggestionsList: root.querySelector('[data-lld-suggestions-list]')
		};

		function setStatus(text, isError) {
			if (!els.status) {
				return;
			}
			els.status.textContent = text || '';
			els.status.classList.toggle('is-error', !!isError);
		}

		function setMsg(node, text, isError) {
			if (!node) {
				return;
			}
			node.textContent = text || '';
			node.classList.toggle('is-error', !!isError);
		}

		function favKey(kind, id) {
			return String(kind || 'food') + ':' + String(Number(id || 0));
		}

		function rememberItem(kind, id, name, grams) {
			var numId = Number(id || 0);
			if (!numId || !name) {
				return;
			}
			var k = favKey(kind, numId);
			var prev = state.catalog[k] || {};
			state.catalog[k] = {
				food_kind: String(kind || 'food'),
				food_id: numId,
				name: String(name),
				grams: grams !== null && grams !== undefined ? Number(grams) : prev.grams
			};
		}

		function indexDayCatalog(day) {
			if (!day || !day.meals) {
				return;
			}
			Object.keys(day.meals).forEach(function (mealKey) {
				var items = day.meals[mealKey];
				if (Array.isArray(items)) {
					items.forEach(function (it) {
						rememberItem(it.food_kind || 'food', it.food_id, it.name, it.grams);
					});
				}
			});
		}

		function findFavouriteRow(kind, id) {
			var targetKind = String(kind || 'food');
			var targetId = Number(id || 0);
			for (var i = 0; i < state.favourites.length; i += 1) {
				var row = state.favourites[i];
				if (String(row.food_kind || 'food') === targetKind && Number(row.food_id || 0) === targetId) {
					return row;
				}
			}
			return null;
		}

		function favButtonHtml(kind, id, name) {
			var numId = Number(id || 0);
			if (!numId) {
				return '';
			}
			var isFav = !!findFavouriteRow(kind, numId);
			var itemName = name || 'item';
			return (
				'<button type="button" class="lld-btn-ghost lld-btn-sm lld-food-fav-btn" data-lld-fav-toggle data-lld-fav-kind="' +
				esc(kind || 'food') +
				'" data-lld-fav-id="' +
				esc(numId) +
				'" data-lld-fav-name="' +
				esc(itemName) +
				'" aria-pressed="' +
				(isFav ? 'true' : 'false') +
				'" aria-label="' +
				esc((isFav ? 'Remove ' : 'Save ') + itemName + (isFav ? ' from favourites' : ' as favourite')) +
				'">' +
				(isFav ? '&#9733;' : '&#9734;') +
				'</button>'
			);
		}

		function syncFavButtons() {
			root.querySelectorAll('[data-lld-fav-toggle]').forEach(function (btn) {
				var kind = btn.getAttribute('data-lld-fav-kind') || 'food';
				var id = btn.getAttribute('data-lld-fav-id') || '0';
				var itemName = btn.getAttribute('data-lld-fav-name') || 'item';
				var isFav = !!findFavouriteRow(kind, id);
				btn.setAttribute('aria-pressed', isFav ? 'true' : 'false');
				btn.setAttribute('aria-label', (isFav ? 'Remove ' : 'Save ') + itemName + (isFav ? ' from favourites' : ' as favourite'));
				btn.innerHTML = isFav ? '&#9733;' : '&#9734;';
			});
		}

		function syncSafety(day) {
			indexDayCatalog(day);
		}

		function renderSummary(day) {
			if (!els.summary || !els.summaryBody) {
				return;
			}
			if (state.edMode || !day || day.locked || !day.totals) {
				els.summary.hidden = true;
				return;
			}
			els.summary.hidden = false;
			var totals = day.totals || {};
			var target = day.target || null;
			var left = day.left || null;
			var over = day.over || null;
			// Only what the interface returned is printed: a total it left out gets no tile and no zero.
			var has = function (obj, key) {
				return !!obj && obj[key] !== null && obj[key] !== undefined && !isNaN(Number(obj[key]));
			};
			var hasKcal = has(totals, 'kcal');
			var kcal = Math.round(Number(totals.kcal || 0));
			var tgtKcal = target && target.kcal !== null && target.kcal !== undefined ? Math.round(Number(target.kcal)) : null;
			var isOver = !!day.over_note || (tgtKcal !== null && kcal > tgtKcal);
			var pct = tgtKcal && tgtKcal > 0 ? Math.min(100, Math.round((kcal / tgtKcal) * 100)) : 0;

			var rightNote = '';
			if (day.over_note) {
				rightNote = '<span class="lld-food-over-note">' + esc(day.over_note) + '</span>';
			} else if (over && over.kcal > 0) {
				rightNote = '<span class="lld-food-over-note">Over by ' + fmtNum(over.kcal) + ' kcal today.</span>';
			} else if (left && left.kcal !== null && left.kcal !== undefined) {
				rightNote = '<span class="lld-food-left-note">' + fmtNum(left.kcal) + ' kcal left</span>';
			}

			var barHtml = '';
			if (tgtKcal !== null) {
				barHtml =
					'<div class="lld-food-cal-bar" role="progressbar" aria-valuenow="' +
					kcal +
					'" aria-valuemin="0" aria-valuemax="' +
					tgtKcal +
					'">' +
					'<span class="lld-food-cal-fill' +
					(isOver ? ' is-over' : '') +
					'" style="width:' +
					pct +
					'%"></span>' +
					'</div>';
			}

			var macros = [
				{ key: 'protein_g', label: 'Protein' },
				{ key: 'carb_g', label: 'Carbs' },
				{ key: 'fat_g', label: 'Fat' },
				{ key: 'fibre_g', label: 'Fibre' }
			];
			var macroHtml = macros
				.map(function (m) {
					if (!has(totals, m.key)) {
						return '';
					}
					var val = Number(totals[m.key]);
					var tgtVal = has(target, m.key) ? Math.round(Number(target[m.key])) : null;
					var sub = '';
					if (tgtVal !== null) {
						if (over && over[m.key] > 0) {
							sub = '<span class="lld-macro-sub lld-macro-over">Target ' + tgtVal + ' g · ' + Math.round(Number(over[m.key])) + ' g over</span>';
						} else if (left && left[m.key] !== null && left[m.key] !== undefined) {
							sub = '<span class="lld-macro-sub">of ' + tgtVal + ' g · ' + Math.round(Number(left[m.key])) + ' g left</span>';
						} else {
							sub = '<span class="lld-macro-sub">Target ' + tgtVal + ' g</span>';
						}
					}
					return (
						'<div class="lld-macro-tile">' +
						'<span class="lld-macro-label">' +
						esc(m.label) +
						'</span>' +
						'<strong class="lld-macro-val">' +
						fmtNum(val, 1) +
						' g</strong>' +
						sub +
						'</div>'
					);
				})
				.join('');

			els.summaryBody.innerHTML =
				(hasKcal
					? '<div class="lld-food-cal-block">' +
					  '<div class="lld-food-cal-numbers">' +
					  '<div><span class="lld-stat-label">Calories today</span><strong class="lld-cal-total">' +
					  fmtNum(kcal) +
					  ' kcal</strong>' +
					  (tgtKcal !== null ? ' <span class="lld-cal-target">of ' + fmtNum(tgtKcal) + ' kcal target</span>' : '') +
					  '</div>' +
					  '<div class="lld-food-cal-state">' +
					  rightNote +
					  '</div>' +
					  '</div>' +
					  barHtml +
					  '</div>'
					: '') +
				'<div class="lld-food-macros">' +
				macroHtml +
				'</div>';

			if (els.targetForm && target) {
				['kcal', 'protein_g', 'carb_g', 'fat_g', 'fibre_g'].forEach(function (field) {
					var input = els.targetForm.elements[field];
					if (input && target[field] !== null && target[field] !== undefined) {
						input.value = Math.round(Number(target[field]));
					}
				});
			}
		}

		function renderLoggedItem(item) {
			var id = Number(item.id || 0);
			var foodId = Number(item.food_id || 0);
			var foodKind = String(item.food_kind || 'food');
			var grams = item.grams !== null && item.grams !== undefined ? Number(item.grams) : 0;
			var unitSuffix = foodKind === 'recipe' ? (grams === 1 ? ' serving' : ' servings') : ' g';
			var meta = '';
			if (!state.edMode) {
				var parts = [];
				var lbl = item.portion_label ? String(item.portion_label) : grams > 0 ? Number(grams.toFixed(1)) + unitSuffix : '';
				if (lbl) {
					parts.push(lbl);
				}
				if (item.kcal !== null && item.kcal !== undefined) {
					parts.push(Math.round(Number(item.kcal)) + ' kcal');
				}
				[
					['P', 'protein_g'],
					['C', 'carb_g'],
					['F', 'fat_g'],
					['Fib', 'fibre_g']
				].forEach(function (pair) {
					var v = item[pair[1]];
					if (v !== null && v !== undefined) {
						parts.push(pair[0] + ' ' + Number(Number(v).toFixed(1)) + 'g');
					}
				});
				meta = '<span class="lld-food-item-meta">' + esc(parts.join(' · ')) + '</span>';
			}
			var favBtn = favButtonHtml(foodKind, foodId, item.name || '');
			var editBtn =
				!state.edMode && id > 0
					? '<button type="button" class="lld-btn-ghost lld-btn-sm" data-lld-edit-item="' +
					  id +
					  '" data-lld-grams="' +
					  esc(grams) +
					  '" data-lld-food-kind="' +
					  esc(foodKind) +
					  '">Edit</button>'
					: '';
			var delBtn =
				id > 0
					? '<button type="button" class="lld-btn-ghost lld-btn-sm" data-lld-delete-item="' + id + '">Remove</button>'
					: '';

			return (
				'<li class="lld-food-item" data-lld-item-id="' +
				id +
				'">' +
				'<div class="lld-food-item-main"><strong class="lld-food-item-name">' +
				esc(item.name || '') +
				'</strong>' +
				meta +
				'</div>' +
				'<div class="lld-food-item-actions">' +
				favBtn +
				editBtn +
				delBtn +
				'</div>' +
				'</li>'
			);
		}

		function renderEarlierItem(entry, badgeLabel) {
			var desc = entry.description ? String(entry.description) : entry.planned ? 'Planned meal' : 'Earlier diary entry';
			var meta = entry.portion ? '<span class="lld-food-item-meta">Portion: ' + esc(entry.portion) + '</span>' : '';
			var photo = entry.photo ? '<a class="lld-btn-ghost lld-btn-sm" href="' + esc(safeUrl(entry.photo)) + '">Photo</a>' : '';
			return (
				'<li class="lld-food-earlier-item">' +
				'<div class="lld-food-item-main">' +
				'<span class="lld-earlier-badge">' +
				esc(badgeLabel) +
				'</span>' +
				'<strong>' +
				esc(desc) +
				'</strong>' +
				meta +
				'</div>' +
				photo +
				'</li>'
			);
		}

		function renderMeals(day) {
			if (!els.mealsWrap) {
				return;
			}
			if (day && day.locked) {
				els.mealsWrap.hidden = true;
				if (els.earlierDay) {
					els.earlierDay.hidden = true;
					els.earlierDay.innerHTML = '';
				}
				if (els.locked) {
					els.locked.hidden = false;
					if (els.lockedMsg) {
						els.lockedMsg.textContent = day.message || 'Free shows the last 14 days. Premium shows your full history.';
					}
				}
				return;
			}
			if (els.locked) {
				els.locked.hidden = true;
			}
			els.mealsWrap.hidden = false;

			var labels = { breakfast: 'breakfast', lunch: 'lunch', dinner: 'dinner', snacks: 'snacks' };
			Object.keys(labels).forEach(function (mealKey) {
				var list = root.querySelector('[data-lld-meal-items="' + mealKey + '"]');
				var earlierList = root.querySelector('[data-lld-meal-earlier="' + mealKey + '"]');
				var kcalEl = root.querySelector('[data-lld-meal-kcal="' + mealKey + '"]');
				var items = day && day.meals && Array.isArray(day.meals[mealKey]) ? day.meals[mealKey] : [];
				var earlier = day && day.earlier && Array.isArray(day.earlier[mealKey]) ? day.earlier[mealKey] : [];
				var earlierLabel = (day && day.earlier_label) || 'Earlier diary entry, nutrition unknown';

				var full = true;
				var subtotal = items.reduce(function (acc, it) {
					if (it.kcal === null || it.kcal === undefined) {
						full = false;
						return acc;
					}
					return acc + Number(it.kcal);
				}, 0);
				if (kcalEl) {
					kcalEl.hidden = state.edMode || !full;
					kcalEl.textContent = state.edMode || !full ? '' : Math.round(subtotal) + ' kcal';
				}
				if (list) {
					if (!items.length && !earlier.length) {
						list.innerHTML = '<li class="lld-food-empty">Nothing logged for ' + esc(labels[mealKey]) + ' yet.</li>';
					} else {
						list.innerHTML = items.map(renderLoggedItem).join('');
					}
				}
				if (earlierList) {
					if (earlier.length) {
						earlierList.hidden = false;
						earlierList.innerHTML = earlier
							.map(function (entry) {
								return renderEarlierItem(entry, earlierLabel);
							})
							.join('');
					} else {
						earlierList.hidden = true;
						earlierList.innerHTML = '';
					}
				}
			});

			if (els.earlierDay) {
				var eday = day && day.earlier_day ? day.earlier_day : null;
				if (eday && (eday.water_glasses || eday.hunger || eday.energy)) {
					var bits = [];
					if (eday.water_glasses) {
						bits.push('Water: ' + eday.water_glasses + ' glasses');
					}
					if (eday.hunger) {
						bits.push('Hunger: ' + eday.hunger);
					}
					if (eday.energy) {
						bits.push('Energy: ' + eday.energy);
					}
					els.earlierDay.hidden = false;
					els.earlierDay.innerHTML =
						'<div class="lld-card lld-food-earlier-note"><span class="lld-earlier-badge">' +
						esc((day && day.earlier_label) || 'Earlier diary entry, nutrition unknown') +
						'</span> <span>' +
						esc(bits.join(' · ')) +
						'</span></div>';
				} else {
					els.earlierDay.hidden = true;
					els.earlierDay.innerHTML = '';
				}
			}
		}

		function loadDay(date) {
			state.date = date;
			if (els.dateInput) {
				els.dateInput.value = date;
			}
			if (els.copyDateInput) {
				els.copyDateInput.value = shiftDate(date, -1);
			}
			setStatus('Loading day...');
			apiRequest(cfg, 'food-day?date=' + encodeURIComponent(date)).then(function (res) {
				if (!res.ok) {
					setStatus(errMsg(res, 'Could not load that day.'), true);
					return;
				}
				state.day = res.data;
				syncSafety(res.data);
				renderSummary(res.data);
				renderMeals(res.data);
				loadWeek(date);
				loadSuggestions(date);
				setStatus('');
			});
		}

		function loadWeek(date) {
			if (state.edMode || !els.weekWrap || !els.weekBody) {
				if (els.weekWrap) {
					els.weekWrap.hidden = true;
				}
				return;
			}
			if (state.day && state.day.locked) {
				els.weekWrap.hidden = true;
				return;
			}
			els.weekWrap.hidden = false;
			if (features.weekly_summary === false) {
				return;
			}
			var monday = mondayOf(date);
			if (els.weekRange) {
				els.weekRange.textContent = 'Week starting ' + monday;
			}
			return apiRequest(cfg, 'food-week?week_start=' + encodeURIComponent(monday)).then(function (res) {
				if (!res.ok) {
					var rawMsg = (res.data && (res.data.message || res.data.error)) || '';
					var isUpgrade = !!(res.data && (res.data.upgrade || /part of Premium/i.test(rawMsg)));
					if (isUpgrade) {
						els.weekBody.innerHTML =
							'<p class="lld-food-calm-note">' +
							esc(errMsg(res, 'The weekly summary is part of Premium.')) +
							' <a href="' +
							esc(safeUrl((res.data && res.data.upgrade_url) || cfg.upgradeUrl)) +
							'">See Premium</a></p>';
					} else {
						els.weekBody.innerHTML = '<p class="lld-food-empty">' + esc(errMsg(res, 'Could not load weekly summary.')) + '</p>';
					}
					return;
				}
				var w = res.data || {};
				var avg = w.averages || null;
				var given = function (obj, key) {
					return !!obj && obj[key] !== null && obj[key] !== undefined && !isNaN(Number(obj[key]));
				};
				var html = '<div class="lld-week-stats">';
				if (given(w, 'logged_days')) {
					html += '<div class="lld-macro-tile"><span class="lld-macro-label">Days logged</span><strong class="lld-macro-val">' + esc(Number(w.logged_days)) + ' of 7</strong></div>';
				}
				// Averages and days on target are numbers about calories: shown only when the interface returned the averages.
				if (given(avg, 'kcal')) {
					if (given(w, 'days_on_target')) {
						html += '<div class="lld-macro-tile"><span class="lld-macro-label">Days on target</span><strong class="lld-macro-val">' + esc(Number(w.days_on_target)) + '</strong></div>';
					}
					var avgParts = [];
					[
						['Protein', 'protein_g'],
						['Carbs', 'carb_g'],
						['Fat', 'fat_g'],
						['Fibre', 'fibre_g']
					].forEach(function (pair) {
						if (given(avg, pair[1])) {
							avgParts.push(pair[0] + ' ' + fmtNum(avg[pair[1]], 1) + ' g');
						}
					});
					html += '<div class="lld-macro-tile"><span class="lld-macro-label">Average calories</span><strong class="lld-macro-val">' + fmtNum(avg.kcal) + ' kcal</strong>' + (avgParts.length ? '<span class="lld-macro-sub">' + esc(avgParts.join(' · ')) + '</span>' : '') + '</div>';
				}
				html += '</div>';
				if (w.tip) {
					html += '<p class="lld-food-tip">' + esc(typeof w.tip === 'string' ? w.tip : w.tip.text || '') + '</p>';
				}
				els.weekBody.innerHTML = html;
			});
		}

		function loadFavourites() {
			return apiRequest(cfg, 'favourites').then(function (res) {
				if (!res.ok) {
					return;
				}
				state.favourites = Array.isArray(res.data.rows) ? res.data.rows : [];
				syncFavButtons();
				renderFavourites();
			});
		}

		function loadCustomFoods() {
			return apiRequest(cfg, 'custom-foods').then(function (res) {
				if (!res.ok) {
					return;
				}
				var rows = Array.isArray(res.data.rows) ? res.data.rows : [];
				state.customFoods = rows;
				rows.forEach(function (cf) {
					rememberItem('custom', cf.id, cf.name, cf.portion_g || 100);
				});
			});
		}

		function renderFavourites() {
			if (!els.favouritesResults) {
				return;
			}
			if (!state.favourites.length) {
				els.favouritesResults.innerHTML = '<li class="lld-food-empty">No favourite foods saved yet.</li>';
				return;
			}
			els.favouritesResults.innerHTML = state.favourites
				.map(function (fav, idx) {
					var kind = fav.food_kind || 'food';
					var id = Number(fav.food_id || 0);
					var cat = state.catalog[favKey(kind, id)];
					var title = cat && cat.name ? cat.name : 'Saved ' + (kind === 'recipe' ? 'recipe' : kind === 'custom' ? 'custom food' : 'food') + ' #' + id;
					var meta = '';
					if (!state.edMode && cat && cat.grams) {
						meta = kind === 'recipe' ? Number(Number(cat.grams).toFixed(1)) + (Number(cat.grams) === 1 ? ' serving' : ' servings') : Number(Number(cat.grams).toFixed(1)) + ' g';
					}
					return (
						'<li class="lld-search-item">' +
						'<div><strong>' +
						esc(title) +
						'</strong>' +
						(meta ? '<span class="lld-food-item-meta">' + esc(meta) + '</span>' : '') +
						'</div>' +
						'<div class="lld-food-item-actions">' +
						'<button type="button" class="lld-btn-ghost lld-btn-sm" data-lld-unfav="' +
						esc(fav.id) +
						'" aria-label="Remove ' +
						esc(title) +
						' from favourites">Remove</button>' +
						'<button type="button" class="lld-pill-btn lld-btn-sm" data-lld-log-fav="' +
						idx +
						'">Add</button>' +
						'</div>' +
						'</li>'
					);
				})
				.join('');
		}

		function loadRecipes() {
			return apiRequest(cfg, 'recipes').then(function (res) {
				if (!res.ok) {
					if (els.recipesList) {
						els.recipesList.innerHTML = '<p class="lld-food-empty">' + esc(errMsg(res, 'Could not load recipes.')) + '</p>';
					}
					return;
				}
				var rows = Array.isArray(res.data.rows) ? res.data.rows : Array.isArray(res.data.recipes) ? res.data.recipes : [];
				state.recipes = rows;
				rows.forEach(function (r) {
					rememberItem('recipe', r.id, r.name, 1);
				});
				renderRecipesList(rows);
				renderModalRecipes(rows);
				renderFavourites();
			});
		}

		function renderRecipesList(rows) {
			if (!els.recipesList) {
				return;
			}
			if (!rows.length) {
				els.recipesList.innerHTML = '<p class="lld-food-empty">No recipes saved yet.</p>';
				return;
			}
			var canEdit = features.recipes !== false;
			els.recipesList.innerHTML =
				'<ul class="lld-search-list">' +
				rows
					.map(function (r) {
						var meta = '';
						if (!state.edMode) {
							meta = String(r.servings || 1) + ' servings' + (r.kcal_per_serving ? ' · ' + Math.round(Number(r.kcal_per_serving)) + ' kcal / serving' : '');
						}
						var logLabel = state.edMode ? 'Log' : 'Log 1 serving';
						var editBtn = canEdit
							? '<button type="button" class="lld-btn-ghost lld-btn-sm" data-lld-edit-recipe="' + esc(r.id) + '">Edit</button>'
							: '';
						return (
							'<li class="lld-search-item">' +
							'<div><strong>' +
							esc(r.name) +
							'</strong>' +
							(meta ? '<span class="lld-food-item-meta">' + esc(meta) + '</span>' : '') +
							'</div>' +
							'<div class="lld-food-item-actions">' +
							editBtn +
							'<button type="button" class="lld-btn-ghost lld-btn-sm" data-lld-delete-recipe="' +
							esc(r.id) +
							'">Delete</button>' +
							'<button type="button" class="lld-btn-ghost lld-btn-sm" data-lld-log-recipe="' +
							esc(r.id) +
							'">' +
							esc(logLabel) +
							'</button>' +
							'</div>' +
							'</li>'
						);
					})
					.join('') +
				'</ul>';
		}

		function renderModalRecipes(rows) {
			if (!els.modalRecipes) {
				return;
			}
			if (!rows.length) {
				els.modalRecipes.innerHTML = '<li class="lld-food-empty">You have not saved any recipes yet.</li>';
				return;
			}
			els.modalRecipes.innerHTML = rows
				.map(function (r) {
					var meta = !state.edMode && r.kcal_per_serving ? Math.round(Number(r.kcal_per_serving)) + ' kcal per serving' : '';
					var btnText = state.edMode ? 'Add to meal' : 'Add 1 serving';
					return (
						'<li class="lld-search-item">' +
						'<div><strong>' +
						esc(r.name) +
						'</strong>' +
						(meta ? '<span class="lld-food-item-meta">' + esc(meta) + '</span>' : '') +
						'</div>' +
						'<button type="button" class="lld-pill-btn lld-btn-sm" data-lld-log-recipe="' +
						esc(r.id) +
						'">' +
						esc(btnText) +
						'</button>' +
						'</li>'
					);
				})
				.join('');
		}

		function parseJsonList(raw) {
			if (Array.isArray(raw)) {
				return raw;
			}
			if (typeof raw === 'string' && raw.trim()) {
				try {
					var parsed = JSON.parse(raw);
					return Array.isArray(parsed) ? parsed : [];
				} catch (e) {
					return [];
				}
			}
			return [];
		}

		function loadPreferences() {
			if (!els.prefsForm) {
				return Promise.resolve();
			}
			return apiRequest(cfg, 'food-preferences').then(function (res) {
				if (!res.ok) {
					return;
				}
				var rows = Array.isArray(res.data.rows) ? res.data.rows : [];
				if (!rows.length) {
					return;
				}
				var prefs = rows[0];
				var allergies = parseJsonList(prefs.allergies);
				var dislikes = parseJsonList(prefs.dislikes);
				var checks = els.prefsForm.querySelectorAll('input[name="allergies[]"]');
				checks.forEach(function (box) {
					box.checked = allergies.indexOf(box.value) !== -1;
				});
				if (els.prefsForm.elements.dislikes) {
					els.prefsForm.elements.dislikes.value = dislikes.join(', ');
				}
			});
		}

		function loadSuggestions(date) {
			if (!els.suggestionsList) {
				return Promise.resolve();
			}
			return apiRequest(cfg, 'recipe-suggestions?date=' + encodeURIComponent(date)).then(function (res) {
				if (!res.ok) {
					var rawMsg = (res.data && (res.data.message || res.data.error)) || '';
					var isUpgrade = !!(res.data && (res.data.upgrade || /part of Premium/i.test(rawMsg)));
					if (isUpgrade) {
						els.suggestionsList.innerHTML =
							'<p class="lld-food-calm-note">' +
							esc(errMsg(res, 'Recipe suggestions are part of Premium.')) +
							' <a href="' +
							esc(safeUrl((res.data && res.data.upgrade_url) || cfg.upgradeUrl)) +
							'">See Premium</a></p>';
					} else {
						els.suggestionsList.innerHTML = '<p class="lld-food-empty">' + esc(errMsg(res, 'Could not load recipe suggestions.')) + '</p>';
					}
					return;
				}
				var list = Array.isArray(res.data.recipes) ? res.data.recipes : [];
				if (!list.length) {
					els.suggestionsList.innerHTML = '<p class="lld-food-empty">No recipe suggestions right now.</p>';
					return;
				}
				els.suggestionsList.innerHTML =
					'<ul class="lld-search-list">' +
					list
						.map(function (r) {
							var meta = !state.edMode && r.kcal_per_serving ? Math.round(Number(r.kcal_per_serving)) + ' kcal / serving' : '';
							return '<li class="lld-search-item"><div><strong>' + esc(r.name) + '</strong>' + (meta ? '<span class="lld-food-item-meta">' + esc(meta) + '</span>' : '') + '</div></li>';
						})
						.join('') +
					'</ul>';
			});
		}

		function openAddModal(mealKey) {
			state.meal = mealKey || 'breakfast';
			state.lastFocus = document.activeElement;
			state.pickedFood = null;
			if (els.modalMeal) {
				els.modalMeal.value = state.meal;
			}
			if (els.portionPicker) {
				els.portionPicker.hidden = true;
			}
			if (els.searchResults) {
				els.searchResults.hidden = false;
			}
			setMsg(els.modalMsg, '');
			els.modal.hidden = false;
			switchModalTab('search');
			if (els.searchInput) {
				els.searchInput.focus();
			}
		}

		function closeAddModal() {
			if (els.modal) {
				els.modal.hidden = true;
			}
			if (state.lastFocus && typeof state.lastFocus.focus === 'function') {
				state.lastFocus.focus();
			}
		}

		function switchModalTab(tabName) {
			root.querySelectorAll('[data-lld-modal-tab]').forEach(function (btn) {
				var active = btn.getAttribute('data-lld-modal-tab') === tabName;
				btn.classList.toggle('is-active', active);
				btn.setAttribute('aria-selected', active ? 'true' : 'false');
			});
			root.querySelectorAll('[data-lld-pane]').forEach(function (pane) {
				pane.hidden = pane.getAttribute('data-lld-pane') !== tabName;
			});
			if (tabName === 'recent') {
				loadRecent();
			} else if (tabName === 'favourites') {
				Promise.all([loadCustomFoods(), loadRecent()]).then(function () {
					loadFavourites();
				});
			} else if (tabName === 'recipes') {
				loadRecipes();
			}
		}

		function loadRecent() {
			if (!els.recentResults) {
				return Promise.resolve();
			}
			els.recentResults.innerHTML = '<li class="lld-food-empty">Loading recent foods...</li>';
			return apiRequest(cfg, 'food-recent').then(function (res) {
				if (!res.ok) {
					els.recentResults.innerHTML = '<li class="lld-food-empty">' + esc(errMsg(res, 'Could not load recent foods.')) + '</li>';
					return;
				}
				var foods = Array.isArray(res.data.foods) ? res.data.foods : [];
				state.recentFoods = foods;
				foods.forEach(function (f) {
					rememberItem(f.food_kind || 'food', f.food_id, f.name, f.grams);
				});
				if (!foods.length) {
					els.recentResults.innerHTML = '<li class="lld-food-empty">No recent foods logged yet.</li>';
					return;
				}
				els.recentResults.innerHTML = foods
					.map(function (f, idx) {
						var meta = '';
						if (!state.edMode && f.grams) {
							meta = f.food_kind === 'recipe' ? Number(Number(f.grams).toFixed(1)) + (Number(f.grams) === 1 ? ' serving' : ' servings') : Number(Number(f.grams).toFixed(1)) + ' g';
						}
						return (
							'<li class="lld-search-item">' +
							'<div><strong>' +
							esc(f.name) +
							'</strong>' +
							(meta ? '<span class="lld-food-item-meta">' + esc(meta) + '</span>' : '') +
							'</div>' +
							'<div class="lld-food-item-actions">' +
							favButtonHtml(f.food_kind || 'food', f.food_id, f.name) +
							'<button type="button" class="lld-pill-btn lld-btn-sm" data-lld-quick-recent="' +
							idx +
							'">Add</button>' +
							'</div>' +
							'</li>'
						);
					})
					.join('');
				els.recentResults._foods = foods;
				renderFavourites();
			});
		}

		function runFoodSearch(q, targetList) {
			if (!targetList) {
				return;
			}
			var trimmed = q.trim();
			if (trimmed.length < 2) {
				targetList.innerHTML = '<li class="lld-food-empty">Type at least two letters to search.</li>';
				return;
			}
			targetList.innerHTML = '<li class="lld-food-empty">Searching...</li>';
			apiRequest(cfg, 'food-search?q=' + encodeURIComponent(trimmed)).then(function (res) {
				if (!res.ok) {
					targetList.innerHTML = '<li class="lld-food-empty">' + esc(errMsg(res, 'Search could not be completed.')) + '</li>';
					return;
				}
				var foods = Array.isArray(res.data.foods) ? res.data.foods : [];
				targetList._foods = foods;
				foods.forEach(function (f) {
					var g = f.portion && f.portion.grams ? f.portion.grams : f.portion_g || 100;
					rememberItem(f.food_kind || 'food', f.id, f.name, g);
				});
				var isRecipeIng = targetList === els.recipeIngHits;
				if (!foods.length) {
					var customBtn =
						!isRecipeIng && features.custom_foods !== false
							? ' <button type="button" class="lld-btn-ghost lld-btn-sm" data-lld-empty-to-custom="' + esc(trimmed) + '">Add as custom food</button>'
							: '';
					targetList.innerHTML = '<li class="lld-food-empty"><span>No foods matched &quot;' + esc(trimmed) + '&quot;.</span>' + customBtn + '</li>';
					return;
				}
				targetList.innerHTML = foods
					.map(function (f, idx) {
						var bits = [];
						if (f.brand) {
							bits.push(f.brand);
						}
						if (f.source_name) {
							bits.push(f.source_name);
						}
						if (!state.edMode) {
							var pLbl = f.portion && f.portion.name ? f.portion.name : f.portion_g ? f.portion_g + ' g' : '100 g';
							bits.push(pLbl);
							if (f.kcal !== null && f.kcal !== undefined) {
								bits.push(Math.round(Number(f.kcal)) + ' kcal');
							}
						}
						var btnAttr = isRecipeIng ? 'data-lld-add-ing="' + idx + '"' : 'data-lld-pick-food="' + idx + '"';
						var fav = isRecipeIng ? '' : favButtonHtml(f.food_kind || 'food', f.id, f.name);
						return (
							'<li class="lld-search-item">' +
							'<div><strong>' +
							esc(f.name) +
							'</strong><span class="lld-food-item-meta">' +
							esc(bits.join(' · ')) +
							'</span></div>' +
							'<div class="lld-food-item-actions">' +
							fav +
							'<button type="button" class="lld-pill-btn lld-btn-sm" ' +
							btnAttr +
							'>' +
							(isRecipeIng ? 'Add ingredient' : state.edMode ? 'Add' : 'Select') +
							'</button>' +
							'</div>' +
							'</li>'
						);
					})
					.join('');
			});
		}

		function updatePortionPreview() {
			if (!state.pickedFood || state.edMode || !els.portionPreview || !els.portionGrams) {
				return;
			}
			var f = state.pickedFood;
			var grams = Number(els.portionGrams.value || 0);
			var baseG = f.portion && f.portion.grams ? Number(f.portion.grams) : f.portion_g ? Number(f.portion_g) : 100;
			var factor = baseG > 0 && grams > 0 ? grams / baseG : 0;
			var parts = [];
			if (f.kcal !== null && f.kcal !== undefined) {
				parts.push(Math.round(Number(f.kcal) * factor) + ' kcal');
			}
			if (f.protein_g !== null && f.protein_g !== undefined) {
				parts.push('Protein ' + Number((Number(f.protein_g) * factor).toFixed(1)) + ' g');
			}
			if (f.carb_g !== null && f.carb_g !== undefined) {
				parts.push('Carbs ' + Number((Number(f.carb_g) * factor).toFixed(1)) + ' g');
			}
			if (f.fat_g !== null && f.fat_g !== undefined) {
				parts.push('Fat ' + Number((Number(f.fat_g) * factor).toFixed(1)) + ' g');
			}
			if (f.fibre_g !== null && f.fibre_g !== undefined) {
				parts.push('Fibre ' + Number((Number(f.fibre_g) * factor).toFixed(1)) + ' g');
			}
			els.portionPreview.textContent = parts.length ? parts.join(' · ') : '';
		}

		function pickFood(food) {
			if (state.edMode) {
				submitFoodLog({
					entry_date: state.date,
					meal: els.modalMeal ? els.modalMeal.value : state.meal,
					food_id: Number(food.id),
					food_kind: food.food_kind || 'food',
					grams: 100,
					source: 'search'
				});
				return;
			}
			state.pickedFood = food;
			if (els.pickedName) {
				els.pickedName.textContent = food.name || '';
			}
			if (els.pickedSource) {
				els.pickedSource.textContent = food.source_name || (food.food_kind === 'custom' ? 'Custom food' : '');
			}
			if (els.pickedAttr) {
				els.pickedAttr.textContent = food.attribution || '';
			}
			var baseG = food.portion && food.portion.grams ? Number(food.portion.grams) : food.portion_g ? Number(food.portion_g) : 100;
			var baseName = food.portion && food.portion.name ? food.portion.name : baseG + ' g';
			if (els.portionGrams) {
				els.portionGrams.value = baseG;
			}
			if (els.portionPresets) {
				els.portionPresets.innerHTML = [0.5, 1, 1.5, 2]
					.map(function (mult) {
						var g = Math.round(baseG * mult * 10) / 10;
						return (
							'<button type="button" class="lld-btn-ghost lld-btn-sm" data-lld-set-grams="' +
							g +
							'">' +
							mult +
							'x ' +
							esc(baseName) +
							' (' +
							g +
							' g)</button>'
						);
					})
					.join('');
			}
			if (els.searchResults) {
				els.searchResults.hidden = true;
			}
			if (els.portionPicker) {
				els.portionPicker.hidden = false;
			}
			updatePortionPreview();
		}

		function submitFoodLog(payload) {
			setMsg(els.modalMsg, 'Saving...');
			apiRequest(cfg, 'food-log', { method: 'POST', body: payload }).then(function (res) {
				if (!res.ok) {
					setMsg(els.modalMsg, errMsg(res, 'Could not log food.'), true);
					setStatus(errMsg(res, 'Could not log food.'), true);
					return;
				}
				closeAddModal();
				loadDay(state.date);
			});
		}

		function copyFromDate(fromDate, mealKey) {
			setStatus('Copying...');
			var body = { from_date: fromDate, to_date: state.date };
			if (mealKey) {
				body.meal = mealKey;
			}
			apiRequest(cfg, 'food-copy', { method: 'POST', body: body }).then(function (res) {
				if (!res.ok) {
					setStatus(errMsg(res, 'Could not copy entries.'), true);
					return;
				}
				loadDay(state.date);
			});
		}

		function renderRecipeIngredients() {
			if (!els.recipePicked) {
				return;
			}
			els.recipePicked.innerHTML = state.recipeIngredients
				.map(function (ing, idx) {
					var label = state.edMode ? esc(ing.name || 'Ingredient') : esc(ing.name || 'Ingredient') + ' (' + esc(ing.grams) + ' g)';
					return (
						'<li class="lld-search-item"><span>' +
						label +
						'</span><button type="button" class="lld-btn-ghost lld-btn-sm" data-lld-rm-ing="' +
						idx +
						'">Remove</button></li>'
					);
				})
				.join('');
		}

		function resetRecipeEditor() {
			state.editingRecipeId = null;
			state.recipeIngredients = [];
			renderRecipeIngredients();
			if (els.recipeForm) {
				els.recipeForm.reset();
			}
			if (els.recipeSubmit) {
				els.recipeSubmit.textContent = 'Save recipe';
			}
			if (els.recipeCancelEdit) {
				els.recipeCancelEdit.hidden = true;
			}
		}

		function startEditRecipe(recipeId) {
			var found = null;
			for (var i = 0; i < state.recipes.length; i += 1) {
				if (Number(state.recipes[i].id) === Number(recipeId)) {
					found = state.recipes[i];
					break;
				}
			}
			if (!found || !els.recipeForm) {
				return;
			}
			state.editingRecipeId = Number(found.id);
			if (els.recipeForm.elements.name) {
				els.recipeForm.elements.name.value = found.name || '';
			}
			if (els.recipeForm.elements.servings) {
				els.recipeForm.elements.servings.value = found.servings || 1;
			}
			state.recipeIngredients = parseJsonList(found.ingredients);
			renderRecipeIngredients();
			if (els.recipeSubmit) {
				els.recipeSubmit.textContent = 'Update recipe';
			}
			if (els.recipeCancelEdit) {
				els.recipeCancelEdit.hidden = false;
			}
			els.recipeForm.hidden = false;
			var toggleBtn = root.querySelector('[data-lld-toggle-recipe-form]');
			if (toggleBtn) {
				toggleBtn.setAttribute('aria-expanded', 'true');
			}
			setMsg(els.recipeMsg, '');
			if (els.recipeForm.elements.name) {
				els.recipeForm.elements.name.focus();
			}
		}

		function toggleFavourite(kind, id, name) {
			var existing = findFavouriteRow(kind, id);
			if (existing && existing.id) {
				apiRequest(cfg, 'favourites/' + existing.id, { method: 'DELETE' }).then(function (res) {
					if (!res.ok) {
						setStatus(errMsg(res, 'Could not remove favourite.'), true);
						return;
					}
					loadFavourites();
				});
				return;
			}
			rememberItem(kind, id, name);
			apiRequest(cfg, 'favourites', {
				method: 'POST',
				body: { food_id: Number(id), food_kind: kind || 'food' }
			}).then(function (res) {
				if (!res.ok) {
					setStatus(errMsg(res, 'Could not save favourite.'), true);
					return;
				}
				loadFavourites();
			});
		}

		root.addEventListener('click', function (ev) {
			var target = ev.target;
			if (!target) {
				return;
			}
			var btn;

			btn = target.closest('[data-lld-toggle-upgrade]');
			if (btn) {
				var key = btn.getAttribute('data-lld-toggle-upgrade');
				var box = root.querySelector('[data-lld-upgrade-box="' + key + '"]');
				if (box) {
					var opening = box.hidden;
					box.hidden = !opening;
					btn.setAttribute('aria-expanded', opening ? 'true' : 'false');
				}
				return;
			}
			btn = target.closest('[data-lld-date-shift]');
			if (btn) {
				loadDay(shiftDate(state.date, Number(btn.getAttribute('data-lld-date-shift'))));
				return;
			}
			if (target.closest('[data-lld-date-today]')) {
				loadDay(cfg.today);
				return;
			}
			if (target.closest('[data-lld-action="copy-yesterday"]')) {
				copyFromDate(shiftDate(state.date, -1), null);
				return;
			}
			if (target.closest('[data-lld-action="copy-date"]')) {
				var chosen = els.copyDateInput && els.copyDateInput.value ? els.copyDateInput.value : shiftDate(state.date, -1);
				copyFromDate(chosen, null);
				return;
			}
			btn = target.closest('[data-lld-copy-meal]');
			if (btn) {
				var srcDate = els.copyDateInput && els.copyDateInput.value ? els.copyDateInput.value : shiftDate(state.date, -1);
				copyFromDate(srcDate, btn.getAttribute('data-lld-copy-meal'));
				return;
			}
			if (target.closest('[data-lld-action="toggle-targets"]')) {
				var open = els.targetsPanel.hidden;
				els.targetsPanel.hidden = !open;
				target.closest('[data-lld-action="toggle-targets"]').setAttribute('aria-expanded', open ? 'true' : 'false');
				return;
			}
			if (target.closest('[data-lld-action="close-targets"]')) {
				els.targetsPanel.hidden = true;
				var toggleTargetsBtn = root.querySelector('[data-lld-action="toggle-targets"]');
				if (toggleTargetsBtn) {
					toggleTargetsBtn.setAttribute('aria-expanded', 'false');
				}
				return;
			}
			btn = target.closest('[data-lld-open-add]');
			if (btn) {
				openAddModal(btn.getAttribute('data-lld-open-add'));
				return;
			}
			if (target.closest('[data-lld-close-modal]') || target === els.modal) {
				closeAddModal();
				return;
			}
			btn = target.closest('[data-lld-modal-tab]');
			if (btn) {
				switchModalTab(btn.getAttribute('data-lld-modal-tab'));
				return;
			}
			btn = target.closest('[data-lld-empty-to-custom]');
			if (btn) {
				var prefillName = btn.getAttribute('data-lld-empty-to-custom') || '';
				switchModalTab('custom');
				if (els.customForm && els.customForm.elements.name) {
					els.customForm.elements.name.value = prefillName;
					els.customForm.elements.name.focus();
				}
				return;
			}
			btn = target.closest('[data-lld-fav-toggle]');
			if (btn) {
				toggleFavourite(
					btn.getAttribute('data-lld-fav-kind') || 'food',
					btn.getAttribute('data-lld-fav-id'),
					btn.getAttribute('data-lld-fav-name') || ''
				);
				return;
			}
			btn = target.closest('[data-lld-unfav]');
			if (btn) {
				var unfavId = btn.getAttribute('data-lld-unfav');
				apiRequest(cfg, 'favourites/' + unfavId, { method: 'DELETE' }).then(function (res) {
					if (!res.ok) {
						setMsg(els.modalMsg, errMsg(res, 'Could not remove favourite.'), true);
						return;
					}
					loadFavourites();
				});
				return;
			}
			btn = target.closest('[data-lld-log-fav]');
			if (btn) {
				var favRow = state.favourites[Number(btn.getAttribute('data-lld-log-fav'))];
				if (favRow) {
					var fKind = favRow.food_kind || 'food';
					var catItem = state.catalog[favKey(fKind, favRow.food_id)];
					var fGrams = fKind === 'recipe' ? 1 : state.edMode ? 100 : Number((catItem && catItem.grams) || 100);
					submitFoodLog({
						entry_date: state.date,
						meal: els.modalMeal ? els.modalMeal.value : state.meal,
						food_id: Number(favRow.food_id),
						food_kind: fKind,
						grams: fGrams,
						source: 'favourite'
					});
				}
				return;
			}
			btn = target.closest('[data-lld-pick-food]');
			if (btn && els.searchResults._foods) {
				var food = els.searchResults._foods[Number(btn.getAttribute('data-lld-pick-food'))];
				if (food) {
					pickFood(food);
				}
				return;
			}
			btn = target.closest('[data-lld-set-grams]');
			if (btn && els.portionGrams) {
				els.portionGrams.value = btn.getAttribute('data-lld-set-grams');
				updatePortionPreview();
				return;
			}
			if (target.closest('[data-lld-cancel-pick]')) {
				if (els.portionPicker) {
					els.portionPicker.hidden = true;
				}
				if (els.searchResults) {
					els.searchResults.hidden = false;
				}
				return;
			}
			if (target.closest('[data-lld-confirm-log]') && state.pickedFood) {
				var g = state.edMode ? 100 : Number(els.portionGrams ? els.portionGrams.value : 100);
				if (!(g > 0)) {
					setMsg(els.modalMsg, 'Enter an amount in grams.', true);
					return;
				}
				submitFoodLog({
					entry_date: state.date,
					meal: els.modalMeal ? els.modalMeal.value : state.meal,
					food_id: Number(state.pickedFood.id),
					food_kind: state.pickedFood.food_kind || 'food',
					grams: g,
					portion_label: state.edMode ? undefined : Math.round(g * 10) / 10 + ' g',
					source: 'search'
				});
				return;
			}
			btn = target.closest('[data-lld-quick-recent]');
			if (btn && els.recentResults._foods) {
				var rec = els.recentResults._foods[Number(btn.getAttribute('data-lld-quick-recent'))];
				if (rec) {
					var recKind = rec.food_kind || 'food';
					var recGrams = recKind === 'recipe' ? (state.edMode ? 1 : Number(rec.grams || 1)) : state.edMode ? 100 : Number(rec.grams || 100);
					submitFoodLog({
						entry_date: state.date,
						meal: els.modalMeal ? els.modalMeal.value : state.meal,
						food_id: Number(rec.food_id),
						food_kind: recKind,
						grams: recGrams,
						source: 'recent'
					});
				}
				return;
			}
			btn = target.closest('[data-lld-log-recipe]');
			if (btn) {
				var recId = Number(btn.getAttribute('data-lld-log-recipe'));
				submitFoodLog({
					entry_date: state.date,
					meal: els.modal && !els.modal.hidden && els.modalMeal ? els.modalMeal.value : state.meal,
					food_id: recId,
					food_kind: 'recipe',
					grams: 1,
					portion_label: state.edMode ? undefined : '1 serving',
					source: 'recipe'
				});
				return;
			}
			btn = target.closest('[data-lld-edit-recipe]');
			if (btn) {
				startEditRecipe(btn.getAttribute('data-lld-edit-recipe'));
				return;
			}
			if (target.closest('[data-lld-cancel-recipe-edit]')) {
				resetRecipeEditor();
				return;
			}
			btn = target.closest('[data-lld-delete-recipe]');
			if (btn) {
				var delRecId = btn.getAttribute('data-lld-delete-recipe');
				setMsg(els.recipeMsg, 'Deleting recipe...');
				apiRequest(cfg, 'recipes/' + delRecId, { method: 'DELETE' }).then(function (res) {
					if (!res.ok) {
						setMsg(els.recipeMsg, errMsg(res, 'Could not delete recipe.'), true);
						return;
					}
					if (Number(state.editingRecipeId) === Number(delRecId)) {
						resetRecipeEditor();
					}
					setMsg(els.recipeMsg, 'Recipe deleted.');
					loadRecipes();
				});
				return;
			}
			btn = target.closest('[data-lld-edit-item]');
			if (btn && !state.edMode) {
				var li = btn.closest('.lld-food-item');
				if (!li || li.querySelector('[data-lld-inline-edit]')) {
					return;
				}
				var itemId = btn.getAttribute('data-lld-edit-item');
				var curG = btn.getAttribute('data-lld-grams') || '100';
				var itemKind = btn.getAttribute('data-lld-food-kind') || 'food';
				var editLabel = itemKind === 'recipe' ? 'Servings' : 'Grams';
				var inpId = 'lld-inline-edit-' + itemId;
				var editRow = document.createElement('form');
				editRow.className = 'lld-inline-edit';
				editRow.setAttribute('data-lld-inline-edit', itemId);
				editRow.innerHTML =
					'<label class="lld-sr" for="' +
					esc(inpId) +
					'">' +
					esc(editLabel) +
					'</label>' +
					'<input id="' +
					esc(inpId) +
					'" type="number" step="0.5" min="0.1" max="5000" value="' +
					esc(curG) +
					'" required />' +
					'<button type="submit" class="lld-pill-btn lld-btn-sm">Save</button>' +
					'<button type="button" class="lld-btn-ghost lld-btn-sm" data-lld-cancel-edit>Cancel</button>';
				li.appendChild(editRow);
				editRow.querySelector('input').focus();
				return;
			}
			if (target.closest('[data-lld-cancel-edit]')) {
				var form = target.closest('[data-lld-inline-edit]');
				if (form) {
					form.remove();
				}
				return;
			}
			btn = target.closest('[data-lld-delete-item]');
			if (btn) {
				var delId = btn.getAttribute('data-lld-delete-item');
				setStatus('Removing...');
				apiRequest(cfg, 'food-log/' + delId, { method: 'DELETE' }).then(function (res) {
					if (!res.ok) {
						setStatus(errMsg(res, 'Could not remove item.'), true);
						return;
					}
					loadDay(state.date);
				});
				return;
			}
			if (target.closest('[data-lld-toggle-recipe-form]') && els.recipeForm) {
				var show = els.recipeForm.hidden;
				els.recipeForm.hidden = !show;
				target.closest('[data-lld-toggle-recipe-form]').setAttribute('aria-expanded', show ? 'true' : 'false');
				if (!show) {
					resetRecipeEditor();
				}
				return;
			}
			if (target.closest('[data-lld-toggle-prefs]') && els.prefsForm) {
				var showP = els.prefsForm.hidden;
				els.prefsForm.hidden = !showP;
				target.closest('[data-lld-toggle-prefs]').setAttribute('aria-expanded', showP ? 'true' : 'false');
				return;
			}
			btn = target.closest('[data-lld-add-ing]');
			if (btn && els.recipeIngHits._foods) {
				var ingFood = els.recipeIngHits._foods[Number(btn.getAttribute('data-lld-add-ing'))];
				var ingG = state.edMode ? 100 : Number(els.recipeIngG ? els.recipeIngG.value : 100) || 100;
				if (ingFood) {
					var baseG = ingFood.portion && ingFood.portion.grams ? Number(ingFood.portion.grams) : ingFood.portion_g ? Number(ingFood.portion_g) : 100;
					var fac = baseG > 0 ? ingG / baseG : 1;
					state.recipeIngredients.push({
						food_id: Number(ingFood.id),
						food_kind: ingFood.food_kind || 'food',
						name: ingFood.name,
						grams: ingG,
						kcal: ingFood.kcal !== null && ingFood.kcal !== undefined ? Number(ingFood.kcal) * fac : 0,
						protein_g: ingFood.protein_g !== null && ingFood.protein_g !== undefined ? Number(ingFood.protein_g) * fac : 0,
						carb_g: ingFood.carb_g !== null && ingFood.carb_g !== undefined ? Number(ingFood.carb_g) * fac : 0,
						fat_g: ingFood.fat_g !== null && ingFood.fat_g !== undefined ? Number(ingFood.fat_g) * fac : 0,
						fibre_g: ingFood.fibre_g !== null && ingFood.fibre_g !== undefined ? Number(ingFood.fibre_g) * fac : 0
					});
					renderRecipeIngredients();
					els.recipeIngHits.innerHTML = '';
					if (els.recipeIngQ) {
						els.recipeIngQ.value = '';
					}
				}
				return;
			}
			btn = target.closest('[data-lld-rm-ing]');
			if (btn) {
				state.recipeIngredients.splice(Number(btn.getAttribute('data-lld-rm-ing')), 1);
				renderRecipeIngredients();
			}
		});

		root.addEventListener('submit', function (ev) {
			var form = ev.target;
			if (!form) {
				return;
			}
			if (form.matches('[data-lld-inline-edit]')) {
				ev.preventDefault();
				var id = form.getAttribute('data-lld-inline-edit');
				var g = Number(form.querySelector('input').value);
				apiRequest(cfg, 'food-log/' + id, { method: 'PATCH', body: { grams: g } }).then(function (res) {
					if (!res.ok) {
						setStatus(errMsg(res, 'Could not update amount.'), true);
						return;
					}
					loadDay(state.date);
				});
				return;
			}
			if (form === els.targetForm) {
				ev.preventDefault();
				var payload = {
					kcal: Number(form.elements.kcal.value),
					set_by: 'member',
					valid_from: state.date
				};
				['protein_g', 'carb_g', 'fat_g', 'fibre_g'].forEach(function (k) {
					var inp = form.elements[k];
					if (inp && inp.value !== '') {
						payload[k] = Number(inp.value);
					}
				});
				setMsg(els.targetMsg, 'Saving...');
				apiRequest(cfg, 'targets', { method: 'POST', body: payload }).then(function (res) {
					if (!res.ok) {
						setMsg(els.targetMsg, errMsg(res, 'Could not save targets.'), true);
						return;
					}
					setMsg(els.targetMsg, 'Targets updated.');
					loadDay(state.date);
				});
				return;
			}
			if (form === els.customForm) {
				ev.preventDefault();
				var customBody = {
					name: form.elements.name.value.trim(),
					portion_g: state.edMode ? 100 : Number(form.elements.portion_g.value || 100)
				};
				if (!state.edMode) {
					['kcal', 'protein_g', 'carb_g', 'fat_g', 'fibre_g'].forEach(function (k) {
						var inp = form.elements[k];
						if (inp && inp.value !== '') {
							customBody[k] = Number(inp.value);
						}
					});
				}
				setMsg(els.modalMsg, 'Saving custom food...');
				apiRequest(cfg, 'custom-foods', { method: 'POST', body: customBody }).then(function (res) {
					if (!res.ok) {
						setMsg(els.modalMsg, errMsg(res, 'Could not save custom food.'), true);
						return;
					}
					var createdId = Number(res.data.id || (res.data.row && res.data.row.id) || 0);
					if (createdId > 0) {
						rememberItem('custom', createdId, customBody.name, customBody.portion_g);
						submitFoodLog({
							entry_date: state.date,
							meal: els.modalMeal ? els.modalMeal.value : state.meal,
							food_id: createdId,
							food_kind: 'custom',
							grams: customBody.portion_g,
							source: 'search'
						});
					} else {
						closeAddModal();
						loadDay(state.date);
					}
				});
				return;
			}
			if (form === els.recipeForm) {
				ev.preventDefault();
				if (!state.recipeIngredients.length) {
					setMsg(els.recipeMsg, 'Add at least one ingredient first.', true);
					return;
				}
				var isEdit = !!state.editingRecipeId;
				var path = isEdit ? 'recipes/' + state.editingRecipeId : 'recipes';
				var method = isEdit ? 'PATCH' : 'POST';
				setMsg(els.recipeMsg, isEdit ? 'Updating recipe...' : 'Saving recipe...');
				apiRequest(cfg, path, {
					method: method,
					body: {
						name: form.elements.name.value.trim(),
						servings: Number(form.elements.servings ? form.elements.servings.value : 1) || 1,
						ingredients: state.recipeIngredients
					}
				}).then(function (res) {
					if (!res.ok) {
						setMsg(els.recipeMsg, errMsg(res, 'Could not save recipe.'), true);
						return;
					}
					resetRecipeEditor();
					setMsg(els.recipeMsg, isEdit ? 'Recipe updated.' : 'Recipe saved.');
					loadRecipes();
				});
				return;
			}
			if (form === els.prefsForm) {
				ev.preventDefault();
				var allergies = [];
				form.querySelectorAll('input[name="allergies[]"]:checked').forEach(function (c) {
					allergies.push(c.value);
				});
				var dislikesRaw = form.elements.dislikes ? form.elements.dislikes.value : '';
				var dislikes = dislikesRaw
					.split(',')
					.map(function (s) {
						return s.trim();
					})
					.filter(Boolean);
				setMsg(els.prefsMsg, 'Saving preferences...');
				apiRequest(cfg, 'food-preferences', {
					method: 'POST',
					body: { allergies: allergies, dislikes: dislikes }
				}).then(function (res) {
					if (!res.ok) {
						setMsg(els.prefsMsg, errMsg(res, 'Could not save preferences.'), true);
						return;
					}
					setMsg(els.prefsMsg, 'Preferences saved.');
					loadSuggestions(state.date);
				});
			}
		});

		if (els.dateInput) {
			els.dateInput.addEventListener('change', function () {
				if (els.dateInput.value) {
					loadDay(els.dateInput.value);
				}
			});
		}

		if (els.searchInput) {
			els.searchInput.addEventListener('input', function () {
				clearTimeout(state.searchTimer);
				state.searchTimer = setTimeout(function () {
					runFoodSearch(els.searchInput.value, els.searchResults);
				}, 220);
			});
		}

		if (els.recipeIngQ) {
			var rTimer = null;
			els.recipeIngQ.addEventListener('input', function () {
				clearTimeout(rTimer);
				rTimer = setTimeout(function () {
					runFoodSearch(els.recipeIngQ.value, els.recipeIngHits);
				}, 240);
			});
		}

		if (els.portionGrams) {
			els.portionGrams.addEventListener('input', updatePortionPreview);
		}

		document.addEventListener('keydown', function (ev) {
			if (els.modal && !els.modal.hidden) {
				if (ev.key === 'Escape') {
					closeAddModal();
				} else {
					trapDialogFocus(els.modal, ev);
				}
			}
		});

		syncSafety(state.day);
		renderSummary(state.day);
		renderMeals(state.day);
		// The opening reads go one after another, so a search the member starts straight away waits behind one
		// request at most, not five (FR3-26: results within a second).
		[
			loadFavourites,
			function () {
				return loadWeek(state.date);
			},
			loadRecipes,
			loadPreferences,
			function () {
				return loadSuggestions(state.date);
			}
		].reduce(function (chain, step) {
			return chain.then(step).catch(function () {});
		}, Promise.resolve());
	}

	function boot() {
		document.querySelectorAll('[data-lld-food-root]').forEach(initFood);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
