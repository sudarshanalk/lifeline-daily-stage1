<?php
/**
 * The one site header for the v2 pages (homepage, category pages, article pages): the homepage design's announcement bar
 * with the Day/Night switch, the LIFELINE DAILY logo, "Your Health" (a menu of every top category of the agreed tree,
 * data/categories.json), Calculators, 4-Week Tracks, The Science and Guides. Owner, 7 Nov 2026: one header across the
 * site, and every category under Your Health.
 *
 * Set before including: $lld_header = ['page' => 'home'|'category'|'article', 'current' => top category slug or ''].
 * Behaviour: assets/lld-home-v2.js (menu, Day/Night).
 */
if (!defined('ABSPATH')) exit;

$lld_header = (array)($lld_header ?? []) + ['page' => 'home', 'current' => ''];
$hu = function (string $path): string { return esc_url(home_url($path)); };
$on_home = $lld_header['page'] === 'home';
$anchor = function (string $id) use ($on_home, $hu): string { return $on_home ? '#' . $id : $hu('/#' . $id); };
$signed_in = function_exists('is_user_logged_in') && is_user_logged_in();
// Icon and one line for each top category; a category added to the tree later shows with a plain icon and its name.
$topic_look = [
  'weight-healthy-eating' => ['⚖️', 'Daily protein target & Mediterranean eating'],
  'blood-sugar-metabolic-health' => ['🩸', 'Diabetes risk, insulin resistance & steady energy'],
  'fitness-strength' => ['🔥', 'Home strength training, mobility & cardio'],
  'sleep-stress' => ['🌙', 'Circadian cues, cortisol & deep sleep'],
  'heart-health' => ['🫀', 'Blood pressure, lipid tests & DASH habits'],
  'gut-health' => ['🦠', 'Microbiome balance & everyday digestion'],
  'healthy-ageing' => ['⏳', 'Muscle, bones, memory & staying independent'],
  'sex-relationships' => ['💞', 'Intimacy, hormones & relationship health'],
  'health-conditions' => ['🩺', 'Common conditions, early signs & where to start'],
];
$topics = [];
foreach ((array)(function_exists('lld_categories_doc') ? (lld_categories_doc()['tree'] ?? []) : []) as $t) {
  $slug = (string)$t['slug'];
  $topics[] = ['slug' => $slug, 'name' => html_entity_decode((string)$t['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
    'icon' => $topic_look[$slug][0] ?? '🧭', 'desc' => $topic_look[$slug][1] ?? '', 'url' => '/category/' . $slug . '/'];
}
$health_on = in_array($lld_header['page'], ['category', 'article'], true);
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
      <a href="<?php echo $hu('/'); ?>" class="flex items-center gap-2.5 group" aria-label="Lifeline Daily home">
        <div class="w-8 h-8 rounded-full bg-[#1D4E48] text-white flex items-center justify-center font-serif-editorial text-xl italic font-bold" aria-hidden="true">L</div>
        <span class="text-lg sm:text-xl font-bold tracking-tight whitespace-nowrap text-[#1F1E1C] text-day-dark">LIFELINE<span class="font-normal text-[#1D4E48] ml-1">DAILY</span></span>
      </a>

      <nav class="hidden md:flex items-center gap-1 text-sm font-medium text-[#4A4742] text-day-muted" aria-label="Main">
        <button type="button" id="nav-your-health-btn" aria-expanded="false" aria-controls="mega-menu" class="px-3.5 py-2 rounded-full hover:bg-[#EFEAE2] flex items-center gap-1.5 transition-colors font-semibold <?php echo $health_on ? 'bg-[#EFEAE2] bg-day-card text-[#1D4E48]' : 'text-[#1F1E1C] text-day-dark'; ?>">
          <span>Your Health</span>
          <svg id="nav-arrow" class="w-4 h-4 transition-transform text-[#1D4E48]" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
        </button>
        <a href="<?php echo $anchor('calculators'); ?>" class="px-3.5 py-2 rounded-full hover:bg-[#EFEAE2] transition-colors">Calculators</a>
        <a href="<?php echo $anchor('tracks'); ?>" class="px-3.5 py-2 rounded-full hover:bg-[#EFEAE2] transition-colors">4-Week Tracks</a>
        <a href="<?php echo $anchor('science'); ?>" class="px-3.5 py-2 rounded-full hover:bg-[#EFEAE2] transition-colors">The Science</a>
        <a href="<?php echo $on_home ? '#reads' : $hu('/articles/'); ?>" class="px-3.5 py-2 rounded-full hover:bg-[#EFEAE2] transition-colors">Guides</a>
      </nav>

      <div class="flex items-center gap-2 sm:gap-4">
        <?php if ($signed_in): ?>
          <a href="<?php echo $hu('/members/'); ?>" class="bg-[#1D4E48] hover:bg-[#143833] text-white text-sm font-medium px-4 sm:px-5 py-2.5 rounded-full shadow-sm transition-all inline-flex items-center gap-2 whitespace-nowrap">
            <span>My account</span>
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
          </a>
        <?php else: ?>
          <a href="<?php echo $hu('/members/sign-in/'); ?>" class="text-sm font-medium text-[#4A4742] text-day-muted hover:text-[#1F1E1C] px-3 py-1.5 hidden sm:inline-block">Sign In</a>
          <a href="<?php echo $hu('/members/join/'); ?>" class="bg-[#1D4E48] hover:bg-[#143833] text-white text-sm font-medium px-4 sm:px-5 py-2.5 rounded-full shadow-sm transition-all inline-flex items-center gap-2 whitespace-nowrap">
            <span>Join Free</span>
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
          </a>
        <?php endif; ?>
        <button type="button" id="nav-mobile-btn" aria-expanded="false" aria-controls="mega-menu" class="md:hidden w-10 h-10 rounded-full border border-[#DDD5C7] border-day-soft text-[#1F1E1C] text-day-dark flex items-center justify-center" aria-label="Menu">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-width="2" d="M4 7h16M4 12h16M4 17h16"/></svg>
        </button>
      </div>
    </div>

    <!-- Your Health menu: every top category -->
    <div id="mega-menu" class="hidden absolute top-full left-0 w-full bg-[#FAF8F5] bg-day-base border-b border-[#DFD8CC] border-day-soft shadow-xl max-h-[80vh] overflow-y-auto" data-test="your-health-menu">
      <div class="max-w-7xl mx-auto p-8 grid grid-cols-1 md:grid-cols-3 gap-6">
        <?php foreach ($topics as $t): $cur = $t['slug'] === $lld_header['current']; ?>
        <a href="<?php echo $hu($t['url']); ?>"<?php echo $cur ? ' aria-current="page"' : ''; ?> class="p-4 rounded-xl bg-white bg-day-card border <?php echo $cur ? 'border-[#1D4E48]' : 'border-[#E0D8CB] border-day-soft'; ?> hover:border-[#1D4E48] transition-all flex items-center gap-4">
          <span class="text-2xl" aria-hidden="true"><?php echo esc_html($t['icon']); ?></span>
          <div>
            <strong class="text-sm text-[#1F1E1C] text-day-dark block"><?php echo esc_html($t['name']); ?></strong>
            <?php if ($t['desc'] !== ''): ?><span class="text-xs text-[#6B6862] text-day-muted"><?php echo esc_html($t['desc']); ?></span><?php endif; ?>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
      <div class="max-w-7xl mx-auto px-8 pb-6">
        <form role="search" method="get" action="<?php echo $hu('/'); ?>" class="max-w-xl">
          <label for="lld-menu-search" class="sr-only">Search Lifeline Daily</label>
          <input id="lld-menu-search" type="search" name="s" placeholder="Search Lifeline Daily..." class="w-full px-4 py-3 text-sm rounded-xl bg-white bg-day-card border border-[#E0D8CB] border-day-soft text-[#1F1E1C] text-day-dark">
        </form>
      </div>
      <div class="md:hidden max-w-7xl mx-auto px-8 pb-8 grid grid-cols-2 gap-3 text-sm font-semibold">
        <a href="<?php echo $anchor('calculators'); ?>" class="px-4 py-3 rounded-xl bg-white bg-day-card border border-[#E0D8CB] border-day-soft text-[#1F1E1C] text-day-dark">Calculators</a>
        <a href="<?php echo $anchor('tracks'); ?>" class="px-4 py-3 rounded-xl bg-white bg-day-card border border-[#E0D8CB] border-day-soft text-[#1F1E1C] text-day-dark">4-Week Tracks</a>
        <a href="<?php echo $anchor('science'); ?>" class="px-4 py-3 rounded-xl bg-white bg-day-card border border-[#E0D8CB] border-day-soft text-[#1F1E1C] text-day-dark">The Science</a>
        <a href="<?php echo $on_home ? '#reads' : $hu('/articles/'); ?>" class="px-4 py-3 rounded-xl bg-white bg-day-card border border-[#E0D8CB] border-day-soft text-[#1F1E1C] text-day-dark">Guides</a>
        <?php if (!$signed_in): ?><a href="<?php echo $hu('/members/sign-in/'); ?>" class="col-span-2 px-4 py-3 rounded-xl bg-white bg-day-card border border-[#E0D8CB] border-day-soft text-[#1D4E48]">Sign In</a><?php endif; ?>
      </div>
    </div>
  </header>
