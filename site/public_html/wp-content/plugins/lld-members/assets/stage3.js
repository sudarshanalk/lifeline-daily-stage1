/**
 * Lifeline Daily — Stage 3 Interactions Script
 * Keyboard-accessible desktop menu (FR3-07), account hub tab navigation (FR3-06).
 */
(function () {
  'use strict';

  function initDesktopUserMenu() {
    const chip = document.querySelector('.lld-chip');
    if (!chip) return;

    // The links come from PHP (a data attribute on the hub), and data-lld-sign-out names the hub's sign-out form:
    // a POST to the Stage 2 sign-out carrying its nonce. Without them no menu is built and the chip stays a plain
    // link to settings.
    const hub = document.querySelector('[data-lld-menu][data-lld-sign-out]');
    if (!hub) return;
    let links;
    try {
      links = JSON.parse(hub.getAttribute('data-lld-menu') || '{}');
    } catch (err) {
      return;
    }
    const signOut = document.getElementById(hub.getAttribute('data-lld-sign-out') || '');
    if (!signOut || signOut.tagName !== 'FORM' || !links.profile || !links.subscription || !links.data || !links.help) return;

    let menu = document.getElementById('lld-desktop-user-menu');
    if (!menu) {
      menu = document.createElement('div');
      menu.id = 'lld-desktop-user-menu';
      menu.className = 'lld-desktop-menu';
      menu.hidden = true;
      menu.setAttribute('role', 'menu');
      menu.setAttribute('aria-label', 'User account menu');

      const items = [
        [links.profile, 'Profile', ''],
        [links.subscription, 'My subscription', ''],
        [links.data, 'My data', ''],
        [links.help, 'Help', ''],
        null,
        [signOut, 'Sign out', ' lld-menu-signout']
      ];
      items.forEach(function (item) {
        if (!item) {
          const hr = document.createElement('hr');
          hr.className = 'lld-menu-divider';
          menu.appendChild(hr);
          return;
        }
        let a;
        if (item[0] === signOut) {
          // Sign out posts the hub's sign-out form (with its nonce); it is never a link.
          a = document.createElement('button');
          a.type = 'button';
          a.addEventListener('click', function () {
            signOut.submit();
          });
        } else {
          a = document.createElement('a');
          a.href = item[0];
        }
        a.textContent = item[1];
        a.setAttribute('role', 'menuitem');
        a.className = 'lld-menu-item' + item[2];
        menu.appendChild(a);
      });

      // Wrap chip in container if not wrapped
      const parent = chip.parentNode;
      if (!parent.classList.contains('lld-chip-wrap')) {
        const wrap = document.createElement('div');
        wrap.className = 'lld-chip-wrap';
        parent.insertBefore(wrap, chip);
        wrap.appendChild(chip);
        wrap.appendChild(menu);
      } else {
        parent.appendChild(menu);
      }
    }

    chip.setAttribute('aria-haspopup', 'true');
    chip.setAttribute('aria-expanded', 'false');

    function toggleMenu(open) {
      const willOpen = typeof open === 'boolean' ? open : menu.hidden;
      menu.hidden = !willOpen;
      chip.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
      if (willOpen) {
        const first = menu.querySelector('[role="menuitem"]');
        if (first) first.focus();
      }
    }

    // On desktop viewports (>= 768px), intercept click on chip to toggle menu
    chip.addEventListener('click', function (e) {
      if (window.innerWidth >= 768) {
        e.preventDefault();
        toggleMenu();
      }
    });

    // Keyboard support on the chip
    chip.addEventListener('keydown', function (e) {
      if (window.innerWidth >= 768) {
        if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') {
          e.preventDefault();
          toggleMenu(true);
        }
      }
    });

    // Keyboard navigation inside the menu (TC-FR3-07-AS-03)
    menu.addEventListener('keydown', function (e) {
      const items = Array.from(menu.querySelectorAll('[role="menuitem"]'));
      const currentIndex = items.indexOf(document.activeElement);

      if (e.key === 'Escape') {
        e.preventDefault();
        toggleMenu(false);
        chip.focus();
      } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        const next = (currentIndex + 1) % items.length;
        items[next].focus();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        const prev = (currentIndex - 1 + items.length) % items.length;
        items[prev].focus();
      } else if (e.key === 'Tab') {
        // Close menu if tabbing out
        setTimeout(function () {
          if (!menu.contains(document.activeElement) && document.activeElement !== chip) {
            toggleMenu(false);
          }
        }, 10);
      }
    });

    // Close when clicking outside
    document.addEventListener('click', function (e) {
      if (!menu.hidden && !chip.contains(e.target) && !menu.contains(e.target)) {
        toggleMenu(false);
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDesktopUserMenu);
  } else {
    initDesktopUserMenu();
  }
})();

/*
 * Calculator pages (P3-57 to P3-5A): pressing an answer button must not move focus to the page's main area. The
 * Stage 2 script brings a newly focused element into view (lld-members.js keepInView), so focusing a tall <main> on
 * press scrolled the page under the pointer and the release missed the answer. Preventing the press's default keeps
 * focus where it is; the click still reaches the label, which checks its radio and focuses it.
 */
(function () {
  'use strict';
  document.addEventListener('mousedown', function (e) {
    var option = e.target && e.target.closest ? e.target.closest('.lld-calc-option') : null;
    if (option && e.target.tagName !== 'INPUT') {
      e.preventDefault();
    }
  });
})();
