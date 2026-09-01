<?php
/**
 * L'écran d'administration : diagnostic et pose du correctif.
 *
 * Il existe parce que l'API REST ne peut pas se débloquer elle-même. Ici
 * l'utilisateur est authentifié par cookie, donc tout fonctionne même quand
 * l'en-tête Authorization est mangé par l'hébergement.
 */

defined( 'ABSPATH' ) || exit;

class EkoSEO_Admin {

	const PAGE  = 'ekoseo-bridge';
	const NONCE = 'ekoseo_bridge_action';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( EKOSEO_BRIDGE_FILE ),
			[ __CLASS__, 'lien_reglages' ] );
		add_filter( 'plugin_row_meta', [ __CLASS__, 'ligne_meta' ], 10, 2 );
		add_action( 'admin_post_ekoseo_htaccess', [ __CLASS__, 'traiter' ] );
		add_action( 'admin_post_ekoseo_cle', [ __CLASS__, 'traiter_cle' ] );
		add_action( 'admin_notices', [ __CLASS__, 'avis' ] );
	}

	public static function menu() {
		/* Entrée de premier niveau : on ouvre ce panneau à chaque connexion d'un
		   site, le noyer dans Réglages ferait perdre du temps à chaque fois. */
		add_menu_page(
			'EkoSEO Bridge',
			'EkoSEO Bridge',
			'manage_options',
			self::PAGE,
			[ __CLASS__, 'ecran' ],
			'dashicons-rest-api',
			58                       // juste sous Réglages
		);
		/* Doublon volontaire dans Réglages : c'est là qu'on cherche d'instinct
		   la configuration d'une extension. */
		add_options_page(
			'EkoSEO Bridge',
			'EkoSEO Bridge',
			'manage_options',
			self::PAGE,
			[ __CLASS__, 'ecran' ]
		);
	}

	/** Le lien « Réglages » à côté de « Désactiver », dans la liste des extensions. */
	public static function lien_reglages( $liens ) {
		array_unshift(
			$liens,
			sprintf(
				'<a href="%s"><b>Réglages</b></a>',
				esc_url( admin_url( 'admin.php?page=' . self::PAGE ) )
			)
		);
		return $liens;
	}

	/** Deuxième ligne sous le plugin : un accès direct au diagnostic. */
	public static function ligne_meta( $liens, $fichier ) {
		if ( plugin_basename( EKOSEO_BRIDGE_FILE ) !== $fichier ) {
			return $liens;
		}
		$e = EkoSEO_Htaccess::etat();
		$liens[] = sprintf(
			'<a href="%s">Diagnostic &amp; clé JUPITER</a>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE ) )
		);
		if ( ! $e['correctif_present'] && $e['htaccess_utile'] ) {
			$liens[] = '<span style="color:#b32d2e">⚠ correctif Authorization à poser</span>';
		}
		return $liens;
	}

	/** Un avis tant que le pont ne peut pas fonctionner. */
	public static function avis() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$e = EkoSEO_Htaccess::etat();
		if ( $e['correctif_present'] || ! $e['htaccess_utile'] ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>EkoSEO Bridge</strong> — '
			. "l'en-tête <code>Authorization</code> n'atteint pas WordPress sur cet hébergement : "
			. 'aucun mot de passe d\'application ne fonctionnera tant que ce n\'est pas corrigé. '
			. '<a href="%s">Voir le diagnostic et corriger</a>.</p></div>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE ) )
		);
	}

	public static function traiter() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Droits insuffisants.', '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::NONCE );

		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		switch ( $op ) {
			case 'appliquer':
				list( $ok, $msg ) = EkoSEO_Htaccess::appliquer();
				break;
			case 'retirer':
				list( $ok, $msg ) = EkoSEO_Htaccess::retirer();
				break;
			case 'restaurer':
				list( $ok, $msg ) = EkoSEO_Htaccess::restaurer();
				break;
			default:
				$ok  = false;
				$msg = 'Opération inconnue.';
		}

		EkoSEO_Audit::journaliser( [
			'post_id' => null,
			'action'  => 'htaccess:' . $op,
			'result'  => $ok ? 'ok' : 'error',
			'message' => $msg,
		] );

		wp_safe_redirect( add_query_arg(
			[ 'page' => self::PAGE, 'ok' => $ok ? 1 : 0, 'msg' => rawurlencode( $msg ) ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * La clé en clair n'existe qu'au retour de sa création. On rend donc la page
	 * dans la foulée, sans redirection : passer par une redirection obligerait à
	 * stocker la clé quelque part pour la retrouver — précisément ce qu'il faut
	 * éviter.
	 */
	public static function traiter_cle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Droits insuffisants.', '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::NONCE );

		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		if ( 'revoquer' === $op ) {
			$uuid = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
			list( $ok, $msg ) = EkoSEO_Cle::revoquer( $uuid );
			wp_safe_redirect( add_query_arg(
				[ 'page' => self::PAGE, 'ok' => $ok ? 1 : 0, 'msg' => rawurlencode( $msg ) ],
				admin_url( 'admin.php' )
			) );
			exit;
		}

		list( $ok, $valeur ) = EkoSEO_Cle::creer();
		require_once ABSPATH . 'wp-admin/admin-header.php';
		self::ecran( $ok ? $valeur : '', $ok ? '' : $valeur );
		require_once ABSPATH . 'wp-admin/admin-footer.php';
		exit;
	}

	public static function ecran( $cle_claire = '', $erreur_cle = '' ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Droits insuffisants.' );
		}
		$e   = EkoSEO_Htaccess::etat();
		$bot = get_user_by( 'login', EKOSEO_BRIDGE_BOT );
		$msg = isset( $_GET['msg'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['msg'] ) ) ) : '';
		$ok  = isset( $_GET['ok'] ) ? (bool) (int) $_GET['ok'] : null;

		$oui = '<span style="color:#1e7e34;font-weight:600">oui</span>';
		$non = '<span style="color:#b32d2e;font-weight:600">non</span>';
		?>
		<div class="wrap">
			<h1>EkoSEO Bridge <span style="font-size:13px;color:#666">v<?php echo esc_html( EKOSEO_BRIDGE_VERSION ); ?></span></h1>

			<?php if ( null !== $ok ) : ?>
				<div class="notice notice-<?php echo $ok ? 'success' : 'error'; ?>">
					<p><?php echo nl2br( esc_html( $msg ) ); ?></p></div>
			<?php endif; ?>

			<h2>Diagnostic</h2>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
				<tr><td style="width:340px">Serveur</td>
					<td><code><?php echo esc_html( $e['serveur'] ); ?></code></td></tr>
				<tr><td>En-tête <code>Authorization</code> reçu par PHP</td>
					<td><?php echo $e['entete_recu'] ? $oui : $non; ?>
						<span style="color:#666">— sur cette requête d'administration, qui n'en porte pas ;
						le test qui compte est un appel authentifié à l'API.</span></td></tr>
				<tr><td>Correctif présent dans <code>.htaccess</code></td>
					<td><?php echo $e['correctif_present'] ? $oui : $non; ?></td></tr>
				<tr><td>Fichier</td>
					<td><code><?php echo esc_html( $e['fichier'] ); ?></code>
						— <?php echo $e['existe'] ? 'existe' : 'absent'; ?>,
						<?php echo $e['inscriptible'] ? 'inscriptible' : '<b>non inscriptible</b>'; ?></td></tr>
				<tr><td>Compte du pont</td>
					<td><?php
						if ( $bot ) {
							printf(
								'<code>%s</code> — rôles : %s · <a href="%s">mots de passe d\'application</a>',
								esc_html( $bot->user_login ),
								esc_html( implode( ', ', (array) $bot->roles ) ),
								esc_url( get_edit_user_link( $bot->ID ) . '#application-passwords-section' )
							);
						} else {
							echo '<b style="color:#b32d2e">absent</b> — désactiver puis réactiver le plugin.';
						}
					?></td></tr>
				<tr><td>Sauvegarde du <code>.htaccess</code></td>
					<td><?php echo $e['sauvegarde'] ? $oui : 'aucune'; ?></td></tr>
				</tbody>
			</table>

			<h2>Correctif de l'en-tête Authorization</h2>
			<?php if ( ! $e['htaccess_utile'] ) : ?>
				<p>Ce serveur (<code><?php echo esc_html( $e['serveur'] ); ?></code>) ne lit pas
				<code>.htaccess</code>. Le correctif doit être posé dans sa configuration :</p>
				<pre style="background:#f6f7f7;padding:10px 12px;border:1px solid #dcdcde">fastcgi_param HTTP_AUTHORIZATION $http_authorization;</pre>
			<?php else : ?>
				<p style="max-width:900px">
					Beaucoup d'hébergements en PHP-CGI ou FastCGI ne transmettent pas l'en-tête
					<code>Authorization</code> à PHP. WordPress ne voit alors aucune authentification et
					répond <code>rest_not_logged_in</code>, même avec un mot de passe d'application valide.
					Les directives ci-dessous le rétablissent.
				</p>
				<pre style="background:#f6f7f7;padding:10px 12px;border:1px solid #dcdcde;max-width:900px"><?php
					echo esc_html( implode( "\n", EkoSEO_Htaccess::lignes() ) );
				?></pre>
				<p style="max-width:900px;color:#666">
					Le fichier est sauvegardé avant toute écriture. Juste après, le plugin appelle la page
					d'accueil du site : si elle ne répond plus, le fichier est <b>immédiatement remis dans
					son état d'origine</b> — une directive refusée par Apache met tout le site en erreur 500.
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					style="display:inline-block;margin-right:8px">
					<?php wp_nonce_field( self::NONCE ); ?>
					<input type="hidden" name="action" value="ekoseo_htaccess">
					<input type="hidden" name="op" value="appliquer">
					<button class="button button-primary" <?php disabled( $e['correctif_present'] ); ?>>
						<?php echo $e['correctif_present'] ? 'Déjà en place' : 'Poser le correctif'; ?>
					</button>
				</form>
				<?php if ( $e['correctif_present'] ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						style="display:inline-block;margin-right:8px">
						<?php wp_nonce_field( self::NONCE ); ?>
						<input type="hidden" name="action" value="ekoseo_htaccess">
						<input type="hidden" name="op" value="retirer">
						<button class="button">Retirer le correctif</button>
					</form>
				<?php endif; ?>
				<?php if ( $e['sauvegarde'] ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						style="display:inline-block">
						<?php wp_nonce_field( self::NONCE ); ?>
						<input type="hidden" name="action" value="ekoseo_htaccess">
						<input type="hidden" name="op" value="restaurer">
						<button class="button">Restaurer la sauvegarde</button>
					</form>
				<?php endif; ?>
			<?php endif; ?>

			<h2>La clé d'accès de JUPITER</h2>
			<?php if ( '' !== $erreur_cle ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $erreur_cle ); ?></p></div>
			<?php endif; ?>

			<?php if ( '' !== $cle_claire ) :
				$bloc = sprintf( "%s\n%s\n%s", home_url(), EKOSEO_BRIDGE_BOT, $cle_claire ); ?>
				<div class="notice notice-success" style="max-width:900px">
					<p><b>Clé créée. Elle ne sera plus jamais affichée.</b> La copier maintenant :
					WordPress n'en garde qu'une empreinte, personne ne peut la retrouver ensuite.</p>
				</div>
				<textarea readonly id="ekoseo-bloc" style="width:100%;max-width:900px;height:78px;
					font-family:ui-monospace,Menlo,monospace;font-size:13px;padding:9px"><?php
					echo esc_textarea( $bloc );
				?></textarea>
				<p>
					<button class="button button-primary" onclick="
						var t=document.getElementById('ekoseo-bloc');t.select();
						document.execCommand('copy');this.textContent='Copié';return false;">
						Copier l'URL, le compte et la clé</button>
				</p>
				<p style="max-width:900px">La même clé sert à JUPITER et à
					<a href="https://waronimage.vercel.app" target="_blank" rel="noreferrer">War on Image Manager</a> :
					dans l'application, ouvrir le site → onglet <b>Médiathèque WP</b> et coller la clé
					(3<sup>e</sup> ligne du bloc). À coller dans JUPITER, ou à passer en une commande :</p>
				<pre style="background:#f6f7f7;padding:10px 12px;border:1px solid #dcdcde;max-width:900px;overflow:auto"><?php
					printf(
						"uv run python -c \"from hud import wp_bridge as B; B.enregistrer('%s','%s','%s','%s','%s')\"",
						esc_html( sanitize_key( get_bloginfo( 'name' ) ) ),
						esc_html( get_bloginfo( 'name' ) ),
						esc_html( untrailingslashit( home_url() ) ),
						esc_html( EKOSEO_BRIDGE_BOT ),
						esc_html( $cle_claire )
					);
				?></pre>
			<?php endif; ?>

			<?php $cles = EkoSEO_Cle::lister(); ?>
			<?php if ( ! EkoSEO_Cle::disponible() ) : ?>
				<div class="notice notice-error" style="max-width:900px"><p><?php
					echo esc_html( EkoSEO_Cle::raison_indisponible() );
				?></p></div>
			<?php else : ?>
				<p style="max-width:900px">
					Une seule action : le plugin crée le mot de passe d'application sur le compte
					<code><?php echo esc_html( EKOSEO_BRIDGE_BOT ); ?></code> et vous le donne à coller.
					Une clé du même nom déjà présente est révoquée au passage, pour éviter les doublons.
					Révoquer une clé coupe l'accès <b>immédiatement</b>, sans toucher au site.
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( self::NONCE ); ?>
					<input type="hidden" name="action" value="ekoseo_cle">
					<input type="hidden" name="op" value="creer">
					<button class="button button-primary">
						<?php echo $cles ? 'Régénérer la clé JUPITER' : 'Générer la clé JUPITER'; ?>
					</button>
				</form>

				<?php if ( $cles ) : ?>
					<table class="widefat striped" style="max-width:900px;margin-top:14px">
						<thead><tr><th>Nom</th><th>Créée</th><th>Dernier usage</th><th>IP</th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $cles as $c ) : ?>
							<tr>
								<td><code><?php echo esc_html( $c['nom'] ); ?></code></td>
								<td><?php echo esc_html( $c['cree'] ); ?></td>
								<td><?php echo $c['dernier'] ? esc_html( $c['dernier'] )
									: '<span style="color:#b32d2e">jamais utilisée</span>'; ?></td>
								<td><?php echo esc_html( $c['ip'] ? $c['ip'] : '—' ); ?></td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<?php wp_nonce_field( self::NONCE ); ?>
										<input type="hidden" name="action" value="ekoseo_cle">
										<input type="hidden" name="op" value="revoquer">
										<input type="hidden" name="uuid" value="<?php echo esc_attr( $c['uuid'] ); ?>">
										<button class="button button-small">Révoquer</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>

			<h2>Vérifier depuis l'extérieur</h2>
			<p style="max-width:900px">Une fois la clé générée, cette commande doit répondre autre chose
			que <code>rest_not_logged_in</code> :</p>
			<pre style="background:#f6f7f7;padding:10px 12px;border:1px solid #dcdcde;max-width:900px;overflow:auto"><?php
				printf(
					"curl -u '%s:VOTRE_CLE' \\\n     %s",
					esc_html( EKOSEO_BRIDGE_BOT ),
					esc_html( rest_url( EKOSEO_BRIDGE_NS . '/site' ) )
				);
			?></pre>
		</div>
		<?php
	}
}
