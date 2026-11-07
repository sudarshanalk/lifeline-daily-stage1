<?php
/**
 * Category page v2 full-page template (see ../category-v2.php). Markup and classes follow the owner's design file
 * `lld_category_weight_eating.html`; styles are the compiled `assets/lld-v2.css`, behaviour `assets/lld-category-v2.js`.
 */
if (!defined('ABSPATH')) exit;

global $wp_query;
$term = get_queried_object();
$hub = lld_category_v2_hub();
$u = function (string $path): string { return esc_url(home_url($path)); };
$signed_in = function_exists('is_user_logged_in') && is_user_logged_in();
$catName = html_entity_decode((string)$term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$paged = max(1, (int)get_query_var('paged'));
$total = (int)$wp_query->found_posts;
$header = lld_hub_header_view($term, $hub, $total);
$tool = lld_hub_tool_view((string)$hub['tool']);
$track = lld_hub_track_view((string)$hub['track']);
$check = isset($_GET['lld_check']) ? sanitize_text_field(wp_unslash($_GET['lld_check'])) : '';
$back = remove_query_arg('lld_check');

// Page 1: the hub's flagship and two trending guides on top, the other guides of the page in the grid.
$posts = (array)$wp_query->posts;
$featured = null; $side = []; $grid = $posts;
if ($paged === 1 && $posts) {
  $byId = []; foreach ($posts as $p) $byId[(int)$p->ID] = $p;
  $want = $hub['flagship'];
  if ($want !== null && $want !== '') {
    $fp = is_numeric($want) ? get_post((int)$want) : get_page_by_path((string)$want, OBJECT, 'post');
    if ($fp && $fp->post_status === 'publish' && !isset($byId[(int)$fp->ID])) $byId[(int)$fp->ID] = $fp;
  }
  $plain = array_map(fn($p) => ['id' => (int)$p->ID, 'slug' => (string)$p->post_name, 'date' => (string)$p->post_date_gmt, 'reviewed' => lld_tools_review_byline($p) !== ''], array_values($byId));
  $pick = lld_hub_pick($plain, $hub);
  if ($pick['flagship']) $featured = lld_category_v2_card($byId[$pick['flagship']], 'medium_large', $catName, true);
  foreach ((array)$pick['trending'] as $id) if (isset($byId[$id])) $side[] = lld_category_v2_card($byId[$id], 'thumbnail', $catName, true);
  $hero = array_filter(array_merge([$pick['flagship']], (array)$pick['trending']));
  $grid = array_slice(array_values(array_filter($posts, fn($p) => !in_array((int)$p->ID, $hero, true))), 0, 9);
}
$cards = array_map(fn($p) => lld_category_v2_card($p, 'medium_large', $catName), $grid);
$from = $total ? ($paged - 1) * LLD_CATEGORY_V2_PER_PAGE + 1 : 0;
$to = min($total, $paged * LLD_CATEGORY_V2_PER_PAGE);
$pages = (int)$wp_query->max_num_pages;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php if (!current_theme_supports('title-tag')): ?>
  <title><?php echo esc_html(wp_get_document_title()); ?></title>
  <?php endif; ?>
  <?php wp_head(); ?>
</head>
<body <?php body_class('lld-v2 mode-day'); ?>>
<?php if (function_exists('wp_body_open')) wp_body_open(); ?>
  <a href="#lld-main" class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[100] focus:bg-white focus:text-[#1D4E48] focus:px-4 focus:py-2 focus:rounded-full">Skip to content</a>

  <!-- Main Global Navigation Header -->
  <header class="nav-blur sticky top-0 z-40 border-b border-day-soft transition-colors duration-200">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-18 flex items-center justify-between gap-4">
      <a href="<?php echo $u('/'); ?>" class="group flex items-baseline gap-2.5 shrink-0 focus:outline-hidden" title="Lifeline Daily Home">
        <span class="font-serif-editorial text-2xl sm:text-3xl font-normal tracking-tight text-day-dark group-hover:text-[#1D4E48] transition-colors">Lifeline Daily</span>
        <span class="hidden sm:inline-block text-[11px] font-medium tracking-wide uppercase text-day-muted border-l border-day-soft pl-2.5">Evidence-based, clinically reviewed</span>
      </a>

      <nav class="hidden lg:flex items-center gap-7 text-sm font-medium text-day-muted" aria-label="Main Navigation">
        <a href="<?php echo $u('/health-tools/'); ?>" class="hover:text-day-dark transition-colors">Health Tools</a>
        <a href="<?php echo $u('/articles/'); ?>" aria-current="page" class="text-[#1D4E48] dark:text-[#4FA89B] font-semibold border-b-2 border-[#1D4E48] pb-1">Articles</a>
        <a href="<?php echo $u('/programmes/'); ?>" class="hover:text-day-dark transition-colors">Programmes</a>
        <a href="<?php echo $u('/pricing/'); ?>" class="hover:text-day-dark transition-colors">Pricing</a>
      </nav>

      <div class="flex items-center gap-3">
        <form role="search" method="get" action="<?php echo $u('/'); ?>" class="relative hidden md:block">
          <label for="lld-cat-search" class="sr-only">Search Lifeline Daily</label>
          <input id="lld-cat-search" type="search" name="s" placeholder="Search Lifeline Daily..." class="w-48 lg:w-56 pl-8 pr-3 py-1.5 text-xs rounded-full bg-white dark:bg-[#1A2624] border border-day-soft text-day-dark focus:outline-hidden focus:border-[#1D4E48] transition-all placeholder:text-day-muted/60">
          <svg class="w-3.5 h-3.5 text-day-muted absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        </form>
        <button type="button" id="themeToggle" aria-pressed="false" class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-full text-xs font-medium border border-day-soft bg-white dark:bg-[#1A2624] text-day-dark hover:border-[#1D4E48] transition-all shadow-xs" title="Switch circadian mode">
          <span id="themeIcon" aria-hidden="true">☀️</span>
          <span id="themeLabel" class="hidden sm:inline text-[11px]">Day</span>
          <span class="sr-only sm:hidden">Day or night colours</span>
        </button>
        <?php if ($signed_in): ?>
        <a href="<?php echo $u('/members/'); ?>" class="hidden sm:inline-flex text-xs font-semibold px-4 py-2 rounded-full bg-[#1D4E48] text-white hover:bg-[#163D38] transition-colors shadow-xs">My account</a>
        <?php else: ?>
        <a href="<?php echo $u('/members/join/'); ?>" class="hidden sm:inline-flex text-xs font-semibold px-4 py-2 rounded-full bg-[#1D4E48] text-white hover:bg-[#163D38] transition-colors shadow-xs">Join Free</a>
        <?php endif; ?>
        <button type="button" id="navMenuBtn" aria-expanded="false" aria-controls="navMenu" class="lg:hidden w-9 h-9 rounded-full border border-day-soft text-day-dark flex items-center justify-center" aria-label="Menu">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-width="2" d="M4 7h16M4 12h16M4 17h16"/></svg>
        </button>
      </div>
    </div>
    <div id="navMenu" class="hidden lg:hidden border-t border-day-soft bg-day-base">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 py-4 grid grid-cols-2 gap-2 text-sm font-semibold">
        <a href="<?php echo $u('/health-tools/'); ?>" class="px-4 py-3 rounded-xl bg-day-surface border border-day-soft text-day-dark">Health Tools</a>
        <a href="<?php echo $u('/articles/'); ?>" class="px-4 py-3 rounded-xl bg-day-surface border border-day-soft text-[#1D4E48] dark:text-[#4FA89B]">Articles</a>
        <a href="<?php echo $u('/programmes/'); ?>" class="px-4 py-3 rounded-xl bg-day-surface border border-day-soft text-day-dark">Programmes</a>
        <a href="<?php echo $u('/pricing/'); ?>" class="px-4 py-3 rounded-xl bg-day-surface border border-day-soft text-day-dark">Pricing</a>
        <form role="search" method="get" action="<?php echo $u('/'); ?>" class="col-span-2">
          <label for="lld-cat-search-m" class="sr-only">Search Lifeline Daily</label>
          <input id="lld-cat-search-m" type="search" name="s" placeholder="Search Lifeline Daily..." class="w-full px-4 py-3 text-sm rounded-xl bg-white dark:bg-[#1A2624] border border-day-soft text-day-dark">
        </form>
        <?php if (!$signed_in): ?><a href="<?php echo $u('/members/join/'); ?>" class="sm:hidden col-span-2 px-4 py-3 rounded-xl bg-[#1D4E48] text-white text-center">Join Free</a><?php endif; ?>
      </div>
    </div>
  </header>

  <main id="lld-main" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-12">

    <!-- Category Masthead -->
    <header class="mb-10 sm:mb-12" data-test="category-header">
      <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <nav class="flex items-center gap-2 text-xs text-day-muted uppercase tracking-wider font-semibold" aria-label="Breadcrumb">
          <a href="<?php echo esc_url($header['home_url']); ?>" class="hover:text-day-dark transition-colors">Home</a>
          <span class="text-day-muted/40" aria-hidden="true">&gt;</span>
          <?php if ($header['parent_name'] !== ''): ?>
          <a href="<?php echo esc_url($header['parent_url']); ?>" class="hover:text-day-dark transition-colors"><?php echo esc_html($header['parent_name']); ?></a>
          <span class="text-day-muted/40" aria-hidden="true">&gt;</span>
          <?php endif; ?>
          <span class="text-[#1D4E48] dark:text-[#4FA89B]" aria-current="page"><?php echo esc_html($catName); ?></span>
        </nav>
        <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-[#E8F2F0] text-[#1D4E48] dark:bg-[#1E302D] dark:text-[#79D1C3] text-xs font-semibold">
          <span class="w-1.5 h-1.5 rounded-full bg-[#1D4E48] dark:bg-[#79D1C3]" aria-hidden="true"></span>
          <span><?php echo (int)$total; ?> Guides &bull; Clinically reviewed</span>
        </div>
      </div>

      <h1 class="font-serif-editorial text-4xl sm:text-5xl lg:text-6xl text-day-dark font-normal tracking-tight leading-[1.08] mb-4"><?php echo esc_html($catName); ?></h1>
      <p class="text-base sm:text-lg text-day-muted max-w-3xl leading-relaxed"><?php echo esc_html($header['description']); ?></p>

      <?php if (!empty($header['pills'])): ?>
      <nav class="mt-7 pt-6 border-t border-day-soft flex flex-wrap items-center gap-2.5 text-xs" aria-label="<?php echo esc_attr(rtrim($header['pills_label'], ':')); ?>">
        <span class="text-[11px] font-bold tracking-wider uppercase text-day-muted mr-1"><?php echo esc_html(rtrim(strtoupper($header['pills_label']), ':')); ?></span>
        <?php foreach ($header['pills'] as $pill): ?>
        <a href="<?php echo esc_url($pill['url']); ?>"<?php echo $pill['active'] ? ' aria-current="page"' : ''; ?> class="tab-pill<?php echo $pill['active'] ? ' active' : ''; ?> px-4 py-2 rounded-full font-semibold border border-day-soft bg-white dark:bg-[#1A2624] text-day-muted hover:text-day-dark hover:border-[#1D4E48] transition-all"><?php echo esc_html($pill['label']); ?></a>
        <?php endforeach; ?>
      </nav>
      <?php endif; ?>
    </header>

    <?php if ($featured): ?>
    <!-- Top 3 Magazine Stories -->
    <section class="mb-14" aria-label="Featured guides">
      <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-stretch">
        <article class="lg:col-span-7 rounded-2xl bg-day-surface border border-day-soft overflow-hidden transition-card flex flex-col justify-between group relative" data-post-id="<?php echo (int)$featured['id']; ?>">
          <div class="p-6 sm:p-8 flex flex-col justify-between h-full">
            <div>
              <div class="flex items-center justify-between gap-2 mb-4">
                <span class="inline-flex items-center px-2.5 py-1 rounded-md text-[11px] font-bold tracking-wider uppercase bg-[#1D4E48] text-white">Popular guide</span>
                <span class="text-xs text-day-muted"><?php echo (int)$featured['minutes']; ?> min read &bull; Updated <?php echo esc_html(date_i18n('M Y', strtotime('1 ' . $featured['updated']))); ?></span>
              </div>
              <h2 class="font-serif-editorial text-2xl sm:text-3xl lg:text-4xl text-day-dark font-normal tracking-tight group-hover:text-[#1D4E48] dark:group-hover:text-[#4FA89B] transition-colors mb-3 leading-snug">
                <a href="<?php echo esc_url($featured['url']); ?>" class="after:absolute after:inset-0"><?php echo esc_html($featured['title']); ?></a>
              </h2>
              <p class="text-sm sm:text-base text-day-muted leading-relaxed mb-6"><?php echo esc_html($featured['excerpt']); ?></p>
            </div>
            <?php if ($featured['image'] !== ''): ?>
            <div class="relative w-full aspect-16/9 rounded-xl overflow-hidden bg-day-base border border-day-soft mb-6">
              <?php echo $featured['image']; // phpcs:ignore -- WordPress-built image tag ?>
              <div class="absolute inset-0 bg-gradient-to-t from-black/25 via-transparent to-transparent pointer-events-none"></div>
            </div>
            <?php endif; ?>
            <div>
              <span class="inline-flex items-center gap-2 text-sm font-bold text-[#1D4E48] dark:text-[#4FA89B] group-hover:underline" aria-hidden="true"><span>Read full guide</span><span class="transition-transform group-hover:translate-x-1">&rarr;</span></span>
            </div>
          </div>
        </article>

        <?php if ($side): ?>
        <div class="lg:col-span-5 flex flex-col gap-6">
          <?php foreach ($side as $s): ?>
          <article class="flex-1 rounded-2xl bg-day-surface border border-day-soft p-6 transition-card flex flex-col justify-between group relative" data-post-id="<?php echo (int)$s['id']; ?>">
            <div>
              <div class="flex items-center justify-between gap-2 mb-3">
                <span class="text-[11px] font-bold tracking-wider uppercase text-[#1D4E48] dark:text-[#4FA89B]"><?php echo esc_html($s['category_label']); ?></span>
                <span class="text-xs text-day-muted"><?php echo (int)$s['minutes']; ?> min read</span>
              </div>
              <div class="flex gap-4 items-start">
                <div class="flex-1">
                  <h3 class="font-serif-editorial text-xl sm:text-2xl text-day-dark font-normal leading-snug group-hover:text-[#1D4E48] dark:group-hover:text-[#4FA89B] transition-colors mb-2">
                    <a href="<?php echo esc_url($s['url']); ?>" class="after:absolute after:inset-0"><?php echo esc_html($s['title']); ?></a>
                  </h3>
                  <p class="text-xs sm:text-sm text-day-muted line-clamp-3 leading-relaxed mb-4"><?php echo esc_html($s['excerpt']); ?></p>
                </div>
                <?php if ($s['image'] !== ''): ?>
                <div class="w-20 h-20 sm:w-24 sm:h-24 rounded-xl overflow-hidden shrink-0 border border-day-soft"><?php echo $s['image']; // phpcs:ignore ?></div>
                <?php endif; ?>
              </div>
            </div>
            <div>
              <span class="inline-flex items-center gap-1.5 text-xs font-bold text-[#1D4E48] dark:text-[#4FA89B] group-hover:underline" aria-hidden="true"><span>Read guide</span><span class="transition-transform group-hover:translate-x-1">&rarr;</span></span>
            </div>
          </article>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($tool && $paged === 1): ?>
    <!-- Featured tool banner -->
    <section class="mb-14" aria-label="Featured tool">
      <div class="rounded-2xl bg-day-surface border-2 border-[#1D4E48]/20 dark:border-[#4FA89B]/30 p-6 sm:p-8 flex flex-col md:flex-row items-start md:items-center justify-between gap-6 shadow-xs">
        <div class="flex items-start gap-4 sm:gap-5">
          <div class="w-12 h-12 rounded-xl bg-[#E8F2F0] dark:bg-[#1E302D] text-[#1D4E48] dark:text-[#79D1C3] flex items-center justify-center shrink-0" aria-hidden="true">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke-width="2"/><path stroke-linecap="round" stroke-width="2" d="M12 6v6l4 2"/></svg>
          </div>
          <div>
            <div class="flex items-center gap-2 mb-1">
              <span class="text-[10px] font-bold tracking-wider uppercase text-[#1D4E48] dark:text-[#4FA89B]">Featured tool</span>
              <span class="text-day-muted text-xs" aria-hidden="true">&bull;</span>
              <span class="text-[10px] font-bold tracking-wider uppercase text-day-muted">Clinical calculator</span>
            </div>
            <h2 class="font-serif-editorial text-2xl sm:text-3xl text-day-dark font-normal mb-1"><?php echo esc_html($tool['name']); ?></h2>
            <p class="text-xs sm:text-sm text-day-muted max-w-2xl"><?php echo esc_html($tool['purpose']); ?></p>
          </div>
        </div>
        <div class="shrink-0 w-full md:w-auto">
          <a href="<?php echo esc_url($tool['url']); ?>" class="w-full md:w-auto inline-flex items-center justify-center gap-2 px-6 py-3 rounded-full bg-[#1D4E48] text-white font-semibold text-xs sm:text-sm hover:bg-[#163D38] transition-colors shadow-xs">
            <span><?php echo esc_html($tool['cta_label']); ?></span><span aria-hidden="true">&rarr;</span>
          </a>
        </div>
      </div>
    </section>
    <?php endif; ?>

    <!-- Latest guides -->
    <div class="flex items-baseline justify-between gap-4 mb-8">
      <h2 class="font-serif-editorial text-2xl sm:text-3xl lg:text-4xl text-day-dark font-normal tracking-tight"><?php echo $paged === 1 ? 'Latest Guides in ' : 'More Guides in '; ?><?php echo esc_html($catName); ?></h2>
      <span class="text-xs text-day-muted shrink-0"><?php echo $total ? 'Showing ' . (int)$from . '&ndash;' . (int)$to . ' of ' . (int)$total . ' guides' : ''; ?></span>
    </div>

    <section class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-10" id="guidesGrid" data-test="category-grid" aria-label="Guides">
      <?php foreach ($cards as $c): ?>
      <article class="guide-card rounded-2xl bg-day-surface border border-day-soft overflow-hidden transition-card flex flex-col justify-between group relative" data-post-id="<?php echo (int)$c['id']; ?>">
        <div>
          <div class="relative w-full aspect-16/10 overflow-hidden bg-day-base">
            <?php echo $c['image']; // phpcs:ignore -- WordPress-built image tag ?>
            <span class="absolute top-3 left-3 px-2.5 py-1 rounded-md text-[11px] font-bold uppercase tracking-wider bg-white/90 dark:bg-[#1A2624]/90 text-day-dark backdrop-blur-xs"><?php echo esc_html($c['category_label']); ?></span>
          </div>
          <div class="p-5">
            <div class="text-[11px] text-day-muted mb-2 font-medium"><?php echo (int)$c['minutes']; ?> min read &bull; Updated <?php echo esc_html($c['updated']); ?></div>
            <h3 class="font-serif-editorial text-xl sm:text-2xl text-day-dark font-normal leading-snug group-hover:text-[#1D4E48] dark:group-hover:text-[#4FA89B] transition-colors mb-2">
              <a href="<?php echo esc_url($c['url']); ?>" class="after:absolute after:inset-0"><?php echo esc_html($c['title']); ?></a>
            </h3>
            <p class="text-xs sm:text-sm text-day-muted line-clamp-3 leading-relaxed"><?php echo esc_html($c['excerpt']); ?></p>
          </div>
        </div>
        <div class="p-5 pt-0">
          <span class="inline-flex items-center gap-1.5 text-xs font-bold text-[#1D4E48] dark:text-[#4FA89B] group-hover:underline" aria-hidden="true"><span>Read guide</span><span class="transition-transform group-hover:translate-x-1">&rarr;</span></span>
        </div>
      </article>
      <?php endforeach; ?>
    </section>

    <?php if ($pages > 1): ?>
    <nav class="mb-16 flex items-center justify-center gap-2 text-sm font-semibold" aria-label="Guide pages">
      <?php
      $links = paginate_links(['total' => $pages, 'current' => $paged, 'type' => 'array', 'prev_text' => '&larr; Newer', 'next_text' => 'Older &rarr;', 'mid_size' => 1]);
      foreach ((array)$links as $l) {
        $on = strpos($l, 'current') !== false;
        echo '<span class="' . ($on ? 'tab-pill active ' : '') . 'inline-flex rounded-full border border-day-soft bg-white dark:bg-[#1A2624] text-day-dark [&>a]:px-4 [&>a]:py-2 [&>span]:px-4 [&>span]:py-2 hover:border-[#1D4E48]">' . $l . '</span>'; // phpcs:ignore -- WordPress-built links
      }
      ?>
    </nav>
    <?php else: ?><div class="mb-6"></div><?php endif; ?>

    <?php if ($track): ?>
    <!-- 4-Week Programme Banner -->
    <section class="mb-16" aria-label="4-week programme">
      <div class="rounded-3xl bg-[#142A27] text-white p-8 sm:p-12 relative overflow-hidden shadow-lg border border-[#23443F]">
        <div class="relative z-10 max-w-2xl">
          <span class="inline-flex items-center px-3 py-1 rounded-full text-[11px] font-bold uppercase tracking-wider bg-[#224A44] text-[#79D1C3] mb-4">4-week programme</span>
          <h2 class="font-serif-editorial text-3xl sm:text-4xl lg:text-5xl font-normal leading-tight mb-4">Ready to put these <?php echo esc_html(function_exists('mb_strtolower') ? mb_strtolower($catName) : strtolower($catName)); ?> guides into practice?</h2>
          <p class="text-sm sm:text-base text-white/80 leading-relaxed mb-8">Join our 4-week <?php echo esc_html($track['title']); ?> track: daily bite-size habits, meal guidance, and weekly review. Week 1 is free, then Premium is US$9.99 a month.</p>
          <div>
            <a href="<?php echo esc_url($track['url']); ?>" class="inline-flex items-center gap-2 px-8 py-3.5 rounded-full bg-[#1D4E48] hover:bg-[#235E57] text-white font-semibold text-sm transition-colors border border-white/20 shadow-md"><span>Start 7-day trial</span><span aria-hidden="true">&rarr;</span></a>
          </div>
        </div>
        <div class="absolute -right-16 -bottom-16 w-80 h-80 rounded-full bg-[#1D4E48]/30 blur-3xl pointer-events-none"></div>
      </div>
    </section>
    <?php endif; ?>

    <!-- Medical Urgent Care Warning Bar -->
    <div class="rounded-xl bg-[#FFF8F0] dark:bg-[#1C1612] border border-[#F3DEC8] dark:border-[#382619] p-4 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 text-xs mb-16 text-[#6B4B27] dark:text-[#E8BD8C]" data-test="urgent-care">
      <div class="flex items-center gap-3">
        <span class="text-base shrink-0" aria-hidden="true">⚠️</span>
        <p class="font-medium">If you feel very unwell, get medical help today. In an emergency, call your local emergency number straight away.</p>
      </div>
      <a href="<?php echo $u('/medical-disclaimer/#urgent-care'); ?>" class="font-bold underline hover:no-underline shrink-0 text-[#8C5E2D] dark:text-[#F3DEC8]">When to see a doctor &rarr;</a>
    </div>
  </main>

  <!-- Footer -->
  <footer class="lld-footer bg-[#101413] text-[#A39E93] text-xs pt-16 pb-12 border-t border-[#1D2926]" role="contentinfo">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-10 pb-14 border-b border-[#1D2926]">
        <div class="lg:col-span-2">
          <a href="<?php echo $u('/'); ?>" class="inline-block font-serif-editorial text-2xl text-[#F4F1EA] mb-2 hover:text-white transition-colors">Lifeline Daily</a>
          <p class="text-[11px] font-semibold tracking-wider uppercase text-[#79D1C3] mb-3">Evidence-based. Clinically reviewed.</p>
          <p class="text-xs text-[#878378] leading-relaxed mb-6 max-w-sm">Simple, evidence-based guides and calculators to improve your everyday health, to help you feel like you again.</p>
          <div class="flex items-center gap-3 text-[#A39E93]">
            <?php foreach ([
              ['https://www.facebook.com/lifelineofhealth', 'Facebook', 'M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z'],
              ['https://www.instagram.com/life.l.daily/', 'Instagram', 'M12 2.2c3.2 0 3.6 0 4.8.1 3.3.1 4.8 1.7 4.9 4.9.1 1.3.1 1.6.1 4.8s0 3.6-.1 4.8c-.1 3.2-1.7 4.8-4.9 4.9-1.3.1-1.6.1-4.8.1s-3.6 0-4.8-.1c-3.3-.1-4.8-1.7-4.9-4.9-.1-1.3-.1-1.6-.1-4.8s0-3.6.1-4.8C2.4 3.9 3.9 2.4 7.2 2.3c1.2-.1 1.6-.1 4.8-.1zM12 5.8a6.2 6.2 0 100 12.4 6.2 6.2 0 000-12.4zm0 10.2a4 4 0 110-8 4 4 0 010 8zm6.4-11.8a1.4 1.4 0 100 2.9 1.4 1.4 0 000-2.9z'],
              ['https://uk.pinterest.com/lifelinedaily7/', 'Pinterest', 'M12 0a12 12 0 0 0-4.37 23.18c-.07-.94-.13-2.39.03-3.42l.96-4.08s-.24-.49-.24-1.22c0-1.14.66-2 1.48-2 .7 0 1.04.53 1.04 1.16 0 .7-.45 1.76-.68 2.74-.2.83.42 1.5 1.23 1.5 1.48 0 2.62-1.56 2.62-3.81 0-1.99-1.43-3.38-3.48-3.38-2.37 0-3.76 1.78-3.76 3.62 0 .72.28 1.49.62 1.9.07.08.08.16.06.24l-.23.95c-.04.15-.12.18-.28.11-1.04-.48-1.69-2-1.69-3.22 0-2.62 1.9-5.03 5.49-5.03 2.88 0 5.12 2.05 5.12 4.8 0 2.86-1.8 5.17-4.3 5.17-.84 0-1.63-.44-1.9-.96l-.52 1.97c-.19.72-.7 1.63-1.04 2.18A12 12 0 1 0 12 0z'],
              ['https://x.com/LifelineDaily', 'X', 'M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z'],
              ['https://www.youtube.com/@LifelineDaily', 'YouTube', 'M23.5 6.2a3 3 0 00-2.1-2.1C19.5 3.5 12 3.5 12 3.5s-7.5 0-9.4.6A3 3 0 00.5 6.2C0 8.1 0 12 0 12s0 3.9.5 5.8a3 3 0 002.1 2.1c1.9.6 9.4.6 9.4.6s7.5 0 9.4-.6a3 3 0 002.1-2.1c.5-1.9.5-5.8.5-5.8s0-3.9-.5-5.8zM9.5 15.6V8.4l6.3 3.6-6.3 3.6z'],
            ] as [$href, $name, $path]): ?>
            <a href="<?php echo esc_url($href); ?>" target="_blank" rel="noopener" aria-label="Lifeline Daily on <?php echo esc_attr($name); ?>" class="w-8 h-8 rounded-full bg-[#1A2624] flex items-center justify-center hover:text-white hover:bg-[#23332F] transition-all"><svg class="w-3.5 h-3.5 fill-current" viewBox="0 0 24 24" aria-hidden="true"><path d="<?php echo esc_attr($path); ?>"/></svg></a>
            <?php endforeach; ?>
          </div>
        </div>
        <nav aria-label="Health tools">
          <h2 class="text-[11px] font-bold tracking-wider uppercase text-[#F4F1EA] mb-4">Health tools</h2>
          <ul class="space-y-2.5">
            <li><a href="<?php echo $u('/health-tools/protein/'); ?>" class="hover:text-white transition-colors">Daily Protein Target</a></li>
            <li><a href="<?php echo $u('/health-tools/diabetes-risk-check/'); ?>" class="hover:text-white transition-colors">Diabetes Risk Check</a></li>
            <li><a href="<?php echo $u('/health-tools/healthy-weight-check/'); ?>" class="hover:text-white transition-colors">Healthy Weight Check</a></li>
            <li class="pt-1"><a href="<?php echo $u('/health-tools/'); ?>" class="text-[#79D1C3] hover:underline font-medium">All health tools &rarr;</a></li>
          </ul>
        </nav>
        <nav aria-label="Topics">
          <h2 class="text-[11px] font-bold tracking-wider uppercase text-[#F4F1EA] mb-4">Topics</h2>
          <ul class="space-y-2.5">
            <?php foreach ((array)(lld_categories_doc()['tree'] ?? []) as $t): $on = (string)$t['slug'] === (string)$hub['category']; ?>
            <li><a href="<?php echo $u('/category/' . $t['slug'] . '/'); ?>" class="<?php echo $on ? 'text-white font-medium hover:underline' : 'hover:text-white transition-colors'; ?>"><?php echo esc_html(str_replace(' & ', ' and ', html_entity_decode((string)$t['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))); ?></a></li>
            <?php endforeach; ?>
            <li class="pt-1"><a href="<?php echo $u('/articles/'); ?>" class="text-[#79D1C3] hover:underline font-medium">All articles &rarr;</a></li>
          </ul>
        </nav>
        <nav aria-label="Programmes">
          <h2 class="text-[11px] font-bold tracking-wider uppercase text-[#F4F1EA] mb-4">Programmes</h2>
          <ul class="space-y-2.5">
            <li><a href="<?php echo $u('/programmes/healthy-weight/'); ?>" class="hover:text-white transition-colors">Healthy Weight</a></li>
            <li><a href="<?php echo $u('/programmes/blood-sugar/'); ?>" class="hover:text-white transition-colors">Blood Sugar Balance</a></li>
            <li><a href="<?php echo $u('/programmes/fitness/'); ?>" class="hover:text-white transition-colors">Fitness and Strength</a></li>
            <li><a href="<?php echo $u('/programmes/sleep-stress/'); ?>" class="hover:text-white transition-colors">Sleep and Stress</a></li>
            <li class="pt-1"><a href="<?php echo $u('/programmes/'); ?>" class="text-[#79D1C3] hover:underline font-medium">All programmes &rarr;</a></li>
            <li class="pt-2"><a href="<?php echo $u('/pricing/'); ?>" class="hover:text-white transition-colors">Pricing</a></li>
          </ul>
        </nav>
        <nav aria-label="Lifeline Daily">
          <h2 class="text-[11px] font-bold tracking-wider uppercase text-[#F4F1EA] mb-4">Lifeline Daily</h2>
          <ul class="space-y-2.5">
            <li><a href="<?php echo $u('/about-us/'); ?>" class="hover:text-white transition-colors">About us</a></li>
            <li><a href="<?php echo $u('/editorial-standards/'); ?>" class="hover:text-white transition-colors">Editorial standards</a></li>
            <li><a href="<?php echo $u('/contact-us/'); ?>" class="hover:text-white transition-colors">Contact</a></li>
            <li><a href="<?php echo $u('/medical-disclaimer/'); ?>" class="hover:text-white transition-colors">Medical disclaimer and urgent care</a></li>
          </ul>
        </nav>
      </div>

      <!-- Free Starter Guide Box (the site's starter-guide request) -->
      <div id="starter-guide" class="my-10 p-6 rounded-2xl bg-[#162220] border border-[#233834] flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
        <div>
          <h2 class="font-serif-editorial text-2xl text-[#F4F1EA] font-normal mb-1">Get a free starter guide</h2>
          <p class="text-xs text-[#A39E93]">Pick a topic and get 7 practical tips by email over your first week.</p>
          <?php if ($check !== '' && $check !== '1'): ?><p class="mt-2 text-xs font-semibold text-[#79D1C3]" role="status">Check your email to confirm.</p><?php endif; ?>
          <?php if ($check === '1'): ?><p class="mt-2 text-xs font-semibold text-[#F3DEC8]" role="alert">We could not send the guide to that address. Please check it and try again.</p><?php endif; ?>
        </div>
        <form method="post" action="" class="flex flex-col sm:flex-row items-stretch sm:items-end gap-2.5 w-full md:w-auto">
          <?php if (function_exists('wp_nonce_field')) wp_nonce_field('lld_guide', 'lld_guide_nonce'); ?>
          <input type="hidden" name="lld_kind" value="guide">
          <input type="hidden" name="lld_back" value="<?php echo esc_url($back . '#starter-guide'); ?>">
          <div class="flex flex-col gap-1">
            <label for="lld-cat-guide-track" class="text-[10px] uppercase font-bold tracking-wider text-[#A39E93]">Your goal</label>
            <select id="lld-cat-guide-track" name="lld_track" required class="px-3 py-2 rounded-lg bg-[#111A18] border border-[#233834] text-xs text-white focus:outline-hidden focus:border-[#79D1C3]">
              <?php foreach (['healthy-weight' => 'HEALTHY WEIGHT', 'blood-sugar' => 'BLOOD SUGAR BALANCE', 'fitness' => 'FITNESS AND STRENGTH', 'sleep-stress' => 'SLEEP AND STRESS'] as $k => $lab): ?>
              <option value="<?php echo esc_attr($k); ?>"<?php echo $k === (string)$hub['track'] ? ' selected' : ''; ?>><?php echo esc_html($lab); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="flex flex-col gap-1 flex-1 sm:w-60">
            <label for="lld-cat-guide-email" class="text-[10px] uppercase font-bold tracking-wider text-[#A39E93]">Email</label>
            <input type="email" id="lld-cat-guide-email" name="lld_email" required autocomplete="email" placeholder="Enter your email" class="px-3 py-2 rounded-lg bg-[#111A18] border border-[#233834] text-xs text-white focus:outline-hidden focus:border-[#79D1C3]">
          </div>
          <button type="submit" class="px-5 py-2 rounded-lg bg-[#1D4E48] text-white font-semibold text-xs hover:bg-[#235E57] transition-colors border border-white/10 shrink-0">Send me the guide &rarr;</button>
        </form>
      </div>

      <div class="pt-6 flex flex-col sm:flex-row items-center justify-between gap-4 text-[11px] text-[#706C64]">
        <p>&copy; <?php echo esc_html(gmdate('Y')); ?> Lifeline Daily. Health information, not medical advice.</p>
        <div class="flex items-center gap-5 flex-wrap justify-center">
          <a href="<?php echo $u('/privacy-policy/'); ?>" class="hover:text-white transition-colors">Privacy policy</a>
          <a href="<?php echo $u('/terms-conditions/'); ?>" class="hover:text-white transition-colors">Terms and conditions</a>
          <a href="<?php echo $u('/editorial-standards/'); ?>" class="hover:text-white transition-colors">Editorial policy</a>
          <a href="#cookie-settings" data-lld-cookie-settings class="hover:text-white transition-colors">Cookie settings</a>
        </div>
      </div>
    </div>
  </footer>
<?php wp_footer(); ?>
</body>
</html>
