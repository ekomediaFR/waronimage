=== EkoSEO Bridge ===
Contributors: ekomedia
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPL-2.0-or-later

Pont API entre JUPITER et ce site WordPress.

== Description ==

Expose sous `/wp-json/ekoseo/v1/` :

* `GET  /site`                 diagnostic : versions, CPT, source du contenu
* `GET  /media`                mediatheque paginee : URL, type, ALT, legende, description
* `POST /media`                upload base64 avec metadonnees SEO ; `POST /media/{id}` : ecriture ALT/titre/legende/description ; `POST /media/{id}/featured` : image a la une
* `GET  /tree?cpt=…`           arborescence paginée, avec hash par page
* `GET  /page/{id}`            état normalisé d'une page
* `PUT  /page/{id}`            écriture des champs SEO, avec verrou et snapshot
* `POST /page/{id}/rollback`   restauration d'un snapshot
* `GET  /audit`                journal des opérations

Toutes les routes exigent la capacité `ekoseo_manage`, portée par le rôle
*EkoSEO Agent* et le compte `ekoseo_bot`. L'accès passe par un mot de passe
d'application WordPress, révocable d'un clic.

Ce que ce plugin ne fait PAS dans cette version : écrire le corps HTML d'une
page (renvoie 501) et créer des pages (renvoie 501).

== Installation ==

1. Extensions → Ajouter → Téléverser `ekoseo-bridge.zip` → Activer.
2. Vérifier que l'utilisateur `ekoseo_bot` existe avec le rôle *EkoSEO Agent*.
3. Utilisateurs → `ekoseo_bot` → Mots de passe d'application → nommer « JUPITER »
   → Générer. La clé de 24 caractères ne s'affiche qu'une fois.
4. Si `GET /wp-json/ekoseo/v1/site` renvoie 401 avec des identifiants corrects,
   l'hébergement supprime l'en-tête Authorization. Ajouter dans `.htaccess`,
   AVANT le bloc `# BEGIN WordPress` :

       SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1

   Sur Nginx + PHP-FPM, ajouter à la place :

       fastcgi_param HTTP_AUTHORIZATION $http_authorization;

== Changelog ==

= 1.5.0 =
* /media : la mediatheque exposee pour War on Image Manager — liste paginee avec toutes les infos (URL, type, dimensions, poids, ALT, titre, legende, description, tailles), ecriture des metadonnees SEO, upload base64, image a la une. Meme cle JUPITER.

= 0.1.0 =
* Première version : lecture, écriture des champs SEO, snapshot, rollback, audit.

= 0.2.0 =
* `GET /scan` : lecture en masse — contenu, titres Hn, volume de texte, liens
  internes sortants, images sans alt, données structurées, champs Yoast.
  Remplace le parcours HTTP externe : tout est lu en base, sur l'intégralité du
  site plutôt que sur un échantillon.
* `GET /redirects` : les redirections en place, quand une extension connue les gère.

= 0.3.0 =
* Le plugin pose lui-même le correctif `SetEnvIf Authorization` dans `.htaccess` :
  tenté à l'activation, et disponible dans Réglages → EkoSEO Bridge.
  Sauvegarde avant écriture, vérification du site par requête réelle juste après,
  et restauration immédiate si le site ne répond plus.
* Écran d'administration : diagnostic complet (serveur, en-tête, fichier, compte
  du pont) et commande de vérification prête à copier.
* `GET /site` remonte l'état du correctif.

= 0.4.0 =
* L'écran du plugin génère lui-même la clé d'accès de JUPITER : un bouton, la
  clé s'affiche une fois, avec l'URL et le compte prêts à coller.
* Liste des clés existantes, avec date de dernier usage, IP et révocation en un clic.
* La clé en clair n'est jamais écrite en base par le plugin : elle est rendue
  dans la foulée de sa création, sans redirection.

= 0.5.0 =
* Entrée « EkoSEO Bridge » dans le menu de gauche de WordPress.
* Lien « Réglages » à côté du plugin dans la liste des extensions, plus une
  mention « correctif Authorization à poser » tant qu'il manque.
