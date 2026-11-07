<?php
/**
 * Public pages v2 full-page template (see ../page-v2.php): the Health Tools hub, the three tool pages, the governance pages
 * and search, in the blocks of the owner's design files 04 to 09, with the site's one header and footer. Every word on
 * these pages is the approved text the old templates showed; the design gives the layout only (Owner_Questions 153).
 */
if (!defined('ABSPATH')) exit;

$view = lld_page_v2_view();
$u = function (string $path): string { return esc_url(home_url($path)); };
$page = $view === 'search' ? null : get_queried_object();
$title = $page ? html_entity_decode(get_the_title($page), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
$content = '';
if ($page) {
  // the page's own content through the usual filters, in the main loop as the theme showed it (shortcodes, the tool frame,
  // the review byline, which the content filters add only inside the loop)
  while (have_posts()) { the_post(); ob_start(); the_content(); $content .= (string)ob_get_clean(); }
  rewind_posts();
}
$crumb_link = 'text-inherit hover:text-day-dark transition-colors';
$card = 'rounded-2xl bg-day-surface bg-white border border-day-soft';
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
<body <?php body_class('lld-v2 lld-v2-page lld-v2-page--' . $view); ?>>
<script>try{if(localStorage.getItem('lld_circadian')==='night')document.body.classList.add('mode-night');}catch(e){}</script>
<?php if (function_exists('wp_body_open')) wp_body_open(); ?>
  <a href="#lld-main" class="text-inherit sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[100] focus:bg-white focus:text-[#1D4E48] focus:px-4 focus:py-2 focus:rounded-full">Skip to content</a>

  <?php $lld_header = ['page' => in_array($view, ['hub', 'tool'], true) ? 'tools' : 'page', 'current' => '']; include __DIR__ . '/parts/v2-header.php'; ?>

  <main id="lld-main" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-12">

<?php if ($view === 'hub'): ?>
    <!-- Health Tools hub masthead (the approved hero text, lld_tools_hub_page_header_html) -->
    <header class="mb-10 sm:mb-12 border-b border-day-soft pb-8" data-test="tools-hub-hero">
      <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div class="flex items-center gap-2.5 flex-wrap">
          <nav class="flex items-center gap-2 text-xs text-[#62605A] text-day-muted uppercase tracking-wider font-bold mr-2" aria-label="Breadcrumb">
            <a href="<?php echo $u('/'); ?>" class="<?php echo $crumb_link; ?>">Home</a>
            <span aria-hidden="true">&gt;</span>
            <span class="text-[#1D4E48] dark:text-[#4FA89B]" aria-current="page">Health Tools</span>
          </nav>
          <span class="inline-flex items-center px-3 py-1 rounded-full bg-[#E8F2F0] text-[#1D4E48] border border-[#B8D8D2] dark:bg-[#1E302D] dark:text-[#79D1C3] dark:border-[#2F4F49] text-[11px] font-bold uppercase tracking-wider">Clinical Self-Service Calculators</span>
        </div>
        <span class="inline-flex items-center gap-1.5 px-3.5 py-1 rounded-full bg-white bg-day-surface border border-day-soft text-[#1F1E1C] text-day-dark text-xs font-bold">
          <span class="w-2 h-2 rounded-full bg-[#1D4E48] dark:bg-[#79D1C3]" aria-hidden="true"></span>
          <span>100% Browser-Private &bull; Clinically Reviewed</span>
        </span>
      </div>
      <h1 class="text-3xl sm:text-4xl lg:text-5xl text-[#1F1E1C] text-day-dark font-extrabold tracking-tight leading-[1.12] mb-4">Health Tools &amp; Calculators</h1>
      <p class="text-base sm:text-lg text-[#3A3834] text-day-muted font-medium max-w-3xl leading-relaxed">Personalised screening checks built from published clinical guidelines and peer-reviewed risk models (WHO, ISSN, PROT-AGE, Oxford QDiabetes-2018, and NICE NG246). Your answers are calculated inside your browser and never stored on our servers.</p>
    </header>

    <!-- Health Tools cards ([lld_tools_hub], markup unchanged) -->
    <div class="lld-v2-hub mb-14">
      <?php echo $content; // phpcs:ignore -- the page's own content, rendered by WordPress ?>
    </div>

    <!-- Urgent care and governance cards (the approved text, lld_tools_hub_page_footer_html) -->
    <section class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6" data-test="tools-hub-foot" aria-label="Urgent care and clinical governance">
      <div class="rounded-2xl bg-[#FFF8F0] dark:bg-[#1C1612] border border-[#DFC3A4] dark:border-[#4A3322] p-6 sm:p-7">
        <h2 class="text-sm sm:text-base font-extrabold uppercase tracking-wider text-[#7A4F21] dark:text-[#F3DEC8] mb-2 flex items-center gap-2"><span aria-hidden="true">&#128680;</span><span>When to seek urgent medical care</span></h2>
        <p class="text-sm text-[#5C3D1B] dark:text-[#E8BD8C] font-medium leading-relaxed">If you feel very unwell — for example chest pain, difficulty breathing, signs of a stroke, severe bleeding, or confusion — do not wait for a screening result. <strong class="font-bold">Call your local emergency number (999, 911, 112, or 000) immediately.</strong> <a href="<?php echo $u('/medical-disclaimer/#urgent-care'); ?>" class="text-inherit font-bold underline hover:no-underline">Read full urgent-care guidance &rarr;</a></p>
      </div>
      <div class="<?php echo $card; ?> p-6 sm:p-7">
        <h2 class="text-sm sm:text-base font-extrabold uppercase tracking-wider text-[#1D4E48] dark:text-[#79D1C3] mb-2 flex items-center gap-2"><span aria-hidden="true">&#128737;&#65039;</span><span>Clinical Governance &amp; Privacy Guarantee</span></h2>
        <p class="text-sm text-[#5A5751] text-day-muted font-medium leading-relaxed">Each calculator is a screening aid for adults aged 18+ and is locked to its clinically approved engine version. Calculations run 100% in your browser. <a href="<?php echo $u('/medical-disclaimer/'); ?>" class="font-bold text-[#1F1E1C] text-day-dark underline hover:no-underline">Medical Disclaimer</a> &bull; <a href="<?php echo $u('/privacy-policy/'); ?>" class="font-bold text-[#1F1E1C] text-day-dark underline hover:no-underline">Privacy Policy</a></p>
      </div>
    </section>

<?php elseif ($view === 'tool'):
  $slug = lld_page_v2_tool_slug();
  $c = lld_tools_card($slug);
  $look = lld_tools_card_look($slug);
  $status = lld_tools_tool_status($slug);
  $parts = lld_page_v2_sections($content);
  $sec = $parts['sections'];
  $all = ['purpose', 'who', 'enter', 'try', 'limitations', 'how', 'privacy', 'related'];
  $shaped = count(array_intersect($all, array_keys($sec))) === count($all);
  $try = $shaped ? $sec['try']['heading'] : '';
  // What lld-members adds below the tool (FR2-23: the save form, the track link, the starter guide, and their listener)
  // arrives at the end of the content; it is shown under the tool, where it belongs.
  $extras = '';
  if ($shaped) {
    [$extras, $sec['related']['body']] = lld_article_v2_take_el('#<div\b[^>]*\bid="lld-landing-extras"#i', $sec['related']['body']);
    if ($extras !== '') {
      [$js, $sec['related']['body']] = lld_article_v2_take('#<script>\(function\(\)\{var frame=document\.querySelector\("iframe\.lld-tool-frame"\).*?</script>#s', $sec['related']['body']);
      $extras .= $js;
      $extras = (string)preg_replace(['#<h2\b#i', '#</h2>#i'], ['<h3', '</h3>'], $extras);   // under the tool's own h2
    }
  }
  $h2card = 'text-lg sm:text-xl text-[#1F1E1C] text-day-dark font-extrabold tracking-tight';
?>
    <!-- Tool page masthead (the tool's approved card: name, purpose, time, status) -->
    <header class="mb-10 border-b border-day-soft pb-8" data-test="tool-header">
      <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <nav class="flex items-center gap-2 text-xs text-[#62605A] text-day-muted uppercase tracking-wider font-bold" aria-label="Breadcrumb">
          <a href="<?php echo $u('/'); ?>" class="<?php echo $crumb_link; ?>">Home</a>
          <span aria-hidden="true">&gt;</span>
          <a href="<?php echo $u('/health-tools/'); ?>" class="<?php echo $crumb_link; ?>">Health Tools</a>
          <span aria-hidden="true">&gt;</span>
          <span class="text-[#1D4E48] dark:text-[#4FA89B]" aria-current="page"><?php echo esc_html($title); ?></span>
        </nav>
        <div class="flex items-center gap-2 flex-wrap">
          <span class="px-3 py-1 rounded-full text-xs font-bold bg-[#F4EFE6] dark:bg-[#121A18] border border-day-soft text-[#1F1E1C] text-day-dark">&#9201; <?php echo (int)$c['time_minutes']; ?> min check</span>
          <span class="inline-flex items-center gap-1.5 px-3.5 py-1 rounded-full bg-white bg-day-surface border border-day-soft text-[#1F1E1C] text-day-dark text-xs font-bold">
            <span class="w-2 h-2 rounded-full <?php echo $status === 'active' ? 'bg-[#1D4E48] dark:bg-[#79D1C3]' : 'bg-[#A56557]'; ?>" aria-hidden="true"></span>
            <span><?php echo esc_html(lld_tools_status_label($status)); ?></span>
          </span>
        </div>
      </div>
      <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-6">
        <div>
          <span class="inline-flex w-12 h-12 mb-4 rounded-2xl bg-[#E8F2F0] border border-[#B8D8D2] dark:bg-[#1E302D] dark:border-[#2F4F49] items-center justify-center text-2xl" aria-hidden="true"><?php echo $look['icon']; // phpcs:ignore -- fixed entity ?></span>
          <h1 class="text-3xl sm:text-4xl lg:text-5xl text-[#1F1E1C] text-day-dark font-extrabold tracking-tight leading-[1.12] mb-3"><?php echo esc_html($title); ?></h1>
          <p class="text-base sm:text-lg text-[#3A3834] text-day-muted font-medium max-w-3xl leading-relaxed"><?php echo esc_html((string)$c['purpose']); ?></p>
        </div>
        <?php if ($shaped && $status === 'active'): ?>
        <div class="shrink-0">
          <a href="#try-calculator" class="inline-flex items-center gap-2 px-6 py-3 rounded-full bg-[#1D4E48] text-white font-bold text-sm hover:bg-[#143833] transition-colors shadow-sm"><span><?php echo esc_html($try); ?></span><span aria-hidden="true">&darr;</span></a>
        </div>
        <?php endif; ?>
      </div>
    </header>

  <?php if (!$shaped): ?>
    <!-- Page not in the landing-page shape: shown whole -->
    <div class="<?php echo $card; ?> p-6 sm:p-8 mb-14 lld-prose lld-prose--flow lld-v2-copy"><?php echo $content; // phpcs:ignore ?></div>
  <?php else: ?>
    <?php if (trim(strip_tags($parts['intro'])) !== ''): ?><div class="mb-8 lld-prose lld-prose--flow"><?php echo $parts['intro']; // phpcs:ignore ?></div><?php endif; ?>

    <!-- Purpose, Who it is for, What you will enter -->
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-12" aria-label="About this tool">
      <?php $n = 0; foreach (['purpose', 'who', 'enter'] as $k): $n++; ?>
      <div class="<?php echo $card; ?> p-6 flex flex-col justify-between">
        <div>
          <div class="flex items-center gap-2 mb-3">
            <span class="w-7 h-7 shrink-0 rounded-lg bg-[#E8F2F0] dark:bg-[#1E302D] text-[#1D4E48] dark:text-[#79D1C3] flex items-center justify-center text-xs font-extrabold border border-[#B8D8D2] dark:border-[#2F4F49]" aria-hidden="true">0<?php echo $n; ?></span>
            <?php echo lld_page_v2_h2($sec[$k]['h2'], $h2card); // phpcs:ignore -- the page's own heading ?>
          </div>
          <div class="lld-v2-copy<?php echo $k === 'enter' ? ' lld-v2-copy--box' : ''; ?>"><?php echo $sec[$k]['body']; // phpcs:ignore ?></div>
        </div>
        <?php if ($k === 'purpose'): ?>
        <p class="mt-4 pt-3 border-t border-day-soft flex items-center gap-1.5 text-xs font-bold text-[#1D4E48] dark:text-[#79D1C3]">
          <svg viewBox="0 0 20 20" width="15" height="15" aria-hidden="true" focusable="false" class="shrink-0"><path fill="currentColor" fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
          <span><?php echo esc_html($look['check']); ?></span>
        </p>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </section>

    <!-- The tool (FR1-21 frame, unchanged) -->
    <section id="try-calculator" class="mb-14 scroll-mt-28">
      <div class="rounded-3xl bg-white bg-day-surface border border-day-soft overflow-hidden">
        <div class="bg-[#F4EFE6] dark:bg-[#121A18] border-b border-day-soft px-6 sm:px-8 py-5 flex flex-wrap items-center justify-between gap-4">
          <div class="flex items-center gap-3">
            <span class="w-10 h-10 shrink-0 rounded-xl bg-[#1D4E48] text-white flex items-center justify-center text-lg" aria-hidden="true"><?php echo $look['icon']; // phpcs:ignore -- fixed entity ?></span>
            <?php echo lld_page_v2_h2($sec['try']['h2'], 'text-xl sm:text-2xl text-[#1F1E1C] text-day-dark font-extrabold tracking-tight'); // phpcs:ignore ?>
          </div>
          <a href="<?php echo $u('/health-tools/'); ?>" class="text-xs font-bold text-[#5A5751] text-day-muted underline hover:text-[#1F1E1C]">&larr; All health tools</a>
        </div>
        <div class="p-2 sm:p-6 bg-[#FAF8F5] dark:bg-[#101413] lld-v2-frame">
          <?php echo $sec['try']['body']; // phpcs:ignore -- [lld_tool]: the frame, its script and its fallback ?>
        </div>
        <?php if ($extras !== ''): ?>
        <div class="lld-v2-extras border-t border-day-soft px-6 sm:px-8 py-6"><?php echo $extras; // phpcs:ignore -- lld-members markup ?></div>
        <?php endif; ?>
      </div>
    </section>

    <!-- Limitations, How it works, Privacy -->
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-14" aria-label="Method, limitations and privacy">
      <?php foreach (['limitations', 'how', 'privacy'] as $k): ?>
      <div class="<?php echo $card; ?> p-6 sm:p-7 lld-v2-copy">
        <?php echo lld_page_v2_h2($sec[$k]['h2'], $h2card . ' mb-3 pb-2 border-b border-day-soft'); // phpcs:ignore -- the body follows its heading directly (TC-FR1-04-AS-05) ?>
        <?php echo $sec[$k]['body']; // phpcs:ignore ?>
      </div>
      <?php endforeach; ?>
    </section>

    <!-- Related reading ([lld_tool_related] chooses the guides) -->
    <?php $rel = $sec['related']['body']; $ids = lld_page_v2_related_ids($rel); ?>
    <section class="mb-6">
      <div class="flex items-baseline justify-between gap-4 mb-6">
        <?php echo lld_page_v2_h2($sec['related']['h2'], 'text-2xl sm:text-3xl text-[#1F1E1C] text-day-dark font-extrabold tracking-tight'); // phpcs:ignore ?>
        <a href="<?php echo $u('/articles/'); ?>" class="text-xs font-bold text-[#1D4E48] dark:text-[#79D1C3] underline hover:no-underline shrink-0">View all guides &rarr;</a>
      </div>
      <?php if ($ids): ?>
      <ul class="grid grid-cols-1 md:grid-cols-3 gap-6 list-none p-0" data-test="related-reading">
        <?php foreach ($ids as $id): $p = get_post($id); if (!$p) continue; $v = lld_category_v2_card($p, 'medium_large', ''); ?>
        <li class="<?php echo $card; ?> overflow-hidden transition-card flex flex-col justify-between group relative" data-post-id="<?php echo (int)$id; ?>">
          <div>
            <?php if ($v['image'] !== ''): ?>
            <div class="relative w-full aspect-16/10 overflow-hidden bg-[#FAF8F5] bg-day-base border-b border-day-soft"><?php echo $v['image']; // phpcs:ignore -- WordPress-built image tag ?></div>
            <?php endif; ?>
            <div class="p-5 pb-3">
              <p class="text-xs text-[#62605A] text-day-muted font-semibold mb-2"><?php if ($v['category_label'] !== ''): ?><span class="uppercase tracking-wider text-[#1D4E48] dark:text-[#4FA89B]"><?php echo esc_html($v['category_label']); ?></span> &bull; <?php endif; ?><?php echo (int)$v['minutes']; ?> min read</p>
              <h3 class="text-lg text-[#1F1E1C] text-day-dark font-extrabold tracking-tight leading-snug group-hover:text-[#1D4E48] dark:group-hover:text-[#4FA89B] transition-colors"><a href="<?php echo esc_url($v['url']); ?>" class="text-inherit after:absolute after:inset-0"><?php echo esc_html($v['title']); ?></a></h3>
            </div>
          </div>
          <div class="px-5 pb-5 pt-3 border-t border-day-soft text-xs">
            <span class="font-bold text-[#1D4E48] dark:text-[#79D1C3] group-hover:underline" aria-hidden="true">Read guide &rarr;</span>
            <?php echo lld_tools_review_byline($p); // phpcs:ignore -- approved byline markup ?>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php $rest = trim((string)preg_replace('#<ul\b[^>]*data-test="related-reading"[^>]*>.*?</ul>#is', '', $rel)); ?>
      <?php if ($rest !== ''): ?><div class="mt-6 lld-v2-copy"><?php echo $rest; // phpcs:ignore -- e.g. the page's review byline ?></div><?php endif; ?>
      <?php else: ?>
      <div class="<?php echo $card; ?> p-6 lld-v2-copy"><?php echo $rel; // phpcs:ignore ?></div>
      <?php endif; ?>
    </section>
  <?php endif; ?>

<?php elseif ($view === 'legal'):
  $slug = (string)$page->post_name;
?>
    <nav class="flex items-center gap-2 text-xs text-[#62605A] text-day-muted uppercase tracking-wider font-bold mb-6" aria-label="Breadcrumb">
      <a href="<?php echo $u('/'); ?>" class="<?php echo $crumb_link; ?>">Home</a>
      <span aria-hidden="true">&gt;</span>
      <span class="text-[#1D4E48] dark:text-[#4FA89B]" aria-current="page"><?php echo esc_html($title); ?></span>
    </nav>
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 mb-6">
      <aside class="lg:col-span-4 space-y-6">
        <nav class="<?php echo $card; ?> p-5" aria-label="Governance and legal pages">
          <p class="text-[11px] font-extrabold uppercase tracking-wider text-[#62605A] text-day-muted mb-3">Governance &amp; trust</p>
          <ul class="space-y-1 list-none p-0">
            <?php foreach (lld_page_v2_legal_pages() as $s => $label): $on = $s === $slug; ?>
            <li><a href="<?php echo $u('/' . $s . '/'); ?>"<?php echo $on ? ' aria-current="page"' : ''; ?> class="flex items-center justify-between gap-2 px-3.5 py-2.5 rounded-xl text-sm font-semibold transition-colors <?php echo $on ? 'bg-[#1D4E48] text-white' : 'text-[#1F1E1C] text-day-dark hover:bg-[#F4EFE6] dark:hover:bg-[#1A2624]'; ?>"><span><?php echo esc_html($label); ?></span><span aria-hidden="true">&rarr;</span></a></li>
            <?php endforeach; ?>
          </ul>
        </nav>
        <div class="rounded-2xl bg-[#FFF8F0] dark:bg-[#1C1612] border border-[#DFC3A4] dark:border-[#4A3322] p-5 text-sm text-[#5C3D1B] dark:text-[#E8BD8C]">
          <p class="font-semibold leading-relaxed">If you feel very unwell, get medical help today. In an emergency, call your local emergency number straight away.</p>
          <?php if ($slug !== 'medical-disclaimer'): ?><a href="<?php echo $u('/medical-disclaimer/#urgent-care'); ?>" class="inline-block mt-2 text-inherit font-bold underline hover:no-underline">When to see a doctor &rarr;</a><?php endif; ?>
        </div>
      </aside>
      <article class="lg:col-span-8 <?php echo $card; ?> p-6 sm:p-10">
        <h1 class="text-3xl sm:text-4xl text-[#1F1E1C] text-day-dark font-extrabold tracking-tight leading-tight mb-6 pb-5 border-b border-day-soft"><?php echo esc_html($title); ?></h1>
        <div class="lld-prose lld-prose--flow lld-v2-legal"><?php echo $content; // phpcs:ignore -- the page's own content ?></div>
      </article>
    </div>

<?php elseif ($view === 'search'):
  global $wp_query;
  $q = get_search_query(false);   // unescaped: each use below escapes it once
  $total = (int)$wp_query->found_posts;
  $paged = max(1, (int)get_query_var('paged'));
  $pages = (int)$wp_query->max_num_pages;
  // the tool whose approved card answers the search: the three Stage 1 tools, by the words of their subject
  $match = '';
  if (preg_match('/protein/i', $q)) $match = 'protein';
  elseif (preg_match('/diabet|sugar|glucose|qdiabetes/i', $q)) $match = 'diabetes';
  elseif (preg_match('/weight|bmi|waist/i', $q)) $match = 'healthy-weight';
  $mc = $match !== '' ? lld_tools_card($match) : null;
  $topics = (array)(function_exists('lld_categories_doc') ? (lld_categories_doc()['tree'] ?? []) : []);
?>
    <nav class="flex items-center gap-2 text-xs text-[#62605A] text-day-muted uppercase tracking-wider font-bold mb-6" aria-label="Breadcrumb">
      <a href="<?php echo $u('/'); ?>" class="<?php echo $crumb_link; ?>">Home</a>
      <span aria-hidden="true">&gt;</span>
      <span class="text-[#1D4E48] dark:text-[#4FA89B]" aria-current="page">Search</span>
    </nav>

    <header class="<?php echo $card; ?> p-6 sm:p-8 mb-10" data-test="search-hero">
      <h1 class="text-3xl sm:text-4xl text-[#1F1E1C] text-day-dark font-extrabold tracking-tight leading-tight mb-5"><?php echo $q !== '' ? 'Search results' : 'Search Lifeline Daily'; ?></h1>
      <form role="search" method="get" action="<?php echo $u('/'); ?>" class="flex flex-col sm:flex-row gap-3">
        <label for="lld-search-q" class="sr-only">Search Lifeline Daily</label>
        <input type="search" id="lld-search-q" name="s" value="<?php echo esc_attr($q); ?>" required placeholder="Search guides and health tools" class="flex-1 px-5 py-3 rounded-full bg-[#FAF8F5] bg-day-base border border-[#CFC5B5] dark:border-[#344D47] text-base text-[#1F1E1C] text-day-dark focus:outline-2 focus:outline-[#1D4E48] dark:focus:outline-[#4FA89B]">
        <button type="submit" class="px-7 py-3 rounded-full bg-[#1D4E48] text-white font-bold text-sm hover:bg-[#143833] transition-colors">Search</button>
      </form>
      <?php if ($q !== ''): ?>
      <p class="mt-4 text-sm text-[#5A5751] text-day-muted" role="status"><strong class="text-[#1F1E1C] text-day-dark"><?php echo (int)$total; ?></strong> <?php echo $total === 1 ? 'result' : 'results'; ?> for &ldquo;<?php echo esc_html($q); ?>&rdquo;</p>
      <?php endif; ?>
    </header>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 mb-6">
      <div class="lg:col-span-8 space-y-5">
        <?php if ($mc): ?>
        <div class="rounded-2xl bg-[#E8F2F0] dark:bg-[#14201D] border border-[#B8D8D2] dark:border-[#2F4F49] p-5 sm:p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4" data-test="search-tool-match">
          <div class="flex items-start gap-4">
            <span class="w-11 h-11 shrink-0 rounded-xl bg-white dark:bg-[#1A2624] border border-[#B8D8D2] dark:border-[#2F4F49] flex items-center justify-center text-xl" aria-hidden="true"><?php echo lld_tools_card_look($match)['icon']; // phpcs:ignore -- fixed entity ?></span>
            <div>
              <p class="text-[11px] font-extrabold uppercase tracking-wider text-[#1D4E48] dark:text-[#79D1C3] mb-1">Health tool</p>
              <h2 class="text-lg font-extrabold text-[#1F1E1C] text-day-dark"><?php echo esc_html((string)$mc['name']); ?></h2>
              <p class="text-sm text-[#3A3834] text-day-muted leading-relaxed mt-1"><?php echo esc_html((string)$mc['purpose']); ?></p>
            </div>
          </div>
          <a href="<?php echo esc_url((string)$mc['landing_url']); ?>" class="shrink-0 inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-full bg-[#1D4E48] text-white font-bold text-sm hover:bg-[#143833] transition-colors"><span><?php echo esc_html((string)$mc['cta_label']); ?></span><span aria-hidden="true">&rarr;</span></a>
        </div>
        <?php endif; ?>

        <?php if ($wp_query->posts): ?>
        <ol class="space-y-5 list-none p-0" data-test="search-results">
          <?php foreach ($wp_query->posts as $p): $v = lld_category_v2_card($p, 'thumbnail', ''); $isPost = $p->post_type === 'post'; ?>
          <li class="<?php echo $card; ?> p-5 sm:p-6 transition-card flex gap-5 items-start group relative" data-post-id="<?php echo (int)$p->ID; ?>">
            <div class="flex-1 min-w-0">
              <p class="text-xs text-[#62605A] text-day-muted font-semibold mb-1.5"><?php if ($v['category_label'] !== '' && $isPost): ?><span class="uppercase tracking-wider text-[#1D4E48] dark:text-[#4FA89B]"><?php echo esc_html($v['category_label']); ?></span> &bull; <?php endif; ?><?php echo $isPost ? (int)$v['minutes'] . ' min read' : 'Page'; ?></p>
              <h2 class="text-lg sm:text-xl text-[#1F1E1C] text-day-dark font-extrabold tracking-tight leading-snug group-hover:text-[#1D4E48] dark:group-hover:text-[#4FA89B] transition-colors"><a href="<?php echo esc_url(get_permalink($p)); ?>" class="text-inherit after:absolute after:inset-0"><?php echo esc_html(html_entity_decode(get_the_title($p), ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?></a></h2>
              <?php if ($v['excerpt'] !== ''): ?><p class="mt-2 text-sm text-[#5A5751] text-day-muted leading-relaxed line-clamp-2"><?php echo esc_html($v['excerpt']); ?></p><?php endif; ?>
            </div>
            <?php if ($v['image'] !== ''): ?><div class="hidden sm:block w-28 h-20 shrink-0 rounded-xl overflow-hidden bg-[#FAF8F5] bg-day-base border border-day-soft"><?php echo $v['image']; // phpcs:ignore -- WordPress-built image tag ?></div><?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ol>
        <?php if ($pages > 1): ?>
        <nav class="flex flex-wrap items-center justify-center gap-2 text-sm font-semibold pt-4" aria-label="Search result pages">
          <?php
          foreach ((array)paginate_links(['total' => $pages, 'current' => $paged, 'type' => 'array', 'prev_text' => '&larr; Previous', 'next_text' => 'Next &rarr;', 'mid_size' => 1]) as $l) {
            $on = strpos($l, 'current') !== false;
            echo '<span class="' . ($on ? 'tab-pill active ' : '') . 'inline-flex rounded-full border border-day-soft bg-white dark:bg-[#1A2624] text-[#1F1E1C] text-day-dark [&>a]:px-4 [&>a]:py-2 [&>span]:px-4 [&>span]:py-2 hover:border-[#1D4E48]">' . $l . '</span>'; // phpcs:ignore -- WordPress-built links
          }
          ?>
        </nav>
        <?php endif; ?>
        <?php elseif ($q !== ''): ?>
        <div class="<?php echo $card; ?> p-6 sm:p-8" data-test="search-empty">
          <h2 class="text-xl font-extrabold text-[#1F1E1C] text-day-dark mb-2">No guides match &ldquo;<?php echo esc_html($q); ?>&rdquo;</h2>
          <p class="text-sm text-[#5A5751] text-day-muted leading-relaxed">Try a shorter or broader word, or start with one of the health tools.</p>
          <ul class="mt-5 grid grid-cols-1 sm:grid-cols-3 gap-3 list-none p-0">
            <?php foreach (lld_tools_cards() as $ts => $tc): ?>
            <li><a href="<?php echo esc_url((string)$tc['landing_url']); ?>" class="flex items-center gap-3 p-3.5 rounded-xl border border-day-soft bg-[#FAF8F5] bg-day-base text-sm font-bold text-[#1F1E1C] text-day-dark hover:border-[#1D4E48]"><span aria-hidden="true"><?php echo lld_tools_card_look($ts)['icon']; // phpcs:ignore ?></span><span><?php echo esc_html((string)$tc['name']); ?></span></a></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>
      </div>

      <aside class="lg:col-span-4">
        <nav class="<?php echo $card; ?> p-5 lg:sticky lg:top-28" aria-label="Topics">
          <p class="text-[11px] font-extrabold uppercase tracking-wider text-[#62605A] text-day-muted mb-3">Browse by topic</p>
          <ul class="space-y-1 list-none p-0">
            <?php foreach ($topics as $t): ?>
            <li><a href="<?php echo $u('/category/' . $t['slug'] . '/'); ?>" class="flex items-center justify-between gap-2 px-3 py-2 rounded-xl text-sm font-semibold text-[#1F1E1C] text-day-dark hover:bg-[#F4EFE6] dark:hover:bg-[#1A2624] transition-colors"><span><?php echo esc_html(html_entity_decode((string)$t['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?></span><span aria-hidden="true">&rarr;</span></a></li>
            <?php endforeach; ?>
            <li class="pt-2 mt-2 border-t border-day-soft"><a href="<?php echo $u('/health-tools/'); ?>" class="flex items-center justify-between gap-2 px-3 py-2 rounded-xl text-sm font-bold text-[#1D4E48] dark:text-[#79D1C3] hover:bg-[#F4EFE6] dark:hover:bg-[#1A2624] transition-colors"><span>All health tools</span><span aria-hidden="true">&rarr;</span></a></li>
          </ul>
        </nav>
      </aside>
    </div>
<?php endif; ?>

  </main>

  <?php include __DIR__ . '/parts/v2-footer.php'; ?>
<?php wp_footer(); ?>
</body>
</html>
