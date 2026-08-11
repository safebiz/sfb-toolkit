<?php
/**
 * SFB URL Bases — paritate de structură URL la migrarea de pe Rank Math.
 *
 * PROBLEMA pe care o rezolvă:
 * Rank Math oferă 4 comutatoare care scot bazele din URL-uri („Remove product base",
 * „Remove category base", „Remove parent slugs"). SureRank are DOAR două filtre PHP
 * (`surerank_remove_category_base`, `surerank_remove_product_category_base`) și NICIUN
 * echivalent pentru baza produsului sau pentru slug-urile părinte. La dezactivarea
 * Rank Math, întregul catalog își schimbă adresa: produsele primesc `/produs/`, iar
 * categoriile imbricate ajung 404. Vezi `projects/lcpackagingshop/audits/TEST-MIGRARE-SURERANK-2026-08-11.md`.
 *
 * CE FACE (implementare proprie pe API WordPress — NU cod copiat din Rank Math,
 * care e GPL-3.0 și depinde de clase interne):
 *   1. `post_type_link` / `term_link` — scoate baza din linkurile generate de site
 *   2. `request` (prio 11)           — router la rădăcină: rezolvă slug-ul de produs de un segment
 *   3. `rewrite_rules_array` (prio 99) — reguli explicite per termen, pentru categorii
 *   4. `template_redirect`           — 301 de pe URL-ul cu bază pe cel fără
 *
 * SIGURANȚĂ:
 *   - toate bifele sunt OPRITE implicit; activarea schimbă adrese, deci e o decizie conștientă
 *   - regenerarea regulilor se face la salvarea setărilor ȘI la create/edit/delete de termeni
 *   - la dezactivarea pluginului regulile se curăță (vezi sfbtk_url_bases_flush_on_deactivate)
 *   - coliziunile slug produs↔categorie sunt RAPORTATE, nu ascunse (vezi collisions())
 *
 * ⚠️ NU activa simultan cu filtrele SureRank echivalente — s-ar aplica de două ori.
 *
 * @package sfb-toolkit
 * @since   1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SFB_URL_Bases {

	/** @var array<int,array{parent:int,slug:string}>|null Cache termeni per taxonomie. */
	private $terms_cache = [];

	/** @var string|null */
	private $product_base_cache = null;

	public function __construct() {
		// Nimic activ dacă toate bifele sunt oprite — zero cost pe site-urile care nu-l folosesc.
		if ( ! $this->any_enabled() ) {
			return;
		}

		// ⚠️ NU pune `woo_active()` ca gate AICI. Constructorul rulează la include-time, iar
		// `sfb-toolkit` se încarcă alfabetic ÎNAINTEA `woocommerce` → `wc_get_permalink_structure()`
		// încă nu e definită și gate-ul ar fi mereu fals-negativ (produsele n-ar primi filtrul).
		// Aceeași capcană ca bug-ul WPS Hide Login din 1.5.7. Verificarea se face la RUNTIME,
		// în interiorul callback-urilor. (Prins la testul pe stager.lcpackagingshop.ro, 2026-08-11.)
		if ( $this->enabled( 'product_base' ) ) {
			add_filter( 'post_type_link', [ $this, 'product_permalink' ], 10, 2 );
			if ( ! is_admin() ) {
				add_filter( 'request', [ $this, 'resolve_root_product' ], 11 );
			}
		}

		if ( $this->enabled( 'product_cat_base' ) || $this->enabled( 'parent_slugs' ) ) {
			add_filter( 'term_link', [ $this, 'product_cat_permalink' ], 0, 3 );
		}
		if ( $this->enabled( 'category_base' ) ) {
			add_filter( 'term_link', [ $this, 'category_permalink' ], 0, 3 );
		}

		add_filter( 'rewrite_rules_array', [ $this, 'add_rewrite_rules' ], 99 );

		// Regenerarea regulilor când se schimbă structura de termeni.
		foreach ( [ 'product_cat', 'category' ] as $tax ) {
			add_action( "created_{$tax}", [ $this, 'schedule_flush' ] );
			add_action( "edited_{$tax}", [ $this, 'schedule_flush' ] );
			add_action( "delete_{$tax}", [ $this, 'schedule_flush' ] );
		}

		if ( ! is_admin() ) {
			add_action( 'template_redirect', [ $this, 'redirect_legacy_base' ], 5 );
		}
	}

	// ─── Setări ──────────────────────────────────────────────────────────────

	/** Numele opțiunilor, în ordinea afișată în UI. */
	public static function options() {
		return [
			'product_base'     => 'sfbtk_url_remove_product_base',
			'product_cat_base' => 'sfbtk_url_remove_product_cat_base',
			'parent_slugs'     => 'sfbtk_url_remove_parent_slugs',
			'category_base'    => 'sfbtk_url_remove_category_base',
		];
	}

	private function enabled( $key ) {
		$opts = self::options();
		return isset( $opts[ $key ] ) && (bool) get_option( $opts[ $key ], 0 );
	}

	private function any_enabled() {
		foreach ( array_keys( self::options() ) as $k ) {
			if ( $this->enabled( $k ) ) return true;
		}
		return false;
	}

	private function woo_active() {
		return function_exists( 'wc_get_permalink_structure' );
	}

	// ─── 1. Linkurile generate ───────────────────────────────────────────────

	/** Scoate baza produsului din permalink. */
	public function product_permalink( $permalink, $post ) {
		if ( ! $post || 'product' !== get_post_type( $post ) ) {
			return $permalink;
		}
		$base = $this->product_base();
		if ( '' === $base || '/' === $base ) {
			return $permalink;
		}
		return str_replace( $base, '/', $permalink );
	}

	/** Scoate baza (și, opțional, calea părinte) din linkul de categorie de produs. */
	public function product_cat_permalink( $link, $term, $taxonomy ) {
		if ( 'product_cat' !== $taxonomy || ! $this->woo_active() ) {
			return $link;
		}
		$structure = wc_get_permalink_structure();
		$base      = isset( $structure['category_rewrite_slug'] ) ? trailingslashit( $structure['category_rewrite_slug'] ) : '';

		if ( $this->enabled( 'product_cat_base' ) && '' !== $base ) {
			$link = str_replace( $base, '', $link );
			$base = '';
		}
		if ( $this->enabled( 'parent_slugs' ) ) {
			$link = home_url( user_trailingslashit( $base . $term->slug ) );
		}
		return $link;
	}

	/** Scoate baza categoriei de blog (implicit `category`). */
	public function category_permalink( $link, $term, $taxonomy ) {
		if ( 'category' !== $taxonomy ) {
			return $link;
		}
		$base = get_option( 'category_base' );
		$base = '' === $base ? 'category' : trim( $base, '/' );
		return preg_replace( '#/' . preg_quote( $base, '#' ) . '/#', '/', $link, 1 );
	}

	// ─── 2. Router la rădăcină pentru produse ────────────────────────────────

	/**
	 * Rezolvă `/{slug}/` ca produs, dacă există un produs publicat cu acel slug.
	 *
	 * ⚠️ COLIZIUNI: dacă slug-ul aparține ȘI unei categorii de produs, produsul câștigă —
	 * comportament identic cu Rank Math, pentru a nu schimba adresele deja indexate.
	 * Coliziunile sunt raportate în pagina de setări; rezolvarea lor e o decizie de conținut
	 * (redenumirea unuia dintre slug-uri), nu una de cod. Suprascrie cu filtrul de mai jos.
	 */
	public function resolve_root_product( $request ) {
		global $wp, $wpdb;

		if ( empty( $wp->request ) ) {
			return $request;
		}

		$parts   = explode( '/', $wp->request );
		$slug    = array_pop( $parts );
		$extra   = [];

		// Sufixe pe care WordPress le atașează după slug.
		if ( 'feed' === $slug || 'amp' === $slug ) {
			$extra[ $slug ] = $slug;
			$slug           = array_pop( $parts );
		}
		if ( ! empty( $slug ) && 0 === strpos( $slug, 'comment-page-' ) ) {
			$extra['cpage'] = substr( $slug, strlen( 'comment-page-' ) );
			$slug           = array_pop( $parts );
		}
		if ( empty( $slug ) ) {
			return $request;
		}

		$found = $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'product' AND post_status IN ('publish','private') LIMIT 1",
			$slug
		) );
		if ( ! $found ) {
			return $request;
		}

		/**
		 * Permite cedarea slug-ului către alt tip de conținut (ex. categoria omonimă).
		 * Întoarce false ca să NU se rezolve ca produs.
		 */
		if ( ! apply_filters( 'sfbtk_url_bases_resolve_product', true, $slug, (int) $found ) ) {
			return $request;
		}

		return array_merge( $extra, [
			'page'      => '',
			'name'      => $slug,
			'product'   => $slug,
			'post_type' => 'product',
		] );
	}

	// ─── 3. Reguli de rescriere pentru categorii ─────────────────────────────

	public function add_rewrite_rules( $rules ) {
		global $wp_rewrite;
		$generated = [];

		if ( $this->woo_active() && ( $this->enabled( 'product_cat_base' ) || $this->enabled( 'parent_slugs' ) ) ) {
			$structure = wc_get_permalink_structure();
			$base      = $this->enabled( 'product_cat_base' )
				? ''
				: trailingslashit( ltrim( $structure['category_rewrite_slug'], '/' ) );
			$generated += $this->rules_for_taxonomy( 'product_cat', $base, $wp_rewrite );
		}

		if ( $this->enabled( 'category_base' ) ) {
			$generated += $this->rules_for_taxonomy( 'category', '', $wp_rewrite );
		}

		return $generated + $rules; // regulile noastre au prioritate față de cele implicite
	}

	/** Generează regulile (pagină, embed, feed ×2, paginare) pentru fiecare termen. */
	private function rules_for_taxonomy( $taxonomy, $base, $wp_rewrite ) {
		$query_var = 'category' === $taxonomy ? 'category_name' : $taxonomy;
		$feeds     = '(' . trim( implode( '|', (array) $wp_rewrite->feeds ) ) . ')';
		$page_base = $wp_rewrite->pagination_base;
		$feed_base = $wp_rewrite->feed_base;
		$rules     = [];

		foreach ( $this->terms( $taxonomy ) as $term_id => $term ) {
			$path = $this->enabled( 'parent_slugs' ) ? $term['slug'] : $this->full_path( $taxonomy, $term_id );
			$path = urldecode( $base . $path );
			if ( '' === $path ) continue;

			$target = 'index.php?' . $query_var . '=' . $term['slug'];

			$rules[ "{$path}/?$" ]                              = $target;
			$rules[ "{$path}/embed/?$" ]                        = $target . '&embed=true';
			$rules[ "{$path}/{$feed_base}/{$feeds}/?$" ]        = $target . '&feed=$matches[1]';
			$rules[ "{$path}/{$feeds}/?$" ]                     = $target . '&feed=$matches[1]';
			$rules[ "{$path}/{$page_base}/?([0-9]{1,})/?$" ]    = $target . '&paged=$matches[1]';
		}

		return $rules;
	}

	private function terms( $taxonomy ) {
		if ( ! isset( $this->terms_cache[ $taxonomy ] ) ) {
			$list  = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );
			$slugs = [];
			if ( ! is_wp_error( $list ) ) {
				foreach ( $list as $t ) {
					$slugs[ (int) $t->term_id ] = [ 'parent' => (int) $t->parent, 'slug' => $t->slug ];
				}
			}
			$this->terms_cache[ $taxonomy ] = $slugs;
		}
		return $this->terms_cache[ $taxonomy ];
	}

	/** Calea completă `parinte/copil`. Cu gardă anti-buclă (ierarhie coruptă în DB). */
	private function full_path( $taxonomy, $term_id, $seen = [] ) {
		$terms = $this->terms( $taxonomy );
		if ( ! isset( $terms[ $term_id ] ) || isset( $seen[ $term_id ] ) ) {
			return '';
		}
		$seen[ $term_id ] = true;
		$term             = $terms[ $term_id ];
		$parent           = $term['parent'];

		if ( $parent > 0 && isset( $terms[ $parent ] ) ) {
			$prefix = $this->full_path( $taxonomy, $parent, $seen );
			return '' === $prefix ? $term['slug'] : $prefix . '/' . $term['slug'];
		}
		return $term['slug'];
	}

	private function product_base() {
		if ( null === $this->product_base_cache ) {
			// NU memoiza cât timp WooCommerce încă nu s-a încărcat — am îngheța un „fără bază" fals.
			if ( ! $this->woo_active() ) {
				return '';
			}
			$structure = wc_get_permalink_structure();
			$base      = isset( $structure['product_rewrite_slug'] ) ? $structure['product_rewrite_slug'] : '';
			$base      = str_replace( '%product_cat%', '', $base );
			$base      = trim( $base, '/' );
			$this->product_base_cache = '' === $base ? '' : '/' . $base . '/';
		}
		return $this->product_base_cache;
	}

	// ─── 4. Redirect 301 de pe URL-ul vechi (cu bază) ────────────────────────

	public function redirect_legacy_base() {
		if ( is_404() || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		// 🔴 NU redirecta sub-rutele. Permalinkul canonic (get_permalink / get_term_link) NU conține
		// sufixul, deci o comparație directă l-ar considera „URL greșit" și ar redirecta — omorând
		// feed-ul, embed-ul sau pagina N. Regresie prinsă la test pe staging 2026-08-11:
		// `/saci-big-bags/feed/` răspundea 200 pe producție și 301 spre categorie cu modulul activ.
		if ( is_feed() || is_embed() || is_trackback() || is_paged() || get_query_var( 'cpage' ) ) {
			return;
		}
		$target = '';
		if ( $this->enabled( 'product_base' ) && function_exists( 'is_product' ) && is_product() ) {
			$target = get_permalink();
		} elseif ( ( $this->enabled( 'product_cat_base' ) || $this->enabled( 'parent_slugs' ) )
			&& function_exists( 'is_product_category' ) && is_product_category() ) {
			$term   = get_queried_object();
			$target = $term instanceof WP_Term ? get_term_link( $term ) : '';
		} elseif ( $this->enabled( 'category_base' ) && is_category() ) {
			$term   = get_queried_object();
			$target = $term instanceof WP_Term ? get_term_link( $term ) : '';
		}
		if ( ! $target || is_wp_error( $target ) ) {
			return;
		}

		$current = strtok( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '?' );
		if ( untrailingslashit( wp_parse_url( $target, PHP_URL_PATH ) ) === untrailingslashit( $current ) ) {
			return; // deja pe adresa corectă
		}

		$query = wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_QUERY );
		wp_safe_redirect( $target . ( $query ? '?' . $query : '' ), 301 );
		exit;
	}

	// ─── Diagnostic pentru pagina de setări ──────────────────────────────────

	/**
	 * Coliziuni de slug produs ↔ categorie de produs.
	 * Cu bazele scoase, ambele ar ocupa aceeași adresă — produsul câștigă, categoria devine
	 * inaccesibilă. NU e un defect al modulului: e o problemă de conținut care trebuie văzută.
	 */
	public static function collisions() {
		global $wpdb;
		if ( ! function_exists( 'wc_get_permalink_structure' ) ) {
			return [];
		}
		$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return [];
		}
		$slugs = wp_list_pluck( $terms, 'slug' );
		$in    = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
		$rows  = $wpdb->get_col( $wpdb->prepare(
			"SELECT post_name FROM {$wpdb->posts} WHERE post_type='product' AND post_status IN ('publish','private') AND post_name IN ($in)",
			$slugs
		) );
		return $rows ? array_values( array_unique( $rows ) ) : [];
	}

	/**
	 * Detectează dacă ALTCINEVA scoate deja bazele (router în tema copil, alt plugin SEO,
	 * filtrele `surerank_remove_*_base`). Semnătura: baza e configurată în WooCommerce/WP,
	 * dar lipsește din permalinkul real, iar bifa noastră e OPRITĂ.
	 *
	 * De ce contează: pe mpss, de exemplu, un router custom de ~390 linii din `kadence-child`
	 * ține deja produsele și categoriile la rădăcină. Bifarea modulului acolo ar aplica
	 * mecanismul de DOUĂ ori. Avertizăm în loc să lăsăm pe cineva să descopere singur.
	 *
	 * @return array<string,string> cheie modul => descriere
	 */
	public static function foreign_handlers() {
		// NU instanția clasa aici: constructorul înregistrează filtre, iar un al doilea
		// set le-ar aplica de două ori. Metoda e pur statică, de diagnostic.
		$found = [];

		if ( function_exists( 'wc_get_permalink_structure' ) ) {
			$structure = wc_get_permalink_structure();

			$p = get_posts( [ 'post_type' => 'product', 'numberposts' => 1, 'fields' => 'ids' ] );
			$base = trim( str_replace( '%product_cat%', '', $structure['product_rewrite_slug'] ?? '' ), '/' );
			if ( $p && '' !== $base && ! get_option( self::options()['product_base'], 0 )
				&& false === strpos( (string) get_permalink( $p[0] ), '/' . $base . '/' ) ) {
				$found['product_base'] = sprintf( 'baza produsului („%s") e configurată dar lipsește din adresa reală', $base );
			}

			$t    = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 1 ] );
			$cbase = trim( $structure['category_rewrite_slug'] ?? '', '/' );
			if ( ! is_wp_error( $t ) && $t && '' !== $cbase && ! get_option( self::options()['product_cat_base'], 0 )
				&& false === strpos( (string) get_term_link( $t[0] ), '/' . $cbase . '/' ) ) {
				$found['product_cat_base'] = sprintf( 'baza categoriei de produs („%s") e configurată dar lipsește din adresa reală', $cbase );
			}
		}

		$c     = get_terms( [ 'taxonomy' => 'category', 'hide_empty' => false, 'number' => 1 ] );
		$bbase = get_option( 'category_base' );
		$bbase = '' === $bbase ? 'category' : trim( $bbase, '/' );
		if ( ! is_wp_error( $c ) && $c && ! get_option( self::options()['category_base'], 0 )
			&& false === strpos( (string) get_term_link( $c[0] ), '/' . $bbase . '/' ) ) {
			$found['category_base'] = sprintf( 'baza categoriei de blog („%s") e configurată dar lipsește din adresa reală', $bbase );
		}

		return $found;
	}

	/** Eșantion de adrese, ca să se vadă efectul înainte/după bifare. */
	public static function sample_urls() {
		$out = [];
		if ( function_exists( 'wc_get_permalink_structure' ) ) {
			$p = get_posts( [ 'post_type' => 'product', 'numberposts' => 1, 'fields' => 'ids' ] );
			if ( $p ) $out['Produs'] = str_replace( home_url(), '', get_permalink( $p[0] ) );
			$t = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 1 ] );
			if ( ! is_wp_error( $t ) && $t ) $out['Categorie produs'] = str_replace( home_url(), '', get_term_link( $t[0] ) );
			$nested = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
			if ( ! is_wp_error( $nested ) ) {
				foreach ( $nested as $x ) {
					if ( $x->parent ) { $out['Categorie imbricată'] = str_replace( home_url(), '', get_term_link( $x ) ); break; }
				}
			}
		}
		$c = get_terms( [ 'taxonomy' => 'category', 'hide_empty' => false, 'number' => 1 ] );
		if ( ! is_wp_error( $c ) && $c ) $out['Categorie blog'] = str_replace( home_url(), '', get_term_link( $c[0] ) );
		return $out;
	}

	/** Regenerează regulile la sfârșitul requestului (evită flush-uri repetate). */
	public function schedule_flush() {
		if ( did_action( 'sfbtk_url_bases_flush_scheduled' ) ) {
			return;
		}
		do_action( 'sfbtk_url_bases_flush_scheduled' );
		add_action( 'shutdown', function () { flush_rewrite_rules( false ); } );
	}
}
