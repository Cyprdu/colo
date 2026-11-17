<?php
// api/add_child.php : ancien point d'entrée, retiré. La création d'un enfant se fait dans add_child.php (CSRF, anti-robot, documents chiffrés).
http_response_code(410);
header('Location: ../add_child');
exit;
