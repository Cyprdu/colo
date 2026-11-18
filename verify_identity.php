<?php
// L'identité et l'IBAN sont collectés directement par Stripe (compte Connect Express) : on redirige (jeton d'organisme ?o=).
header('Location: api/connect_onboarding.php?o=' . rawurlencode((string)($_GET['o'] ?? '')), true, 302);
exit;
