<?php
// Faux client Stripe pour tests/versements_integration.php (aucun appel réseau). $GLOBALS['stripe_fail'] = 'transfer' | 'reversal' | null.
namespace Stripe\Exception {
    class ApiErrorException extends \Exception {
        public $status = 400;
        public function getHttpStatus() { return $this->status; }
    }
}
namespace Stripe {
    class FakeTransfers {
        public $calls = [];
        public function create($params, $opts = []) {
            $this->calls[] = ['create', $params, $opts];
            if (($GLOBALS['stripe_fail'] ?? null) === 'transfer') { $e = new \Stripe\Exception\ApiErrorException('Compte non prêt'); throw $e; }
            return (object)['id' => 'tr_' . count($this->calls)];
        }
        public function createReversal($id, $params, $opts = []) {
            $this->calls[] = ['reversal', $id, $params, $opts];
            if (($GLOBALS['stripe_fail'] ?? null) === 'reversal') { $e = new \Stripe\Exception\ApiErrorException('Insufficient funds in Stripe account'); throw $e; }
            return (object)['id' => 'trr_' . count($this->calls)];
        }
    }
    /** Service générique : enregistre l'appel dans $GLOBALS['stripe_calls'] et répond via $GLOBALS['stripe_resp']['service.methode'] (valeur ou fonction). */
    class FakeService {
        public $name;
        public function __construct($name) { $this->name = $name; }
        public function __get($n) { return new FakeService($this->name . '.' . $n); } // ex. checkout->sessions
        public function __call($m, $args) {
            $k = $this->name . '.' . $m;
            $GLOBALS['stripe_calls'][] = [$k, $args];
            $r = $GLOBALS['stripe_resp'][$k] ?? null;
            if ($r instanceof \Closure) return $r(...$args);
            if ($r instanceof \Throwable) throw $r;
            return $r ?? (object)['id' => str_replace('.', '_', $k) . '_' . count($GLOBALS['stripe_calls'])];
        }
    }
    class StripeClient {
        public $transfers;
        public function __construct($key = null) { $this->transfers = $GLOBALS['fake_transfers'] ??= new FakeTransfers(); }
        public function __get($n) { return new FakeService($n); }
    }
}
