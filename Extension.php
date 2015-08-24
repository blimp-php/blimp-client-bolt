<?php

namespace Bolt\Extension\Blimp\Client;

use Bolt\BaseExtension;

class Extension extends BaseExtension {
    public function initialize() {
        // $this->addTwigPath(__DIR__ . '/twig', true);

        $this->app->register(new Provider\BlimpClientServiceProvider($this));
    }

    public function getName() {
        return "blimp-client-bolt";
    }
}
