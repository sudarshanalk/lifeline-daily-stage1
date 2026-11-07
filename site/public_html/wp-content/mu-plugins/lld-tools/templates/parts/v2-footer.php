<?php
/**
 * The homepage design's footer (urgent-care notice, brand, Health Tools, Your Health, 4-Week Tracks, Lifeline Daily, legal
 * links and Cookie settings), shared by the v2 pages and, through lld_v2_members_shell(), the membership screens.
 * Set $lld_footer_plain = true before including to print the column titles as text, not headings (the membership screens
 * keep one heading per page, UI2-01).
 */
if (!defined('ABSPATH')) exit;
$fu = function (string $path): string { return esc_url(home_url($path)); };
$fh = empty($lld_footer_plain) ? 'h2' : 'p';
?>
  <!-- FOOTER -->
  <footer class="lld-footer bg-[#101413] text-[#A6A29A] pt-12 pb-12 px-6 border-t border-[#222E2B]" role="contentinfo">
    <div class="max-w-7xl mx-auto space-y-12">
      <div class="rounded-2xl bg-[#16201E] border border-[#263834] p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs text-[#D8E2E0]" data-test="urgent-care">
        <div class="flex items-center gap-3">
          <span class="w-7 h-7 rounded-full bg-amber-500/20 text-amber-400 flex items-center justify-center text-sm font-bold shrink-0" aria-hidden="true">⚠️</span>
          <span><strong class="text-white font-semibold">Urgent medical notice:</strong> If you feel very unwell, get medical help today. In an emergency, call your local emergency number straight away.</span>
        </div>
        <a href="<?php echo $fu('/medical-disclaimer/#urgent-care'); ?>" class="text-[#D4826F] hover:underline font-semibold shrink-0 flex items-center gap-1"><span>When to see a doctor</span><span aria-hidden="true">→</span></a>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-8 pb-8 border-b border-[#222E2B]">
        <div class="sm:col-span-2 lg:col-span-3 space-y-3.5">
          <div class="flex items-center gap-2.5">
            <div class="w-8 h-8 rounded-full bg-[#1D4E48] text-white flex items-center justify-center font-serif-editorial text-lg italic font-bold" aria-hidden="true">L</div>
            <span class="text-white font-bold tracking-tight text-lg">LIFELINE<span class="text-[#D4826F] font-normal ml-1">DAILY</span></span>
          </div>
          <p class="font-serif-editorial text-lg italic text-[#D8E2E0] leading-snug">Start small. Change big. Live healthier.</p>
          <p class="text-xs text-[#828D8A] leading-relaxed max-w-xs">Simple, evidence-based guidance on food, movement, sleep, and everyday health to help you build habits that last.</p>
        </div>
        <nav class="lg:col-span-2 space-y-2.5 text-xs" aria-label="Health tools">
          <<?php echo $fh; ?> class="font-semibold uppercase tracking-wider text-[11px] mb-3 text-[#D4826F]">Health Tools</<?php echo $fh; ?>>
          <p><a href="<?php echo $fu('/health-tools/protein/'); ?>" class="text-inherit hover:text-white transition-colors">Daily Protein Target</a></p>
          <p><a href="<?php echo $fu('/health-tools/diabetes-risk-check/'); ?>" class="text-inherit hover:text-white transition-colors">Diabetes Risk Check</a></p>
          <p><a href="<?php echo $fu('/health-tools/healthy-weight-check/'); ?>" class="text-inherit hover:text-white transition-colors">Healthy Weight Check</a></p>
          <p class="pt-2"><a href="<?php echo $fu('/health-tools/'); ?>" class="text-[#4FA89B] hover:underline font-semibold">All health tools →</a></p>
        </nav>
        <nav class="lg:col-span-3 space-y-2.5 text-xs" aria-label="Your health">
          <<?php echo $fh; ?> class="font-semibold uppercase tracking-wider text-[11px] mb-3 text-[#D4826F]">Your Health</<?php echo $fh; ?>>
          <p><a href="<?php echo $fu('/category/weight-healthy-eating/'); ?>" class="text-inherit hover:text-white transition-colors">Weight &amp; Healthy Eating</a></p>
          <p><a href="<?php echo $fu('/category/blood-sugar-metabolic-health/'); ?>" class="text-inherit hover:text-white transition-colors">Blood Sugar &amp; Metabolic</a></p>
          <p><a href="<?php echo $fu('/category/fitness-strength/'); ?>" class="text-inherit hover:text-white transition-colors">Fitness and Strength</a></p>
          <p><a href="<?php echo $fu('/category/sleep-stress/'); ?>" class="text-inherit hover:text-white transition-colors">Sleep and Stress</a></p>
          <p><a href="<?php echo $fu('/category/heart-health/'); ?>" class="text-inherit hover:text-white transition-colors">Heart Health</a></p>
          <p><a href="<?php echo $fu('/category/gut-health/'); ?>" class="text-inherit hover:text-white transition-colors">Gut Health</a></p>
          <p><a href="<?php echo $fu('/category/healthy-ageing/'); ?>" class="text-inherit hover:text-white transition-colors">Healthy Ageing</a></p>
          <p><a href="<?php echo $fu('/category/health-conditions/'); ?>" class="text-inherit hover:text-white transition-colors">Health Conditions</a></p>
          <p class="pt-2"><a href="<?php echo $fu('/articles/'); ?>" class="text-[#4FA89B] hover:underline font-semibold">All articles →</a></p>
        </nav>
        <nav class="lg:col-span-2 space-y-2.5 text-xs" aria-label="4-week tracks">
          <<?php echo $fh; ?> class="font-semibold uppercase tracking-wider text-[11px] mb-3 text-[#D4826F]">4-Week Tracks</<?php echo $fh; ?>>
          <p><a href="<?php echo $fu('/programmes/healthy-weight/'); ?>" class="text-inherit hover:text-white transition-colors">Healthy Weight</a></p>
          <p><a href="<?php echo $fu('/programmes/blood-sugar/'); ?>" class="text-inherit hover:text-white transition-colors">Blood Sugar Balance</a></p>
          <p><a href="<?php echo $fu('/programmes/fitness/'); ?>" class="text-inherit hover:text-white transition-colors">Fitness &amp; Strength</a></p>
          <p><a href="<?php echo $fu('/programmes/sleep-stress/'); ?>" class="text-inherit hover:text-white transition-colors">Sleep &amp; Stress</a></p>
          <p class="pt-2"><a href="<?php echo $fu('/programmes/'); ?>" class="text-[#4FA89B] hover:underline font-semibold">All tracks →</a></p>
        </nav>
        <nav class="lg:col-span-2 space-y-2.5 text-xs" aria-label="Lifeline Daily">
          <<?php echo $fh; ?> class="font-semibold uppercase tracking-wider text-[11px] mb-3 text-[#D4826F]">Lifeline Daily</<?php echo $fh; ?>>
          <p><a href="<?php echo $fu('/about-us/'); ?>" class="text-inherit hover:text-white transition-colors">About Us</a></p>
          <p><a href="<?php echo $fu('/editorial-standards/'); ?>" class="text-inherit hover:text-white transition-colors">Editorial Standards</a></p>
          <p><a href="<?php echo $fu('/contact-us/'); ?>" class="text-inherit hover:text-white transition-colors">Contact</a></p>
          <p><a href="<?php echo $fu('/medical-disclaimer/'); ?>" class="text-inherit hover:text-white transition-colors">Medical Disclaimer</a></p>
        </nav>
      </div>

      <div class="flex flex-col sm:flex-row items-center justify-between gap-4 text-[11px] text-[#8B9593]">
        <div>&copy; <?php echo esc_html(gmdate('Y')); ?> Lifeline Daily. All rights reserved.</div>
        <div class="flex items-center gap-5 flex-wrap justify-center">
          <a href="<?php echo $fu('/privacy-policy/'); ?>" class="text-inherit hover:text-white transition-colors">Privacy Policy</a>
          <a href="<?php echo $fu('/terms-conditions/'); ?>" class="text-inherit hover:text-white transition-colors">Terms &amp; Conditions</a>
          <a href="<?php echo $fu('/terms-of-sale/'); ?>" class="text-inherit hover:text-white transition-colors">Terms of Sale</a>
          <a href="#cookie-settings" data-lld-cookie-settings class="text-inherit hover:text-white transition-colors">Cookie settings</a>
        </div>
      </div>
    </div>
  </footer>
