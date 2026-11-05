<?php

declare(strict_types=1);

namespace {
    if ( ! class_exists( 'WP_Ability', false ) ) {
        /** Just enough of core's WP_Ability for the stack lookup: execute() runs the callback inside a frame named execute. */
        class WP_Ability
        {
            public function __construct( private string $name ) {}

            public function get_name(): string
            {
                return $this->name;
            }

            public function execute( callable $callback ): mixed
            {
                return $callback();
            }
        }
    }
}
