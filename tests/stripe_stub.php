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
    class StripeClient {
        public $transfers;
        public function __construct($key = null) { $this->transfers = $GLOBALS['fake_transfers'] ??= new FakeTransfers(); }
    }
}
