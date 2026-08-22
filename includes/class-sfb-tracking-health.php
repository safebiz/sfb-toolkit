<?php
/**
 * SFB Tracking Health — marcaj tehnic pe fiecare comandă: ce s-a întâmplat EFECTIV în browserul
 * cumpărătorului pe pagina de confirmare (GTM încărcat? purchase în dataLayer? a plecat cererea
 * către Google? pixelul Facebook? releul PixelYourSite? ce a ales omul la cookie-uri?).
 *
 * PROBLEMA pe care o rezolvă (măsurată pe special-plus.ro, 18–21 aug 2026):
 * marcajele `_pys_purchase_event_fired` / `_gtmkit_order_tracked` spun doar că PHP-ul a CONSTRUIT
 * evenimentul, nu că a PLECAT din browser. Pe comenzile pierdute ele erau „1", iar Google/Meta nu
 * primiseră nimic. Fără un martor în browser, fiecare reclamație costă un audit de o zi și tot se
 * încheie cu „probabil refuz de cookie-uri sau blocant".
 *
 * CE FACE:
 *   1. PHP, la randarea paginii de mulțumire: numără randările (`_sfb_th_renders`) — semnal
 *      independent de JS: dacă randările sunt > 0 și marcajul din browser lipsește, browserul
 *      n-a rulat NIMIC de-al nostru (blocant total / JS oprit).
 *   2. Un script în `<head>` (prioritate 0, ÎNAINTEA oricărui tracker) care doar OBSERVĂ:
 *      înfășoară `navigator.sendBeacon`, `fetch`, `XMLHttpRequest.open` și citește Resource Timing,
 *      ca să numere cererile către Google (gtm.js, /g/collect, doubleclick), Meta (/tr,
 *      fbevents.js, releul PYS /wp-json/pys-facebook, Gateway on.aws). Nu modifică, nu blochează,
 *      nu trimite nimic către terți.
 *   3. La T+3s și T+8s trimite un rezumat (DOAR da/nu și numărători, fără conținut, fără date
 *      personale) la `POST /wp-json/sfb/v1/tracking-health`, autorizat cu un token HMAC derivat
 *      din cheia comenzii (doar cine a văzut pagina de confirmare îl poate calcula).
 *   4. Serverul scrie meta `_sfb_tracking_health` (JSON) pe comandă. Se citește prin WC REST
 *      (meta_data) — `wat/tools/purchase-truth.js` îl folosește ca „dovedit".
 *
 * GDPR: date tehnice despre propria comandă, stocate la operator, nimic trimis terților;
 * categoriile de consimțământ se rețin ca fapt tehnic (ce a ales), nu ca dovadă juridică.
 *
 * Pornire: bifa `sfbtk_tracking_health_enabled` (implicit OPRIT — se activează per site, din Setări → SFB Toolkit). Oprire de urgență:
 * `define( 'SFB_TRACKING_HEALTH_DISABLE', true );` în wp-config.php.
 *
 * @package sfb-toolkit
 * @since   1.8.0 (implicit OPRIT din 1.8.1; 1.8.2: + POST prin formular/iframe — fbevents trimite Purchase așa — + casetă și coloană în admin)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SFB_Tracking_Health {

	const META_KEY     = '_sfb_tracking_health';
	const META_RENDERS = '_sfb_th_renders';
	const MAX_REPORTS  = 12; // protecție: nu acumulăm la nesfârșit pe o comandă

	public function __construct() {
		if ( defined( 'SFB_TRACKING_HEALTH_DISABLE' ) && SFB_TRACKING_HEALTH_DISABLE ) return;
		if ( ! get_option( 'sfbtk_tracking_health_enabled', 0 ) ) return; // OPRIT implicit — se pornește per site, din Setări

		add_action( 'wp_head', [ $this, 'head_observer' ], 0 );
		add_action( 'woocommerce_thankyou', [ $this, 'count_render' ], 1 );
		add_action( 'wp_footer', [ $this, 'footer_reporter' ], 99 );
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		add_action( 'add_meta_boxes', [ $this, 'metabox' ] );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', [ $this, 'column' ], 30 );
		add_filter( 'manage_edit-shop_order_columns', [ $this, 'column' ], 30 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ $this, 'column_value' ], 10, 2 );
		add_action( 'manage_shop_order_posts_custom_column', [ $this, 'column_value' ], 10, 2 );
	}

	// ── 5. afișare în admin: casetă pe comandă + coloană în listă ───────────────────────
	public function metabox() {
		// wc_get_page_screen_id știe singur dacă magazinul e pe HPOS sau pe tabela clasică
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'sfb-tracking-health', 'Măsurare (marcaj tehnic Safebiz)', [ $this, 'render_metabox' ], $screen, 'side', 'default' );
	}

	public function render_metabox( $post_or_order ) {
		$order = ( $post_or_order instanceof WC_Order ) ? $post_or_order : wc_get_order( is_object( $post_or_order ) ? $post_or_order->ID : $post_or_order );
		if ( ! $order ) return;
		echo $this->summary_html( $order, false ); // phpcs:ignore WordPress.Security.EscapeOutput -- construit cu esc_html mai jos
	}

	public function column( $cols ) {
		$out = [];
		foreach ( $cols as $k => $v ) { $out[ $k ] = $v; if ( 'order_status' === $k ) $out['sfb_th'] = 'Măsurare'; }
		if ( ! isset( $out['sfb_th'] ) ) $out['sfb_th'] = 'Măsurare';
		return $out;
	}

	public function column_value( $col, $order_or_id ) {
		if ( 'sfb_th' !== $col ) return;
		$order = ( $order_or_id instanceof WC_Order ) ? $order_or_id : wc_get_order( $order_or_id );
		if ( $order ) echo $this->summary_html( $order, true ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Rezumat în română. $short = o linie pentru lista de comenzi. */
	public function summary_html( $order, $short ) {
		$renders = (int) $order->get_meta( self::META_RENDERS );
		$h = json_decode( (string) $order->get_meta( self::META_KEY ), true );
		if ( ! $h && ! $renders ) return $short ? '<span style="color:#999">—</span>' : '<p style="color:#666">Nicio informație — comandă dinaintea modulului sau pagina de confirmare nu a fost afișată.</p>';
		if ( ! $h ) return $short ? '<span style="color:#c0453b">❌ pagina afișată, niciun script nu a rulat</span>' : '<p><strong>❌ Pagina de confirmare s-a afișat de ' . esc_html( $renders ) . ' ori, dar din browser nu a venit niciun raport</strong> — browserul nu a rulat niciun script de-al nostru (blocant total / JavaScript oprit).</p>';
		$l = $h['last'] ?? []; $v = $h['verdict'] ?? [];
		$mx = function ( $k ) use ( $h ) { $m = 0; foreach ( ( $h['reports'] ?? [] ) as $r ) $m = max( $m, (int) ( $r[ $k ] ?? 0 ) ); return $m; };
		$any = function ( $k ) use ( $h ) { foreach ( ( $h['reports'] ?? [] ) as $r ) if ( ! empty( $r[ $k ] ) ) return true; return false; };
		$c = $l['consent'] ?? [];
		if ( isset( $c['moove'] ) ) $cons = ( (int) ( $c['moove']['thirdparty'] ?? 0 ) === 1 ) ? 'acceptate' : ( ( (int) ( $c['moove']['advanced'] ?? 0 ) === 1 ) ? 'parțial (publicitate da, terți nu)' : 'REFUZATE' );
		elseif ( isset( $c['wp_consent_api']['marketing'] ) ) $cons = $c['wp_consent_api']['marketing'] ? 'acceptate' : 'REFUZATE';
		else $cons = 'necunoscut';
		$g_ok = $mx( 'ga_purchase' ) > 0; $g_txt = $g_ok ? '✅ cerere GA4 cu purchase trimisă' : ( $mx( 'ga_collect' ) > 0 ? '⚠️ GA4 a primit cereri, dar fără purchase' : ( $any( 'gtm_loaded' ) ? '❌ GTM încărcat, nicio cerere către GA4 (blocat în browser)' : '❌ GTM neîncărcat' . ( 'REFUZATE' === $cons ? ' — cookie-uri refuzate' : '' ) ) );
		$f_px = $mx( 'fb_tr_purchase' ) > 0; $f_srv = $mx( 'pys_relay' ) + $mx( 'capi_gw' );
		$f_txt = $f_px ? '✅ pixel: Purchase trimis' : ( $mx( 'fb_tr' ) > 0 ? '⚠️ pixel activ, fără Purchase' : ( $any( 'fbq' ) ? '❌ pixel încărcat, nicio cerere' : '❌ pixel neîncărcat' ) );
		$f_txt .= $f_srv ? ' · server: ' . $mx( 'pys_relay' ) . ' releu + ' . $mx( 'capi_gw' ) . ' gateway' : ' · server: nimic';
		if ( $short ) {
			$ico = ( $g_ok && ( $f_px || $f_srv ) ) ? '✅' : ( ( $g_ok || $f_px || $f_srv ) ? '⚠️' : '❌' );
			return '<span title="' . esc_attr( wp_strip_all_tags( $g_txt . ' | ' . $f_txt . ' | cookie-uri ' . $cons ) ) . '">' . $ico . ' G:' . ( $g_ok ? '✅' : '❌' ) . ' F:' . ( $f_px ? '✅' : ( $f_srv ? '⚠️' : '❌' ) ) . ' <small>' . esc_html( $cons ) . '</small></span>';
		}
		$ua = (string) ( $l['ua'] ?? '' ); $br = preg_match( '/FBAN|FBAV/i', $ua ) ? 'browser Facebook' : ( preg_match( '/Instagram/i', $ua ) ? 'browser Instagram' : ( preg_match( '/SamsungBrowser/i', $ua ) ? 'Samsung Internet' : ( preg_match( '/iPhone|iPad/i', $ua ) ? 'iPhone/Safari' : ( preg_match( '/Android/i', $ua ) ? 'Android' : ( preg_match( '/Headless/i', $ua ) ? 'browser automat (headless)' : 'desktop' ) ) ) ) );
		$rows = [
			[ 'Pagina de confirmare', 'afișată de ' . $renders . ' ori · ' . count( $h['reports'] ?? [] ) . ' rapoarte din browser' ],
			[ 'Cookie-uri', '<strong>' . esc_html( $cons ) . '</strong>' ],
			[ 'Google', esc_html( $g_txt ) . ( $mx( 'ads_conv' ) ? ' · ' . $mx( 'ads_conv' ) . ' cereri conversie Ads' : '' ) . ( $any( 'dl_purchase' ) ? '' : ' · <em>purchase lipsă din dataLayer</em>' ) ],
			[ 'Facebook', esc_html( $f_txt ) ],
			[ 'Browser', esc_html( $br ) . ( (int) ( $l['js_errors'] ?? 0 ) ? ' · ' . (int) $l['js_errors'] . ' erori JS pe pagină' : '' ) ],
		];
		$out = '<table style="width:100%;font-size:12px;border-collapse:collapse">';
		foreach ( $rows as $r ) $out .= '<tr><th style="text-align:left;padding:3px 6px 3px 0;vertical-align:top;white-space:nowrap">' . esc_html( $r[0] ) . '</th><td style="padding:3px 0">' . $r[1] . '</td></tr>';
		$out .= '</table><p style="color:#666;font-size:11px;margin:6px 0 0">Ce a plecat efectiv din browserul cumpărătorului. „Google ✅" = Google Analytics a primit cererea (și Google Ads prin import). Facebook „server" = releul PixelYourSite / Gateway-ul Meta (aceeași comandă, unită de Facebook).</p>';
		return $out;
	}

	// ── contextul comenzii de pe pagina de mulțumire ─────────────────────────────────────
	private function current_order() {
		if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) return null;
		global $wp;
		$id = isset( $wp->query_vars['order-received'] ) ? absint( $wp->query_vars['order-received'] ) : 0;
		if ( ! $id ) return null;
		$order = wc_get_order( $id );
		if ( ! $order ) return null;
		$key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $key || ! hash_equals( $order->get_order_key(), $key ) ) return null;
		return $order;
	}

	public static function token_for( $order ) {
		return hash_hmac( 'sha256', $order->get_id() . '|' . $order->get_order_key(), wp_salt( 'auth' ) );
	}

	// ── 1. numărătoarea randărilor (PHP, independent de JS) ──────────────────────────────
	public function count_render( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) return;
		$n = (int) $order->get_meta( self::META_RENDERS );
		$order->update_meta_data( self::META_RENDERS, $n + 1 );
		$order->save_meta_data();
	}

	// ── 2. observatorul din <head> — înaintea oricărui tracker ────────────────────────────
	public function head_observer() {
		if ( ! $this->current_order() ) return;
		?>
<script id="sfb-th-observer">(function(){try{
var L=window.__sfbTH={t0:Date.now(),req:[],err:0};
function rec(u,how){try{L.req.push({u:String(u).slice(0,300),h:how,t:Date.now()-L.t0});}catch(e){}}
if(navigator.sendBeacon){var sb=navigator.sendBeacon.bind(navigator);L.sb=sb;navigator.sendBeacon=function(u,d){rec(u,'beacon');return sb(u,d);};}
if(window.fetch){var f=window.fetch.bind(window);L.fetch=f;window.fetch=function(u,o){rec((u&&u.url)||u,'fetch');return f(u,o);};}
if(window.XMLHttpRequest){var op=XMLHttpRequest.prototype.open;XMLHttpRequest.prototype.open=function(m,u){rec(u,'xhr');return op.apply(this,arguments);};}
try{var fsub=HTMLFormElement.prototype.submit;HTMLFormElement.prototype.submit=function(){try{var a=String(this.action||'');var ev=(this.querySelector('[name=ev]')||{}).value||'';rec(a+(a.indexOf('?')>-1?'&':'?')+'ev='+ev,'form');}catch(e){}return fsub.apply(this,arguments);};}catch(e){}
try{if(performance.setResourceTimingBufferSize)performance.setResourceTimingBufferSize(2000);}catch(e){}
try{new MutationObserver(function(ms){ms.forEach(function(m){(m.addedNodes||[]).forEach(function(n){try{if(n.tagName==='IFRAME'&&n.src)rec(n.src,'iframe');if(n.tagName==='IMG'&&n.src&&/facebook\.com\/tr/.test(n.src))rec(n.src,'img');}catch(e){}});});}).observe(document.documentElement,{childList:true,subtree:true});}catch(e){}
window.addEventListener('error',function(){L.err++;});
}catch(e){}})();</script>
		<?php
	}

	// ── 3. raportorul din footer ─────────────────────────────────────────────────────────
	public function footer_reporter() {
		$order = $this->current_order();
		if ( ! $order ) return;
		$cfg = [
			'id'  => $order->get_id(),
			'tok' => self::token_for( $order ),
			'url' => esc_url_raw( rest_url( 'sfb/v1/tracking-health' ) ),
		];
		?>
<script id="sfb-th-reporter">(function(){var C=<?php echo wp_json_encode( $cfg ); ?>;try{
function has(re,list){var n=0;for(var i=0;i<list.length;i++){if(re.test(list[i]))n++;}return n;}
function urls(){var L=window.__sfbTH||{req:[]};var a=[];for(var i=0;i<L.req.length;i++)a.push(L.req[i].u);
 try{var pe=performance.getEntriesByType('resource');for(var j=0;j<pe.length;j++)a.push(pe[j].name);}catch(e){}return a;}
function consent(){var c={};try{var m=document.cookie.match(/(?:^|;\s*)moove_gdpr_popup=([^;]*)/);if(m){var d=JSON.parse(decodeURIComponent(m[1]));c.moove={strict:d.strict,thirdparty:d.thirdparty,advanced:d.advanced};}}catch(e){}
 try{if(typeof wp_has_consent==='function'){c.wp_consent_api={marketing:wp_has_consent('marketing'),statistics:wp_has_consent('statistics')};}}catch(e){}
 try{var s=document.cookie.match(/(?:^|;\s*)(surecookie[^=]*|cookie_consent[^=]*|wp_consent_[a-z]+)=([^;]*)/g);if(s){c.cookies=s.map(function(x){return x.replace(/^;\s*/,'').slice(0,80);});}}catch(e){}
 return c;}
function snap(phase){var u=urls();var dl=window.dataLayer||[];var hasPurchase=false;try{for(var i=0;i<dl.length;i++){if(dl[i]&&dl[i].event==='purchase')hasPurchase=true;}}catch(e){}
 return {phase:phase,ms:Date.now()-((window.__sfbTH||{}).t0||Date.now()),observer:!!window.__sfbTH,
  gtm_loaded:!!(window.google_tag_manager),gtm_js:has(/googletagmanager\.com\/gtm\.js/,u),dl_purchase:hasPurchase,dl_len:dl.length,gtmkit:!!window.gtmkit_settings,
  ga_collect:has(/analytics\.google\.com\/g\/collect|google-analytics\.com\/g\/collect/,u),ga_purchase:has(/\/g\/collect\?[^#]*en=purchase/,u),
  ads_conv:has(/googleads\.g\.doubleclick\.net|google\.com\/pagead\/1p-conversion|googleadservices/,u),
  fbq:typeof window.fbq==='function',fb_js:has(/connect\.facebook\.net/,u),fb_tr:has(/facebook\.com\/tr/,u),fb_tr_purchase:has(/facebook\.com\/tr[^#]*ev=Purchase/,u),
  pys:!!(window.pys||window.pysOptions),pys_relay:has(/\/pys-facebook\/v1\/event/,u),capi_gw:has(/\.on\.aws\//,u),
  consent:consent(),js_errors:(window.__sfbTH||{}).err||0,ua:String(navigator.userAgent).slice(0,200),w:window.innerWidth};}
function send(phase){try{var body=JSON.stringify({order_id:C.id,token:C.tok,report:snap(phase)});var L=window.__sfbTH||{};
 if(L.fetch){L.fetch(C.url,{method:'POST',headers:{'Content-Type':'application/json'},body:body,keepalive:true,credentials:'omit'}).catch(function(){});}
 else{var x=new XMLHttpRequest();x.open('POST',C.url,true);x.setRequestHeader('Content-Type','application/json');x.send(body);}}catch(e){}}
setTimeout(function(){send('t3');},3000);setTimeout(function(){send('t8');},8000);
window.addEventListener('pagehide',function(){try{var L=window.__sfbTH||{};var b=new Blob([JSON.stringify({order_id:C.id,token:C.tok,report:snap('hide')})],{type:'application/json'});(L.sb||navigator.sendBeacon.bind(navigator))(C.url,b);}catch(e){}});
}catch(e){}})();</script>
		<?php
	}

	// ── 4. REST: primește marcajul ───────────────────────────────────────────────────────
	public function routes() {
		register_rest_route( 'sfb/v1', '/tracking-health', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'receive' ],
			'permission_callback' => '__return_true', // autorizarea = tokenul HMAC legat de cheia comenzii
			'args'                => [
				'order_id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
				'token'    => [ 'type' => 'string',  'required' => true, 'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => fn( $v ) => is_string( $v ) && (bool) preg_match( '/^[a-f0-9]{64}$/', $v ) ],
				'report'   => [ 'type' => 'object',  'required' => true ],
			],
		] );
		// citire (admin): GET /sfb/v1/tracking-health?order_id=…
		register_rest_route( 'sfb/v1', '/tracking-health', [
			'methods'             => 'GET',
			'callback'            => function ( $r ) {
				$order = wc_get_order( absint( $r->get_param( 'order_id' ) ) );
				if ( ! $order ) return new WP_Error( 'not_found', 'Order not found', [ 'status' => 404 ] );
				return [ 'order_id' => $order->get_id(), 'renders' => (int) $order->get_meta( self::META_RENDERS ), 'health' => json_decode( (string) $order->get_meta( self::META_KEY ), true ) ];
			},
			'permission_callback' => fn() => current_user_can( 'manage_woocommerce' ),
			'args'                => [ 'order_id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ] ],
		] );
	}

	public function receive( WP_REST_Request $r ) {
		$order = wc_get_order( $r->get_param( 'order_id' ) );
		if ( ! $order ) return new WP_Error( 'not_found', 'Order not found', [ 'status' => 404 ] );
		if ( ! hash_equals( self::token_for( $order ), (string) $r->get_param( 'token' ) ) ) {
			return new WP_Error( 'forbidden', 'Bad token', [ 'status' => 403 ] );
		}
		$report = $this->sanitize_report( (array) $r->get_param( 'report' ) );
		$existing = json_decode( (string) $order->get_meta( self::META_KEY ), true );
		if ( ! is_array( $existing ) ) $existing = [ 'first_seen' => gmdate( 'c' ), 'reports' => [] ];
		if ( count( $existing['reports'] ) >= self::MAX_REPORTS ) {
			return [ 'ok' => false, 'reason' => 'max_reports' ];
		}
		$report['at'] = gmdate( 'c' );
		$existing['reports'][] = $report;
		$existing['last']      = $report;
		$existing['verdict']   = $this->verdict( $existing['reports'] );
		$order->update_meta_data( self::META_KEY, wp_json_encode( $existing, JSON_UNESCAPED_UNICODE ) );
		$order->save_meta_data();
		return [ 'ok' => true, 'reports' => count( $existing['reports'] ), 'verdict' => $existing['verdict'] ];
	}

	/** Doar chei cunoscute, doar bool / int / string scurt. Nimic altceva nu intră în DB. */
	private function sanitize_report( array $in ) {
		$bools = [ 'observer', 'gtm_loaded', 'dl_purchase', 'gtmkit', 'fbq', 'pys' ];
		$ints  = [ 'ms', 'gtm_js', 'dl_len', 'ga_collect', 'ga_purchase', 'ads_conv', 'fb_js', 'fb_tr', 'fb_tr_purchase', 'pys_relay', 'capi_gw', 'js_errors', 'w' ];
		$out   = [];
		foreach ( $bools as $k ) $out[ $k ] = ! empty( $in[ $k ] );
		foreach ( $ints as $k )  $out[ $k ] = isset( $in[ $k ] ) ? max( 0, min( 100000, (int) $in[ $k ] ) ) : 0;
		$out['phase'] = isset( $in['phase'] ) ? substr( preg_replace( '/[^a-z0-9]/', '', (string) $in['phase'] ), 0, 8 ) : '';
		$out['ua']    = isset( $in['ua'] ) ? substr( sanitize_text_field( (string) $in['ua'] ), 0, 200 ) : '';
		$c = isset( $in['consent'] ) && is_array( $in['consent'] ) ? $in['consent'] : [];
		$consent = [];
		if ( isset( $c['moove'] ) && is_array( $c['moove'] ) ) {
			$consent['moove'] = [];
			foreach ( [ 'strict', 'thirdparty', 'advanced' ] as $k ) $consent['moove'][ $k ] = isset( $c['moove'][ $k ] ) ? (int) $c['moove'][ $k ] : null;
		}
		if ( isset( $c['wp_consent_api'] ) && is_array( $c['wp_consent_api'] ) ) {
			$consent['wp_consent_api'] = [];
			foreach ( [ 'marketing', 'statistics' ] as $k ) $consent['wp_consent_api'][ $k ] = isset( $c['wp_consent_api'][ $k ] ) ? (bool) $c['wp_consent_api'][ $k ] : null;
		}
		if ( isset( $c['cookies'] ) && is_array( $c['cookies'] ) ) {
			$consent['cookies'] = array_slice( array_map( fn( $s ) => substr( sanitize_text_field( (string) $s ), 0, 80 ), $c['cookies'] ), 0, 6 );
		}
		$out['consent'] = $consent;
		return $out;
	}

	/** Verdictul agregat din toate rapoartele (max-ul pe fiecare semnal). */
	private function verdict( array $reports ) {
		$m = fn( $k ) => max( array_map( fn( $r ) => (int) ( $r[ $k ] ?? 0 ), $reports ) );
		$any = fn( $k ) => (bool) array_sum( array_map( fn( $r ) => ! empty( $r[ $k ] ) ? 1 : 0, $reports ) );
		$google = $m( 'ga_purchase' ) > 0 ? 'purchase_trimis' : ( $m( 'ga_collect' ) > 0 ? 'ga_fara_purchase' : ( $any( 'gtm_loaded' ) ? 'gtm_fara_ga' : 'gtm_neincarcat' ) );
		$meta   = $m( 'fb_tr_purchase' ) > 0 ? 'pixel_purchase_trimis' : ( $m( 'fb_tr' ) > 0 ? 'pixel_fara_purchase' : ( $m( 'pys_relay' ) > 0 || $m( 'capi_gw' ) > 0 ? 'doar_server' : ( $any( 'fbq' ) ? 'fbq_fara_cereri' : 'pixel_neincarcat' ) ) );
		return [ 'google' => $google, 'meta' => $meta, 'dl_purchase' => $any( 'dl_purchase' ), 'reports' => count( $reports ) ];
	}
}
