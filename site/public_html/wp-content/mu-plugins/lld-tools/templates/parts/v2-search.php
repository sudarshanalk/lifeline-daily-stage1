<?php
/**
 * Site search box for the top of category and article pages (owner, 8 Nov 2026: search on categories and posts, the
 * homepage search stays where it is, no header change). The same look as the homepage search; WordPress search (?s=).
 * Expects $lld_search_id (unique id for the field). Icon: Tabler Icons "search" (MIT licence).
 */
if (!defined('ABSPATH')) exit;
$lld_search_id = isset($lld_search_id) ? (string)$lld_search_id : 'lld-page-search';
?>
<form role="search" aria-label="Search guides" method="get" action="<?php echo esc_url(home_url('/')); ?>" class="lld-page-search relative flex items-center max-w-md rounded-full overflow-hidden border border-[#D5CCBE] border-day-soft bg-white bg-day-card shadow-sm" data-test="page-search">
  <span class="pl-4 text-[#6B675F]" aria-hidden="true"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0"/><path d="M21 21l-6 -6"/></svg></span>
  <label for="<?php echo esc_attr($lld_search_id); ?>" class="sr-only">Search Lifeline Daily</label>
  <input id="<?php echo esc_attr($lld_search_id); ?>" type="search" name="s" placeholder="Search Lifeline Daily..." class="w-full py-3 pl-3 pr-24 text-sm text-[#1F1E1C] text-day-dark bg-transparent focus:outline-none">
  <button type="submit" class="absolute right-1.5 min-h-[36px] bg-[#1D4E48] text-white text-xs font-semibold px-4 rounded-full hover:bg-[#143833] transition-colors">Search</button>
</form>
