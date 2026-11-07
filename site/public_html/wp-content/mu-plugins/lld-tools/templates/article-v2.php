<?php
/**
 * Article page v2 full-page template (see ../article-v2.php). Markup and classes follow the owner's design file
 * `lld_article_diabetes_style1.html`, with the site's one header and the homepage footer (parts/); styles are the compiled
 * `assets/lld-v2.css` (the article's own HTML is styled by its .lld-prose rules), behaviour `assets/lld-home-v2.js`.
 */
if (!defined('ABSPATH')) exit;

$u = function (string $path): string { return esc_url(home_url($path)); };
$html = ''; $post = null;
while (have_posts()) { the_post(); $post = get_post(); $html = (string)apply_filters('the_content', get_the_content()); }
$P = lld_article_v2_parts($html);
$title = html_entity_decode(get_the_title($post), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$minutes = lld_article_v2_minutes((string)$post->post_content);
$top = function_exists('lld_tools_hub_term') ? lld_tools_hub_term($post) : null;
$topName = $top ? html_entity_decode((string)$top->name, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
$topUrl = $top ? get_term_link($top) : '';
$topUrl = is_wp_error($topUrl) ? '' : (string)$topUrl;
$caption = trim((string)get_post_meta($post->ID, 'lld_image_caption', true));
if ($caption === '' && has_post_thumbnail($post)) $caption = trim((string)wp_get_attachment_caption(get_post_thumbnail_id($post)));
$faqIntro = trim((string)get_post_meta($post->ID, 'lld_faq_intro', true));
$guideLabel = trim((string)get_post_meta($post->ID, 'lld_guide_label', true));
$tags = get_the_tags($post->ID) ?: [];
$link = get_permalink($post);
$share = rawurlencode($link); $shareTitle = rawurlencode($title);
$check = isset($_GET['lld_check']) ? sanitize_text_field(wp_unslash($_GET['lld_check'])) : '';
$guide_sent = (bool)preg_match('/^(guide|waitlist)-/', $check);
$back = remove_query_arg('lld_check');
$prev = get_previous_post();
$prevTop = ($prev && function_exists('lld_tools_hub_term')) ? lld_tools_hub_term($prev) : null;
$next = [];
if ($top) {
  $q = new WP_Query(['post_type' => 'post', 'post_status' => 'publish', 'cat' => (int)$top->term_id, 'posts_per_page' => 3, 'post__not_in' => [(int)$post->ID],
    'orderby' => 'date', 'order' => 'DESC', 'ignore_sticky_posts' => true, 'no_found_rows' => true]);
  $next = $q->posts;
}
$sections = $P['sections'];
$count = count($sections);
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
<body <?php body_class('lld-v2 selection:bg-[#1D4E48] selection:text-white'); ?>>
<script>try{if(localStorage.getItem('lld_circadian')==='night')document.body.classList.add('mode-night');}catch(e){}</script>
<?php if (function_exists('wp_body_open')) wp_body_open(); ?>
  <a href="#lld-main" class="text-inherit sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[100] focus:bg-white focus:text-[#1D4E48] focus:px-4 focus:py-2 focus:rounded-full">Skip to content</a>
  <?php $lld_header = ['page' => 'article', 'current' => $top ? (string)$top->slug : '']; include __DIR__ . '/parts/v2-header.php'; ?>

  <main id="lld-main">
  <article class="max-w-4xl mx-auto px-6 pt-10 pb-20" data-test="article">

    <nav class="flex items-center gap-2 text-xs text-[#62605A] text-day-muted mb-6 flex-wrap" aria-label="Breadcrumb">
      <a href="<?php echo $u('/'); ?>" class="text-inherit hover:text-[#1D4E48]">Home</a>
      <span aria-hidden="true">&rsaquo;</span>
      <a href="<?php echo $u('/articles/'); ?>" class="text-[#1D4E48] font-semibold hover:underline dark:text-[#4FA89B]">Your Health</a>
      <?php if ($top): ?>
      <span aria-hidden="true">&rsaquo;</span>
      <span class="text-[#1F1E1C] text-day-dark" aria-current="page"><?php echo esc_html($topName); ?></span>
      <?php endif; ?>
    </nav>

    <?php if ($top): ?>
    <div class="mb-3.5">
      <a href="<?php echo esc_url($topUrl); ?>" class="inline-flex items-center gap-2 px-3.5 py-1 rounded-full bg-[#EAE2D5] bg-day-card text-[#1D4E48] hover:bg-[#DDD4C5] text-xs font-bold tracking-wider uppercase transition-colors shadow-2xs dark:text-[#4FA89B]">
        <span class="w-2 h-2 rounded-full bg-[#D4826F]" aria-hidden="true"></span>
        <span><?php echo esc_html($topName); ?></span>
      </a>
    </div>
    <?php endif; ?>

    <h1 class="font-serif-editorial text-4xl sm:text-5xl md:text-6xl text-[#1F1E1C] text-day-dark font-normal leading-[1.1] tracking-tight"><?php echo esc_html($title); ?></h1>

    <div class="mt-6 pt-4 pb-4 border-y border-[#EAE4D8] border-day-soft flex items-center justify-between gap-4 text-xs">
      <div class="flex items-center gap-3">
        <div class="w-9 h-9 rounded-full bg-[#1D4E48] text-white flex items-center justify-center font-bold text-xs" aria-hidden="true">LD</div>
        <strong class="text-[#1F1E1C] text-day-dark font-semibold">Lifeline Daily</strong>
      </div>
      <div class="text-[#62605A] text-day-muted text-xs"><span><span aria-hidden="true">⏱</span> <?php echo (int)$minutes; ?> min read</span></div>
    </div>

    <?php if (has_post_thumbnail($post)): ?>
    <figure class="mt-8 space-y-3">
      <div class="rounded-3xl overflow-hidden border border-[#E0D8CB] border-day-soft shadow-md bg-[#FAF8F5] bg-day-surface relative group">
        <?php echo get_the_post_thumbnail($post, 'large', ['class' => 'w-full h-72 sm:h-96 md:h-[440px] object-cover object-center transform group-hover:scale-[1.01] transition-transform duration-500', 'loading' => 'eager', 'fetchpriority' => 'high', 'decoding' => 'async', 'data-no-lazy' => '1']); // phpcs:ignore ?>
      </div>
      <?php if ($caption !== ''): ?>
      <figcaption class="flex items-center gap-2 text-xs text-[#5A5751] text-day-muted px-2">
        <span class="w-1.5 h-1.5 rounded-full bg-[#D4826F] shrink-0" aria-hidden="true"></span>
        <span><?php echo esc_html($caption); ?></span>
      </figcaption>
      <?php endif; ?>
    </figure>
    <?php endif; ?>

    <?php if ($count > 1): ?>
    <!-- In this guide -->
    <nav class="my-8 p-5 sm:p-6 rounded-3xl bg-[#F4EDE2] bg-day-surface border border-[#E0D8CB] border-day-soft shadow-xs" aria-label="In this guide">
      <div class="flex items-center justify-between mb-3.5 pb-2.5 border-b border-[#DDD5C7] border-day-soft gap-3 flex-wrap">
        <div class="flex items-center gap-2">
          <span class="w-2 h-2 rounded-full bg-[#1D4E48]" aria-hidden="true"></span>
          <span class="text-xs uppercase tracking-wider font-bold text-[#1D4E48] dark:text-[#4FA89B]">In this guide<?php echo $guideLabel !== '' ? ' &bull; ' . esc_html($guideLabel) : ''; ?></span>
        </div>
        <?php $jumps = array_filter([$P['answer'] !== '' ? ['#key-takeaways', 'Takeaways'] : null, $P['faq'] ? ['#faq', 'FAQ'] : null, $P['sources'] !== '' ? ['#sources', 'Sources'] : null]); ?>
        <?php if ($jumps): ?>
        <div class="flex items-center gap-2 text-[11px] font-medium text-[#6B675F] text-day-muted">
          <span>Jump to:</span>
          <?php $i = 0; foreach ($jumps as [$href, $lab]): ?><?php echo $i++ ? ' &bull; ' : ''; ?><a href="<?php echo esc_attr($href); ?>" class="text-inherit hover:text-[#1D4E48] hover:underline<?php echo $i === 1 ? ' font-semibold text-[#1D4E48] dark:text-[#4FA89B]' : ''; ?>"><?php echo esc_html($lab); ?></a><?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5 text-xs">
        <?php foreach ($sections as $k => $s): $wide = ($k === $count - 1) && ($count % 2 === 1); ?>
        <a href="#<?php echo esc_attr($s['id']); ?>" class="flex items-center gap-2.5 p-2.5 rounded-xl bg-white bg-day-card border border-[#DDD5C7] border-day-soft hover:border-[#1D4E48] hover:shadow-xs transition-all text-[#1F1E1C] text-day-dark group<?php echo $wide ? ' sm:col-span-2' : ''; ?>">
          <span class="w-5 h-5 rounded-full bg-[#EAE2D5] text-[#1D4E48] group-hover:bg-[#1D4E48] group-hover:text-white font-bold text-[11px] flex items-center justify-center shrink-0 transition-colors dark:text-[#4FA89B] dark:bg-[#22302D]"><?php echo (int)($k + 1); ?></span>
          <span class="font-medium<?php echo $wide ? '' : ' truncate'; ?>"><?php echo esc_html($s['short']); ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </nav>
    <?php endif; ?>

    <?php if (trim(strip_tags($P['intro'], '<img><iframe>')) !== ''): ?>
    <div class="lld-prose lld-prose--intro mt-10 space-y-5 text-base sm:text-lg text-[#3A3834] text-day-dark leading-relaxed font-normal">
      <?php echo $P['intro']; // phpcs:ignore -- the article's own filtered content ?>
    </div>
    <?php endif; ?>

    <?php if ($P['answer'] !== ''): ?>
    <div id="key-takeaways" class="my-8 p-6 sm:p-8 rounded-2xl bg-[#FAF5EE] bg-day-callout border border-[#E0D8CB] border-day-soft space-y-3.5 scroll-mt-28">
      <div class="flex items-center gap-2 pb-2 border-b border-[#E0D8CB] border-day-soft">
        <span class="w-2.5 h-2.5 rounded-full bg-[#1D4E48]" aria-hidden="true"></span>
        <h2 class="text-xs uppercase tracking-widest font-bold text-[#1D4E48] dark:text-[#4FA89B]">Key Takeaways</h2>
      </div>
      <div class="lld-prose lld-prose--takeaways space-y-3 text-sm sm:text-base text-[#1F1E1C] text-day-dark leading-relaxed font-normal">
        <?php echo $P['answer']; // phpcs:ignore -- [lld_answer] output ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($P['benchmarks'] && $P['benchmarks']['stats']): $B = $P['benchmarks']; ?>
    <div class="my-10 p-6 sm:p-8 rounded-3xl bg-[#FAF5EE] bg-day-callout border border-[#EAE2D5] border-day-soft space-y-5 shadow-xs" data-test="benchmarks">
      <div class="flex items-center justify-between border-b border-[#EAE2D5] border-day-soft pb-3 gap-3">
        <span class="text-xs uppercase tracking-widest font-bold text-[#1D4E48] flex items-center gap-2 dark:text-[#4FA89B]">
          <span class="w-2 h-2 rounded-full bg-[#D4826F]" aria-hidden="true"></span>
          <span>Key clinical benchmarks</span>
        </span>
        <?php if ($B['source'] !== ''): ?><span class="text-[11px] font-medium text-[#62605A] text-day-muted text-right"><?php echo esc_html($B['source']); ?></span><?php endif; ?>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <?php foreach ($B['stats'] as $i => $stat): ?>
        <div class="p-5 rounded-2xl bg-white bg-day-card border border-[#E0D8CB] border-day-soft">
          <span class="text-3xl sm:text-4xl font-serif-editorial font-bold <?php echo $i % 2 ? 'text-[#A56557] dark:text-[#E1A89A]' : 'text-[#1D4E48] dark:text-[#4FA89B]'; ?> block leading-tight"><?php echo esc_html($stat['value']); ?></span>
          <span class="text-xs text-[#5A5751] text-day-muted mt-2 block leading-relaxed"><?php echo wp_kses_post($stat['line']); ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if ($B['summary'] !== ''): ?>
      <p class="text-xs sm:text-sm text-[#1F1E1C] text-day-dark leading-relaxed font-normal pt-1"><?php echo wp_kses_post($B['summary']); ?></p>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="space-y-10 text-base sm:text-lg text-[#3A3834] text-day-dark leading-relaxed font-normal">
      <?php foreach ($sections as $k => $s): $closing = $s['closing'] && $k === $count - 1; ?>
      <?php if ($closing): ?>
      <section id="<?php echo esc_attr($s['id']); ?>" class="p-8 sm:p-10 rounded-3xl bg-[#EDE6DC] bg-day-surface border border-[#DDD5C7] border-day-soft space-y-4 scroll-mt-28">
        <h2 class="font-serif-editorial text-3xl sm:text-4xl text-[#1F1E1C] text-day-dark"><?php echo wp_kses_post($s['title']); ?></h2>
        <div class="lld-prose lld-prose--card space-y-4"><?php echo $s['body']; // phpcs:ignore ?></div>
      </section>
      <?php else: ?>
      <section id="<?php echo esc_attr($s['id']); ?>" class="p-6 sm:p-8 rounded-3xl bg-white bg-day-card border border-[#E0D8CB] border-day-soft space-y-4 shadow-xs scroll-mt-28">
        <div class="flex items-center justify-between gap-3">
          <span class="text-xs font-bold text-[#1D4E48] uppercase tracking-wider dark:text-[#4FA89B]">Part <?php echo (int)($k + 1); ?></span>
          <?php if ($s['label'] !== ''): ?><span class="text-xs text-[#6E6A62] text-day-muted"><?php echo esc_html($s['label']); ?></span><?php endif; ?>
        </div>
        <h2 class="font-serif-editorial text-3xl text-[#1F1E1C] text-day-dark"><?php echo wp_kses_post($s['title']); ?></h2>
        <div class="lld-prose lld-prose--card space-y-4"><?php echo $s['body']; // phpcs:ignore ?></div>
      </section>
      <?php endif; ?>
      <?php endforeach; ?>

      <?php if ($P['faq']): ?>
      <section id="faq" data-test="faq" class="lld-faq p-6 sm:p-10 rounded-3xl bg-white bg-day-card border border-[#E0D8CB] border-day-soft shadow-xs space-y-6 scroll-mt-28">
        <div class="space-y-1.5 pb-2 border-b border-[#EAE4D8] border-day-soft">
          <div class="flex items-center gap-2">
            <span class="w-2.5 h-2.5 rounded-full bg-[#D4826F]" aria-hidden="true"></span>
            <span class="text-xs uppercase tracking-widest font-bold text-[#1D4E48] dark:text-[#4FA89B]">Evidence-based answers</span>
          </div>
          <h2 class="font-serif-editorial text-3xl sm:text-4xl text-[#1F1E1C] text-day-dark"><?php echo esc_html($P['faq_title'] !== '' ? $P['faq_title'] : 'Frequently Asked Questions'); ?></h2>
          <?php if ($faqIntro !== ''): ?><p class="text-xs sm:text-sm text-[#5A5751] text-day-muted"><?php echo esc_html($faqIntro); ?></p><?php endif; ?>
          <?php if ($P['faq_note'] !== ''): ?><div class="lld-prose text-xs sm:text-sm text-[#5A5751] text-day-muted"><?php echo $P['faq_note']; // phpcs:ignore -- [lld_faq] text ?></div><?php endif; ?>
        </div>
        <div class="space-y-3.5">
          <?php foreach ($P['faq'] as $i => $f): ?>
          <details class="group p-5 rounded-2xl bg-[#FAF8F5] bg-day-surface border border-[#DDD5C7] border-day-soft transition-all duration-200 open:border-[#1D4E48] open:shadow-xs"<?php echo $i === 0 ? ' open' : ''; ?>>
            <summary class="flex items-center justify-between cursor-pointer list-none text-base font-semibold text-[#1F1E1C] text-day-dark select-none gap-4 [&::-webkit-details-marker]:hidden">
              <span class="flex items-center gap-3">
                <span class="w-6 h-6 rounded-full bg-[#EAE2D5] text-[#1D4E48] text-xs font-bold flex items-center justify-center shrink-0 group-open:bg-[#1D4E48] group-open:text-white transition-colors dark:text-[#4FA89B] dark:bg-[#22302D]" aria-hidden="true">Q<?php echo (int)($i + 1); ?></span>
                <span class="lld-faq__q"><?php echo wp_kses_post($f['q']); ?></span>
              </span>
              <span class="w-6 h-6 rounded-full bg-[#EAE2D5] text-[#1D4E48] flex items-center justify-center shrink-0 text-xs font-bold transition-transform duration-200 group-open:rotate-180 dark:text-[#4FA89B] dark:bg-[#22302D]" aria-hidden="true">&#9660;</span>
            </summary>
            <div class="lld-prose mt-4 pt-3 border-t border-[#EAE2D5] border-day-soft text-sm text-[#4A4742] text-day-muted leading-relaxed"><?php echo $f['a']; // phpcs:ignore -- [lld_q] output ?></div>
          </details>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($P['sources'] !== ''): ?>
      <section id="sources" class="p-6 sm:p-8 rounded-2xl bg-white bg-day-card border border-[#E0D8CB] border-day-soft space-y-3.5 scroll-mt-28" data-test="sources">
        <div class="flex items-center justify-between pb-2 border-b border-[#E0D8CB] border-day-soft">
          <div class="flex items-center gap-2">
            <span class="w-2.5 h-2.5 rounded-full bg-[#1D4E48]" aria-hidden="true"></span>
            <h2 class="text-xs uppercase tracking-widest font-bold text-[#1D4E48] dark:text-[#4FA89B]">References</h2>
          </div>
          <?php $nsrc = substr_count(strtolower($P['sources']), '<li'); ?>
          <span class="text-[11px] font-medium text-[#6B675F] text-day-muted"><?php echo (int)$nsrc; ?> source<?php echo $nsrc === 1 ? '' : 's'; ?></span>
        </div>
        <ol class="lld-prose lld-prose--sources list-decimal pl-5 space-y-2.5 text-xs sm:text-sm text-[#4A4742] text-day-muted leading-relaxed"><?php echo $P['sources']; // phpcs:ignore -- [lld_source] output ?></ol>
      </section>
      <?php endif; ?>

      <!-- Tags, author and sharing -->
      <div class="mt-12 pt-8 border-t border-[#EAE4D8] border-day-soft space-y-6">
        <?php if ($tags): ?>
        <div class="flex flex-wrap items-center gap-2 text-xs">
          <span class="font-bold text-[#1D4E48] tracking-wider uppercase text-[11px] mr-1 dark:text-[#4FA89B]">Tags</span>
          <?php foreach ($tags as $tg): $tl = get_tag_link($tg); ?>
          <a href="<?php echo esc_url(is_wp_error($tl) ? '#' : $tl); ?>" class="px-3.5 py-1.5 rounded-lg bg-white bg-day-card border border-[#DDD5C7] border-day-soft text-[#1F1E1C] text-day-dark hover:border-[#1D4E48] transition-colors font-medium"><?php echo esc_html(html_entity_decode($tg->name, ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?></a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="p-6 sm:p-7 rounded-2xl bg-white bg-day-card border border-[#DDD5C7] border-day-soft shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-5">
          <div class="space-y-1.5 max-w-lg">
            <div class="flex items-center gap-2">
              <span class="w-2.5 h-2.5 rounded-full bg-[#1D4E48]" aria-hidden="true"></span>
              <p class="font-serif-editorial text-2xl font-bold text-[#1F1E1C] text-day-dark">Lifeline Daily</p>
            </div>
            <p class="text-xs sm:text-sm text-[#5A5751] text-day-muted leading-relaxed">Simple, evidence-based guidance on food, movement, sleep, and everyday health to help you build habits that last. Clinically reviewed content for educational purposes.</p>
            <?php if ($P['byline'] !== ''): ?><div class="text-xs text-[#5A5751] text-day-muted"><?php echo $P['byline']; // phpcs:ignore -- lld_tools_review_byline ?></div><?php endif; ?>
          </div>
          <div class="space-y-2 shrink-0">
            <span class="text-[11px] uppercase tracking-wider font-semibold text-[#62605A] text-day-muted block">Share this guide</span>
            <div class="flex items-center gap-2">
              <?php foreach ([
                ['https://www.facebook.com/sharer/sharer.php?u=' . $share, 'Share on Facebook', 'hover:bg-[#1877F2] hover:border-[#1877F2]', 'M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z'],
                ['https://twitter.com/intent/tweet?text=' . $shareTitle . '&url=' . $share, 'Share on X', 'hover:bg-black hover:border-black', 'M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z'],
                ['https://api.whatsapp.com/send?text=' . $shareTitle . '%20' . $share, 'Share on WhatsApp', 'hover:bg-[#25D366] hover:border-[#25D366]', 'M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.711 2.599 2.67-.7c.974.553 1.96.848 2.79.849h.002c3.18 0 5.767-2.587 5.768-5.766.001-3.182-2.585-5.77-5.77-5.771zm3.374 8.211c-.147.417-.732.772-1.014.808-.282.036-.615.158-1.996-.402-1.666-.677-2.738-2.384-2.821-2.497-.083-.113-.679-.903-.679-1.722 0-.819.429-1.222.581-1.389.152-.167.332-.209.443-.209.11 0 .221 0 .319.005.105.005.244-.04.382.291.147.353.498 1.218.542 1.307.044.09.073.195.014.312-.059.117-.089.19-.176.293-.087.103-.183.23-.262.31-.088.089-.18.187-.078.362.103.175.457.753.981 1.22 1.019.908 1.879 1.189 2.146 1.322.267.133.424.111.581-.07.157-.181.67-.779.849-1.046.18-.266.36-.222.604-.133.244.089 1.547.729 1.813.862.266.133.443.199.509.31.066.111.066.643-.081 1.06z'],
                ['https://www.linkedin.com/sharing/share-offsite/?url=' . $share, 'Share on LinkedIn', 'hover:bg-[#0A66C2] hover:border-[#0A66C2]', 'M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.762 0 5-2.239 5-5v-14c0-2.761-2.238-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.79-1.75-1.764s.784-1.764 1.75-1.764 1.75.79 1.75 1.764-.783 1.764-1.75 1.764zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z'],
              ] as [$href, $label, $hover, $path]): ?>
              <a href="<?php echo esc_url($href); ?>" target="_blank" rel="noopener" aria-label="<?php echo esc_attr($label); ?>" class="w-9 h-9 rounded-full bg-[#F4EDE2] bg-day-surface border border-[#DDD5C7] border-day-soft hover:text-white <?php echo esc_attr($hover); ?> text-[#1F1E1C] text-day-dark flex items-center justify-center transition-all"><svg class="w-4 h-4 fill-current" viewBox="0 0 24 24" aria-hidden="true"><path d="<?php echo esc_attr($path); ?>"/></svg></a>
              <?php endforeach; ?>
              <button type="button" data-copy-link="<?php echo esc_url($link); ?>" aria-label="Copy link" class="w-9 h-9 rounded-full bg-[#F4EDE2] bg-day-surface border border-[#DDD5C7] border-day-soft hover:bg-[#1D4E48] hover:text-white hover:border-[#1D4E48] text-[#1F1E1C] text-day-dark flex items-center justify-center transition-all text-xs"><span aria-hidden="true">🔗</span></button>
            </div>
            <p data-copy-status class="text-[11px] text-[#1D4E48] min-h-4 dark:text-[#4FA89B]" role="status"></p>
          </div>
        </div>

        <?php if ($prev): ?>
        <nav class="py-3 px-4 rounded-xl bg-[#EDE6DC] bg-day-surface border border-[#DDD5C7] border-day-soft text-xs text-[#62605A] text-day-muted flex items-center gap-1.5 overflow-x-auto" aria-label="More from Lifeline Daily">
          <a href="<?php echo $u('/'); ?>" class="text-inherit hover:text-[#1D4E48]">Home</a>
          <?php if ($prevTop): $pl = get_term_link($prevTop); ?>
          <span aria-hidden="true">&rsaquo;</span>
          <a href="<?php echo esc_url(is_wp_error($pl) ? '#' : $pl); ?>" class="text-inherit hover:text-[#1D4E48] whitespace-nowrap"><?php echo esc_html(html_entity_decode($prevTop->name, ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?></a>
          <?php endif; ?>
          <span aria-hidden="true">&rsaquo;</span>
          <a href="<?php echo esc_url(get_permalink($prev)); ?>" class="text-[#1F1E1C] text-day-dark hover:underline font-medium"><?php echo esc_html(html_entity_decode(get_the_title($prev), ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?> &rarr;</a>
        </nav>
        <?php endif; ?>
      </div>

      <?php if ($P['guide'] || $P['hub_link'] !== ''): ?>
      <!-- Get the free guide (lld-members starter guide for this article's track) -->
      <section id="starter-guide" class="mt-12 p-6 sm:p-8 rounded-3xl bg-white bg-day-card border border-[#E0D8CB] border-day-soft shadow-xs space-y-5 scroll-mt-28">
        <?php if ($P['guide'] && $P['guide']['track'] !== ''): ?>
        <div class="space-y-1">
          <h2 class="text-2xl sm:text-3xl font-bold text-[#1F1E1C] text-day-dark tracking-tight">Get the free guide</h2>
          <p class="text-xs sm:text-sm text-[#5A5751] text-day-muted">Practical daily steps and pacing guides delivered straight to your inbox.</p>
        </div>
        <?php if ($guide_sent): ?>
        <p class="text-sm font-semibold text-[#1D4E48] dark:text-[#4FA89B]" role="status">Check your email to confirm.</p>
        <?php else: ?>
        <?php if ($check === '1'): ?><p class="text-sm font-semibold text-[#8C5E2D]" role="alert">We could not send the guide to that address. Please check it and try again.</p><?php endif; ?>
        <form method="post" action="" class="space-y-3.5 max-w-xl">
          <?php wp_nonce_field('lld_guide', 'lld_guide_nonce'); ?>
          <input type="hidden" name="lld_track" value="<?php echo esc_attr($P['guide']['track']); ?>">
          <input type="hidden" name="lld_kind" value="guide">
          <input type="hidden" name="lld_back" value="<?php echo esc_url($back . '#starter-guide'); ?>">
          <div>
            <label for="lld-art-guide-email" class="block text-xs font-semibold text-[#5A5751] text-day-muted mb-1.5">Email</label>
            <input type="email" id="lld-art-guide-email" name="lld_email" required autocomplete="email" placeholder="Enter your email address" class="w-full px-4 py-3 rounded-xl border border-[#DDD5C7] border-day-soft bg-[#FAF8F5] bg-day-surface text-sm text-[#1F1E1C] text-day-dark placeholder-[#6E6A62] focus:outline-none focus:ring-2 focus:ring-[#1D4E48]">
          </div>
          <div><button type="submit" class="bg-[#1D4E48] hover:bg-[#143833] text-white font-semibold text-xs px-6 py-3 rounded-full transition-all shadow-xs cursor-pointer">Send me the guide</button></div>
        </form>
        <?php endif; ?>
        <?php endif; ?>
        <?php if ($P['hub_link'] !== ''): ?>
        <div class="lld-prose lld-prose--hub text-xs text-[#62605A] text-day-muted<?php echo $P['guide'] ? ' pt-3 border-t border-[#EAE4D8] border-day-soft' : ''; ?>"><?php echo $P['hub_link']; // phpcs:ignore -- lld-tools hub link ?></div>
        <?php endif; ?>
      </section>
      <?php endif; ?>

      <!-- Free starter guide dark card -->
      <section class="mt-14 p-8 sm:p-10 rounded-3xl bg-[#112421] text-white space-y-4 relative overflow-hidden shadow-md">
        <div class="inline-block px-3 py-1 rounded-full bg-[#1D4E48] text-[#86E4D4] font-bold text-[11px] uppercase tracking-wider">Free health tools</div>
        <h2 class="text-2xl sm:text-3xl font-bold tracking-tight text-white max-w-xl">Start Small: Check Your Numbers in Two Minutes</h2>
        <p class="text-xs sm:text-sm text-[#C4D9D5] max-w-2xl leading-relaxed">Turn what you have read into a first step. Our free calculators give you a daily protein target, your 10-year diabetes risk and a healthy weight check.</p>
        <div class="pt-2">
          <a href="<?php echo $u('/health-tools/'); ?>" class="inline-flex items-center gap-2 bg-[#1E7E6B] hover:bg-[#186858] text-white font-bold text-xs sm:text-sm px-6 py-3 rounded-full transition-all shadow-sm"><span>Explore Free Health Tools</span><span aria-hidden="true">&rarr;</span></a>
        </div>
      </section>

      <?php if ($next): ?>
      <section class="mt-14 pt-10 border-t border-[#EAE4D8] border-day-soft space-y-6" aria-labelledby="read-next">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
          <h2 id="read-next" class="text-xl sm:text-2xl font-bold text-[#1F1E1C] text-day-dark tracking-tight">Read Next in <?php echo esc_html($topName); ?></h2>
          <a href="<?php echo esc_url($topUrl); ?>" class="text-xs font-bold text-[#1D4E48] hover:underline flex items-center gap-1 dark:text-[#4FA89B]"><span>View all in <?php echo esc_html($topName); ?></span><span aria-hidden="true">&rarr;</span></a>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
          <?php foreach ($next as $np): $nm = lld_article_v2_minutes((string)$np->post_content); $nl = function_exists('lld_hub_post_category_label') ? lld_hub_post_category_label($np, $topName) : $topName; ?>
          <article class="bg-white bg-day-card rounded-2xl overflow-hidden border border-[#E0D8CB] border-day-soft shadow-xs flex flex-col justify-between hover:shadow-md transition-all relative" data-post-id="<?php echo (int)$np->ID; ?>">
            <div>
              <div class="relative h-44 bg-[#EDE6DC] overflow-hidden">
                <?php echo has_post_thumbnail($np) ? get_the_post_thumbnail($np, 'medium_large', ['class' => 'w-full h-full object-cover', 'loading' => 'lazy', 'decoding' => 'async', 'alt' => '']) : ''; // phpcs:ignore ?>
                <span class="absolute top-3 left-3 px-3 py-1 rounded-full bg-white/95 text-[#1F1E1C] text-[10px] font-semibold shadow-xs"><?php echo esc_html($nl); ?></span>
              </div>
              <div class="p-5 space-y-2">
                <span class="text-[11px] text-[#6B675F] text-day-muted block"><?php echo (int)$nm; ?> min read &bull; Clinically Reviewed</span>
                <h3 class="text-base font-bold text-[#1F1E1C] text-day-dark leading-snug"><a href="<?php echo esc_url(get_permalink($np)); ?>" class="text-inherit after:absolute after:inset-0"><?php echo esc_html(html_entity_decode(get_the_title($np), ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?></a></h3>
              </div>
            </div>
            <div class="px-5 pb-5"><span class="text-xs font-bold text-[#1D4E48] inline-flex items-center gap-1 dark:text-[#4FA89B]" aria-hidden="true"><span>Read guide</span><span>&rarr;</span></span></div>
          </article>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>
    </div>
  </article>
  </main>

  <?php include __DIR__ . '/parts/v2-footer.php'; ?>
<?php wp_footer(); ?>
</body>
</html>
