/**
 * Lifeline Daily — Stage 3 Interactions Script
 * Keyboard-accessible desktop menu (FR3-07), account hub tab navigation (FR3-06).
 */
(function () {
  'use strict';

  function initDesktopUserMenu() {
    const chip = document.querySelector('.lld-chip');
    if (!chip) return;

    // Check if desktop menu already exists or build it
    let menu = document.getElementById('lld-desktop-user-menu');
    if (!menu) {
      menu = document.createElement('div');
      menu.id = 'lld-desktop-user-menu';
      menu.className = 'lld-desktop-menu';
      menu.hidden = true;
      menu.setAttribute('role', 'menu');
      menu.setAttribute('aria-label', 'User account menu');

      const origin = window.location.origin;
      menu.innerHTML = [
        '<a href="' + origin + '/members/settings/?section=profile" role="menuitem" class="lld-menu-item">Profile</a>',
        '<a href="' + origin + '/members/settings/?section=membership" role="menuitem" class="lld-menu-item">My subscription</a>',
        '<a href="' + origin + '/members/settings/?section=privacy" role="menuitem" class="lld-menu-item">My data</a>',
        '<a href="' + origin + '/members/help/" role="menuitem" class="lld-menu-item">Help</a>',
        '<hr class="lld-menu-divider">',
        '<a href="' + origin + '/members/sign-in/?action=sign_out" role="menuitem" class="lld-menu-item lld-menu-signout">Sign out</a>'
      ].join('');

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
