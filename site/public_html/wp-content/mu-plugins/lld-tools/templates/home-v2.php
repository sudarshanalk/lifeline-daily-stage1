<?php
/**
 * Homepage v2 full-page template (see ../home-v2.php). Markup and classes follow the owner's design file
 * `lld_homepage_interactive_demo.html`; styles are the compiled `assets/lld-v2.css`, behaviour `assets/lld-home-v2.js`.
 */
if (!defined('ABSPATH')) exit;

$img = function (string $f): string { return esc_url(lld_home_v2_asset('home/' . $f)); };
$u = function (string $path): string { return esc_url(home_url($path)); };
$signed_in = function_exists('is_user_logged_in') && is_user_logged_in();
// A tool withdrawn with `wp lld status set` (DEC-24) leaves the card too, as it leaves its own page.
$tools = array_filter(lld_home_v2_tools(), function ($slug) { return !function_exists('lld_tools_tool_status') || lld_tools_tool_status((string)$slug) === 'active'; }, ARRAY_FILTER_USE_KEY);
$first = array_key_first($tools);
$check = isset($_GET['lld_check']) ? sanitize_text_field(wp_unslash($_GET['lld_check'])) : '';
$back = remove_query_arg('lld_check');

$tracks = [
  ['track-weight.jpg', 'Healthy eating meals', 'Healthy Weight', 'Daily protein pacing, Mediterranean whole-food frameworks, and mindful portion rhythms.', '/programmes/healthy-weight/'],
  ['track-blood-sugar.jpg', 'Blood sugar balance habits', 'Blood Sugar Balance', 'Food sequencing, 10-minute post-meal movement, and steady daytime energy without crashes.', '/programmes/blood-sugar/'],
  ['track-fitness.jpg', 'Home strength training', 'Fitness & Strength', 'Progressive home bodyweight strength, mobility cues, and Zone 2 cardiovascular stamina.', '/programmes/fitness/'],
  ['track-sleep.jpg', 'Restful sleep', 'Sleep & Stress', 'Reset circadian rhythm cues, calm nocturnal cortisol, and improve deep recovery stages.', '/programmes/sleep-stress/'],
];
$science = [
  ['Food Sequencing Over Extreme Restriction', 'Eating fibre and protein before carbohydrates naturally blunts post-meal glucose spikes by slowing gastric emptying—no cutting whole food groups.'],
  ['The 10-Minute Post-Meal Stroll', 'Contracting large leg muscles after meals clears glucose directly into muscle cells without extra insulin demand, dropping peaks by up to 30%.'],
  ['Circadian Resetting & Evening Cortisol', 'Morning light within 30 minutes of waking sets your internal melatonin timer. Evening dimming prevents cortisol surges that cause 3am waking.'],
];
$reads = lld_home_v2_reads();
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php if (!current_theme_supports('title-tag')): // Newspaper prints the title in its own header.php, which this page skips ?>
  <title><?php echo esc_html(wp_get_document_title()); ?></title>
  <?php endif; ?>
  <?php wp_head(); ?>
</head>
<body <?php body_class('lld-home-v2 selection:bg-[#1D4E48] selection:text-white'); ?>>
<script>try{if(localStorage.getItem('lld_circadian')==='night')document.body.classList.add('mode-night');}catch(e){}</script>
<?php if (function_exists('wp_body_open')) wp_body_open(); ?>
  <a href="#lld-main" class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[100] focus:bg-white focus:text-[#1D4E48] focus:px-4 focus:py-2 focus:rounded-full">Skip to content</a>

  <?php $lld_header = ['page' => 'home', 'current' => '']; include __DIR__ . '/parts/v2-header.php'; ?>

  <main id="lld-main">
    <!-- 1. HERO -->
    <section class="pt-12 pb-16 px-6 max-w-7xl mx-auto" data-test="home-hero">
      <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
        <div class="lg:col-span-6 space-y-6">
          <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-[#EAE2D5] bg-day-card text-[#1D4E48] text-xs font-semibold uppercase tracking-wider">
            <span class="w-1.5 h-1.5 rounded-full bg-[#D4826F]" aria-hidden="true"></span>
            <span>Clinically Reviewed Health</span>
          </div>
          <h1 class="font-serif-editorial text-5xl sm:text-6xl md:text-7xl font-normal leading-[1.05] text-[#1F1E1C] text-day-dark tracking-tight">
            Start small.<br>
            Change big.<br>
            <span class="italic text-[#1D4E48]">Live healthier every day.</span>
          </h1>
          <p class="text-base sm:text-lg text-[#5A5751] text-day-muted font-normal leading-relaxed max-w-lg">
            Evidence-backed micro-habits, validated clinical calculators, and guided 4-week tracks without fads, extremes, or medical jargon.
          </p>

          <div class="pt-2 max-w-md">
            <form role="search" method="get" action="<?php echo $u('/'); ?>" class="relative flex items-center shadow-sm rounded-full overflow-hidden border border-[#D5CCBE] border-day-soft bg-white bg-day-card">
              <span class="pl-4 text-[#8C8880]" aria-hidden="true">🔍</span>
              <label for="lld-home-search" class="sr-only">Search Lifeline Daily</label>
              <input id="lld-home-search" type="search" name="s" placeholder="Search protein, diabetes, sleep, fasting..." class="w-full py-3 pl-3 pr-24 text-xs text-[#1F1E1C] text-day-dark bg-transparent focus:outline-none">
              <button type="submit" class="absolute right-1.5 bg-[#1D4E48] text-white text-xs font-semibold px-4 py-1.5 rounded-full hover:bg-[#143833] transition-colors">Search</button>
            </form>
            <div class="mt-2.5 flex items-center gap-2 text-[11px] text-[#62605A] text-day-muted flex-wrap">
              <span>Try:</span>
              <a href="#calculators" data-calc-tab="protein" class="underline hover:text-[#1D4E48]">Protein Target</a>
              <span aria-hidden="true">•</span>
              <a href="#calculators" data-calc-tab="diabetes" class="underline hover:text-[#1D4E48]">Diabetes Risk</a>
              <span aria-hidden="true">•</span>
              <a href="#tracks" class="underline hover:text-[#1D4E48]">4-Week Tracks</a>
            </div>
          </div>

          <div class="pt-3 flex items-center gap-4">
            <a href="#calculators" class="bg-[#1D4E48] hover:bg-[#143833] text-white px-6 py-3 rounded-full font-medium text-xs shadow-sm transition-all">Check Your Numbers ↓</a>
            <a href="#tracks" class="bg-[#A56557] hover:bg-[#8E5548] text-white px-6 py-3 rounded-full font-medium text-xs shadow-sm transition-all">Join 4-Week Track</a>
          </div>
        </div>

        <div class="lg:col-span-6 grid grid-cols-12 gap-4">
          <div class="col-span-7 rounded-3xl overflow-hidden bg-[#E2D8C7] shadow-sm relative group h-80 sm:h-96">
            <img src="<?php echo $img('hero-food.jpg'); ?>" alt="Fresh wholesome food" width="800" height="1210" fetchpriority="high" decoding="async" data-no-lazy="1" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700">
            <div class="absolute bottom-4 left-4 right-4 p-3 rounded-xl bg-white/90 backdrop-blur-md text-[11px] font-semibold text-[#1F1E1C]">🥗 Balanced Whole-Food Sequencing</div>
          </div>
          <div class="col-span-5 flex flex-col gap-4">
            <div class="rounded-3xl overflow-hidden bg-[#E2D8C7] shadow-sm relative group h-44 sm:h-52">
              <img src="<?php echo $img('hero-movement.jpg'); ?>" alt="Active movement" width="600" height="400" decoding="async" data-no-lazy="1" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700">
              <div class="absolute bottom-2 left-2 text-[10px] bg-black/60 text-white px-2 py-0.5 rounded backdrop-blur-sm">10-Min Post-Meal</div>
            </div>
            <div class="rounded-2xl bg-[#EDE6DC] bg-day-surface p-4 border border-[#DDD5C7] border-day-soft flex-1 flex flex-col justify-center">
              <span class="text-[10px] uppercase font-bold text-[#1D4E48] tracking-wider">Clinical Benchmark</span>
              <div class="font-serif-editorial text-2xl text-[#1F1E1C] text-day-dark mt-0.5">-58% Risk</div>
              <p class="text-[10px] text-[#62605A] text-day-muted mt-0.5 leading-snug">Lower type 2 diabetes rate with guided lifestyle changes (DPP trial).</p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- 2. CLINICAL CALCULATORS: the design's quick-check card; every number is the signed-off engine's own
         (assets/lld-home-calc.js, copied unchanged from the tools). The full check, with its safety questions, is on each
         tool's own page. -->
    <?php if ($tools): ?>
    <section id="calculators" class="py-16 px-6 max-w-7xl mx-auto scroll-mt-24" data-test="home-tools">
      <div class="grid grid-cols-1 lg:grid-cols-12 gap-10 items-center">
        <div class="lg:col-span-5 order-2 lg:order-1">
          <div class="rounded-3xl overflow-hidden bg-[#EDE6DC] relative shadow-sm border border-[#DDD5C7] border-day-soft h-[420px] group">
            <?php foreach ($tools as $slug => $t): ?>
            <img data-calc-img="<?php echo esc_attr($slug); ?>" src="<?php echo $img($t['img']); ?>" alt="<?php echo esc_attr($t['alt']); ?>" width="800" height="600" loading="lazy" decoding="async" class="<?php echo $slug === $first ? '' : 'hidden '; ?>w-full h-full object-cover group-hover:scale-105 transition-transform duration-700">
            <?php endforeach; ?>
            <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent flex flex-col justify-end p-6 text-white">
              <span class="text-xs uppercase font-bold text-[#D4826F]">Instant Feedback</span>
              <h2 id="calc-image-title" class="font-serif-editorial text-2xl mt-1"><?php echo esc_html($tools[$first]['title']); ?></h2>
              <p id="calc-image-sub" class="text-xs text-white/80 mt-1"><?php echo esc_html($tools[$first]['sub']); ?></p>
            </div>
          </div>
        </div>

        <div class="lg:col-span-7 order-1 lg:order-2 space-y-6">
          <div>
            <div class="flex items-center justify-between gap-4">
              <span class="text-xs uppercase tracking-widest text-[#1D4E48] font-bold">Interactive Health Check</span>
              <a href="<?php echo $u('/health-tools/'); ?>" class="text-xs text-[#1D4E48] underline font-semibold">View all 3 tools in full →</a>
            </div>
            <h2 class="font-serif-editorial text-3xl sm:text-4xl text-[#1F1E1C] text-day-dark mt-1">Personalised checks backed by evidence.</h2>
          </div>

          <div class="inline-flex rounded-full bg-[#EDE6DC] bg-day-surface p-1 border border-[#DDD5C7] border-day-soft flex-wrap" role="tablist" aria-label="Health calculators">
            <?php foreach ($tools as $slug => $t): $on = $slug === $first; ?>
            <button type="button" role="tab" id="tab-btn-<?php echo esc_attr($slug); ?>" data-calc-tab="<?php echo esc_attr($slug); ?>" aria-controls="calc-panel-<?php echo esc_attr($slug); ?>" aria-selected="<?php echo $on ? 'true' : 'false'; ?>" tabindex="<?php echo $on ? '0' : '-1'; ?>" data-title="<?php echo esc_attr($t['title']); ?>" data-sub="<?php echo esc_attr($t['sub']); ?>"
              class="calc-tab px-4 py-1.5 rounded-full text-xs font-semibold transition-all <?php echo $on ? 'is-active bg-[#1D4E48] text-white shadow-sm' : 'text-[#5A5751] text-day-muted hover:text-black'; ?>"><?php echo esc_html($t['tab']); ?></button>
            <?php endforeach; ?>
          </div>

          <?php foreach ($tools as $slug => $t): $on = $slug === $first; ?>
          <div role="tabpanel" id="calc-panel-<?php echo esc_attr($slug); ?>" aria-labelledby="tab-btn-<?php echo esc_attr($slug); ?>" data-calc-panel="<?php echo esc_attr($slug); ?>"<?php echo $on ? '' : ' hidden'; ?> class="bg-white bg-day-card rounded-2xl p-6 border border-[#DDD5C7] border-day-soft space-y-5">
            <?php foreach ($t['inputs'] as $in): $id = 'q-' . $slug . '-' . $in['key']; ?>
            <div>
              <div class="flex justify-between items-center text-xs font-semibold text-[#1F1E1C] text-day-dark mb-1.5">
                <?php if (!empty($in['choices'])): ?>
                <span id="<?php echo esc_attr($id); ?>-label"><?php echo esc_html($in['label']); ?></span>
                <?php else: ?>
                <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($in['label']); ?></label>
                <span data-q-out="<?php echo esc_attr($in['key']); ?>" class="text-[#1D4E48] bg-[#F4EFE7] bg-day-surface px-2 py-0.5 rounded"></span>
                <?php endif; ?>
              </div>
              <?php if (!empty($in['choices'])): ?>
              <div class="flex gap-2" role="radiogroup" aria-labelledby="<?php echo esc_attr($id); ?>-label">
                <?php foreach ($in['choices'] as [$val, $lab]): ?>
                <button type="button" role="radio" data-q="<?php echo esc_attr($in['key']); ?>" data-val="<?php echo esc_attr($val); ?>" aria-checked="<?php echo $val === $in['value'] ? 'true' : 'false'; ?>" class="q-choice px-4 py-1.5 rounded-full text-xs font-semibold border transition-all <?php echo $val === $in['value'] ? 'is-active bg-[#1D4E48] text-white border-[#1D4E48]' : 'border-[#DDD5C7] border-day-soft text-[#5A5751] text-day-muted hover:text-black'; ?>"><?php echo esc_html($lab); ?></button>
                <?php endforeach; ?>
              </div>
              <?php else: ?>
              <input type="range" id="<?php echo esc_attr($id); ?>" data-q="<?php echo esc_attr($in['key']); ?>" min="<?php echo (int)$in['min']; ?>" max="<?php echo (int)$in['max']; ?>" step="<?php echo (int)($in['step'] ?? 1); ?>" value="<?php echo (int)$in['value']; ?>" class="w-full h-1.5 bg-[#E6DFD4] rounded-lg appearance-none cursor-pointer accent-[#1D4E48]">
              <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <div class="pt-3 border-t border-[#E8E2D6] border-day-soft flex items-center justify-between gap-4">
              <div>
                <span class="text-[10px] uppercase font-bold text-[#62605A] text-day-muted block"><?php echo esc_html($t['result_label']); ?></span>
                <div data-q-score class="font-serif-editorial text-4xl text-[#1F1E1C] text-day-dark score-glow"></div>
              </div>
              <div class="text-right">
                <span data-q-badge class="inline-block px-3 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800"></span>
                <a href="<?php echo $u($t['url']); ?>" class="block text-xs font-semibold text-[#9D6052] dark:text-[#E1A89A] hover:underline mt-1"><?php echo esc_html($t['cta']); ?></a>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <?php endif; ?>

    <!-- 3. FOUR 4-WEEK TRACKS -->
    <section id="tracks" class="py-16 bg-[#EDE7DD] bg-day-surface border-y border-[#DDD5C7] border-day-soft px-6 scroll-mt-24" data-test="home-tracks">
      <div class="max-w-7xl mx-auto">
        <div class="flex flex-col sm:flex-row sm:items-end justify-between mb-10 gap-4">
          <div>
            <span class="text-xs uppercase tracking-widest text-[#1D4E48] font-bold">Guided Habit Change</span>
            <h2 class="font-serif-editorial text-3xl sm:text-4xl text-[#1F1E1C] text-day-dark mt-1">Four structured 4-week tracks.</h2>
          </div>
          <p class="text-xs text-[#62605A] text-day-muted max-w-sm">Clinician-designed daily micro-habits, meal plans, and weekly reviews for sustainable health.</p>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
          <?php foreach ($tracks as [$f, $alt, $name, $desc, $path]): ?>
          <div class="group bg-white bg-day-card rounded-2xl overflow-hidden border border-[#E0D8CB] border-day-soft shadow-sm hover:shadow-md transition-all flex flex-col justify-between">
            <div>
              <div class="h-44 overflow-hidden relative">
                <img src="<?php echo $img($f); ?>" alt="<?php echo esc_attr($alt); ?>" width="600" height="400" loading="lazy" decoding="async" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                <span class="absolute top-3 left-3 bg-[#1D4E48] text-white text-[10px] font-bold px-2 py-0.5 rounded">4 WEEKS</span>
              </div>
              <div class="p-5">
                <h3 class="font-serif-editorial text-2xl text-[#1F1E1C] text-day-dark"><?php echo esc_html($name); ?></h3>
                <p class="text-xs text-[#62605A] text-day-muted mt-1 leading-relaxed"><?php echo esc_html($desc); ?></p>
              </div>
            </div>
            <div class="p-5 pt-0">
              <a href="<?php echo $u($path); ?>" class="text-xs font-bold text-[#1D4E48] group-hover:text-[#9D6052] dark:group-hover:text-[#E1A89A] flex items-center justify-between">
                <span>Explore track<span class="sr-only">: <?php echo esc_html($name); ?></span></span> <span aria-hidden="true">→</span>
              </a>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <!-- 4. THE SCIENCE -->
    <section id="science" class="py-16 px-6 max-w-7xl mx-auto scroll-mt-24">
      <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
        <div class="lg:col-span-5">
          <div class="rounded-3xl overflow-hidden bg-[#EDE6DC] relative shadow-sm h-[460px] group border border-[#DDD5C7] border-day-soft">
            <img src="<?php echo $img('science.jpg'); ?>" alt="Active healthy person" width="800" height="600" loading="lazy" decoding="async" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700">
            <div class="absolute bottom-4 left-4 right-4 p-4 rounded-2xl bg-white/90 backdrop-blur-md">
              <span class="text-xs font-bold text-[#1D4E48]">Evidence-First Standard</span>
              <p class="text-xs text-[#62605A] mt-0.5">Every recommendation is referenced to peer-reviewed systematic reviews and ADA/NICE guidelines.</p>
            </div>
          </div>
        </div>
        <div class="lg:col-span-7 space-y-6">
          <div>
            <span class="text-xs uppercase tracking-widest text-[#1D4E48] font-bold">The Science &amp; Method</span>
            <h2 class="font-serif-editorial text-3xl sm:text-4xl text-[#1F1E1C] text-day-dark mt-1">Clinical evidence translated into simple daily actions.</h2>
          </div>
          <div class="space-y-3">
            <?php foreach ($science as $i => [$q, $a]): $n = $i + 1; $open = $i === 0; ?>
            <div class="border border-[#DFD8CC] border-day-soft rounded-2xl bg-white bg-day-card p-4 transition-all">
              <button type="button" data-acc="acc-<?php echo $n; ?>" aria-expanded="<?php echo $open ? 'true' : 'false'; ?>" aria-controls="acc-<?php echo $n; ?>" class="w-full text-left flex items-center justify-between font-semibold text-sm text-[#1F1E1C] text-day-dark">
                <span><?php echo $n . '. ' . esc_html($q); ?></span>
                <span id="acc-icon-<?php echo $n; ?>" class="text-lg text-[#1D4E48]" aria-hidden="true"><?php echo $open ? '−' : '+'; ?></span>
              </button>
              <p id="acc-<?php echo $n; ?>" class="<?php echo $open ? '' : 'hidden '; ?>text-xs text-[#5A5751] text-day-muted mt-2 leading-relaxed"><?php echo esc_html($a); ?></p>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </section>

    <!-- 5. ESSENTIAL READS: the newest articles -->
    <section id="reads" class="py-16 px-6 max-w-7xl mx-auto scroll-mt-24" data-test="learn-row">
      <div class="flex items-center justify-between mb-8 gap-4">
        <div>
          <span class="text-xs uppercase tracking-widest text-[#1D4E48] font-bold">Clinical Knowledge Base</span>
          <h2 class="font-serif-editorial text-3xl sm:text-4xl text-[#1F1E1C] text-day-dark mt-1">Essential Health Reads</h2>
        </div>
        <a href="<?php echo $u('/articles/'); ?>" class="text-xs font-semibold text-[#1D4E48] hover:underline shrink-0">View all articles →</a>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <?php foreach ($reads as $p):
          $cat = function_exists('lld_home_post_category_label') ? lld_home_post_category_label($p) : 'Health Guide';
          $mins = function_exists('lld_home_post_minutes') ? lld_home_post_minutes($p) : 5;
          $thumb = (function_exists('has_post_thumbnail') && has_post_thumbnail($p))
            ? get_the_post_thumbnail($p, 'medium_large', ['class' => 'w-full h-full object-cover group-hover:scale-105 transition-transform duration-500', 'loading' => 'lazy', 'decoding' => 'async', 'alt' => ''])
            : ''; ?>
        <article class="group bg-white bg-day-card rounded-2xl overflow-hidden border border-[#E0D8CB] border-day-soft hover:shadow-md transition-all flex flex-col justify-between relative" data-post-id="<?php echo (int)$p->ID; ?>">
          <div>
            <div class="h-44 overflow-hidden relative bg-[#EDE6DC]">
              <?php echo $thumb; // phpcs:ignore -- WordPress-built image tag ?>
              <span class="absolute top-3 left-3 bg-[#1D4E48] text-white text-[10px] font-bold px-2 py-0.5 rounded uppercase"><?php echo esc_html($cat); ?></span>
            </div>
            <div class="p-5">
              <div class="text-[11px] text-[#62605A] text-day-muted"><?php echo (int)$mins; ?> min read &bull; <time datetime="<?php echo esc_attr(get_the_date('c', $p)); ?>"><?php echo esc_html(get_the_date('j M Y', $p)); ?></time></div>
              <h3 class="font-serif-editorial text-2xl text-[#1F1E1C] text-day-dark mt-1 leading-snug group-hover:text-[#1D4E48]">
                <a href="<?php echo esc_url(get_permalink($p)); ?>" class="after:absolute after:inset-0"><?php echo esc_html(html_entity_decode(get_the_title($p), ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?></a>
              </h3>
            </div>
          </div>
          <div class="p-5 pt-0">
            <span class="text-xs font-semibold text-[#1D4E48]" aria-hidden="true">Read guide →</span>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- 6. FREE STARTER GUIDE (the site's starter-guide request, lld-members Growth) -->
    <section id="starter-guide" class="py-12 px-6 max-w-4xl mx-auto scroll-mt-24" data-test="guide-row">
      <div class="bg-[#1D4E48] text-white rounded-3xl p-8 sm:p-10 text-center relative overflow-hidden shadow-lg">
        <span class="text-[11px] uppercase tracking-widest text-[#E3AEA1] font-bold">Free Starter Guide</span>
        <h2 class="font-serif-editorial text-3xl sm:text-4xl mt-2 leading-tight">Get the 4-Week Healthy Habit Starter Guide</h2>
        <p class="text-xs text-[#D8E2E0] mt-2 max-w-md mx-auto">Pick a goal and we'll email you a short guide to your first week: practical food, movement and habit steps you can start today.</p>
        <?php if ($check !== '' && $check !== '1'): ?>
          <p class="mt-6 text-sm font-semibold text-white" role="status">Check your email to confirm.</p>
        <?php else: ?>
        <?php if ($check === '1'): ?><p class="mt-6 text-sm font-semibold text-white" role="alert">We could not send the guide to that address. Please check it and try again.</p><?php endif; ?>
        <form method="post" action="" class="mt-6 flex flex-col sm:flex-row gap-2 max-w-xl mx-auto">
          <?php if (function_exists('wp_nonce_field')) wp_nonce_field('lld_guide', 'lld_guide_nonce'); ?>
          <input type="hidden" name="lld_kind" value="guide">
          <input type="hidden" name="lld_back" value="<?php echo esc_url($back . '#starter-guide'); ?>">
          <label for="lld-home-guide-track" class="sr-only">Your goal</label>
          <select id="lld-home-guide-track" name="lld_track" required class="bg-white/10 border border-white/20 text-white text-xs rounded-full px-4 py-2.5 focus:outline-none [&>option]:text-[#1F1E1C]">
            <option value="healthy-weight">Healthy Weight</option>
            <option value="blood-sugar">Blood Sugar Balance</option>
            <option value="fitness">Fitness &amp; Strength</option>
            <option value="sleep-stress">Sleep &amp; Stress</option>
          </select>
          <label for="lld-home-guide-email" class="sr-only">Email</label>
          <input type="email" id="lld-home-guide-email" name="lld_email" placeholder="Enter your email address" required autocomplete="email" class="bg-white/10 border border-white/20 text-white placeholder-white/60 text-xs rounded-full px-4 py-2.5 flex-1 focus:outline-none">
          <button type="submit" class="bg-[#A56557] hover:bg-[#8E5548] text-white text-xs font-bold px-5 py-2.5 rounded-full transition-all">Send My Free Guide</button>
        </form>
        <?php endif; ?>
        <p class="text-[11px] text-[#D8E2E0]/80 mt-4">We only use your email to send the guide you asked for. You can unsubscribe at any time. <a href="<?php echo $u('/privacy-policy/'); ?>" class="underline">Privacy policy</a></p>
      </div>
    </section>
  </main>

  <?php include __DIR__ . '/parts/v2-footer.php'; ?>
<?php wp_footer(); ?>
</body>
</html>
