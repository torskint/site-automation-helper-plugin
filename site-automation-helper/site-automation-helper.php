<?php
/**
 * Plugin Name: Site Automation Helper
 * Description: Plugin compagnon de l'outil d'automatisation multi-sites (automation/cli).
 *              Auto-suffisant : génère sa propre clé API à l'activation et authentifie lui-même
 *              toutes les requêtes REST (cœur WP + ses propres routes) — aucun mot de passe
 *              d'application WordPress à créer séparément.
 * Version: 0.10.0 (authentification par clé API propre au plugin, plus de dépendance aux
 *          Application Passwords ; route /media pour l'upload direct d'images générées côté
 *          outil ; mécanisme de mise à jour auto-hébergé via releases GitHub publiques)
 * Update URI: https://github.com/torskint/site-automation-helper-plugin
 *
 * Installation : Extensions → Ajouter → Téléverser un plugin → choisir
 * site-automation-helper.zip → Installer → Activer. Aller ensuite dans
 * Réglages → Site Automation Helper pour copier la clé API générée automatiquement — c'est la
 * seule information à transmettre à l'outil CLI (variable d'environnement
 * `<SLUG>_SAH_API_KEY`, voir automation/config/_schema.md).
 *
 * Mises à jour : ce plugin n'est pas distribué sur wordpress.org — les nouvelles versions sont
 * détectées automatiquement depuis les releases publiques de
 * https://github.com/torskint/site-automation-helper-plugin (dépôt dédié, ne contient QUE le
 * code du plugin — jamais le code des projets clients, qui reste dans un dépôt privé séparé).
 * Une fois détectée, la mise à jour apparaît normalement sur la page Extensions et passe par le
 * moteur natif WordPress (Plugin_Upgrader) au clic "Mettre à jour maintenant" — mêmes
 * garde-fous que pour une extension du répertoire officiel (sauvegarde, vérification du zip,
 * pas d'exécution de code non validé). Voir sah_check_for_update() ci-dessous.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SAH_NAMESPACE', 'site-automation/v1');
define('SAH_PLUGIN_VERSION', '0.10.0');
define('SAH_UPDATE_REPO', 'torskint/site-automation-helper-plugin');
define('SAH_PLUGIN_SLUG', plugin_basename(__FILE__));

/**
 * Les endpoints REST custom ne répondent parfois pas tant que les rewrite rules n'ont pas été
 * régénérées (piège classique de la REST API WP). On le fait automatiquement à l'activation,
 * pour que les appels API fonctionnent immédiatement après le clic "Activer" — sans avoir
 * besoin de rouvrir Réglages → Permaliens → Enregistrer. On en profite pour générer la clé API
 * du plugin si elle n'existe pas encore (jamais régénérée à l'activation si déjà présente, pour
 * ne pas invalider une clé déjà distribuée à un simple clic "Désactiver puis Réactiver").
 */
register_activation_hook(__FILE__, function () {
    sah_get_or_create_api_key();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});

/**
 * Clé API propre au plugin : remplace entièrement les Application Passwords WordPress comme
 * mécanisme d'authentification pour l'outil CLI. Générée une seule fois (64 caractères
 * aléatoires, sans caractères spéciaux ambigus), stockée en option, jamais régénérée
 * automatiquement (seulement via le bouton "Régénérer" de la page de réglages, geste humain
 * explicite car cela invalide immédiatement l'ancienne clé partout où elle est utilisée).
 */
function sah_get_or_create_api_key() {
    $key = get_option('sah_api_key', '');
    if ($key === '') {
        $key = wp_generate_password(64, false, false);
        update_option('sah_api_key', $key);
    }
    return $key;
}

/**
 * Le premier compte Administrateur du site sert d'identité d'exécution pour les requêtes
 * authentifiées par clé API — cohérent avec la vérification `manage_options` déjà en place sur
 * chaque endpoint (sah_permission_check), et avec l'esprit "un seul admin pilote l'automatisation"
 * déjà implicite dans l'usage d'un compte + mot de passe d'application avant cette version.
 */
function sah_get_auth_user() {
    $admins = get_users([
        'role' => 'administrator',
        'number' => 1,
        'orderby' => 'ID',
        'order' => 'ASC',
    ]);
    return $admins ? $admins[0] : null;
}

/**
 * Authentifie TOUTE requête REST (cœur WordPress /wp/v2/... ET nos propres routes
 * /site-automation/v1/...) porteuse de l'en-tête `X-SAH-Api-Key` valide, en s'identifiant comme
 * le premier administrateur du site. Comparaison en temps constant (hash_equals) pour éviter
 * une attaque par timing sur la clé. Ne touche jamais à un résultat d'authentification déjà
 * déterminé en amont (cookie WP valide, ou erreur déjà levée) : ne s'active que si l'en-tête est
 * présent, sinon laisse la chaîne d'authentification WordPress habituelle continuer normalement
 * (cette clé n'exclut donc pas un usage classique du site par ses utilisateurs).
 */
add_filter('rest_authentication_errors', function ($result) {
    if (!empty($result)) {
        return $result;
    }

    $provided = isset($_SERVER['HTTP_X_SAH_API_KEY']) ? (string) $_SERVER['HTTP_X_SAH_API_KEY'] : '';
    if ($provided === '') {
        return $result;
    }

    $stored = get_option('sah_api_key', '');
    if ($stored === '' || !hash_equals($stored, $provided)) {
        return new WP_Error('sah_invalid_api_key', 'Clé API Site Automation Helper invalide.', ['status' => 401]);
    }

    $user = sah_get_auth_user();
    if (!$user) {
        return new WP_Error('sah_no_admin_user', 'Aucun compte administrateur disponible pour authentifier la requête.', ['status' => 500]);
    }

    wp_set_current_user($user->ID);
    return true;
}, 20);

/**
 * Page Réglages → Site Automation Helper : seul endroit où la clé API est visible en clair.
 * Jamais exposée via un endpoint REST (ce serait la rendre récupérable par quiconque connaît
 * l'URL) — uniquement dans l'admin, derrière la capacité manage_options.
 */
add_action('admin_menu', function () {
    add_options_page(
        'Site Automation Helper',
        'Site Automation Helper',
        'manage_options',
        'site-automation-helper',
        'sah_render_settings_page'
    );
});

function sah_render_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    $key = sah_get_or_create_api_key();
    ?>
    <div class="wrap">
        <h1>Site Automation Helper</h1>
        <p>Clé API à renseigner dans la variable d'environnement <code>&lt;SLUG&gt;_SAH_API_KEY</code> côté outil CLI (voir <code>automation/config/_schema.md</code>). Elle seule authentifie l'outil — aucun mot de passe d'application WordPress n'est nécessaire.</p>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="sah-api-key">Clé API</label></th>
                <td><input type="text" id="sah-api-key" readonly style="width:32em;font-family:monospace;" value="<?php echo esc_attr($key); ?>" onclick="this.select();"></td>
            </tr>
            <tr>
                <th scope="row">Version installée</th>
                <td><?php echo esc_html(SAH_PLUGIN_VERSION); ?> — mises à jour détectées automatiquement depuis <a href="https://github.com/<?php echo esc_attr(SAH_UPDATE_REPO); ?>/releases" target="_blank" rel="noopener">les releases GitHub</a>, à appliquer depuis la page <a href="<?php echo esc_url(admin_url('plugins.php')); ?>">Extensions</a> comme n'importe quelle autre extension.</td>
            </tr>
        </table>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Régénérer la clé ? L\'ancienne clé cessera immédiatement de fonctionner partout où elle est utilisée.');">
            <?php wp_nonce_field('sah_regenerate_key'); ?>
            <input type="hidden" name="action" value="sah_regenerate_key">
            <?php submit_button('Régénérer la clé', 'delete'); ?>
        </form>
    </div>
    <?php
}

add_action('admin_post_sah_regenerate_key', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('sah_regenerate_key')) {
        wp_die('Action non autorisée.', 403);
    }
    update_option('sah_api_key', wp_generate_password(64, false, false));
    wp_safe_redirect(add_query_arg(['page' => 'site-automation-helper', 'sah_regenerated' => '1'], admin_url('options-general.php')));
    exit;
});

/**
 * Mécanisme de mise à jour auto-hébergé (plugin non distribué sur wordpress.org). Interroge
 * l'API GitHub "dernière release" du dépôt public dédié (SAH_UPDATE_REPO — ne contient QUE ce
 * plugin, jamais le code des projets clients) et, si une version plus récente existe, l'annonce
 * à WordPress via le transient standard `update_plugins` : la mise à jour proposée sur la page
 * Extensions passe ensuite intégralement par le moteur natif WordPress (Plugin_Upgrader), avec
 * ses propres garde-fous (téléchargement, vérification, remplacement atomique des fichiers) —
 * ce code ne fait qu'informer WordPress qu'une nouvelle version existe et où la télécharger,
 * il ne télécharge/installe jamais lui-même quoi que ce soit.
 */
function sah_get_latest_release() {
    $cached = get_transient('sah_latest_release');
    if ($cached !== false) {
        return $cached;
    }

    $response = wp_remote_get(
        'https://api.github.com/repos/' . SAH_UPDATE_REPO . '/releases/latest',
        [
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'site-automation-helper-plugin',
            ],
            'timeout' => 15,
        ]
    );

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return null;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($body) || empty($body['tag_name'])) {
        return null;
    }

    $download_url = null;
    foreach (($body['assets'] ?? []) as $asset) {
        if (isset($asset['name'], $asset['browser_download_url']) && substr($asset['name'], -4) === '.zip') {
            $download_url = $asset['browser_download_url'];
            break;
        }
    }
    if (!$download_url) {
        return null;
    }

    $result = [
        'version' => ltrim($body['tag_name'], 'v'),
        'download_url' => $download_url,
        'html_url' => $body['html_url'] ?? ('https://github.com/' . SAH_UPDATE_REPO . '/releases'),
        'body' => $body['body'] ?? '',
    ];

    // Cache 6h : évite de solliciter l'API GitHub (limitée en requêtes anonymes) à chaque
    // chargement de wp-admin, tout en restant réactif à un clic "Vérifier à nouveau" récent.
    set_transient('sah_latest_release', $result, 6 * HOUR_IN_SECONDS);
    return $result;
}

add_filter('pre_set_site_transient_update_plugins', function ($transient) {
    if (empty($transient) || !is_object($transient)) {
        return $transient;
    }

    $remote = sah_get_latest_release();
    if (!$remote) {
        return $transient;
    }

    if (version_compare($remote['version'], SAH_PLUGIN_VERSION, '>')) {
        $item = new stdClass();
        $item->id = 'github.com/' . SAH_UPDATE_REPO;
        $item->slug = 'site-automation-helper';
        $item->plugin = SAH_PLUGIN_SLUG;
        $item->new_version = $remote['version'];
        $item->url = $remote['html_url'];
        $item->package = $remote['download_url'];
        $item->tested = get_bloginfo('version');
        $transient->response[SAH_PLUGIN_SLUG] = $item;
    } else {
        unset($transient->response[SAH_PLUGIN_SLUG]);
    }

    return $transient;
});

/**
 * Alimente la popup "Voir les détails de la version" sur la page Extensions (facultatif, mais
 * évite une erreur WordPress générique quand l'utilisateur clique sur le lien de version pour
 * un plugin qui n'est pas sur wordpress.org).
 */
add_filter('plugins_api', function ($result, $action, $args) {
    if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'site-automation-helper') {
        return $result;
    }

    $remote = sah_get_latest_release();
    if (!$remote) {
        return $result;
    }

    $info = new stdClass();
    $info->name = 'Site Automation Helper';
    $info->slug = 'site-automation-helper';
    $info->version = $remote['version'];
    $info->author = '<a href="https://github.com/torskint">torskint</a>';
    $info->homepage = $remote['html_url'];
    $info->download_link = $remote['download_url'];
    $info->sections = [
        'description' => 'Plugin compagnon de l\'outil d\'automatisation multi-sites (ecommerce-allemand-toolkit).',
        'changelog' => wpautop(esc_html($remote['body'])),
    ];
    return $info;
}, 20, 3);

add_action('rest_api_init', function () {
    register_rest_route(SAH_NAMESPACE, '/inventory', [
        'methods' => 'GET',
        'callback' => 'sah_get_inventory',
        'permission_callback' => 'sah_permission_check',
    ]);

    register_rest_route(SAH_NAMESPACE, '/ping', [
        'methods' => 'GET',
        'callback' => function () {
            return new WP_REST_Response(['ok' => true, 'plugin_version' => SAH_PLUGIN_VERSION], 200);
        },
        'permission_callback' => 'sah_permission_check',
    ]);

    register_rest_route(SAH_NAMESPACE, '/elementor-footer/(?P<id>\d+)', [
        'methods' => 'GET',
        'callback' => 'sah_get_elementor_footer_data',
        'permission_callback' => 'sah_permission_check',
    ]);

    register_rest_route(SAH_NAMESPACE, '/elementor-footer/(?P<id>\d+)', [
        'methods' => 'POST',
        'callback' => 'sah_set_elementor_footer_data',
        'permission_callback' => 'sah_permission_check',
    ]);

    register_rest_route(SAH_NAMESPACE, '/legal-css', [
        'methods' => 'GET',
        'callback' => function () {
            return new WP_REST_Response(sah_get_legal_css_state(), 200);
        },
        'permission_callback' => 'sah_permission_check',
    ]);

    register_rest_route(SAH_NAMESPACE, '/legal-css', [
        'methods' => 'POST',
        'callback' => 'sah_set_legal_css',
        'permission_callback' => 'sah_permission_check',
    ]);

    register_rest_route(SAH_NAMESPACE, '/media', [
        'methods' => 'POST',
        'callback' => 'sah_upload_media',
        'permission_callback' => 'sah_permission_check',
    ]);
});

/**
 * Feuille de style des pages légales (`legal/html/legal-page.css`, écrite avec des variables CSS
 * placeholders) + couleurs de marque réelles, stockées en options WP et injectées en `<style>`
 * dans `wp_head` sur tout le site — même principe que l'"Additional CSS" natif de WordPress
 * (Personnaliser → CSS additionnel), qui stocke et sort lui aussi du CSS de confiance admin sans
 * échappement HTML. Les couleurs sont ajoutées APRÈS le CSS de base pour surcharger, à spécificité
 * égale, les valeurs placeholder définies dans le fichier source.
 */
function sah_get_legal_css_state() {
    $colors = get_option('sah_legal_colors', []);
    return [
        'css' => (string) get_option('sah_legal_css', ''),
        'colors' => [
            'primary' => $colors['primary'] ?? null,
            'secondary' => $colors['secondary'] ?? null,
            'light' => $colors['light'] ?? null,
        ],
    ];
}

function sah_set_legal_css(WP_REST_Request $request) {
    $css = $request->get_param('css');
    $colors = $request->get_param('colors');

    if (!is_string($css)) {
        return new WP_Error('sah_invalid_payload', 'Le champ "css" doit être une chaîne.', ['status' => 400]);
    }
    if (!is_array($colors)) {
        return new WP_Error('sah_invalid_payload', 'Le champ "colors" doit être un objet {primary, secondary, light}.', ['status' => 400]);
    }

    update_option('sah_legal_css', $css);
    update_option('sah_legal_colors', [
        'primary' => isset($colors['primary']) ? sanitize_text_field($colors['primary']) : null,
        'secondary' => isset($colors['secondary']) ? sanitize_text_field($colors['secondary']) : null,
        'light' => isset($colors['light']) ? sanitize_text_field($colors['light']) : null,
    ]);

    return new WP_REST_Response(['ok' => true], 200);
}

add_action('wp_head', function () {
    $state = sah_get_legal_css_state();
    if (empty($state['css'])) {
        return;
    }

    echo '<style id="sah-legal-page-css">' . $state['css']; // phpcs:ignore -- CSS de confiance admin, même logique que wp_get_custom_css()

    $colors = $state['colors'];
    $overrides = [];
    if (!empty($colors['primary'])) {
        $overrides[] = '--color-primary:' . esc_attr($colors['primary']) . ';';
    }
    if (!empty($colors['secondary'])) {
        $overrides[] = '--color-secondary:' . esc_attr($colors['secondary']) . ';';
    }
    if (!empty($colors['light'])) {
        $overrides[] = '--color-light:' . esc_attr($colors['light']) . ';';
    }
    if (!empty($overrides)) {
        echo ':root{' . implode('', $overrides) . '}';
    }

    echo '</style>';
});

/**
 * Téléverse un fichier (image de catégorie/produit générée côté outil, ex. `generate_gmc_image.py`)
 * dans la médiathèque, via la même clé API que le reste du plugin — évite d'avoir à créer un
 * Application Password séparé juste pour l'upload média. Le contenu du fichier voyage en
 * base64 dans le corps JSON (limite raisonnable pour des images GMC de quelques centaines de
 * Ko à quelques Mo ; pas pensé pour des vidéos ou des lots massifs). Extension whitelist
 * stricte (types image usuels uniquement) : ce n'est pas un endpoint d'upload de fichier
 * générique, seulement un point d'entrée pour des assets visuels.
 */
function sah_upload_media(WP_REST_Request $request) {
    $filename = $request->get_param('filename');
    $file_base64 = $request->get_param('file_base64');
    $title = $request->get_param('title');
    $alt_text = $request->get_param('alt_text');

    if (!is_string($filename) || $filename === '') {
        return new WP_Error('sah_invalid_payload', 'Le champ "filename" est requis.', ['status' => 400]);
    }
    if (!is_string($file_base64) || $file_base64 === '') {
        return new WP_Error('sah_invalid_payload', 'Le champ "file_base64" est requis.', ['status' => 400]);
    }

    $allowed_extensions = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed_extensions, true)) {
        return new WP_Error(
            'sah_invalid_filetype',
            'Extension non autorisée : ' . implode(', ', $allowed_extensions) . ' uniquement.',
            ['status' => 400]
        );
    }

    $decoded = base64_decode($file_base64, true);
    if ($decoded === false) {
        return new WP_Error('sah_invalid_payload', 'Le champ "file_base64" n\'est pas du base64 valide.', ['status' => 400]);
    }

    if (!function_exists('wp_handle_sideload')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    if (!function_exists('wp_generate_attachment_metadata')) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    if (!function_exists('media_handle_sideload')) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
    }

    $tmp_file = wp_tempnam($filename);
    if (!$tmp_file) {
        return new WP_Error('sah_upload_failed', 'Impossible de créer un fichier temporaire.', ['status' => 500]);
    }
    file_put_contents($tmp_file, $decoded); // phpcs:ignore -- fichier temporaire local, pas une requête distante

    $file_array = [
        'name' => sanitize_file_name($filename),
        'tmp_name' => $tmp_file,
    ];

    $attachment_id = media_handle_sideload($file_array, 0, is_string($title) ? $title : null);

    if (is_wp_error($attachment_id)) {
        @unlink($tmp_file); // phpcs:ignore -- nettoyage best-effort du temporaire en cas d'échec
        return new WP_Error('sah_upload_failed', $attachment_id->get_error_message(), ['status' => 500]);
    }

    if (is_string($alt_text) && $alt_text !== '') {
        update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
    }

    return new WP_REST_Response([
        'ok' => true,
        'id' => $attachment_id,
        'source_url' => wp_get_attachment_url($attachment_id),
    ], 201);
}

/**
 * Vérifie que l'ID fourni correspond bien à un template Elementor de type "footer" — jamais
 * n'importe quel post — avant de permettre une lecture/écriture de son _elementor_data.
 */
function sah_get_validated_footer_template($id) {
    $post = get_post($id);
    if (!$post || $post->post_type !== 'elementor_library') {
        return new WP_Error('sah_not_found', 'Template Elementor introuvable.', ['status' => 404]);
    }
    if (get_post_meta($id, '_elementor_template_type', true) !== 'footer') {
        return new WP_Error('sah_wrong_type', 'Ce template n\'est pas de type "footer".', ['status' => 400]);
    }
    return $post;
}

function sah_get_elementor_footer_data(WP_REST_Request $request) {
    $id = (int) $request['id'];
    $post = sah_get_validated_footer_template($id);
    if (is_wp_error($post)) {
        return $post;
    }

    $raw = get_post_meta($id, '_elementor_data', true);
    $data = $raw ? json_decode($raw, true) : [];
    if (!is_array($data)) {
        $data = [];
    }

    return new WP_REST_Response([
        'id' => $id,
        'title' => $post->post_title,
        'elementor_data' => $data,
    ], 200);
}

/**
 * Remplace intégralement _elementor_data par le JSON fourni (le calcul de fusion — préserver le
 * reste du template, ne modifier que les widgets Nav Menu gérés — est fait côté outil Node avant
 * l'appel, pas ici : ce endpoint est un simple point d'écriture validé, pas un moteur de fusion).
 * Vide le cache Elementor après écriture pour que le footer se régénère avec le nouveau contenu.
 */
function sah_set_elementor_footer_data(WP_REST_Request $request) {
    $id = (int) $request['id'];
    $post = sah_get_validated_footer_template($id);
    if (is_wp_error($post)) {
        return $post;
    }

    $data = $request->get_param('elementor_data');
    if (!is_array($data)) {
        return new WP_Error('sah_invalid_payload', 'Le champ "elementor_data" doit être un tableau JSON.', ['status' => 400]);
    }

    update_post_meta($id, '_elementor_data', wp_slash(wp_json_encode($data)));

    if (class_exists('\Elementor\Plugin')) {
        \Elementor\Plugin::instance()->files_manager->clear_cache();
    }

    return new WP_REST_Response(['ok' => true, 'id' => $id], 200);
}

/**
 * Seuls les comptes avec la capacité manage_options peuvent utiliser ces endpoints.
 * L'authentification elle-même est gérée soit par la clé API du plugin (voir le filtre
 * rest_authentication_errors ci-dessus, qui identifie l'appelant comme un administrateur),
 * soit par les mécanismes WordPress habituels (cookie de session) si un humain navigue vers
 * ces URLs directement.
 */
function sah_permission_check() {
    return current_user_can('manage_options');
}

/**
 * Débloque les endpoints d'écriture de l'API Abilities de WPForms (wpforms/create-form,
 * add-field, update-field, update-form-settings), désactivés par défaut par WPForms lui-même
 * pour éviter toute création/modification de formulaire accidentelle. L'accès reste protégé par
 * les capacités WordPress natives de WPForms — ce filtre ne fait que lever l'interrupteur
 * "écriture désactivée par défaut", il n'ouvre aucune permission supplémentaire.
 */
add_filter('wpforms_integrations_abilities_allow_write', '__return_true');

/**
 * Marquage visuel des pages gérées par l'outil d'automatisation dans la liste "Pages" de
 * wp-admin (badge de colonne + ligne teintée + filtre déroulant), sans toucher aux permaliens
 * ni à la hiérarchie des pages. Le marqueur est une simple postmeta booléenne, écrite par
 * `apply.js` via le champ "meta" du cœur REST (/wp/v2/pages) lors de la création/mise à jour
 * d'une page gérée — purement additif, jamais retiré automatiquement si une page sort de la config.
 */
add_action('init', function () {
    register_post_meta('page', 'sah_managed', [
        'type' => 'boolean',
        'single' => true,
        'default' => false,
        'show_in_rest' => true,
        'auth_callback' => function () {
            return current_user_can('manage_options');
        },
    ]);
});

add_filter('manage_page_posts_columns', function ($columns) {
    $columns['sah_managed'] = 'Automatisation';
    return $columns;
});

add_action('manage_page_posts_custom_column', function ($column, $post_id) {
    if ($column !== 'sah_managed') {
        return;
    }
    if (get_post_meta($post_id, 'sah_managed', true)) {
        echo '<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:#2271b1;color:#fff;font-size:11px;">Géré</span>';
    } else {
        echo '<span style="color:#aaa;">—</span>';
    }
}, 10, 2);

add_filter('post_class', function ($classes, $class, $post_id) {
    if (get_post_type($post_id) === 'page' && get_post_meta($post_id, 'sah_managed', true)) {
        $classes[] = 'sah-managed-page';
    }
    return $classes;
}, 10, 3);

add_action('admin_head-edit.php', function () {
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'page') {
        return;
    }
    echo '<style>.wp-list-table tr.sah-managed-page{background-color:#f0f6fc;}</style>';
});

add_action('restrict_manage_posts', function ($post_type) {
    if ($post_type !== 'page') {
        return;
    }
    $current = isset($_GET['sah_managed_filter']) ? sanitize_text_field(wp_unslash($_GET['sah_managed_filter'])) : '';
    ?>
    <select name="sah_managed_filter">
        <option value="">Toutes les pages</option>
        <option value="managed" <?php selected($current, 'managed'); ?>>Pages gérées (automatisation)</option>
        <option value="unmanaged" <?php selected($current, 'unmanaged'); ?>>Pages non gérées</option>
    </select>
    <?php
});

add_filter('parse_query', function ($query) {
    if (!is_admin() || !$query->is_main_query() || ($query->get('post_type') !== 'page')) {
        return;
    }
    if (empty($_GET['sah_managed_filter'])) {
        return;
    }
    $filter = sanitize_text_field(wp_unslash($_GET['sah_managed_filter']));
    if ($filter === 'managed') {
        $query->set('meta_key', 'sah_managed');
        $query->set('meta_value', '1');
    } elseif ($filter === 'unmanaged') {
        $query->set('meta_query', [
            'relation' => 'OR',
            ['key' => 'sah_managed', 'compare' => 'NOT EXISTS'],
            ['key' => 'sah_managed', 'value' => '1', 'compare' => '!='],
        ]);
    }
});

function sah_get_inventory(WP_REST_Request $request) {
    global $wp_version;

    return new WP_REST_Response([
        'site' => [
            'name' => get_bloginfo('name'),
            'url' => get_bloginfo('url'),
            'wp_version' => $wp_version,
        ],
        'language' => sah_get_language_info(),
        'pages' => sah_get_pages(),
        'menus' => sah_get_menus(),
        'plugins' => sah_get_relevant_plugins(),
        'elementor_footer_templates' => sah_get_elementor_footer_templates(),
        'legal_css' => sah_get_legal_css_state(),
    ], 200);
}

function sah_get_language_info() {
    return [
        'current_locale' => get_locale(),
        'available_locales' => array_values(get_available_languages()),
    ];
}

function sah_get_pages() {
    $pages = get_posts([
        'post_type' => 'page',
        'post_status' => ['publish', 'draft', 'pending', 'private'],
        'numberposts' => -1,
        'orderby' => 'ID',
        'order' => 'ASC',
    ]);

    return array_map(function ($page) {
        return [
            'id' => $page->ID,
            'slug' => $page->post_name,
            'title' => $page->post_title,
            'status' => $page->post_status,
            'modified' => $page->post_modified_gmt,
            'content' => $page->post_content,
            'link' => get_permalink($page->ID),
            'sah_managed' => (bool) get_post_meta($page->ID, 'sah_managed', true),
        ];
    }, $pages);
}

function sah_get_menus() {
    $menus = wp_get_nav_menus();
    $locations = get_nav_menu_locations();

    return array_map(function ($menu) use ($locations) {
        $items = wp_get_nav_menu_items($menu->term_id) ?: [];
        $assigned_locations = array_keys(array_filter($locations, function ($term_id) use ($menu) {
            return (int) $term_id === (int) $menu->term_id;
        }));

        return [
            'id' => $menu->term_id,
            'name' => $menu->name,
            'slug' => $menu->slug,
            'assigned_locations' => $assigned_locations,
            'items' => array_map(function ($item) {
                return [
                    'id' => $item->ID,
                    'title' => $item->title,
                    'url' => $item->url,
                    'order' => (int) $item->menu_order,
                    'parent' => (int) $item->menu_item_parent,
                ];
            }, $items),
        ];
    }, $menus);
}

/**
 * Ne remonte que les plugins pertinents pour l'automatisation (formulaire, page builder),
 * pas la liste complète des plugins du site.
 */
function sah_get_relevant_plugins() {
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $watched = [
        'elementor/elementor.php' => 'elementor',
        'elementor-pro/elementor-pro.php' => 'elementor-pro',
        'wpforms-lite/wpforms.php' => 'wpforms',
        'wpforms/wpforms.php' => 'wpforms-pro',
        'forminator/forminator.php' => 'forminator',
        'contact-form-7/wp-contact-form-7.php' => 'contact-form-7',
    ];

    $all_plugins = get_plugins();
    $active_plugins = (array) get_option('active_plugins', []);

    $result = [];
    foreach ($watched as $plugin_file => $key) {
        if (!isset($all_plugins[$plugin_file])) {
            continue;
        }
        $result[$key] = [
            'plugin_file' => $plugin_file,
            'version' => $all_plugins[$plugin_file]['Version'] ?? null,
            'active' => in_array($plugin_file, $active_plugins, true),
        ];
    }

    return $result;
}

/**
 * Détecte les templates de footer Elementor (post type elementor_library, meta
 * _elementor_template_type = footer) si Elementor Pro est actif. Ne lit pas encore le JSON
 * complet (réservé à l'endpoint d'écriture d'une étape suivante).
 */
function sah_get_elementor_footer_templates() {
    if (!post_type_exists('elementor_library')) {
        return [];
    }

    $templates = get_posts([
        'post_type' => 'elementor_library',
        'post_status' => 'publish',
        'numberposts' => -1,
        'meta_key' => '_elementor_template_type',
        'meta_value' => 'footer',
    ]);

    return array_map(function ($template) {
        return [
            'id' => $template->ID,
            'title' => $template->post_title,
            'modified' => $template->post_modified_gmt,
        ];
    }, $templates);
}
