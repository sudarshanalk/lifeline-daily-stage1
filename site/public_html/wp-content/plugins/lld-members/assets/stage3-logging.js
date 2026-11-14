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

	function rdEsc(str) {
		return String(str == null ? '' : str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function rdNum(val) {
		if (val == null || String(val).trim() === '') return null;
		const n = Number(val);
		return Number.isFinite(n) ? n : null;
	}

	function rdShiftDate(ymd, deltaDays) {
		const parts = String(ymd || '').split('-').map(Number);
		if (parts.length !== 3 || parts.some(Number.isNaN)) return '';
		const d = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2] + deltaDays));
		return d.toISOString().slice(0, 10);
	}

	function rdFormatTakenAt(val, fallbackDay) {
		const raw = String(val || '').trim();
		if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(raw)) return raw.replace('T', ' ') + ':00';
		if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/.test(raw)) return raw.replace('T', ' ');
		if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/.test(raw)) return raw.length === 16 ? raw + ':00' : raw.slice(0, 19);
		return (fallbackDay || new Date().toISOString().slice(0, 10)) + ' 09:00:00';
	}

	function rdDisplayDateTime(takenAt) {
		const raw = String(takenAt || '');
		const d = raw.slice(0, 10);
		const t = raw.slice(11, 16);
		if (!d) return raw;
		return t ? d + ' \u00b7 ' + t : d;
	}

	function rdComputeSleepHours(bedtime, takenAtVal) {
		const bMatch = /^([01]\d|2[0-3]):([0-5]\d)$/.exec(String(bedtime || '').trim());
		const wMatch = /T([01]\d|2[0-3]):([0-5]\d)/.exec(String(takenAtVal || '').trim());
		if (!bMatch || !wMatch) return null;
		const bedMin = Number(bMatch[1]) * 60 + Number(bMatch[2]);
		const wakeMin = Number(wMatch[1]) * 60 + Number(wMatch[2]);
		let diff = wakeMin - bedMin;
		if (diff <= 0) diff += 24 * 60;
		return Math.round((diff / 60) * 10) / 10;
	}

	async function rdRequest(cfg, path, options) {
		const opts = Object.assign({ method: 'GET' }, options || {});
		const headers = Object.assign({}, opts.headers || {});
		if (cfg.nonce) headers['X-WP-Nonce'] = cfg.nonce;
		if (opts.body !== undefined) {
			headers['Content-Type'] = 'application/json';
			opts.body = typeof opts.body === 'string' ? opts.body : JSON.stringify(opts.body);
		}
		opts.headers = headers;
		opts.credentials = 'same-origin';
		const url = String(cfg.apiBase || '').replace(/\/+$/, '') + '/' + String(path || '').replace(/^\/+/, '');
		let res;
		let data = {};
		try {
			res = await fetch(url, opts);
			data = await res.json().catch(function () { return {}; });
		} catch (e) {
			return { ok: false, status: 0, data: { error: 'Network error. Please try again.' } };
		}
		return { ok: res.ok, status: res.status, data: data || {} };
	}

	function initReadings(root) {
		let cfg = {};
		try {
			cfg = JSON.parse(root.getAttribute('data-lld-readings-config') || '{}');
		} catch (e) {
			return;
		}

		const qs = function (sel) { return root.querySelector(sel); };
		const qsa = function (sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); };

		const els = {
			tabs: qsa('[data-lld-type-tab]'),
			panels: qsa('[data-lld-type-panel]'),
			heading: qs('[data-lld-form-heading]'),
			form: qs('[data-lld-readings-form]'),
			takenAt: qs('[data-lld-taken-at]'),
			bpSys: qs('[data-lld-bp-sys]'),
			bpDia: qs('[data-lld-bp-dia]'),
			bpPulse: qs('[data-lld-bp-pulse]'),
			bpDizzy: qs('[data-lld-bp-dizzy]'),
			wVal: qs('[data-lld-w-val]'),
			wUnit: qs('[data-lld-w-unit]'),
			waistVal: qs('[data-lld-waist-val]'),
			waistUnit: qs('[data-lld-waist-unit]'),
			actMin: qs('[data-lld-act-min]'),
			actEffort: qs('[data-lld-act-effort]'),
			actSteps: qs('[data-lld-act-steps]'),
			actDetail: qs('[data-lld-act-detail]'),
			actWeekText: qs('[data-lld-activity-week-text]'),
			actViewToggle: qs('[data-lld-act-view-toggle]'),
			actChart: qs('[data-lld-activity-chart]'),
			actTable: qs('[data-lld-activity-table]'),
			actMeter: qs('[data-lld-activity-meter]'),
			actFill: qs('[data-lld-activity-fill]'),
			sleepHours: qs('[data-lld-sleep-hours]'),
			sleepBed: qs('[data-lld-sleep-bed]'),
			sleepWake: qs('[data-lld-sleep-wake]'),
			sleepRested: qs('[data-lld-sleep-rested]'),
			waterTotal: qs('[data-lld-water-total]'),
			waterQuickBtns: qsa('[data-lld-water-quick]'),
			waterVal: qs('[data-lld-water-val]'),
			waterUnit: qs('[data-lld-water-unit]'),
			moodBtns: qsa('[data-lld-mood-btn]'),
			moodNote: qs('[data-lld-mood-note]'),
			gluVal: qs('[data-lld-glu-val]'),
			gluUnit: qs('[data-lld-glu-unit]'),
			gluTiming: qs('[data-lld-glu-timing]'),
			gluSymptoms: qsa('[data-lld-glu-symptom]'),
			saveBtn: qs('[data-lld-save-btn]'),
			cancelEditBtn: qs('[data-lld-cancel-edit]'),
			urgent: qs('[data-lld-urgent]'),
			caution: qs('[data-lld-caution]'),
			pulseNote: qs('[data-lld-pulse-note]'),
			error: qs('[data-lld-error]'),
			status: qs('[data-lld-status]'),
			insightsSlot: qs('[data-lld-insights-slot]'),
			empty: qs('[data-lld-empty]'),
			emptyText: qs('[data-lld-empty-text]'),
			emptyBpLink: qs('[data-lld-empty-bp-link]'),
			focusFormBtn: qs('[data-lld-focus-form]'),
			historySection: qs('[data-lld-history-section]'),
			historyHeading: qs('[data-lld-history-heading]'),
			filterFrom: qs('[data-lld-filter-from]'),
			filterTo: qs('[data-lld-filter-to]'),
			filterApply: qs('[data-lld-filter-apply]'),
			filterReset: qs('[data-lld-filter-reset]'),
			viewToggle: qs('[data-lld-view-toggle]'),
			chartBox: qs('[data-lld-chart-box]'),
			tableBox: qs('[data-lld-table-box]'),
			actSrBox: qs('[data-lld-act-sr-box]')
		};

		const TYPE_LABELS = {
			blood_pressure: 'Blood pressure',
			weight: 'Weight',
			waist: 'Waist',
			activity: 'Activity',
			sleep: 'Sleep',
			water: 'Water',
			mood: 'Mood',
			glucose: 'Blood sugar'
		};

		const MOOD_WORDS = { 1: 'Low', 2: 'Down', 3: 'OK', 4: 'Good', 5: 'Great' };
		const EFFORT_WORDS = { 1: 'Easy', 2: 'Moderate', 3: 'Hard' };

		const state = {
			type: cfg.initialType || 'blood_pressure',
			selectedMood: null,
			viewMode: 'chart',
			actViewMode: 'chart',
			rows: [],
			trendPoints: [],
			from: '',
			to: '',
			editingId: null,
			loadSeq: 0,
			busy: false
		};

		function linksCfg() {
			return cfg.links || {};
		}

		function msgsCfg() {
			return cfg.messages || {};
		}

		function formatSafetyText(rawText) {
			const links = linksCfg();
			let safe = rdEsc(rawText);
			const safetyHref = rdEsc(links.safety || '/safety/');
			safe = safe.replace(
				/\[When to seek urgent care\]/g,
				'<a href="' + safetyHref + '">When to seek urgent care</a>'
			);
			return safe;
		}

		function formatInsightText(rawText) {
			// The insight card as Today prints it: the catalogue text, escaped, bold only. Nothing is added.
			let safe = rdEsc(String(rawText == null ? '' : rawText).replace(/\s*\[[^\]]+\]/g, '').trim());
			safe = safe.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
			return safe;
		}

		function clearFeedback() {
			if (els.caution) { els.caution.hidden = true; els.caution.innerHTML = ''; }
			if (els.pulseNote) { els.pulseNote.hidden = true; els.pulseNote.innerHTML = ''; }
			if (els.error) { els.error.hidden = true; els.error.innerHTML = ''; }
			if (els.status) { els.status.hidden = true; els.status.textContent = ''; }
			if (els.urgent) {
				els.urgent.hidden = true;
				els.urgent.innerHTML = '';
				els.urgent.classList.remove('lld-safety');
			}
		}

		function renderUrgentWarning(warning) {
			if (!els.urgent) return;
			const msg = String(warning.message || '').trim();
			const lines = msg.split(/\r\n|\n/).map(function (l) { return l.trim(); }).filter(Boolean);
			const html = lines.map(function (line) {
				return '<p>' + formatSafetyText(line) + '</p>';
			}).join(' ');
			if (!html) return; // the server sent no wording: the page adds none of its own
			els.urgent.innerHTML = html;
			els.urgent.classList.add('lld-safety');
			els.urgent.hidden = false;
			els.urgent.focus();
		}

		function renderServerError(status, data) {
			if (!els.error) return;
			const links = linksCfg();
			const msg = (data && (data.error || data.message)) || 'Could not save reading. Please check your entry.';
			let html = '<p>' + rdEsc(msg) + '</p>';
			if (status === 403) {
				if (data && data.link) {
					html += '<p><a class="lld-pill-btn lld-btn-secondary" href="' + rdEsc(data.link) + '">Health profile</a></p>';
				} else if (data && data.upgrade) {
					html += '<p><a class="lld-pill-btn lld-btn-secondary" href="' + rdEsc(links.pricing || '/pricing/') + '">See Premium</a></p>';
				} else if (!cfg.hasConsent || /consent/i.test(msg)) {
					html += '<p><a class="lld-pill-btn lld-btn-secondary" href="' + rdEsc(links.settings || '/members/settings/') + '">Review health consent</a></p>';
				}
			}
			els.error.innerHTML = html;
			els.error.hidden = false;
			els.error.focus();
		}

		function handleSaveWarning(res, savedVerb) {
			const warning = res.data && res.data.warning ? res.data.warning : null;
			let focused = false;
			if (!warning || warning.level !== 'urgent') {
				if (els.urgent) {
					els.urgent.hidden = true;
					els.urgent.innerHTML = '';
					els.urgent.classList.remove('lld-safety');
				}
			}
			if (warning && warning.pulse_left_out && warning.pulse_message && els.pulseNote) {
				els.pulseNote.innerHTML = '<p>' + rdEsc(warning.pulse_message) + '</p>';
				els.pulseNote.hidden = false;
			}
			if (warning && warning.level === 'urgent') {
				renderUrgentWarning(warning);
				return;
			}
			if (warning && (warning.level === 'caution' || warning.level === 'check') && warning.message && els.caution) {
				els.caution.innerHTML = '<p>' + formatSafetyText(warning.message) + '</p>';
				els.caution.hidden = false;
				els.caution.focus();
				focused = true;
			}
			if (warning && warning.pulse_left_out && els.pulseNote && !els.pulseNote.hidden && !focused) {
				els.pulseNote.focus();
				focused = true;
			}
			if (els.status) {
				els.status.textContent = savedVerb || 'Reading saved.';
				els.status.hidden = false;
				if (!focused) els.status.focus();
			}
		}

		function activeUnitFor(type, rowUnit) {
			if (type === 'weight') {
				return (els.wUnit && els.wUnit.value) || (cfg.imperial ? 'lb' : (rowUnit || 'kg'));
			}
			if (type === 'waist') {
				return (els.waistUnit && els.waistUnit.value) || (cfg.imperial ? 'in' : (rowUnit || 'cm'));
			}
			if (type === 'glucose') {
				return (els.gluUnit && els.gluUnit.value) || (cfg.imperial ? 'mg/dL' : (rowUnit || 'mmol/L'));
			}
			if (type === 'water') {
				return (els.waterUnit && els.waterUnit.value) || (rowUnit || 'ml');
			}
			return rowUnit || '';
		}

		function convertMetricValue(type, metricVal, unit) {
			if (metricVal === null || metricVal === undefined || isNaN(Number(metricVal))) {
				return null;
			}
			const n = Number(metricVal);
			if (type === 'weight' && unit === 'lb') {
				return Math.round(n * 2.2046226218 * 10) / 10;
			}
			if (type === 'waist' && unit === 'in') {
				return Math.round((n / 2.54) * 10) / 10;
			}
			if (type === 'glucose' && unit === 'mg/dL') {
				return Math.round(n * 18.016);
			}
			if (type === 'water' && unit === 'glasses') {
				return Math.round((n / 250) * 10) / 10;
			}
			return Math.round(n * 10) / 10;
		}

		function formatRowSummary(row) {
			const type = row.reading_type;
			const unit = activeUnitFor(type, row.display_unit || row.unit_shown || '');
			const converted = convertMetricValue(type, row.value_1, unit);
			const v1 = converted != null ? converted : (row.display_value_1 != null ? row.display_value_1 : row.value_1);
			const v2 = row.value_2;
			const v3 = row.value_3;
			if (type === 'blood_pressure') {
				let txt = (row.value_1 != null ? row.value_1 : '') + '/' + (v2 != null ? v2 : '') + ' mmHg';
				if (v3 != null) txt += ' \u00b7 Pulse ' + v3 + ' bpm';
				return txt;
			}
			if (type === 'weight') {
				return (v1 != null ? v1 : '') + ' ' + (unit || 'kg');
			}
			if (type === 'waist') {
				return (v1 != null ? v1 : '') + ' ' + (unit || 'cm');
			}
			if (type === 'activity') {
				const parts = [(row.value_1 != null ? row.value_1 : 0) + ' min'];
				if (v2 != null && Number(v2) > 0) parts.push(Number(v2).toLocaleString() + ' steps');
				if (v3 != null && EFFORT_WORDS[Math.round(Number(v3))]) parts.push(EFFORT_WORDS[Math.round(Number(v3))]);
				return parts.join(' \u00b7 ');
			}
			if (type === 'sleep') {
				const parts = [(row.value_1 != null ? row.value_1 : '') + ' hrs'];
				if (v3 != null) parts.push('Rested ' + v3 + '/5');
				return parts.join(' \u00b7 ');
			}
			if (type === 'water') {
				const ml = row.value_1 != null ? Math.round(Number(row.value_1)) : 0;
				const glasses = Math.round((ml / 250) * 10) / 10;
				if (unit === 'glasses') {
					return glasses + (glasses === 1 ? ' glass' : ' glasses') + ' (' + ml + ' ml)';
				}
				return ml + ' ml (' + glasses + (glasses === 1 ? ' glass)' : ' glasses)');
			}
			if (type === 'mood') {
				const score = row.value_1 != null ? Math.round(Number(row.value_1)) : '';
				const label = MOOD_WORDS[score] || '';
				return 'Mood ' + score + '/5' + (label ? ' (' + label + ')' : '');
			}
			if (type === 'glucose') {
				return (v1 != null ? v1 : '') + ' ' + (unit || 'mmol/L');
			}
			return String(v1 != null ? v1 : '');
		}

		function extractRowNote(row) {
			if (!row.detail) return '';
			try {
				const parsed = JSON.parse(row.detail);
				if (parsed && typeof parsed === 'object') {
					const bits = [];
					if (parsed.activity_type) bits.push(parsed.activity_type);
					if (parsed.timing) bits.push(String(parsed.timing).replace(/_/g, ' '));
					if (Array.isArray(parsed.symptoms) && parsed.symptoms.length) {
						bits.push(parsed.symptoms.map(function (s) { return String(s).replace(/_/g, ' '); }).join(', '));
					}
					if (parsed.note) bits.push(parsed.note);
					return bits.join(' \u00b7 ');
				}
			} catch (e) {
				/* plain text fallback */
			}
			return String(row.detail);
		}

		function renderWaterTotal(rows) {
			if (!els.waterTotal) return;
			const today = cfg.utcToday || cfg.today || '';
			let ml = 0;
			rows.forEach(function (r) {
				if (r.reading_type === 'water' && String(r.taken_at || '').slice(0, 10) === today && r.value_1 != null) {
					ml += Number(r.value_1) || 0;
				}
			});
			ml = Math.round(ml);
			const glasses = Math.round((ml / 250) * 10) / 10;
			els.waterTotal.textContent = 'Today\u2019s water total: ' + ml.toLocaleString() + ' ml (' + glasses + (glasses === 1 ? ' glass)' : ' glasses)');
		}

		function renderChart(points, trendError) {
			if (!els.chartBox) return;
			if (!cfg.hasTrends) {
				const links = linksCfg();
				const msg = msgsCfg().trendsUpgrade || 'Long-term trends and insights are part of Premium.';
				els.chartBox.innerHTML =
					'<div class="lld-readings-chart-empty">' +
					'<p>' + rdEsc(msg) + '</p>' +
					'<p><a class="lld-pill-btn lld-btn-secondary" href="' + rdEsc(links.pricing || '/pricing/') + '">See Premium</a></p>' +
					'</div>';
				return;
			}
			if (trendError) {
				if (trendError.status === 403 && trendError.data && trendError.data.upgrade) {
					const links = linksCfg();
					const msg = (trendError.data && (trendError.data.error || trendError.data.message)) || msgsCfg().trendsUpgrade || 'Long-term trends and insights are part of Premium.';
					els.chartBox.innerHTML =
						'<div class="lld-readings-chart-empty">' +
						'<p>' + rdEsc(msg) + '</p>' +
						'<p><a class="lld-pill-btn lld-btn-secondary" href="' + rdEsc(links.pricing || '/pricing/') + '">See Premium</a></p>' +
						'</div>';
					return;
				}
				els.chartBox.innerHTML = '<p class="lld-hint">' + rdEsc((trendError.data && (trendError.data.error || trendError.data.message)) || 'Chart unavailable right now.') + '</p>';
				return;
			}
			const valid = (points || []).filter(function (p) { return p && p.value_1 != null && Number.isFinite(Number(p.value_1)); });
			if (valid.length === 0) {
				els.chartBox.innerHTML = '<p class="lld-hint">No trend points for the current selection.</p>';
				return;
			}

			const w = 400;
			const h = 96;
			const padX = 16;
			const padY = 14;
			const unit = activeUnitFor(state.type, '');
			const vals = valid.map(function (p) {
				const c = convertMetricValue(state.type, p.value_1, unit);
				return c != null ? c : Number(p.value_1);
			});
			if (state.type === 'blood_pressure') {
				valid.forEach(function (p) { if (p.value_2 != null) vals.push(Number(p.value_2)); });
			}
			let min = Math.min.apply(null, vals);
			let max = Math.max.apply(null, vals);
			if (min === max) { min -= 1; max += 1; }

			const xFor = function (idx) {
				return valid.length === 1 ? w / 2 : padX + (idx / (valid.length - 1)) * (w - padX * 2);
			};
			const yFor = function (val) {
				return padY + (1 - (val - min) / (max - min)) * (h - padY * 2);
			};

			const pts1 = valid.map(function (p, i) {
				const c = convertMetricValue(state.type, p.value_1, unit);
				return xFor(i).toFixed(1) + ',' + yFor(c != null ? c : Number(p.value_1)).toFixed(1);
			});
			const chartTitle = (TYPE_LABELS[state.type] || 'Readings') + ' trend';
			const tableMode = state.viewMode === 'table';
			let svg = '<figure class="lld-trend-figure"' + (tableMode ? ' hidden' : '') + '>';
			const latest = valid[valid.length - 1];
			const latestVal = convertMetricValue(state.type, latest.value_1, unit);
			const summary = valid.length + (valid.length === 1 ? ' reading on ' + latest.date : ' readings, from ' + valid[0].date + ' to ' + latest.date) + '. Latest: ' + (latestVal != null ? latestVal : latest.value_1) + (state.type === 'blood_pressure' && latest.value_2 != null ? '/' + latest.value_2 : '') + (unit ? ' ' + unit : '') + '.';
			svg += '<svg viewBox="0 0 ' + w + ' ' + h + '" width="100%" height="96" role="img" aria-label="' + rdEsc(chartTitle) + '">';
			if (valid.length > 1) {
				svg += '<polyline class="lld-trend-line" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" points="' + pts1.join(' ') + '" />';
			}
			valid.forEach(function (p, i) {
				const c = convertMetricValue(state.type, p.value_1, unit);
				svg += '<circle class="lld-trend-dot" cx="' + xFor(i).toFixed(1) + '" cy="' + yFor(c != null ? c : Number(p.value_1)).toFixed(1) + '" r="4" fill="currentColor" />';
			});
			if (state.type === 'blood_pressure' && valid.every(function (p) { return p.value_2 != null; })) {
				const pts2 = valid.map(function (p, i) { return xFor(i).toFixed(1) + ',' + yFor(Number(p.value_2)).toFixed(1); });
				if (valid.length > 1) {
					svg += '<polyline class="lld-trend-line-secondary" fill="none" stroke="currentColor" stroke-width="2" stroke-dasharray="4 4" stroke-linecap="round" stroke-linejoin="round" points="' + pts2.join(' ') + '" />';
				}
				valid.forEach(function (p, i) {
					svg += '<circle class="lld-trend-dot-secondary" cx="' + xFor(i).toFixed(1) + '" cy="' + yFor(Number(p.value_2)).toFixed(1) + '" r="3.5" fill="currentColor" />';
				});
			}
			svg += '</svg><figcaption class="lld-hint">' + rdEsc(summary) + '</figcaption></figure>';
			let tbl = '<div class="lld-table-wrap"><table class="lld-table' + (tableMode ? '' : ' lld-sr-only') + '" aria-label="' + rdEsc(chartTitle + ' data') + '" data-lld-trend-table><caption>' + rdEsc(chartTitle + ' data') + '</caption><thead><tr><th scope="col">Date</th><th scope="col">Reading</th></tr></thead><tbody>';
			valid.forEach(function (p) {
				const c = convertMetricValue(state.type, p.value_1, unit);
				const vText = state.type === 'blood_pressure' && p.value_2 != null
					? String(c != null ? c : p.value_1) + '/' + String(Math.round(Number(p.value_2))) + ' mmHg'
					: String(c != null ? c : p.value_1) + (unit ? ' ' + unit : '');
				tbl += '<tr><td>' + rdEsc(p.date || '') + '</td><td>' + rdEsc(vText) + '</td></tr>';
			});
			tbl += '</tbody></table></div>';
			els.chartBox.innerHTML = svg + tbl;
		}

		function minutesToHm(totalMins) {
			const m = ((Math.round(totalMins) % 1440) + 1440) % 1440;
			const hh = String(Math.floor(m / 60)).padStart(2, '0');
			const mm = String(m % 60).padStart(2, '0');
			return hh + ':' + mm;
		}

		function startEditRow(row) {
			state.editingId = Number(row.id);
			if (els.saveBtn) els.saveBtn.textContent = 'Save changes';
			if (els.cancelEditBtn) els.cancelEditBtn.hidden = false;

			const dt = String(row.taken_at || '');
			if (els.takenAt && dt.length >= 16) {
				els.takenAt.value = dt.slice(0, 16).replace(' ', 'T');
			}

			const type = row.reading_type;
			const rowUnit = row.display_unit || row.unit_shown || '';
			let focusTarget = null;
			if (els.bpDizzy) els.bpDizzy.checked = false;

			if (type === 'blood_pressure') {
				if (els.bpSys) els.bpSys.value = row.value_1 != null ? String(row.value_1) : '';
				if (els.bpDia) els.bpDia.value = row.value_2 != null ? String(row.value_2) : '';
				if (els.bpPulse) els.bpPulse.value = row.value_3 != null ? String(row.value_3) : '';
				focusTarget = els.bpSys;
			} else if (type === 'weight') {
				const unit = activeUnitFor('weight', rowUnit || 'kg');
				const val = convertMetricValue('weight', row.value_1, unit);
				if (els.wUnit) els.wUnit.value = unit;
				if (els.wVal) els.wVal.value = val != null ? String(val) : '';
				focusTarget = els.wVal;
			} else if (type === 'waist') {
				const unit = activeUnitFor('waist', rowUnit || 'cm');
				const val = convertMetricValue('waist', row.value_1, unit);
				if (els.waistUnit) els.waistUnit.value = unit;
				if (els.waistVal) els.waistVal.value = val != null ? String(val) : '';
				focusTarget = els.waistVal;
			} else if (type === 'activity') {
				if (els.actMin) els.actMin.value = row.value_1 != null ? String(row.value_1) : '';
				if (els.actSteps) els.actSteps.value = row.value_2 != null ? String(row.value_2) : '';
				if (els.actEffort) els.actEffort.value = row.value_3 != null ? String(Math.round(Number(row.value_3))) : '';
				if (els.actDetail) els.actDetail.value = row.detail ? String(row.detail) : '';
				focusTarget = els.actMin;
			} else if (type === 'sleep') {
				const wake = dt.length >= 16 ? dt.slice(11, 16) : '07:00';
				const hrs = Number(row.value_1 || 8);
				let bed = '';
				if (row.value_2 != null && Number.isFinite(Number(row.value_2))) {
					bed = minutesToHm(Number(row.value_2));
				} else {
					const wakeParts = wake.split(':').map(Number);
					const wakeMin = wakeParts[0] * 60 + wakeParts[1];
					bed = minutesToHm(wakeMin - hrs * 60);
				}
				if (els.sleepWake) els.sleepWake.value = wake;
				if (els.sleepBed) els.sleepBed.value = bed;
				if (els.sleepHours) {
					els.sleepHours.value = row.value_1 != null ? String(row.value_1) : '';
					els.sleepHours.setAttribute('data-lld-auto', '1');
				}
				if (els.sleepRested) els.sleepRested.value = row.value_3 != null ? String(Math.round(Number(row.value_3))) : '';
				focusTarget = els.sleepBed || els.sleepHours;
			} else if (type === 'water') {
				const unit = activeUnitFor('water', rowUnit || 'ml');
				const val = convertMetricValue('water', row.value_1, unit);
				if (els.waterUnit) els.waterUnit.value = unit;
				if (els.waterVal) els.waterVal.value = val != null ? String(val) : '';
				focusTarget = els.waterVal;
			} else if (type === 'mood') {
				const score = row.value_1 != null ? Math.round(Number(row.value_1)) : null;
				state.selectedMood = score;
				els.moodBtns.forEach(function (b) {
					b.setAttribute('aria-pressed', Number(b.getAttribute('data-lld-mood-btn')) === score ? 'true' : 'false');
				});
				if (els.moodNote) els.moodNote.value = row.detail ? String(row.detail) : '';
				focusTarget = els.moodBtns[0] || els.moodNote;
			} else if (type === 'glucose') {
				const unit = activeUnitFor('glucose', rowUnit || 'mmol/L');
				const val = convertMetricValue('glucose', row.value_1, unit);
				if (els.gluUnit) els.gluUnit.value = unit;
				if (els.gluVal) els.gluVal.value = val != null ? String(val) : '';
				let timing = 'before_meal';
				let syms = [];
				if (row.detail) {
					try {
						const parsed = JSON.parse(row.detail);
						if (parsed && parsed.timing) timing = parsed.timing;
						if (parsed && Array.isArray(parsed.symptoms)) syms = parsed.symptoms;
					} catch (e) {
						/* ignore */
					}
				}
				if (els.gluTiming) els.gluTiming.value = timing === 'after_meal' ? 'after_meal' : 'before_meal';
				els.gluSymptoms.forEach(function (c) {
					c.checked = syms.indexOf(c.value) !== -1;
				});
				focusTarget = els.gluVal;
			}

			if (focusTarget) focusTarget.focus();
		}

		function cancelEditRow(restoreFocus) {
			state.editingId = null;
			if (els.bpDizzy) els.bpDizzy.checked = false;
			if (els.saveBtn) els.saveBtn.textContent = 'Save reading';
			if (restoreFocus && els.cancelEditBtn && document.activeElement === els.cancelEditBtn) {
				const target = els.saveBtn || els.heading;
				if (target) target.focus();
			}
			if (els.cancelEditBtn) els.cancelEditBtn.hidden = true;
		}

		function renderHistory() {
			const rows = state.rows || [];
			if (state.type === 'water') renderWaterTotal(rows);
			const isEmpty = rows.length === 0;
			const hasFilter = Boolean(state.from || state.to);
			const allowed = Boolean(cfg.canStore && cfg.hasConsent);
			if (els.empty) els.empty.hidden = !allowed || !isEmpty || hasFilter;
			if (els.tableBox) els.tableBox.innerHTML = '';
			if (els.historySection) {
				els.historySection.hidden = !allowed || (isEmpty && cfg.hasFullHistory && !hasFilter);
			}
			if (isEmpty) {
				if (els.tableBox && hasFilter) {
					els.tableBox.innerHTML = '<p class="lld-hint">No readings match the current date filter.</p>';
				}
				return;
			}

			if (els.tableBox) {
				let tbl = '<div class="lld-table-wrap"><table class="lld-table"><caption>Recent ' + rdEsc((TYPE_LABELS[state.type] || '').toLowerCase()) + ' readings</caption>' +
					'<thead><tr><th scope="col">Date</th><th scope="col">Reading</th><th scope="col">Notes</th><th scope="col">Actions</th></tr></thead><tbody>';
				rows.forEach(function (r) {
					const note = extractRowNote(r);
					tbl += '<tr data-lld-row-id="' + r.id + '">' +
						'<td>' + rdEsc(rdDisplayDateTime(r.taken_at)) + '</td>' +
						'<td><strong>' + rdEsc(formatRowSummary(r)) + '</strong></td>' +
						'<td>' + rdEsc(note || '\u2014') + '</td>' +
						'<td class="lld-table-actions"><div class="lld-history-actions">' +
						'<button type="button" class="lld-pill-btn lld-btn-secondary lld-btn-sm" data-lld-edit-row="' + r.id + '">Edit</button>' +
						'<button type="button" class="lld-pill-btn lld-btn-secondary lld-btn-sm" data-lld-delete-row="' + r.id + '">Delete</button>' +
						'</div></td></tr>';
				});
				tbl += '</tbody></table></div>';
				els.tableBox.innerHTML = tbl;
			}
		}

		function renderActivityWeekChart(daysMap, guide, totalMins) {
			if (!els.actChart) return;
			const keys = Object.keys(daysMap || {}).sort();
			if (!keys.length) {
				els.actChart.innerHTML = '';
				if (els.actTable) els.actTable.innerHTML = '';
				if (els.actSrBox) els.actSrBox.innerHTML = '';
				return;
			}
			const dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
			const vals = keys.map(function (k) { return Number(daysMap[k] || 0); });
			const total = typeof totalMins === 'number' && !isNaN(totalMins)
				? totalMins
				: vals.reduce(function (a, b) { return a + b; }, 0);
			const targetGuide = guide || 150;
			const maxVal = Math.max.apply(null, [targetGuide].concat(vals));
			const w = 360;
			const h = 110;
			const padX = 14;
			const padTop = 12;
			const padBot = 24;
			const plotH = h - padTop - padBot;
			const stepX = (w - padX * 2) / keys.length;
			const barW = Math.max(18, Math.floor(stepX * 0.56));
			const guideY = padTop + plotH * (1 - (targetGuide / (maxVal || 150)));

			let fig = '<figure class="lld-activity-figure">';
			fig += '<svg viewBox="0 0 ' + w + ' ' + h + '" width="100%" height="110" role="img" aria-label="Weekly activity">';
			fig += '<line x1="' + padX + '" y1="' + guideY.toFixed(1) + '" x2="' + (w - padX) + '" y2="' + guideY.toFixed(1) + '" stroke="currentColor" stroke-width="1.5" stroke-dasharray="4 4" opacity="0.7" />';
			keys.forEach(function (k, idx) {
				const mins = Number(daysMap[k] || 0);
				const barH = Math.max(2, Math.round((mins / (maxVal || 150)) * plotH));
				const x = Math.round(padX + idx * stepX + (stepX - barW) / 2);
				const y = Math.round(padTop + plotH - barH);
				const label = dayNames[idx] || k.slice(5);
				fig += '<rect x="' + x + '" y="' + y + '" width="' + barW + '" height="' + barH + '" rx="4" fill="currentColor" />';
				fig += '<text x="' + Math.round(x + barW / 2) + '" y="' + (h - 6) + '" text-anchor="middle" font-size="11" fill="currentColor">' + rdEsc(label) + '</text>';
			});
			fig += '</svg>';
			fig += '<figcaption class="lld-hint">Weekly ' + rdEsc(targetGuide) + '-minute guide (' + rdEsc(total) + ' of ' + rdEsc(targetGuide) + ' min)</figcaption>';
			fig += '</figure>';
			els.actChart.innerHTML = fig;

			let tbody = '<thead><tr><th scope="col">Date</th><th scope="col">Minutes</th></tr></thead><tbody>';
			keys.forEach(function (k) {
				tbody += '<tr><td>' + rdEsc(k) + '</td><td>' + rdEsc(daysMap[k] || 0) + ' min</td></tr>';
			});
			tbody += '</tbody>';
			if (els.actTable) {
				els.actTable.innerHTML = '<div class="lld-table-wrap"><table class="lld-table"><caption>Weekly activity by day</caption>' + tbody + '</table></div>';
			}
			if (els.actSrBox) {
				els.actSrBox.innerHTML = '<div class="lld-table-wrap"><table class="lld-table lld-sr-only" aria-label="Weekly activity data"><caption>Weekly activity data</caption>' + tbody + '</table></div>';
			}
		}

		async function loadActivityWeek() {
			if (!els.actWeekText || !cfg.weekStart || !cfg.canStore || !cfg.hasConsent) {
				if (els.actSrBox) els.actSrBox.innerHTML = '';
				return;
			}
			const res = await rdRequest(cfg, 'readings-activity-week?week_start=' + encodeURIComponent(cfg.weekStart));
			if (!res.ok) {
				els.actWeekText.textContent = 'Weekly total unavailable.';
				if (els.actSrBox) els.actSrBox.innerHTML = '';
				return;
			}
			const mins = Number(res.data.minutes || 0);
			const guide = Number(res.data.guide || 150);
			els.actWeekText.innerHTML = '<strong>Weekly total:</strong> ' + rdEsc(mins) + ' of ' + rdEsc(guide) + ' minutes';
			if (els.actMeter && els.actFill) {
				const pct = Math.min(100, Math.round((mins / (guide || 150)) * 100));
				els.actMeter.setAttribute('aria-valuenow', String(mins));
				els.actFill.style.width = pct + '%';
			}
			if (res.data && res.data.days) {
				renderActivityWeekChart(res.data.days, guide, mins);
			}
		}

		async function loadHistory(type) {
			// Each load takes a ticket; a reply that arrives after the member chose another type or range is dropped,
			// so one type's rows never show (or get edited) under another type's heading and units.
			const ticket = ++state.loadSeq;
			const stale = function () { return ticket !== state.loadSeq || type !== state.type; };
			if (!cfg.canStore || !cfg.hasConsent) {
				state.rows = [];
				renderHistory();
				renderChart([], null);
				if (els.actSrBox) els.actSrBox.innerHTML = '';
				return;
			}
			if (type !== 'activity' && els.actSrBox) {
				els.actSrBox.innerHTML = '';
			}
			let query = 'type=' + encodeURIComponent(type);
			if (state.from) query += '&from=' + encodeURIComponent(state.from);
			if (state.to) query += '&to=' + encodeURIComponent(state.to);

			const resList = await rdRequest(cfg, 'readings?' + query);
			if (stale()) return;
			if (resList.ok && Array.isArray(resList.data.rows)) {
				state.rows = resList.data.rows.slice().sort(function (a, b) {
					const cmp = String(b.taken_at || '').localeCompare(String(a.taken_at || ''));
					return cmp !== 0 ? cmp : (Number(b.id || 0) - Number(a.id || 0));
				});
			} else {
				state.rows = [];
			}
			renderHistory();

			if (!cfg.hasTrends) {
				state.trendPoints = [];
				renderChart([], null);
			} else {
				const resTrend = await rdRequest(cfg, 'readings-trend?' + query);
				if (stale()) return;
				if (resTrend.ok && Array.isArray(resTrend.data.points)) {
					state.trendPoints = resTrend.data.points;
					renderChart(state.trendPoints, null);
				} else {
					state.trendPoints = [];
					renderChart([], resTrend);
				}
			}
			if (type === 'activity') await loadActivityWeek();
		}

		function switchType(type, focusTab) {
			if (!TYPE_LABELS[type]) return;
			state.type = type;
			cancelEditRow();
			clearFeedback();
			els.tabs.forEach(function (tab) {
				const active = tab.getAttribute('data-lld-type-tab') === type;
				tab.setAttribute('aria-selected', active ? 'true' : 'false');
				tab.setAttribute('tabindex', active ? '0' : '-1');
				if (active && focusTab) tab.focus();
			});
			els.panels.forEach(function (panel) {
				panel.hidden = panel.getAttribute('data-lld-type-panel') !== type;
			});
			if (els.heading) els.heading.textContent = TYPE_LABELS[type];
			if (els.historyHeading) els.historyHeading.textContent = 'Recent ' + TYPE_LABELS[type].toLowerCase() + ' readings';
			if (els.emptyBpLink) els.emptyBpLink.hidden = type !== 'blood_pressure';
			loadHistory(type);
		}

		function rdComputeSleepFromBedWake(bedtime, wakeTime) {
			const bMatch = /^([01]\d|2[0-3]):([0-5]\d)$/.exec(String(bedtime || '').trim());
			const wMatch = /^([01]\d|2[0-3]):([0-5]\d)$/.exec(String(wakeTime || '').trim());
			if (!bMatch || !wMatch) return null;
			const bedMin = Number(bMatch[1]) * 60 + Number(bMatch[2]);
			const wakeMin = Number(wMatch[1]) * 60 + Number(wMatch[2]);
			let diff = wakeMin - bedMin;
			if (diff <= 0) diff += 24 * 60;
			return Math.round((diff / 60) * 10) / 10;
		}

		function buildPayload(isEdit, existingRow) {
			const typedAt = els.takenAt ? String(els.takenAt.value || '').trim() : '';
			const takenAt = isEdit && existingRow && existingRow.taken_at && !typedAt
				? String(existingRow.taken_at)
				: rdFormatTakenAt(typedAt, cfg.today);
			const base = { reading_type: state.type, taken_at: takenAt, source: 'manual' };

			if (state.type === 'blood_pressure') {
				const sys = rdNum(els.bpSys && els.bpSys.value);
				const dia = rdNum(els.bpDia && els.bpDia.value);
				const pulse = rdNum(els.bpPulse && els.bpPulse.value);
				if (sys === null || dia === null) return { error: 'Enter both systolic and diastolic blood pressure numbers.' };
				base.value_1 = sys;
				base.value_2 = dia;
				if (pulse !== null) base.value_3 = pulse;
				else if (isEdit) base.value_3 = null;
				if (els.bpDizzy && els.bpDizzy.checked) base.dizzy = true;
				return { payload: base };
			}
			if (state.type === 'weight') {
				const val = rdNum(els.wVal && els.wVal.value);
				if (val === null) return { error: 'Enter your weight reading.' };
				base.value_1 = val;
				base.unit_shown = els.wUnit ? els.wUnit.value : 'kg';
				return { payload: base };
			}
			if (state.type === 'waist') {
				const val = rdNum(els.waistVal && els.waistVal.value);
				if (val === null) return { error: 'Enter your waist measurement.' };
				base.value_1 = val;
				base.unit_shown = els.waistUnit ? els.waistUnit.value : 'cm';
				return { payload: base };
			}
			if (state.type === 'activity') {
				const mins = rdNum(els.actMin && els.actMin.value);
				if (mins === null) return { error: 'Enter active minutes.' };
				base.value_1 = mins;
				const steps = rdNum(els.actSteps && els.actSteps.value);
				if (steps !== null) base.value_2 = steps;
				else if (isEdit) base.value_2 = null;
				const effort = rdNum(els.actEffort && els.actEffort.value);
				if (effort !== null) base.value_3 = effort;
				else if (isEdit) base.value_3 = null;
				const detailVal = els.actDetail ? els.actDetail.value.trim() : '';
				if (detailVal) base.detail = detailVal;
				else if (isEdit) base.detail = null;
				return { payload: base };
			}
			if (state.type === 'sleep') {
				const bed = els.sleepBed ? els.sleepBed.value.trim() : '';
				const wake = els.sleepWake ? els.sleepWake.value.trim() : '';
				const manualHrs = rdNum(els.sleepHours && els.sleepHours.value);
				const isAuto = !els.sleepHours || els.sleepHours.value === '' || els.sleepHours.getAttribute('data-lld-auto') === '1';
				const dayPart = takenAt.slice(0, 10) || cfg.today || '';
				let hrs = manualHrs;
				if (bed && wake && (isAuto || hrs === null)) {
					const calc = rdComputeSleepFromBedWake(bed, wake);
					if (calc !== null) {
						hrs = calc;
						base.bedtime = bed;
						if (dayPart) base.taken_at = dayPart + ' ' + wake + ':00';
					}
				} else if (bed && hrs === null) {
					const calcFromTaken = rdComputeSleepHours(bed, els.takenAt ? els.takenAt.value : '');
					if (calcFromTaken !== null) {
						hrs = calcFromTaken;
						base.bedtime = bed;
					}
				}
				if (hrs === null) return { error: 'Enter bedtime and wake time, or hours slept.' };
				base.value_1 = hrs;
				const rested = rdNum(els.sleepRested && els.sleepRested.value);
				if (rested !== null) base.value_3 = rested;
				else if (isEdit) base.value_3 = null;
				return { payload: base };
			}
			if (state.type === 'water') {
				const val = rdNum(els.waterVal && els.waterVal.value);
				if (val === null) return { error: 'Enter a water amount.' };
				base.value_1 = val;
				base.unit_shown = els.waterUnit ? els.waterUnit.value : 'ml';
				return { payload: base };
			}
			if (state.type === 'mood') {
				if (state.selectedMood === null) return { error: 'Choose a mood rating from 1 to 5.' };
				base.value_1 = state.selectedMood;
				const note = els.moodNote ? els.moodNote.value.trim() : '';
				if (note) base.detail = note;
				else if (isEdit) base.detail = null;
				return { payload: base };
			}
			if (state.type === 'glucose') {
				const val = rdNum(els.gluVal && els.gluVal.value);
				if (val === null) return { error: 'Enter your blood sugar reading.' };
				base.value_1 = val;
				base.unit_shown = els.gluUnit ? els.gluUnit.value : 'mmol/L';
				const syms = els.gluSymptoms.filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
				if (syms.length > 0) base.symptoms = syms;
				const timing = els.gluTiming ? els.gluTiming.value : 'before_meal';
				base.detail = JSON.stringify({ timing: timing, symptoms: syms });
				return { payload: base };
			}
			return { error: 'Unsupported reading type.' };
		}

		async function checkLowMoodSignal() {
			if (!cfg.insightsEnabled || !cfg.hasConsent || !els.insightsSlot) return;
			const res = await rdRequest(cfg, 'insights');
			if (!res.ok || !res.data) return;
			const rows = Array.isArray(res.data) ? res.data : (Array.isArray(res.data.rows) ? res.data.rows : (Array.isArray(res.data.insights) ? res.data.insights : []));
			const ruleId = Number(cfg.lowMoodRuleId || 0);
			const oldest = rdShiftDate(cfg.today, -7);
			const found = rows.filter(function (item) {
				if (!item || Number(item.dismissed) || !ruleId || Number(item.rule_id) !== ruleId) return false;
				const shown = String(item.shown_at || '').slice(0, 10);
				return !shown || !oldest || shown >= oldest;
			}).sort(function (a, b) {
				return String(b.shown_at || '').localeCompare(String(a.shown_at || ''));
			})[0];
			let parsed = null;
			if (found && found.values) {
				if (typeof found.values === 'string') {
					try { parsed = JSON.parse(found.values); } catch (e) { parsed = null; }
				} else if (typeof found.values === 'object') {
					parsed = found.values;
				}
			}
			const txt = found ? String((parsed && parsed.text) || found.text || found.message || '').trim() : '';
			if (txt) {
				els.insightsSlot.innerHTML = '<section class="lld-card" data-lld-section="insights"><p class="lld-insight" data-lld-rule="R-SAFE-04">' + formatInsightText(txt) + '</p></section>';
			} else {
				els.insightsSlot.innerHTML = '';
			}
		}

		async function submitPayload(payload, savedVerb, editId) {
			if (state.busy) return;
			state.busy = true;
			if (els.saveBtn) els.saveBtn.disabled = true;
			clearFeedback();
			const method = editId ? 'PATCH' : 'POST';
			const endpoint = editId ? ('readings/' + editId) : 'readings';
			const res = await rdRequest(cfg, endpoint, { method: method, body: payload });
			state.busy = false;
			if (els.saveBtn) els.saveBtn.disabled = false;
			if (!res.ok) {
				renderServerError(res.status, res.data);
				return;
			}
			if (editId) cancelEditRow(false);
			handleSaveWarning(res, savedVerb);
			if (payload.reading_type === 'mood' && !(res.data && res.data.warning && res.data.warning.level === 'urgent')) {
				await checkLowMoodSignal();
			}
			await loadHistory(state.type);
		}

		// Tab events + keyboard navigation
		els.tabs.forEach(function (tab, idx) {
			tab.addEventListener('click', function (ev) {
				ev.preventDefault();
				const slug = tab.getAttribute('data-lld-type-tab');
				if (window.history && window.history.replaceState) {
					const nextUrl = tab.getAttribute('href');
					if (nextUrl) window.history.replaceState(null, '', nextUrl);
				}
				switchType(slug, false);
			});
			tab.addEventListener('keydown', function (ev) {
				let next = -1;
				if (ev.key === 'ArrowRight') next = (idx + 1) % els.tabs.length;
				if (ev.key === 'ArrowLeft') next = (idx - 1 + els.tabs.length) % els.tabs.length;
				if (ev.key === 'Home') next = 0;
				if (ev.key === 'End') next = els.tabs.length - 1;
				if (next !== -1) {
					ev.preventDefault();
					const slug = els.tabs[next].getAttribute('data-lld-type-tab');
					if (window.history && window.history.replaceState) {
						const nextUrl = els.tabs[next].getAttribute('href');
						if (nextUrl) window.history.replaceState(null, '', nextUrl);
					}
					switchType(slug, true);
				}
			});
		});

		// Sleep auto-calc from bedtime + wake time unless hours manually typed
		function syncSleepAuto() {
			if (!els.sleepHours || !els.sleepBed || !els.sleepWake) return;
			if (!els.sleepBed.value || !els.sleepWake.value) return;
			const hrs = rdComputeSleepFromBedWake(els.sleepBed.value, els.sleepWake.value);
			if (hrs !== null) {
				els.sleepHours.value = String(hrs);
				els.sleepHours.setAttribute('data-lld-auto', '1');
			}
		}
		if (els.sleepBed) els.sleepBed.addEventListener('change', syncSleepAuto);
		if (els.sleepWake) els.sleepWake.addEventListener('change', syncSleepAuto);
		if (els.sleepHours) {
			els.sleepHours.addEventListener('input', function () {
				els.sleepHours.setAttribute('data-lld-auto', els.sleepHours.value === '' ? '1' : '0');
			});
		}

		// Unit dropdowns re-render displayed values & trend chart immediately
		[els.wUnit, els.waistUnit, els.gluUnit, els.waterUnit].forEach(function (sel) {
			if (sel) {
				sel.addEventListener('change', function () {
					renderHistory();
					if (cfg.hasTrends) renderChart(state.trendPoints, null);
				});
			}
		});

		// Water quick-add buttons
		els.waterQuickBtns.forEach(function (btn) {
			btn.addEventListener('click', function () {
				const amount = Number(btn.getAttribute('data-lld-water-quick') || 250);
				submitPayload({
					reading_type: 'water',
					value_1: amount,
					unit_shown: 'ml',
					taken_at: rdFormatTakenAt(els.takenAt ? els.takenAt.value : '', cfg.today),
					source: 'quick_tile'
				}, 'Added ' + amount + ' ml.', null);
			});
		});

		// Mood 1-5 picker
		els.moodBtns.forEach(function (btn) {
			btn.addEventListener('click', function () {
				const val = Number(btn.getAttribute('data-lld-mood-btn'));
				state.selectedMood = val;
				els.moodBtns.forEach(function (b) {
					b.setAttribute('aria-pressed', Number(b.getAttribute('data-lld-mood-btn')) === val ? 'true' : 'false');
				});
			});
		});

		// Main form submit (create or save changes)
		if (els.form) {
			els.form.addEventListener('submit', function (ev) {
				ev.preventDefault();
				const existingRow = state.editingId ? state.rows.find(function (r) { return Number(r.id) === state.editingId; }) : null;
				const built = buildPayload(Boolean(state.editingId), existingRow);
				if (built.error) {
					clearFeedback();
					renderServerError(400, { error: built.error });
					return;
				}
				submitPayload(built.payload, state.editingId ? 'Reading updated.' : 'Reading saved.', state.editingId);
			});
		}

		if (els.cancelEditBtn) {
			els.cancelEditBtn.addEventListener('click', function () {
				cancelEditRow(true);
			});
		}

		// Empty-state focus helper
		if (els.focusFormBtn) {
			els.focusFormBtn.addEventListener('click', function () {
				const activePanel = qs('[data-lld-type-panel="' + state.type + '"]');
				const target = activePanel ? activePanel.querySelector('input, select, button, textarea') : els.takenAt;
				if (target) target.focus();
			});
		}

		// Date range filter controls
		if (els.filterApply) {
			els.filterApply.addEventListener('click', function () {
				state.from = els.filterFrom ? els.filterFrom.value : '';
				state.to = els.filterTo ? els.filterTo.value : '';
				loadHistory(state.type);
			});
		}
		if (els.filterReset) {
			els.filterReset.addEventListener('click', function () {
				state.from = '';
				state.to = '';
				if (els.filterFrom) els.filterFrom.value = '';
				if (els.filterTo) els.filterTo.value = '';
				loadHistory(state.type);
			});
		}

		// Activity week Chart <-> Table view toggle
		if (els.actViewToggle) {
			els.actViewToggle.addEventListener('click', function () {
				const showTable = state.actViewMode !== 'table';
				state.actViewMode = showTable ? 'table' : 'chart';
				els.actViewToggle.setAttribute('aria-pressed', showTable ? 'true' : 'false');
				els.actViewToggle.textContent = showTable ? 'Show as chart' : 'Show as table';
				if (els.actChart) els.actChart.hidden = showTable;
				if (els.actTable) els.actTable.hidden = !showTable;
			});
		}

		// History Chart <-> Table view toggle (toggles chart figure vs accessible data table visibility)
		if (els.viewToggle) {
			els.viewToggle.addEventListener('click', function () {
				const showTableOnly = els.viewToggle.getAttribute('aria-pressed') !== 'true';
				state.viewMode = showTableOnly ? 'table' : 'chart';
				els.viewToggle.setAttribute('aria-pressed', showTableOnly ? 'true' : 'false');
				els.viewToggle.textContent = showTableOnly ? 'Show as chart' : 'Show as table';
				if (els.chartBox) {
					const fig = els.chartBox.querySelector('.lld-trend-figure');
					const tbl = els.chartBox.querySelector('[data-lld-trend-table]');
					if (fig) fig.hidden = showTableOnly;
					if (tbl) tbl.classList.toggle('lld-sr-only', !showTableOnly);
					if (!fig && !tbl) els.chartBox.hidden = showTableOnly;
				}
			});
		}

		// History edit / delete actions
		root.addEventListener('click', async function (ev) {
			const editBtn = ev.target.closest('[data-lld-edit-row]');
			if (editBtn) {
				const id = Number(editBtn.getAttribute('data-lld-edit-row'));
				const row = state.rows.find(function (r) { return Number(r.id) === id; });
				if (row) startEditRow(row);
				return;
			}
			const delBtn = ev.target.closest('[data-lld-delete-row]');
			if (delBtn) {
				const id = Number(delBtn.getAttribute('data-lld-delete-row'));
				clearFeedback();
				const res = await rdRequest(cfg, 'readings/' + id, { method: 'DELETE' });
				if (!res.ok) {
					renderServerError(res.status, res.data);
					return;
				}
				if (state.editingId === id) cancelEditRow(false);
				if (els.status) {
					els.status.textContent = 'Reading deleted.';
					els.status.hidden = false;
					els.status.focus();
				}
				if (state.type === 'mood') await checkLowMoodSignal();
				await loadHistory(state.type);
			}
		});

		syncSleepAuto();
		switchType(state.type, false);
	}

	function boot() {
		document.querySelectorAll('[data-lld-food-root]').forEach(initFood);
		document.querySelectorAll('[data-lld-readings]').forEach(initReadings);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
