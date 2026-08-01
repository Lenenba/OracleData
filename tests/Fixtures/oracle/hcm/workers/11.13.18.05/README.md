# Fixtures Oracle HCM Workers 11.13.18.05

Ces fichiers sont des contrats synthétiques dérivés de la documentation publique Oracle. Ils permettent de développer et tester hors tenant sans stocker de donnée personnelle ni de secret.

Ils ne constituent pas encore une capture d'un environnement Oracle réel. Cette limite est enregistrée dans `manifest.json` avec le statut `tenant-capture-pending`.

## Règles de remplacement par une capture tenant

1. Utiliser uniquement un environnement autorisé, de préférence non productif.
2. Enregistrer la version de ressource, la version REST Framework réellement envoyée et un alias pseudonyme du tenant.
3. Conserver les enveloppes Oracle, les types, les liens et la cohérence des identifiants.
4. Remplacer l'hôte par `https://oracle.invalid`.
5. Remplacer les identifiants par des jetons stables et cohérents entre champs et liens.
6. Supprimer noms, courriels, téléphones, numéros employés, cookies, autorisations, erreurs brutes et `Metadata-Context` réel.
7. Ne jamais fabriquer `workersUniqID`, `assignmentsUniqID` ou `managersUniqID` dans le moteur : ils proviennent des liens retournés par Oracle.
8. Faire relire le diff avant de committer la capture nettoyée.

Les fichiers `describe/*` sont des extraits contractuels ayant la forme acceptée par `OracleDescribeNormalizer`. Les métadonnées enfants sont documentées comme extraites du `/workers/describe`, car aucun endpoint enfant `/describe` n'a encore été validé sur un tenant.
