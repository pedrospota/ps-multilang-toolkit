<?php
/**
 * Plugin Name: PS Multilang Toolkit
 * Plugin URI:  https://airankia.com
 * Description: Header / footer / language switcher + Polylang duplicate-slug fix — 100% configurable desde Ajustes. Usa los menús nativos de WordPress.
 * Version:     1.0.0
 * Author:      airankia
 * License:     GPL-2.0-or-later
 * Text Domain: ps-multilang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PS_Multilang_Toolkit {

	const OPT = 'ps_multilang_settings';
	const VER = '1.0.0';

	public function __construct() {
		add_action( 'init',              [ $this, 'on_init' ] );
		add_action( 'admin_menu',        [ $this, 'admin_menu' ] );
		add_action( 'admin_init',        [ $this, 'admin_init' ] );
		add_action( 'after_setup_theme', [ $this, 'register_nav_menus' ] );

		add_filter( 'wp_unique_post_slug', [ $this, 'allow_duplicate_slugs_per_language' ], 10, 6 );
		add_filter( 'request',             [ $this, 'disambiguate_by_lang_prefix' ], 5 );

		add_action( 'wp_head',       [ $this, 'inject_css' ], 99 );
		add_action( 'wp_body_open',  [ $this, 'render_header' ], 1 );
		add_action( 'wp_footer',     [ $this, 'render_footer' ], 1 );
	}

	/* ---------------- Settings ---------------- */

	public function defaults() {
		return [
			'enabled'             => 1,
			'template_trigger'    => 'html',
			'apply_to_all_pages'  => 0,
			'hide_theme_chrome'   => 1,
			'enable_polylang_fix' => 1,
			'brand_name'          => get_bloginfo( 'name' ),
			'logo_url'            => '',
			'bg_color'            => '#050811',
			'text_color'          => '#e2e8f0',
			'accent_color'        => '#10b981',
			'accent_color_2'      => '#3b82f6',
			'muted_color'         => '#94a3b8',
			'cta_label'           => 'Free trial',
			'cta_url'             => '',
			'tagline'             => '',
			'product_col'         => 'Product',
			'legal_col'           => 'Legal',
			'rights_label'        => 'All rights reserved.',
			'contact_email'       => '',
		];
	}

	public function settings() {
		$saved = get_option( self::OPT, [] );
		return array_merge( $this->defaults(), is_array( $saved ) ? $saved : [] );
	}

	public function on_init() {
		if ( function_exists( 'pll_register_string' ) ) {
			$s = $this->settings();
			$strings = [ 'tagline', 'cta_label', 'product_col', 'legal_col', 'rights_label' ];
			foreach ( $strings as $k ) {
				if ( ! empty( $s[ $k ] ) ) {
					pll_register_string( 'ps_mt_' . $k, $s[ $k ], 'PS Multilang Toolkit' );
				}
			}
		}
	}

	public function register_nav_menus() {
		register_nav_menus( [
			'ps_header'         => 'PS Multilang — Header Menu',
			'ps_footer_product' => 'PS Multilang — Footer (Column 1)',
			'ps_footer_legal'   => 'PS Multilang — Footer (Column 2)',
		] );
	}

	/* ---------------- Polylang duplicate-slug fix ---------------- */

	public function allow_duplicate_slugs_per_language( $slug, $post_ID, $post_status, $post_type, $post_parent, $original_slug ) {
		if ( ! $this->settings()['enable_polylang_fix'] ) return $slug;
		if ( ! function_exists( 'pll_get_post_language' ) ) return $slug;

		global $wpdb;
		$lang = pll_get_post_language( $post_ID );
		if ( ! $lang ) return $slug;

		$same_slug_same_lang = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			 INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
			 WHERE p.post_name = %s AND p.post_type = %s AND p.ID != %d
			   AND p.post_status NOT IN ('trash','auto-draft')
			   AND tt.taxonomy = 'language' AND t.slug = %s",
			$original_slug, $post_type, $post_ID, $lang
		) );

		return empty( $same_slug_same_lang ) ? $original_slug : $slug;
	}

	public function disambiguate_by_lang_prefix( $qv ) {
		if ( ! $this->settings()['enable_polylang_fix'] ) return $qv;
		if ( empty( $qv['pagename'] ) ) return $qv;
		if ( ! function_exists( 'pll_get_post_language' ) ) return $qv;

		$uri  = parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
		$lang = 'en';
		if ( function_exists( 'pll_default_language' ) ) {
			$lang = pll_default_language() ?: 'en';
		}
		if ( function_exists( 'pll_languages_list' ) ) {
			$langs = pll_languages_list();
			usort( $langs, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
			foreach ( $langs as $l ) {
				if ( preg_match( '#^/' . preg_quote( $l, '#' ) . '(?:/|$)#', $uri ) ) {
					$lang = $l;
					break;
				}
			}
		}

		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'page' AND post_status = 'publish'",
			$qv['pagename']
		) );
		if ( count( $ids ) <= 1 ) return $qv;

		foreach ( $ids as $id ) {
			if ( pll_get_post_language( (int) $id ) === $lang ) {
				unset( $qv['pagename'], $qv['name'], $qv['page'] );
				$qv['page_id']   = (int) $id;
				$qv['post_type'] = 'page';
				return $qv;
			}
		}
		return $qv;
	}

	/* ---------------- Front-end ---------------- */

	public function should_inject() {
		$s = $this->settings();
		if ( ! $s['enabled'] ) return false;
		if ( is_admin() || ! is_page() ) return false;
		if ( $s['apply_to_all_pages'] ) return true;
		$pid      = get_queried_object_id();
		$template = $pid ? get_page_template_slug( $pid ) : '';
		return $template === $s['template_trigger'];
	}

	private function t( $str ) {
		return function_exists( 'pll__' ) ? pll__( $str ) : $str;
	}

	private function current_lang() {
		if ( function_exists( 'pll_current_language' ) ) {
			return pll_current_language() ?: 'en';
		}
		return 'en';
	}

	private function lang_home( $lang ) {
		if ( function_exists( 'pll_home_url' ) ) return pll_home_url( $lang );
		return home_url( '/' );
	}

	private function get_languages() {
		if ( function_exists( 'pll_the_languages' ) ) {
			$out = pll_the_languages( [ 'raw' => 1 ] );
			return is_array( $out ) ? $out : [];
		}
		return [];
	}

	private function flag_for( $slug ) {
		$flags = [
			'en' => '🇺🇸', 'es' => '🇪🇸', 'es-mx' => '🇲🇽',
			'fr' => '🇫🇷', 'de' => '🇩🇪', 'it' => '🇮🇹',
			'pt' => '🇵🇹', 'ca' => '🇨🇦', 'ja' => '🇯🇵', 'zh' => '🇨🇳',
		];
		return $flags[ $slug ] ?? '🌐';
	}

	/**
	 * Resolve the menu ID for a location honoring the current language.
	 * Looks up `{location}___{lang}` first, then falls back to `{location}`.
	 * This bypasses Polylang's option format — the admin just assigns one menu
	 * per language in `Appearance → Menus` and everything Just Works.
	 */
	private function menu_id_for( $location ) {
		$lang      = $this->current_lang();
		$locations = get_nav_menu_locations();
		if ( ! is_array( $locations ) ) return 0;
		if ( $lang && ! empty( $locations[ "{$location}___{$lang}" ] ) ) {
			return (int) $locations[ "{$location}___{$lang}" ];
		}
		if ( ! empty( $locations[ $location ] ) ) {
			return (int) $locations[ $location ];
		}
		return 0;
	}

	private function has_menu_anywhere( $location ) {
		return $this->menu_id_for( $location ) > 0;
	}

	public function inject_css() {
		if ( ! $this->should_inject() ) return;
		$s       = $this->settings();
		$bg      = esc_attr( $s['bg_color'] );
		$text    = esc_attr( $s['text_color'] );
		$accent  = esc_attr( $s['accent_color'] );
		$accent2 = esc_attr( $s['accent_color_2'] );
		$muted   = esc_attr( $s['muted_color'] );

		$hide = '';
		if ( $s['hide_theme_chrome'] ) {
			$hide = "
				.site-header,#masthead,.main-navigation,.menu-toggle,.site-footer,.site-info,.footer-widgets,.footer-bar{display:none!important}
				.site-content,body,.inside-article,.entry-content,.site,#page{background:{$bg}!important;color:{$text}!important}
				.entry-content,#main-content,.inside-article,article.page,.entry-header,.entry-footer,.post-navigation,.comments-area{margin:0!important;padding:0!important}
				.entry-header,.entry-footer,.post-navigation,.comments-area{display:none!important}
				.inside-article{padding:0!important;box-shadow:none!important}
				body{margin:0!important}
			";
		}
		?>
<style id="ps-mt-css">
<?php echo $hide; ?>
.psmt-hdr,.psmt-ftr{font-family:'Inter',ui-sans-serif,system-ui,sans-serif}
.psmt-hdr{background:<?php echo $bg; ?>;border-bottom:1px solid #1f2937;padding:16px 0;position:sticky;top:0;z-index:1000}
.psmt-hdr-c{max-width:1200px;margin:0 auto;padding:0 24px;display:flex;align-items:center;justify-content:space-between;gap:24px}
.psmt-brand{display:flex;align-items:center;gap:10px;text-decoration:none!important;color:#fff!important;font-weight:800;font-size:18px;letter-spacing:-.02em}
.psmt-brand img{height:32px;width:auto;display:block}
.psmt-brand-ico{width:28px;height:28px;background:linear-gradient(135deg,<?php echo $accent; ?> 0%,<?php echo $accent2; ?> 100%);border-radius:8px;position:relative;flex-shrink:0}
.psmt-brand-ico::after{content:'';position:absolute;inset:8px;border:2px solid <?php echo $bg; ?>;border-radius:2px}
.psmt-nav{display:flex;align-items:center;gap:28px}
.psmt-nav ul{list-style:none;margin:0;padding:0;display:flex;align-items:center;gap:28px}
.psmt-nav li{margin:0;list-style:none}
.psmt-nav a{color:#cbd5e1!important;text-decoration:none!important;font-size:15px;font-weight:500;transition:color .15s}
.psmt-nav a:hover{color:<?php echo $accent; ?>!important}
.psmt-right{display:flex;align-items:center;gap:12px}
.psmt-lang{position:relative}
.psmt-lang-btn{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.25);color:<?php echo $accent; ?>;padding:8px 14px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-family:inherit;line-height:1.2}
.psmt-lang-btn:hover{background:rgba(16,185,129,.18)}
.psmt-lang-menu{position:absolute;top:calc(100% + 8px);right:0;background:#0d121f;border:1px solid #1f2937;border-radius:10px;list-style:none;padding:6px;margin:0;min-width:210px;opacity:0;visibility:hidden;transform:translateY(-4px);transition:all .15s;z-index:100}
.psmt-lang:hover .psmt-lang-menu,.psmt-lang:focus-within .psmt-lang-menu{opacity:1;visibility:visible;transform:translateY(0)}
.psmt-lang-menu li{margin:0;list-style:none}
.psmt-lang-menu a{display:block;padding:9px 12px;color:#cbd5e1!important;text-decoration:none!important;border-radius:6px;font-size:14px}
.psmt-lang-menu a:hover,.psmt-lang-menu a.active{background:rgba(16,185,129,.1);color:<?php echo $accent; ?>!important}
.psmt-cta-hdr{background:linear-gradient(135deg,<?php echo $accent; ?> 0%,#059669 100%);color:<?php echo $bg; ?>!important;padding:10px 18px;border-radius:8px;font-weight:700;font-size:14px;text-decoration:none!important;transition:transform .15s;white-space:nowrap}
.psmt-cta-hdr:hover{transform:translateY(-1px)}
@media(max-width:860px){.psmt-nav{display:none}.psmt-cta-hdr{display:none}.psmt-hdr-c{gap:10px}}
.psmt-ftr{background:<?php echo $bg; ?>;border-top:1px solid #1f2937;padding:48px 0 28px;color:<?php echo $muted; ?>;margin-top:0}
.psmt-ftr-c{max-width:1200px;margin:0 auto;padding:0 24px}
.psmt-ftr-top{display:grid;grid-template-columns:1.2fr 2fr;gap:40px;padding-bottom:28px;border-bottom:1px solid #1f2937}
@media(max-width:760px){.psmt-ftr-top{grid-template-columns:1fr}}
.psmt-ftr-brand-blk{display:flex;align-items:center;gap:10px}
.psmt-ftr-brand-name{color:#fff;font-weight:800;font-size:17px}
.psmt-ftr-tagline{max-width:340px;margin:14px 0 0;font-size:14px;line-height:1.6;color:<?php echo $muted; ?>}
.psmt-ftr-cols{display:flex;gap:64px;flex-wrap:wrap}
.psmt-ftr-col h4{color:#fff;font-size:12px;text-transform:uppercase;letter-spacing:.08em;font-weight:700;margin:0 0 14px}
.psmt-ftr-col ul{list-style:none;margin:0;padding:0}
.psmt-ftr-col li{margin:0;list-style:none}
.psmt-ftr-col a{display:block;color:<?php echo $muted; ?>!important;text-decoration:none!important;font-size:14px;margin-bottom:10px}
.psmt-ftr-col a:hover{color:<?php echo $accent; ?>!important}
.psmt-ftr-bottom{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;padding-top:22px;font-size:13px;color:#64748b}
.psmt-ftr-bottom p{margin:0}
</style>
		<?php
	}

	public function render_header() {
		if ( ! $this->should_inject() ) return;
		$s         = $this->settings();
		$brand     = esc_html( $s['brand_name'] );
		$logo      = esc_url( $s['logo_url'] );
		$home      = esc_url( $this->lang_home( $this->current_lang() ) );
		$cta_label = esc_html( $this->t( $s['cta_label'] ) );
		$cta_url   = esc_url( $s['cta_url'] );
		?>
<header class="psmt-hdr">
	<div class="psmt-hdr-c">
		<a href="<?php echo $home; ?>" class="psmt-brand">
			<?php if ( $logo ) : ?>
				<img src="<?php echo $logo; ?>" alt="<?php echo $brand; ?>">
			<?php else : ?>
				<span class="psmt-brand-ico"></span>
				<span><?php echo $brand; ?></span>
			<?php endif; ?>
		</a>
		<?php $mid = $this->menu_id_for( 'ps_header' ); if ( $mid ) : ?>
			<?php wp_nav_menu( [
				'menu'           => $mid,
				'container'      => 'nav',
				'container_class'=> 'psmt-nav',
				'menu_class'     => 'psmt-nav-list',
				'fallback_cb'    => false,
				'depth'          => 1,
			] ); ?>
		<?php endif; ?>
		<div class="psmt-right">
			<?php $this->render_lang_switcher(); ?>
			<?php if ( $cta_url ) : ?>
				<a href="<?php echo $cta_url; ?>" class="psmt-cta-hdr"><?php echo $cta_label; ?></a>
			<?php endif; ?>
		</div>
	</div>
</header>
		<?php
	}

	private function render_lang_switcher() {
		$langs = $this->get_languages();
		if ( empty( $langs ) ) return;
		$current = $this->current_lang();

		$current_label = strtoupper( $current );
		foreach ( $langs as $l ) {
			if ( ( $l['slug'] ?? '' ) === $current ) {
				$current_label = $this->flag_for( $current ) . ' ' . strtoupper( $current );
				break;
			}
		}
		?>
		<div class="psmt-lang" tabindex="0">
			<button class="psmt-lang-btn" type="button"><?php echo $current_label; ?> ▾</button>
			<ul class="psmt-lang-menu">
				<?php foreach ( $langs as $l ) :
					$slug   = $l['slug'] ?? '';
					if ( ! $slug ) continue;
					$url    = $l['url'] ?? ( $l['no_translation_url'] ?? home_url( '/' ) );
					$name   = $l['name'] ?? strtoupper( $slug );
					$active = $slug === $current ? 'active' : '';
					?>
					<li><a href="<?php echo esc_url( $url ); ?>" class="<?php echo $active; ?>">
						<?php echo $this->flag_for( $slug ); ?> <?php echo esc_html( $name ); ?>
					</a></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	public function render_footer() {
		if ( ! $this->should_inject() ) return;
		$s           = $this->settings();
		$brand       = esc_html( $s['brand_name'] );
		$logo        = esc_url( $s['logo_url'] );
		$tagline     = esc_html( $this->t( $s['tagline'] ) );
		$rights      = esc_html( $this->t( $s['rights_label'] ) );
		$product_col = esc_html( $this->t( $s['product_col'] ) );
		$legal_col   = esc_html( $this->t( $s['legal_col'] ) );
		$email       = esc_html( $s['contact_email'] );
		?>
<footer class="psmt-ftr">
	<div class="psmt-ftr-c">
		<div class="psmt-ftr-top">
			<div>
				<div class="psmt-ftr-brand-blk">
					<?php if ( $logo ) : ?>
						<img src="<?php echo $logo; ?>" alt="<?php echo $brand; ?>" style="height:28px;width:auto">
					<?php else : ?>
						<span class="psmt-brand-ico"></span>
						<span class="psmt-ftr-brand-name"><?php echo $brand; ?></span>
					<?php endif; ?>
				</div>
				<?php if ( $tagline ) : ?>
					<p class="psmt-ftr-tagline"><?php echo $tagline; ?></p>
				<?php endif; ?>
			</div>
			<div class="psmt-ftr-cols">
				<?php $fp = $this->menu_id_for( 'ps_footer_product' ); if ( $fp ) : ?>
					<div class="psmt-ftr-col">
						<h4><?php echo $product_col; ?></h4>
						<?php wp_nav_menu( [
							'menu'        => $fp,
							'container'   => false,
							'menu_class'  => 'psmt-ftr-list',
							'fallback_cb' => false,
							'depth'       => 1,
						] ); ?>
					</div>
				<?php endif; ?>
				<?php $fl = $this->menu_id_for( 'ps_footer_legal' ); if ( $fl ) : ?>
					<div class="psmt-ftr-col">
						<h4><?php echo $legal_col; ?></h4>
						<?php wp_nav_menu( [
							'menu'        => $fl,
							'container'   => false,
							'menu_class'  => 'psmt-ftr-list',
							'fallback_cb' => false,
							'depth'       => 1,
						] ); ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<div class="psmt-ftr-bottom">
			<p>&copy; <?php echo date( 'Y' ); ?> <?php echo $brand; ?>. <?php echo $rights; ?></p>
			<?php if ( $email ) : ?><p><?php echo $email; ?></p><?php endif; ?>
		</div>
	</div>
</footer>
		<?php
	}

	/* ---------------- Admin ---------------- */

	public function admin_menu() {
		add_options_page(
			'PS Multilang Toolkit',
			'PS Multilang',
			'manage_options',
			'ps-multilang',
			[ $this, 'render_settings_page' ]
		);
	}

	public function admin_init() {
		register_setting( 'ps_multilang_group', self::OPT, [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize' ],
			'default'           => [],
		] );
	}

	public function sanitize( $input ) {
		$d     = $this->defaults();
		$clean = [];
		foreach ( [ 'enabled', 'hide_theme_chrome', 'apply_to_all_pages', 'enable_polylang_fix' ] as $k ) {
			$clean[ $k ] = ! empty( $input[ $k ] ) ? 1 : 0;
		}
		$clean['template_trigger'] = sanitize_text_field( $input['template_trigger'] ?? $d['template_trigger'] );
		$clean['brand_name']       = sanitize_text_field( $input['brand_name'] ?? $d['brand_name'] );
		$clean['logo_url']         = esc_url_raw( $input['logo_url'] ?? '' );
		foreach ( [ 'bg_color', 'text_color', 'accent_color', 'accent_color_2', 'muted_color' ] as $k ) {
			$v           = $input[ $k ] ?? $d[ $k ];
			$clean[ $k ] = preg_match( '/^#[0-9a-f]{3,8}$/i', $v ) ? $v : $d[ $k ];
		}
		foreach ( [ 'tagline', 'cta_label', 'cta_url', 'contact_email', 'rights_label', 'product_col', 'legal_col' ] as $k ) {
			$clean[ $k ] = sanitize_text_field( $input[ $k ] ?? $d[ $k ] );
		}
		return $clean;
	}

	public function render_settings_page() {
		$s    = $this->settings();
		$name = self::OPT;
		?>
<div class="wrap">
	<h1>PS Multilang Toolkit</h1>
	<p>Inyecta header / footer / selector de idioma y parchea Polylang Free. Los enlaces se toman de los <strong>menús nativos de WordPress</strong> — nada se hardcodea.</p>

	<form method="post" action="options.php">
		<?php settings_fields( 'ps_multilang_group' ); ?>

		<h2>General</h2>
		<table class="form-table" role="presentation">
			<tr><th scope="row">Activación</th><td>
				<label><input type="checkbox" name="<?php echo $name; ?>[enabled]" value="1" <?php checked( $s['enabled'] ); ?>> Plugin activo</label><br>
				<label><input type="checkbox" name="<?php echo $name; ?>[hide_theme_chrome]" value="1" <?php checked( $s['hide_theme_chrome'] ); ?>> Ocultar header/footer del tema</label><br>
				<label><input type="checkbox" name="<?php echo $name; ?>[apply_to_all_pages]" value="1" <?php checked( $s['apply_to_all_pages'] ); ?>> Aplicar a <em>todas</em> las páginas (si no, solo las que usen el template seleccionado)</label><br>
				<label><input type="checkbox" name="<?php echo $name; ?>[enable_polylang_fix]" value="1" <?php checked( $s['enable_polylang_fix'] ); ?>> Activar parche Polylang duplicate-slug</label>
			</td></tr>
			<tr><th scope="row">Template trigger</th><td>
				<input type="text" name="<?php echo $name; ?>[template_trigger]" value="<?php echo esc_attr( $s['template_trigger'] ); ?>" class="regular-text">
				<p class="description">Slug del template (p.ej. <code>html</code>, <code>default</code>). Solo se inyecta en páginas con este template. Ignorado si <em>Aplicar a todas</em> está marcado.</p>
			</td></tr>
		</table>

		<h2>Marca</h2>
		<table class="form-table" role="presentation">
			<tr><th scope="row">Nombre</th><td>
				<input type="text" name="<?php echo $name; ?>[brand_name]" value="<?php echo esc_attr( $s['brand_name'] ); ?>" class="regular-text">
			</td></tr>
			<tr><th scope="row">URL del logo</th><td>
				<input type="url" name="<?php echo $name; ?>[logo_url]" value="<?php echo esc_attr( $s['logo_url'] ); ?>" class="large-text">
				<p class="description">Déjalo en blanco para usar el icono de gradiente autogenerado.</p>
			</td></tr>
			<tr><th scope="row">Colores</th><td>
				<?php foreach ( [
					'bg_color'       => 'Fondo',
					'text_color'     => 'Texto',
					'accent_color'   => 'Acento 1',
					'accent_color_2' => 'Acento 2',
					'muted_color'    => 'Texto apagado',
				] as $k => $l ) : ?>
					<label style="display:inline-block;margin-right:20px"><?php echo esc_html( $l ); ?>:
						<input type="text" name="<?php echo $name; ?>[<?php echo $k; ?>]" value="<?php echo esc_attr( $s[ $k ] ); ?>" style="width:100px">
					</label>
				<?php endforeach; ?>
			</td></tr>
		</table>

		<h2>Header</h2>
		<table class="form-table" role="presentation">
			<tr><th scope="row">Botón CTA · label</th><td>
				<input type="text" name="<?php echo $name; ?>[cta_label]" value="<?php echo esc_attr( $s['cta_label'] ); ?>" class="regular-text">
			</td></tr>
			<tr><th scope="row">Botón CTA · URL</th><td>
				<input type="url" name="<?php echo $name; ?>[cta_url]" value="<?php echo esc_attr( $s['cta_url'] ); ?>" class="large-text">
				<p class="description">Vacío = ocultar botón.</p>
			</td></tr>
		</table>

		<h2>Footer</h2>
		<table class="form-table" role="presentation">
			<tr><th scope="row">Tagline</th><td>
				<input type="text" name="<?php echo $name; ?>[tagline]" value="<?php echo esc_attr( $s['tagline'] ); ?>" class="large-text">
			</td></tr>
			<tr><th scope="row">Título columna 1</th><td>
				<input type="text" name="<?php echo $name; ?>[product_col]" value="<?php echo esc_attr( $s['product_col'] ); ?>" class="regular-text">
			</td></tr>
			<tr><th scope="row">Título columna 2</th><td>
				<input type="text" name="<?php echo $name; ?>[legal_col]" value="<?php echo esc_attr( $s['legal_col'] ); ?>" class="regular-text">
			</td></tr>
			<tr><th scope="row">Copyright</th><td>
				<input type="text" name="<?php echo $name; ?>[rights_label]" value="<?php echo esc_attr( $s['rights_label'] ); ?>" class="regular-text">
			</td></tr>
			<tr><th scope="row">Email</th><td>
				<input type="email" name="<?php echo $name; ?>[contact_email]" value="<?php echo esc_attr( $s['contact_email'] ); ?>" class="regular-text">
			</td></tr>
		</table>

		<?php submit_button(); ?>
	</form>

	<hr>

	<h2>Menús</h2>
	<p>Asigna menús en <a href="<?php echo admin_url( 'nav-menus.php' ); ?>"><strong>Apariencia → Menús</strong></a> a estas ubicaciones:</p>
	<ul style="list-style:disc;margin-left:20px">
		<li><strong>PS Multilang — Header Menu</strong></li>
		<li><strong>PS Multilang — Footer (Column 1)</strong></li>
		<li><strong>PS Multilang — Footer (Column 2)</strong></li>
	</ul>
	<?php if ( function_exists( 'pll_register_string' ) ) : ?>
		<p><strong>Polylang detectado.</strong> Los textos (tagline, CTA label, etc.) están registrados en <a href="<?php echo admin_url( 'admin.php?page=mlang_strings' ); ?>">Idiomas → Traducciones de cadenas</a> — tradúcelos ahí. Para los menús, asigna uno distinto por idioma en la misma ubicación.</p>
	<?php endif; ?>
</div>
		<?php
	}
}

new PS_Multilang_Toolkit();
