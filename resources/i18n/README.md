# Flux de traduction OracleData

Le français (`fr`) est la langue source et la langue par défaut. L’anglais (`en`) et l’espagnol (`es`) sont les langues cibles.

## Mise à jour

1. Ajouter la clé française dans `resources/js/locales/fr.json`.
2. Ajouter exactement la même clé dans `en.json` et `es.json`.
3. Respecter les termes approuvés dans `glossary.json` et conserver les noms de produits Oracle.
4. Mettre à jour les fichiers XLIFF lorsqu’un échange avec un traducteur externe est nécessaire.
5. Exécuter `php artisan test tests/Feature/LocalizationTest.php` : le test refuse tout catalogue incomplet.

Les messages Laravel se trouvent dans `lang/`. Les textes React se trouvent dans `resources/js/locales/`. Les variables comme `:name` doivent rester identiques dans toutes les langues.

## XLIFF

Les fichiers `oracledata.fr-en.xlf` et `oracledata.fr-es.xlf` utilisent XLIFF 2.0. Une unité est identifiée par la même clé stable que les catalogues JSON. Les états recommandés sont `initial`, `translated` et `reviewed`.
