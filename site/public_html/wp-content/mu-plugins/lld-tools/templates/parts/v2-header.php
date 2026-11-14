<?php
/**
 * The one site header for the v2 pages (homepage, category pages, article pages): the homepage design's announcement bar
 * with the Day/Night switch, the LIFELINE DAILY logo, "Your Health" (a menu of every top category of the agreed tree,
 * data/categories.json), Calculators, 4-Week Tracks, The Science and Guides. Owner, 7 Nov 2026: one header across the
 * site, and every category under Your Health.
 *
 * Set before including: $lld_header = ['page' => 'home'|'category'|'article'|'tools'|'page', 'current' => top category slug or ''].
 * 'tools' (the Health Tools hub and tool pages) marks Calculators as the current section.
 * Behaviour: assets/lld-home-v2.js (menu, Day/Night).
 */
if (!defined('ABSPATH')) exit;

$lld_header = (array)($lld_header ?? []) + ['page' => 'home', 'current' => '', 'exact' => false];   // exact: viewing that category itself
$hu = function (string $path): string { return esc_url(home_url($path)); };
$on_home = $lld_header['page'] === 'home';
$signed_in = function_exists('is_user_logged_in') && is_user_logged_in();
// Icon, colour and one short line for each top category (owner's choice, 8 Nov 2026: tidied cards, line icons in soft
// coloured circles, a bathroom scale for weight); a category added to the tree later shows a compass and its name.
// Icons: Tabler Icons (MIT licence, tabler.io), outline set, drawn inline so nothing extra loads.
$topic_icons = [
  'weight' => '<path d="M3 3m0 4a4 4 0 0 1 4 -4h10a4 4 0 0 1 4 4v10a4 4 0 0 1 -4 4h-10a4 4 0 0 1 -4 -4z"/><path d="M12 7c1.956 0 3.724 .802 5 2.095l-2.956 2.904a3 3 0 0 0 -2.038 -.799a3 3 0 0 0 -2.038 .798l-2.956 -2.903a6.979 6.979 0 0 1 5 -2.095z"/>',
  'droplet' => '<path d="M7.502 19.423c2.602 2.105 6.395 2.105 8.996 0c2.602 -2.105 3.262 -5.708 1.566 -8.546l-4.89 -7.26c-.42 -.625 -1.287 -.803 -1.936 -.397a1.376 1.376 0 0 0 -.41 .397l-4.893 7.26c-1.695 2.838 -1.035 6.441 1.567 8.546z"/>',
  'flame' => '<path d="M12 10.941c2.333 -3.308 .167 -7.823 -1 -8.941c0 3.395 -2.235 5.299 -3.667 6.706c-1.43 1.408 -2.333 3.621 -2.333 5.588c0 3.704 3.134 6.706 7 6.706s7 -3.002 7 -6.706c0 -1.712 -1.232 -4.403 -2.333 -5.588c-2.084 3.353 -3.257 3.353 -4.667 2.235"/>',
  'moon' => '<path d="M12 3c.132 0 .263 0 .393 0a7.5 7.5 0 0 0 7.92 12.446a9 9 0 1 1 -8.313 -12.454z"/>',
  'heart' => '<path d="M19.5 13.572l-7.5 7.428l-2.896 -2.868m-6.117 -8.104a5 5 0 0 1 9.013 -3.022a5 5 0 1 1 7.5 6.572"/><path d="M3 13h2l2 3l2 -6l1 3h3"/>',
  'leaf' => '<path d="M5 21c.5 -4.5 2.5 -8 7 -10"/><path d="M9 18c6.218 0 10.5 -3.288 11 -12v-2h-4.014c-9 0 -11.986 4 -12 9c0 1 0 3 2 5h3z"/>',
  'hourglass' => '<path d="M6.5 7h11"/><path d="M6.5 17h11"/><path d="M6 20v-2a6 6 0 1 1 12 0v2a1 1 0 0 1 -1 1h-10a1 1 0 0 1 -1 -1z"/><path d="M6 4v2a6 6 0 1 0 12 0v-2a1 1 0 0 0 -1 -1h-10a1 1 0 0 0 -1 1z"/>',
  'hearts' => '<path d="M14.017 18l-2.017 2l-7.5 -7.428a5 5 0 1 1 7.5 -6.566a5 5 0 0 1 8.153 5.784"/><path d="M15.99 20l4.197 -4.223a2.81 2.81 0 0 0 0 -3.948a2.747 2.747 0 0 0 -3.91 -.007l-.28 .282l-.279 -.283a2.747 2.747 0 0 0 -3.91 -.007a2.81 2.81 0 0 0 -.007 3.948l4.182 4.238z"/>',
  'stethoscope' => '<path d="M6 4h-1a2 2 0 0 0 -2 2v3.5h0a5.5 5.5 0 0 0 11 0v-3.5a2 2 0 0 0 -2 -2h-1"/><path d="M8 15a6 6 0 1 0 12 0v-3"/><path d="M11 3v2"/><path d="M6 3v2"/><path d="M20 10m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"/>',
  'compass' => '<path d="M8 16l2 -6l6 -2l-2 6l-6 2"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"/><path d="M12 3l0 2"/><path d="M12 19l0 2"/><path d="M3 12l2 0"/><path d="M19 12l2 0"/>',
];
$topic_look = [
  'weight-healthy-eating' => ['weight', 'amber', 'Protein target, Mediterranean eating'],
  'blood-sugar-metabolic-health' => ['droplet', 'rose', 'Diabetes risk, steady energy'],
  'fitness-strength' => ['flame', 'coral', 'Home strength, mobility, cardio'],
  'sleep-stress' => ['moon', 'violet', 'Deep sleep, calmer evenings'],
  'heart-health' => ['heart', 'rose', 'Blood pressure, lipids, DASH'],
  'gut-health' => ['leaf', 'teal', 'Microbiome, digestion'],
  'healthy-ageing' => ['hourglass', 'stone', 'Muscle, bones, memory'],
  'sex-relationships' => ['hearts', 'pink', 'Intimacy, hormones'],
  'health-conditions' => ['stethoscope', 'blue', 'Early signs, where to start'],
];
$topics = [];
foreach ((array)(function_exists('lld_categories_doc') ? (lld_categories_doc()['tree'] ?? []) : []) as $t) {
  $slug = (string)$t['slug'];
  $topics[] = ['slug' => $slug, 'name' => html_entity_decode((string)$t['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
    'icon' => $topic_icons[$topic_look[$slug][0] ?? 'compass'], 'tone' => $topic_look[$slug][1] ?? 'stone', 'desc' => $topic_look[$slug][2] ?? '', 'url' => '/category/' . $slug . '/'];
}
$health_on = in_array($lld_header['page'], ['category', 'article'], true);
$tools_on = $lld_header['page'] === 'tools';
?>
  <!-- Top Announcement Bar -->
  <div class="bg-[#ECE6DC] bg-day-surface text-[#4A4742] text-day-muted text-xs py-2 px-6 flex items-center justify-between border-b border-[#E0D8CB] border-day-soft">
    <div class="flex items-center gap-2">
      <span class="inline-block w-2 h-2 rounded-full bg-[#1D4E48]" aria-hidden="true"></span>
      <span>Clinically reviewed guidance • Week 1 of every 4-week track free</span>
    </div>
    <div class="flex items-center gap-1.5" role="group" aria-label="Day or night colours">
      <button type="button" data-circadian="day" id="circadian-btn-day" aria-pressed="true" class="px-2.5 py-0.5 rounded-full bg-[#1D4E48] text-white text-[11px] font-medium transition-all shadow-sm">☀️ Day</button>
      <button type="button" data-circadian="night" id="circadian-btn-night" aria-pressed="false" class="px-2.5 py-0.5 rounded-full text-[#5A5751] text-day-muted hover:text-black text-[11px] font-medium transition-all">🌙 Night</button>
    </div>
  </div>

  <!-- Global Header Navigation -->
  <header class="sticky top-0 z-50 bg-[#FAF8F5]/95 bg-day-base backdrop-blur-md border-b border-[#EAE4D8] border-day-soft transition-colors">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 h-20 flex items-center justify-between gap-2">
      <a href="<?php echo $hu('/'); ?>" class="text-inherit flex items-center gap-2.5 group" aria-label="Lifeline Daily home">
        <div class="w-8 h-8 rounded-full bg-[#1D4E48] text-white flex items-center justify-center font-serif-editorial text-xl italic font-bold" aria-hidden="true">L</div>
        <span class="text-base sm:text-xl font-bold tracking-tight whitespace-nowrap text-[#1F1E1C] text-day-dark">LIFELINE<span class="font-normal text-[#1D4E48] ml-1 dark:text-[#4FA89B]">DAILY</span></span>
      </a>

      <nav class="hidden md:flex items-center gap-1 text-sm font-medium text-[#4A4742] text-day-muted" aria-label="Main">
        <button type="button" id="nav-your-health-btn" aria-expanded="false" aria-controls="mega-menu" class="px-3.5 py-2 rounded-full hover:bg-[#EFEAE2] flex items-center gap-1.5 transition-colors font-semibold <?php echo $health_on ? 'bg-[#EFEAE2] bg-day-card text-[#1D4E48] dark:text-[#4FA89B]' : 'text-[#1F1E1C] text-day-dark'; ?>">
          <span>Your Health</span>
          <svg id="nav-arrow" class="w-4 h-4 transition-transform text-[#1D4E48]" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
        </button>
        <a href="<?php echo $hu('/health-tools/'); ?>"<?php echo $tools_on ? ' aria-current="true"' : ''; ?> class="text-inherit px-3.5 py-2 rounded-full hover:bg-[#EFEAE2] transition-colors<?php echo $tools_on ? ' bg-[#EFEAE2] bg-day-card text-[#1D4E48] dark:text-[#4FA89B] font-semibold' : ''; ?>">Calculators</a>
        <a href="<?php echo $hu('/programmes/'); ?>" class="text-inherit px-3.5 py-2 rounded-full hover:bg-[#EFEAE2] transition-colors">4-Week Tracks</a>
        <a href="<?php echo $hu('/the-science/'); ?>" class="text-inherit px-3.5 py-2 rounded-full hover:bg-[#EFEAE2] transition-colors">The Science</a>
        <a href="<?php echo $on_home ? '#reads' : $hu('/articles/'); ?>" class="text-inherit px-3.5 py-2 rounded-full hover:bg-[#EFEAE2] transition-colors">Guides</a>
      </nav>

      <div class="flex items-center gap-2 sm:gap-4">
        <?php if ($signed_in): ?>
          <?php if (function_exists('current_user_can') && current_user_can('lld_admin_panel')): // P3-87b: the Owner and Support helpers reach Admin from every page ?>
          <a href="<?php echo $hu('/members/admin/'); ?>" class="text-sm font-semibold text-[#1D4E48] dark:text-[#4FA89B] hover:underline px-3 py-1.5 hidden sm:inline-block">Admin</a>
          <?php endif; ?>
          <?php $lld_account = function_exists('apply_filters') ? (string)apply_filters('lld_v2_header_account', '') : ''; // P3-36: member pages put the member chip (with its menu) here ?>
          <?php if ($lld_account !== ''): echo $lld_account; // phpcs:ignore -- built and escaped by lld-members ?>
          <?php else: ?>
          <a href="<?php echo $hu('/members/'); ?>" class="bg-[#1D4E48] hover:bg-[#143833] text-white text-sm font-medium px-3.5 sm:px-5 py-2.5 rounded-full shadow-sm transition-all inline-flex items-center gap-2 whitespace-nowrap">
            <span>My account</span>
            <svg class="hidden sm:block w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
          </a>
          <?php endif; ?>
        <?php else: ?>
          <a href="<?php echo $hu('/members/sign-in/'); ?>" class="text-sm font-medium text-[#4A4742] text-day-muted hover:text-[#1F1E1C] px-3 py-1.5 hidden sm:inline-block">Sign In</a>
          <a href="<?php echo $hu('/members/join/'); ?>" class="bg-[#1D4E48] hover:bg-[#143833] text-white text-sm font-medium px-3.5 sm:px-5 py-2.5 rounded-full shadow-sm transition-all inline-flex items-center gap-2 whitespace-nowrap">
            <span>Join Free</span>
            <svg class="hidden sm:block w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
          </a>
        <?php endif; ?>
        <button type="button" id="nav-mobile-btn" aria-expanded="false" aria-controls="mega-menu" class="md:hidden w-10 h-10 rounded-full border border-[#DDD5C7] border-day-soft text-[#1F1E1C] text-day-dark flex items-center justify-center" aria-label="Menu">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-width="2" d="M4 7h16M4 12h16M4 17h16"/></svg>
        </button>
      </div>
    </div>

    <!-- Your Health menu: every top category -->
    <div id="mega-menu" class="hidden absolute top-full left-0 w-full bg-[#FAF8F5] bg-day-base border-b border-[#DFD8CC] border-day-soft shadow-xl max-h-[80vh] overflow-y-auto" data-test="your-health-menu">
      <div class="lld-topics max-w-6xl mx-auto px-4 sm:px-8 pt-5 pb-2 grid grid-cols-1 md:grid-cols-3 gap-1">
        <?php foreach ($topics as $t): $cur = $t['slug'] === $lld_header['current']; ?>
        <a href="<?php echo $hu($t['url']); ?>"<?php echo $cur ? ' aria-current="' . ($lld_header['exact'] ? 'page' : 'true') . '"' : ''; ?> class="lld-topic flex items-center gap-3 px-3 py-2.5 rounded-xl transition-colors<?php echo $cur ? ' is-current' : ''; ?>">
          <span class="lld-topic-icon tone-<?php echo esc_attr($t['tone']); ?>" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><?php echo $t['icon']; // phpcs:ignore -- fixed Tabler markup above ?></svg></span>
          <span class="min-w-0">
            <strong class="lld-topic-name block text-sm font-bold leading-snug text-[#1F1E1C] text-day-dark"><?php echo esc_html($t['name']); ?></strong>
            <?php if ($t['desc'] !== ''): ?><span class="lld-topic-desc block text-xs leading-snug text-[#5A5751] text-day-muted"><?php echo esc_html($t['desc']); ?></span><?php endif; ?>
          </span>
        </a>
        <?php endforeach; ?>
      </div>
      <div class="lld-topics-foot max-w-6xl mx-auto px-4 sm:px-8 pb-5">
        <div class="flex flex-wrap items-center justify-between gap-3 pt-4 border-t border-[#EAE4D8] border-day-soft">
          <form role="search" method="get" action="<?php echo $hu('/'); ?>" class="flex-1 min-w-[14rem] max-w-md">
            <label for="lld-menu-search" class="sr-only">Search Lifeline Daily</label>
            <input id="lld-menu-search" type="search" name="s" placeholder="Search Lifeline Daily..." class="lld-search-input w-full px-4 py-2.5 text-sm rounded-full bg-white bg-day-card border-[1.5px] border-[#CFC5B5] text-[#1F1E1C] text-day-dark">
          </form>
          <a href="<?php echo $hu('/articles/'); ?>" class="lld-topics-all inline-flex items-center gap-1.5 min-h-[44px] text-sm font-bold text-[#1D4E48] hover:underline">All topics <span aria-hidden="true">&rarr;</span></a>
        </div>
      </div>
      <div class="md:hidden max-w-7xl mx-auto px-8 pb-8 grid grid-cols-2 gap-3 text-sm font-semibold">
        <a href="<?php echo $hu('/health-tools/'); ?>" class="px-4 py-3 rounded-xl bg-white bg-day-card border border-[#E0D8CB] border-day-soft text-[#1F1E1C] text-day-dark">Calculators</a>
        <a href="<?php echo $hu('/programmes/'); ?>" class="px-4 py-3 rounded-xl bg-white bg-day-card border border-[#E0D8CB] border-day-soft text-[#1F1E1C] text-day-dark">4-Week Tracks</a>
        <a href="<?php echo $hu('/the-science/'); ?>" class="px-4 py-3 rounded-xl bg-white bg-day-card border border-[#E0D8CB] border-day-soft text-[#1F1E1C] text-day-dark">The Science</a>
        <a href="<?php echo $on_home ? '#reads' : $hu('/articles/'); ?>" class="px-4 py-3 rounded-xl bg-white bg-day-card border border-[#E0D8CB] border-day-soft text-[#1F1E1C] text-day-dark">Guides</a>
        <?php if (!$signed_in): ?><a href="<?php echo $hu('/members/sign-in/'); ?>" class="col-span-2 px-4 py-3 rounded-xl bg-white bg-day-card border border-[#E0D8CB] border-day-soft text-[#1D4E48] dark:text-[#4FA89B]">Sign In</a><?php endif; ?>
        <?php if ($signed_in && function_exists('current_user_can') && current_user_can('lld_admin_panel')): ?><a href="<?php echo $hu('/members/admin/'); ?>" class="col-span-2 px-4 py-3 rounded-xl bg-white bg-day-card border border-[#E0D8CB] border-day-soft text-[#1D4E48] dark:text-[#4FA89B]">Admin</a><?php endif; ?>
        <?php if ($signed_in && function_exists('apply_filters')) echo (string)apply_filters('lld_v2_header_menu_end', ''); // P3-36: Sign out on member pages; phpcs:ignore -- built and escaped by lld-members ?>
      </div>
    </div>
  </header>
