# Site Automation Helper

Plugin compagnon WordPress de l'outil d'automatisation multi-sites
(`ecommerce-allemand-toolkit`, dépôt privé). Ce dépôt-ci est **public et ne contient que le
code du plugin** — jamais le code des projets clients, qui reste dans le dépôt privé.

Il existe uniquement pour que les sites WordPress équipés de ce plugin puissent détecter et
installer les mises à jour via le mécanisme natif de WordPress (page Extensions), sans
nécessiter d'authentification puisque les releases sont publiques.

## Installation initiale

Téléverser `site-automation-helper.zip` (voir [Releases](../../releases)) via
Extensions → Ajouter → Téléverser un plugin → Installer → Activer, puis récupérer la clé API
dans Réglages → Site Automation Helper.

## Mises à jour

Une fois installé, WordPress détecte automatiquement une nouvelle release publiée ici et
propose la mise à jour sur la page Extensions, comme pour une extension du répertoire
officiel — un clic sur « Mettre à jour maintenant » suffit.

## Source

Le code source de référence et son historique de développement vivent dans le dépôt privé
`ecommerce-allemand-toolkit` (`Tools/wordpress-deployment/plugin/site-automation-helper/`).
Ce dépôt est synchronisé manuellement à chaque nouvelle version prête à être distribuée.
