<?php

declare(strict_types=1);

// WooCommerce's own validation exception (same constructor shape); its message is meant to be read by the person.
// Defining it does not make WooCommerce look active: WooProductWriter::isActive() looks at WC_Product_Simple.
if ( ! class_exists( 'WC_Data_Exception', false ) ) {
    class WC_Data_Exception extends \Exception
    {
        public function __construct( private string $error_code, string $message, private int $http_status_code = 400 )
        {
            parent::__construct( $message );
        }

        public function getErrorCode(): string
        {
            return $this->error_code;
        }
    }
}
