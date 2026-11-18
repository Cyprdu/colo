<?php
// api/buy_points_wallet.php : paiement des points avec les gains, désactivé (l'argent des organismes est sur leur propre compte de paiement).
header('Content-Type: application/json');
http_response_code(410);
echo json_encode(['success' => false, 'message' => 'Le paiement avec les gains n\'est plus disponible : payez par carte ou par prélèvement.']);
