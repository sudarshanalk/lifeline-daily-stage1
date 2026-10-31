<?php
/**
 * Lifeline Daily Approved Site Footer (DEC-88, S2-L1).
 * Content & build brief: Footer_Content.md (Approved 31 October 2026).
 *
 * Layout order:
 *   1. Urgent care strip (full width)
 *   2. Brand and trust (left column) beside the four link groups
 *   3. Starter guide sign-up (omitted on homepage)
 *   4. Legal bar (full width, bottom)
 *
 * Rules:
 *   - Plain relative paths work on staging and production.
 *   - Deliberately NO emergency phone numbers in the urgent care strip.
 *   - Names use "and", never "&".
 *   - Token-compliant styles only (TC-UI1-09).
 */
if (!defined('ABSPATH')) exit;

/**
 * Render the approved site footer markup.
 *
 * @param bool $is_member_view Whether this is rendered in the member portal area.
 * @return string HTML footer markup.
 */
function lld_tools_footer_html(bool $is_member_view = false): string {
  $year = gmdate('Y');
  $is_home = !$is_member_view && function_exists('is_front_page') && is_front_page();

  // 1. Check if user just submitted a guide request (?lld_check=...)
  $check = isset($_GET['lld_check']) ? sanitize_text_field(wp_unslash($_GET['lld_check'])) : '';
  $has_submitted = !empty($check);

  // 2. Build current back URL without ?lld_check
  $current_url = remove_query_arg('lld_check');

  ob_start();
  ?>
  <footer id="lifeline-site-footer" class="lld-site-footer" role="contentinfo">
    
    <!-- 1. Urgent care strip (full width) -->
    <section class="lld-footer-urgent" aria-label="Urgent medical assistance">
      <div class="lld-footer-urgent__wrap">
        <div class="lld-footer-urgent__message">
          <span class="lld-footer-urgent__icon" aria-hidden="true">⚠️</span>
          <p>If you feel very unwell, get medical help today. In an emergency, call your local emergency number straight away.</p>
        </div>
        <a href="<?php echo esc_url(home_url('/medical-disclaimer/#urgent-care')); ?>" class="lld-footer-urgent__link">
          <span>When to see a doctor</span>
          <span aria-hidden="true">&rarr;</span>
        </a>
      </div>
    </section>

    <!-- Main footer body -->
    <div class="lld-footer-body">
      
      <!-- Top content: Brand & trust beside four link groups -->
      <div class="lld-footer-top">
        
        <!-- 2. Brand and trust (left column) -->
        <div class="lld-footer-brand">
          <div class="lld-footer-brand__logo">
            <a href="<?php echo esc_url(home_url('/')); ?>" class="lld-footer-brand__logo-link">
              <span class="lld-footer-brand__cross" aria-hidden="true">+</span>
              <span class="lld-footer-brand__title-wrap">
                <span class="lld-footer-brand__title">Lifeline Daily</span>
                <span class="lld-footer-brand__tagline">Start small. Change big. Live healthier.</span>
              </span>
            </a>
          </div>

          <p class="lld-footer-brand__mission">
            Simple, evidence-based guidance on food, movement, sleep and everyday health, to help you build habits that last.
          </p>

          <p class="lld-footer-brand__trust">
            <span class="lld-footer-brand__trust-dot" aria-hidden="true"></span>
            <span>Sources cited &middot; Review dates shown</span>
          </p>

          <!-- Social links (icons only, spoken labels, new tab) -->
          <div class="lld-footer-social">
            <span class="lld-footer-social__label">Connect with us</span>
            <div class="lld-footer-social__links">
              <a href="https://www.facebook.com/lifelineofhealth" target="_blank" rel="noopener" aria-label="Lifeline Daily on Facebook" class="lld-footer-social__link">
                <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
              </a>
              <a href="https://www.instagram.com/life.l.daily/" target="_blank" rel="noopener" aria-label="Lifeline Daily on Instagram" class="lld-footer-social__link">
                <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
              </a>
              <a href="https://uk.pinterest.com/lifelinedaily7/" target="_blank" rel="noopener" aria-label="Lifeline Daily on Pinterest" class="lld-footer-social__link">
                <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 0a12 12 0 0 0-4.37 23.18c-.07-.94-.13-2.39.03-3.42l.96-4.08s-.24-.49-.24-1.22c0-1.14.66-2 1.48-2 .7 0 1.04.53 1.04 1.16 0 .7-.45 1.76-.68 2.74-.2.83.42 1.5 1.23 1.5 1.48 0 2.62-1.56 2.62-3.81 0-1.99-1.43-3.38-3.48-3.38-2.37 0-3.76 1.78-3.76 3.62 0 .72.28 1.49.62 1.9.07.08.08.16.06.24l-.23.95c-.04.15-.12.18-.28.11-1.04-.48-1.69-2-1.69-3.22 0-2.62 1.9-5.03 5.49-5.03 2.88 0 5.12 2.05 5.12 4.8 0 2.86-1.8 5.17-4.3 5.17-.84 0-1.63-.44-1.9-.96l-.52 1.97c-.19.72-.7 1.63-1.04 2.18A12 12 0 1 0 12 0z"/></svg>
              </a>
              <a href="https://x.com/LifelineDaily" target="_blank" rel="noopener" aria-label="Lifeline Daily on X" class="lld-footer-social__link">
                <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
              </a>
              <a href="https://www.youtube.com/@LifelineDaily" target="_blank" rel="noopener" aria-label="Lifeline Daily on YouTube" class="lld-footer-social__link">
                <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
              </a>
            </div>
          </div>
        </div>

        <!-- 4. Link groups (four columns) -->
        <div class="lld-footer-groups">
          
          <!-- Health tools -->
          <nav class="lld-footer-col" aria-label="Health tools">
            <h3 class="lld-footer-col__title">Health tools</h3>
            <ul class="lld-footer-col__list">
              <li><a href="<?php echo esc_url(home_url('/health-tools/protein/')); ?>">Daily Protein Target</a></li>
              <li><a href="<?php echo esc_url(home_url('/health-tools/diabetes-risk-check/')); ?>">Diabetes Risk Check</a></li>
              <li><a href="<?php echo esc_url(home_url('/health-tools/healthy-weight-check/')); ?>">Healthy Weight Check</a></li>
              <li class="lld-footer-col__all"><a href="<?php echo esc_url(home_url('/health-tools/')); ?>">All health tools &rarr;</a></li>
            </ul>
          </nav>

          <!-- Topics (names use "and", never "&") -->
          <nav class="lld-footer-col" aria-label="Topics">
            <h3 class="lld-footer-col__title">Topics</h3>
            <ul class="lld-footer-col__list">
              <li><a href="<?php echo esc_url(home_url('/category/weight-healthy-eating/')); ?>">Weight and Healthy Eating</a></li>
              <li><a href="<?php echo esc_url(home_url('/category/blood-sugar-metabolic-health/')); ?>">Blood Sugar and Metabolic Health</a></li>
              <li><a href="<?php echo esc_url(home_url('/category/fitness-strength/')); ?>">Fitness and Strength</a></li>
              <li><a href="<?php echo esc_url(home_url('/category/sleep-stress/')); ?>">Sleep and Stress</a></li>
              <li><a href="<?php echo esc_url(home_url('/category/heart-health/')); ?>">Heart Health</a></li>
              <li><a href="<?php echo esc_url(home_url('/category/gut-health/')); ?>">Gut Health</a></li>
              <li><a href="<?php echo esc_url(home_url('/category/healthy-ageing/')); ?>">Healthy Ageing</a></li>
              <li><a href="<?php echo esc_url(home_url('/category/sex-relationships/')); ?>">Sex and Relationships</a></li>
              <li><a href="<?php echo esc_url(home_url('/category/health-conditions/')); ?>">Health Conditions</a></li>
              <li class="lld-footer-col__all"><a href="<?php echo esc_url(home_url('/articles/')); ?>">All articles &rarr;</a></li>
            </ul>
          </nav>

          <!-- Programmes -->
          <nav class="lld-footer-col" aria-label="Programmes">
            <h3 class="lld-footer-col__title">Programmes</h3>
            <ul class="lld-footer-col__list">
              <li><a href="<?php echo esc_url(home_url('/programmes/healthy-weight/')); ?>">Healthy Weight</a></li>
              <li><a href="<?php echo esc_url(home_url('/programmes/blood-sugar/')); ?>">Blood Sugar Balance</a></li>
              <li><a href="<?php echo esc_url(home_url('/programmes/fitness/')); ?>">Fitness and Strength</a></li>
              <li><a href="<?php echo esc_url(home_url('/programmes/sleep-stress/')); ?>">Sleep and Stress</a></li>
              <li class="lld-footer-col__all"><a href="<?php echo esc_url(home_url('/programmes/')); ?>">All programmes</a></li>
              <li class="lld-footer-col__pricing"><a href="<?php echo esc_url(home_url('/pricing/')); ?>">Pricing</a></li>
            </ul>
          </nav>

          <!-- Lifeline Daily -->
          <nav class="lld-footer-col" aria-label="Lifeline Daily">
            <h3 class="lld-footer-col__title">Lifeline Daily</h3>
            <ul class="lld-footer-col__list">
              <li><a href="<?php echo esc_url(home_url('/about-us/')); ?>">About us</a></li>
              <li><a href="<?php echo esc_url(home_url('/editorial-standards/')); ?>">Editorial standards</a></li>
              <li><a href="<?php echo esc_url(home_url('/contact-us/')); ?>">Contact</a></li>
              <li><a href="<?php echo esc_url(home_url('/medical-disclaimer/')); ?>">Medical disclaimer and urgent care</a></li>
            </ul>
          </nav>

        </div>

      </div>

      <!-- 3. Starter guide sign-up (Omitted on homepage per §3) -->
      <?php if (!$is_home): ?>
        <section class="lld-footer-guide" aria-labelledby="lld-footer-guide-title">
          <div class="lld-footer-guide__grid">
            
            <div class="lld-footer-guide__intro">
              <h2 id="lld-footer-guide-title" class="lld-footer-guide__title">Get a free starter guide</h2>
              <p class="lld-footer-guide__sub">Pick a goal and we'll email you a short guide to your first week.</p>
            </div>

            <div class="lld-footer-guide__form-wrap">
              <form method="post" action="" class="lld-footer-guide__form">
                <?php if (function_exists('wp_nonce_field')) wp_nonce_field('lld_guide', 'lld_guide_nonce'); ?>
                <input type="hidden" name="lld_kind" value="guide">
                <input type="hidden" name="lld_back" value="<?php echo esc_url($current_url); ?>">

                <div class="lld-footer-guide__fields">
                  
                  <!-- Choice: Your goal -->
                  <div class="lld-footer-guide__field lld-footer-guide__field--track">
                    <label for="lld_footer_track">Your goal</label>
                    <select id="lld_footer_track" name="lld_track" required>
                      <option value="healthy-weight">Healthy Weight</option>
                      <option value="blood-sugar">Blood Sugar Balance</option>
                      <option value="fitness">Fitness and Strength</option>
                      <option value="sleep-stress">Sleep and Stress</option>
                    </select>
                  </div>

                  <!-- Email: name@example.com -->
                  <div class="lld-footer-guide__field lld-footer-guide__field--email">
                    <label for="lld_footer_email">Email</label>
                    <input type="email" id="lld_footer_email" name="lld_email" required 
                           placeholder="name@example.com" autocomplete="email">
                  </div>

                </div>

                <div class="lld-footer-guide__submit-wrap">
                  <button type="submit" class="lld-footer-guide__submit">
                    <span>Send me the guide</span>
                    <span aria-hidden="true">&rarr;</span>
                  </button>
                </div>

                <?php if ($has_submitted): ?>
                  <div class="lld-footer-guide__status" role="status">
                    Check your email to confirm.
                  </div>
                <?php endif; ?>

                <p class="lld-footer-guide__privacy">
                  We only use your email to send the guide you asked for. You can unsubscribe at any time. Read our <a href="<?php echo esc_url(home_url('/privacy-policy/')); ?>">privacy policy</a>.
                </p>

              </form>
            </div>

          </div>
        </section>
      <?php endif; ?>

      <!-- 5. Legal bar (full width, bottom) -->
      <section class="lld-footer-legal" aria-label="Legal information">
        <div class="lld-footer-legal__wrap">
          <p class="lld-footer-legal__copy">
            &copy; <?php echo esc_html($year); ?> Lifeline Daily. Health information, not medical advice.
          </p>
          <div class="lld-footer-legal__links">
            <a href="<?php echo esc_url(home_url('/privacy-policy/')); ?>">Privacy policy</a>
            <span class="lld-footer-legal__sep" aria-hidden="true">&bull;</span>
            <a href="<?php echo esc_url(home_url('/terms-conditions/')); ?>">Terms and conditions</a>
            <span class="lld-footer-legal__sep" aria-hidden="true">&bull;</span>
            <a href="<?php echo esc_url(home_url('/terms-of-sale/')); ?>">Terms of sale</a>
            <span class="lld-footer-legal__sep" aria-hidden="true">&bull;</span>
            <button type="button" class="cky-banner-element lld-footer-legal__cookies">Cookie settings</button>
          </div>
        </div>
      </section>

    </div>

  </footer>
  <?php
  return (string)ob_get_clean();
}

/**
 * Public function callable from anywhere (including member screens).
 */
function lld_tools_footer(bool $is_member_view = false): void {
  echo lld_tools_footer_html($is_member_view);
}

/**
 * Print footer on wp_footer for site-wide coverage outside Newspaper template loops.
 */
add_action('wp_footer', function () {
  // If we already rendered inside lld_page_layout_transform, don't duplicate
  if (defined('LLD_FOOTER_RENDERED') && LLD_FOOTER_RENDERED) return;
  lld_tools_footer();
  define('LLD_FOOTER_RENDERED', true);
}, 20);
