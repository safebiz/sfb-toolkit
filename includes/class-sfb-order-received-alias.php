<?php
/**
 * SFB Order Received Alias — salvează pagina de mulțumire când un gateway
 * construiește adresa de întoarcere cu endpointul ENGLEZ, pe un site care îl are tradus.
 *
 * PROBLEMA pe care o rezolvă:
 * WooCommerce permite traducerea endpointului paginii de mulțumire
 * (`woocommerce_checkout_order_received_endpoint`). Pe site-urile românești el devine
 * de regulă `comanda-primita`. Unele gateway-uri de plată **hardcodează** varianta
 * engleză `order-received` în adresa de întoarcere pe care o dau procesatorului,
 * în loc să folosească `WC_Order::get_checkout_order_received_url()`.
 *
 * Rezultatul: cumpărătorul e trimis înapoi de la procesator pe o adresă care **dă 404**.
 * Consecințe, în ordinea gravității:
 *   1. clientul care tocmai a plătit vede „Pagină negăsită", fără confirmare și fără nr. comandă;
 *   2. evenimentul `Purchase` nu se declanșează niciodată — nici Meta, nici GA4/Ads,
 *      pentru că ambele stau pe pagina de mulțumire.
 *
 * Caz măsurat pe un magazin din flotă, cu `fusion-pay-ro-tbi` v3.0 (TBI/Fusion Pay).
 * Pluginul scrie `/order-received/` în `PaymentGateway.php:261` și în `tbi_checkout.js:110`,
 * `tbi_cart.js:63`, `tbi_product.js:315` — deci pe toate căile de cumpărare, iar
 * trecerea pe pop-up NU repară nimic (`grep order_received_endpoint` pe tot pluginul = 0).
 * Efect măsurat: 100% din comenzile prin acest gateway au rămas fără eveniment `Purchase`
 * pe o fereastră de 30 de zile.
 *
 * CE FACE:
 * Un singur cârlig pe `template_redirect` (prioritate 1, înaintea `redirect_canonical`):
 * dacă adresa cerută conține `/order-received/{id}/` iar site-ul folosește ALT endpoint,
 * redirecționează 302 către adresa reală construită de WooCommerce însuși.
 *
 * SIGURANȚĂ — de ce se poate lăsa pornit fără bifă:
 *   - **nu schimbă nicio adresă existentă.** Acționează exclusiv pe adrese care ALTFEL
 *     ar da 404. Dacă endpointul site-ului e deja `order-received`, iese imediat (no-op).
 *   - **verifică cheia comenzii** (`hash_equals`) înainte de a redirecționa. Fără cheie
 *     validă nu se redirecționează nimic ⇒ nu devine cale de enumerare a comenzilor.
 *   - **302, nu 301** — e o punte peste un bug de vendor, nu o adresă canonică nouă.
 *     Când gateway-ul se repară, puntea nu rămâne memorată în browsere.
 *   - adresa de destinație vine din `get_checkout_order_received_url()`, deci e mereu
 *     cea corectă pentru configurația curentă, inclusiv cheia.
 *
 * Oprire de urgență: `define( 'SFB_ORDER_RECEIVED_ALIAS_DISABLE', true );` în `wp-config.php`.
 *
 * ⚠️ Nu înlocuiește raportarea bugului către producătorul gateway-ului. Soluția corectă
 * la ei e `$order->get_checkout_order_received_url()`.
 *
 * @package sfb-toolkit
 * @since   1.7.6
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SFB_Order_Received_Alias {

	/** Endpointul implicit (englez) pe care îl hardcodează gateway-urile cu bug. */
	const LEGACY_ENDPOINT = 'order-received';

	public function __construct() {
		if ( defined( 'SFB_ORDER_RECEIVED_ALIAS_DISABLE' ) && SFB_ORDER_RECEIVED_ALIAS_DISABLE ) {
			return;
		}
		// Prioritate 1: înaintea lui `redirect_canonical` (prio 10), ca să prindem
		// și varianta cu slash dublu (`/finalizare//order-received/…`) dintr-un singur pas.
		add_action( 'template_redirect', [ $this, 'maybe_redirect' ], 1 );
	}

	/**
	 * Redirecționează adresa cu endpoint englez către cea reală, dacă e cazul.
	 *
	 * @return void
	 */
	public function maybe_redirect() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		// WooCommerce poate fi inactiv sau încă neîncărcat — verificare la RUNTIME,
		// nu în constructor (aceeași capcană de ordine de încărcare ca la SFB_URL_Bases).
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$real_endpoint = get_option( 'woocommerce_checkout_order_received_endpoint', self::LEGACY_ENDPOINT );
		// Endpointul e deja cel englez (sau gol) ⇒ nu există nepotrivire de rezolvat.
		if ( ! is_string( $real_endpoint ) || '' === $real_endpoint || self::LEGACY_ENDPOINT === $real_endpoint ) {
			return;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( ! is_string( $uri ) || '' === $uri ) {
			return;
		}
		if ( ! preg_match( '#/' . preg_quote( self::LEGACY_ENDPOINT, '#' ) . '/(\d+)/?#', $uri, $m ) ) {
			return;
		}

		$order_id = (int) $m[1];
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		if ( $order_id <= 0 || 0 !== strpos( $key, 'wc_order_' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		// Fără cheia corectă nu redirecționăm: altfel adresa ar confirma existența comenzilor.
		if ( ! hash_equals( (string) $order->get_order_key(), $key ) ) {
			return;
		}

		wp_safe_redirect( $order->get_checkout_order_received_url(), 302 );
		exit;
	}
}
