<?php

namespace Bolt\Extension\Blimp\Client\DataCollector;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

class BlimpDataCollector extends \Bolt\Profiler\BoltDataCollector {
    protected $app;
    protected $data;

    public function __construct(Application $app) {
        parent::__construct($app);

        $this->app = $app;
    }

    public function getName() {
        return 'blimp';
    }

    public function collect(Request $request, Response $response, \Exception $exception = null) {
        parent::collect($request, $response, $exception);

        $access_token = $this->app['blimp_client.access_token']();
        $profile = $this->app['blimp_client.profile']();

        $has_token = !empty($access_token);

        $this->data = array_merge($this->data, [
            'branding_name' => $this->app['config']->get('general/branding/name'),
            'blimp_version' => '1.0',
            'backend_url' => $this->app['blimp_client.backend_url'],
            'client_id' => $this->app['blimp_client.client_id'],
            'client_secret' => $this->app['blimp_client.client_secret'],
            'redirect_uri' => $this->app['blimp_client.redirect_uri'],
            'scope' => $this->app['blimp_client.scope'],
            'user_token' => $has_token ? 'yes' : 'no',
            'role' => $has_token ? $access_token['scope'] : null,
            'expires_at' => $has_token ? date("Y-m-d H:i:s", $access_token['best_before']) : null,
            'profile_id' => $has_token ? $profile['id'] : null,
            'profile_name' => $has_token ? $profile['name'] : null
        ]);
    }

    public function getBranding_name() {
        return $this->data['branding_name'];
    }

    public function getBlimp_version() {
        return $this->data['blimp_version'];
    }

    public function getBackend_url() {
        return $this->data['backend_url'];
    }

    public function getClient_id() {
        return $this->data['client_id'];
    }

    public function getClient_secret() {
        return $this->data['client_secret'];
    }

    public function getRedirect_uri() {
        return $this->data['redirect_uri'];
    }

    public function getScope() {
        return $this->data['scope'];
    }

    public function getUser_token() {
        return $this->data['user_token'];
    }

    public function getRole() {
        return $this->data['role'];
    }

    public function getExpires_at() {
        return $this->data['expires_at'];
    }

    public function getProfile_id() {
        return $this->data['profile_id'];
    }

    public function getProfile_name() {
        return $this->data['profile_name'];
    }
}
