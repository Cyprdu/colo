<?php
// L'identité et l'IBAN sont désormais collectés directement par Stripe (compte Connect Express) : on redirige.
$id = (int)($_GET['organisateur_id'] ?? 0);
header('Location: api/connect_onboarding.php?organisateur_id=' . $id, true, 302);
exit;
