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
 * Oprire: bifa `sfbtk_tracking_health_enabled` (implicit PORNIT) sau
 * `define( 'SFB_TRACKING_HEALTH_DISABLE', true );` în wp-config.php.
 *
 * @package sfb-toolkit
 * @since   1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SFB_Tracking_Health {

	const META_KEY     = '_sfb_tracking_health';
	const META_RENDERS = '_sfb_th_renders';
	const MAX_REPORTS  = 12; // protecție: nu acumulăm la nesfârșit pe o comandă

	public function __construct() {
		if ( defined( 'SFB_TRACKING_HEALTH_DISABLE' ) && SFB_TRACKING_HEALTH_DISABLE ) return;
		if ( ! get_option( 'sfbtk_tracking_health_enabled', 1 ) ) return;

		add_action( 'wp_head', [ $this, 'head_observer' ], 0 );
		add_action( 'woocommerce_thankyou', [ $this, 'count_render' ], 1 );
		add_action( 'wp_footer', [ $this, 'footer_reporter' ], 99 );
		add_action( 'rest_api_init', [ $this, 'routes' ] );
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
