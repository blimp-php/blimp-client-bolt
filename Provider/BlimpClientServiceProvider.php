<?php
namespace Bolt\Extension\Blimp\Client\Provider;

use Bolt\Events\ControllerEvents;
use Bolt\Events\MountEvent;
use Bolt\Extension\Blimp\Client\Extension;
use GuzzleHttp\Client;
use Silex\Application;
use Silex\ServiceProviderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use Bolt\Extension\Blimp\Client\DataCollector\BlimpDataCollector;
use Bolt\Extension\Blimp\Client\DataCollector\HttpDataCollector;

class BlimpClientServiceProvider implements ServiceProviderInterface {
    private $ext;
    private $app;

    public function __construct(Extension $ext) {
        $this->ext = $ext;
    }

    public function register(Application $app) {
        $this->app = $app;

        // Set the 'bolt' toolbar item as the first one, and overriding the 'Symfony' one.
        // Note: we use this workaround, because setting $app['data_collector.templates'][0]
        // does not work.
        $templates = $app['data_collector.templates'];
        $bolt = array_shift($templates);
        $templates = array_merge(
            [['blimp', '@BlimpProfiler/profiler/toolbar.html.twig']],
            $templates
        );

        // Hackishly replace the template for the first toolbar item with our own.
        // $templates[1][1] = '@BlimpProfiler/toolbar/blimp.html.twig';

        $templates = array_merge(
            $templates,
            [['blimp-http', '@BlimpProfiler/profiler/http.html.twig']]
        );

        $app['data_collector.templates'] = $templates;

        $app['data_collectors'] = array_merge(
            $app['data_collectors'],
            [
                'blimp' => $app->share(
                    function ($app) {
                        return new BlimpDataCollector($app);
                    }
                ),
                'blimp-http' => $app->share(
                    function ($app) {
                        return $app['blimp_client.http_client.collector'];
                    }
                ),
            ]
        );

        $app['twig.loader.filesystem'] = $app->share(
            $app->extend(
                'twig.loader.filesystem',
                function (\Twig_Loader_Filesystem $filesystem, Application $app) {
                    $filesystem->addPath(__DIR__ . '/../twig', 'BlimpProfiler');

                    return $filesystem;
                }
            )
        );

        $app['blimp_client.backend_url'] = '';
        $app['blimp_client.client_id'] = '';
        $app['blimp_client.client_secret'] = null;
        $app['blimp_client.redirect_uri'] = '';
        $app['blimp_client.scope'] = null;

        $app['blimp_client.authorization_endpoint'] = '/oauth/authorize';
        $app['blimp_client.token_endpoint'] = '/oauth/token';
        $app['blimp_client.code_endpoint'] = '/oauth/code';
        $app['blimp_client.verify_endpoint'] = '/oauth/verify-credentials';

        $app['blimp_client.certificate'] = true;

        $app['blimp_client.http_client'] = $app->share(function () use ($app) {
            $c = new Client();

            $c->getEmitter()->attach($app['blimp_client.http_client.collector']);

            return $c;
        });

        $app['blimp_client.http_client.collector'] = $app->share(function () use ($app) {
            return new HttpDataCollector($app);
        });

        $app['blimp_client.access_token'] = $app->protect(function () use ($app) {
            if ($app['session']->has('blimp_access_token')) {
                return $app['session']->get('blimp_access_token');
            }

            return null;
        });

        $app['blimp_client.profile'] = $app->protect(function () use ($app) {
            if ($app['session']->has('blimp_profile')) {
                return $app['session']->get('blimp_profile');
            }

            return null;
        });

        $app['blimp_client.request_code'] = $app->protect(function ($context, $error = null, $error_description = null) use ($app) {
            $state = $app['blimp_client.random'](16);
            $app['session']->set('blimp_state_' . $state, $context);
            $params = array(
                'state' => $state,
                'response_type' => 'code',
                'client_id' => $app['blimp_client.client_id'],
            );

            $redirect_uri = $app['blimp_client.redirect_uri'];
            if (parse_url($redirect_uri, PHP_URL_SCHEME) === null) {
                $redirect_uri = $redirect_uri;
            }

            $params['redirect_uri'] = $redirect_uri;

            if (!empty($app['blimp_client.scope'])) {
                $params['scope'] = $app['blimp_client.scope'];
            }

            if (!empty($error)) {
                $params['error'] = $error;
            }

            if (!empty($error_description)) {
                $params['error_description'] = $error_description;
            }

            return $app['blimp_client.backend_url'] . $app['blimp_client.authorization_endpoint'] . '?' . http_build_query($params, null, '&');
        });

        $app['blimp_client.session_from_code'] = $app->protect(function ($code, $state, $out_error = null, $out_erro_description = null) use ($app) {
            $context = null;
            $error = '';
            $error_description = '';

            if (!empty($state)) {
                if ($app['session']->has('blimp_state_' . $state)) {
                    $context = $app['session']->get('blimp_state_' . $state);
                    $app['session']->remove('blimp_state_' . $state);

                    if (!empty($code)) {
                        $response_data = $app['blimp_client.token_from_code']($code);

                        if (!empty($response_data) && is_array($response_data)) {
                            if (array_key_exists('access_token', $response_data)) {
                                $response_data['best_before'] = time() + $response_data['expires_in'];

                                $app['session']->set('blimp_access_token', $response_data);

                                return !empty($context) && array_key_exists('return_to', $context) ? $context['return_to'] : '/';
                            } else if (array_key_exists('error', $response_data)) {
                                $error = $response_data['error'];
                                if (array_key_exists('error_description', $response_data)) {
                                    $error_description = $response_data['error_description'];
                                }
                            }
                        } else {
                            $error = 'server_error';
                            $error_description = 'Unknown error. Empty response.';
                        }
                    } else if (!empty($out_error)) {
                        $error = $out_error;
                        if (!empty($out_erro_description)) {
                            $error_description = $out_erro_description;
                        }
                    }
                } else {
                    $error = 'csrf_prevention';
                    $error_description = 'Invalid local state.';
                }
            } else {
                $error = 'csrf_prevention';
                $error_description = 'Missing local state.';
            }

            $app['session']->remove('blimp_access_token');

            return $app['blimp_client.request_code']($context, $error, $error_description);
        });

        $app['blimp_client.token_from_code'] = $app->protect(function ($code) use ($app) {
            $payload = [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $app['blimp_client.redirect_uri'],
            ];

            $auth = [$app['blimp_client.client_id'], $app['blimp_client.client_secret']];

            $response = $app['blimp_client.request']('POST', $app['blimp_client.token_endpoint'], null, $payload, $auth);

            return $response['data'];
        });

        $app['blimp_client.validate_access_token'] = $app->protect(function ($access_token, $include_entities = false, $force_remote_check = false) use ($app) {
            $force_remote_check = $force_remote_check || $access_token['best_before'] < time() || $include_entities && empty($app['blimp_client.profile']());

            if(!$force_remote_check) {
                $data = [];

                if (!empty($include_entities) && boolval($include_entities) && $include_entities !== 'false') {
                    $profile = $app['blimp_client.profile']();

                    if(!empty($profile)) {
                        $data['profile'] = $profile;
                    }
                }

                return $data;
            }

            $parameters = [
                'input_token' => $access_token['access_token'],
                'redirect_uri' => $app['blimp_client.redirect_uri'],
                'include_entities' => $include_entities,
            ];

            $auth = [$app['blimp_client.client_id'], $app['blimp_client.client_secret']];

            $response = $app['blimp_client.request']('GET', $app['blimp_client.verify_endpoint'], $parameters, null, $auth);

            if($response['status'] == 200) {
                if(!empty($response['data']['profile'])) {
                    $app['session']->set('blimp_profile', $response['data']['profile']);
                }

                return $response['data'];
            }

            return false;
        });

        $app['blimp_client.request'] = $app->protect(function ($method, $url, $parameters = null, $payload = null, $auth = null, $etag = null) use ($app) {
            if (strpos($url, '?') !== false) {
                $uri_query = array();
                $parameters = $parameters ?: array();

                $parts = explode('?', $url, 2);
                parse_str($parts[1], $uri_query);

                $parameters = array_merge($parameters, $uri_query);

                $url = $parts[0];
            }

            if (parse_url($url, PHP_URL_SCHEME) === null) {
                $url = $app['blimp_client.backend_url'] . $url;
            }

            $headers = array();

            $headers['User-Agent'] = 'blimp-client-php';
            $headers['Accept-Encoding'] = '*';
            // TODO Get it from somewhere
            $headers['Accept-Language'] = 'pt-PT';

            if (empty($auth)) {
                $access_token = $app['blimp_client.access_token']();

                if (!empty($access_token)) {
                    $headers['Authorization'] = $access_token['token_type'] . ' ' . $access_token['access_token'];

                    $client_secret = $app['blimp_client.client_secret'];
                    if (!empty($client_secret)) {
                        $headers['Authorization-Proof'] = hash_hmac('sha256', $access_token['access_token'], $client_secret);
                    }
                }
            }

            if (!empty($etag)) {
                $headers['If-None-Match'] = $etag;
            }

            $options = array();

            if ($headers) {
                $options['headers'] = $headers;
            }

            if (!empty($parameters)) {
                $options['query'] = $parameters;
            }

            if (!empty($payload)) {
                $options['json'] = $payload;
            }

            if (!empty($auth)) {
                $options['auth'] = $auth;
            }

            // $options['debug'] = true;

            $cert = $app['blimp_client.certificate'];
            if (!empty($cert)) {
                $options['verify'] = $cert;
            }

            $options['exceptions'] = false;

            $request = $app['blimp_client.http_client']->createRequest($method, $url, $options);
            $response = $app['blimp_client.http_client']->send($request);

            $response_status = $response->getStatusCode();
            $response_headers = $response->getHeaders();
            $response_body = $response->getBody();

            $response_data = json_decode($response_body, true);
            if ($response_data === null) {
                $response_data = array();
                parse_str($response_body, $response_data);
            }

            $etag_hit = !empty($etag) && $response_status == 304;

            if ($etag_hit) {
                $response_etag = $etag;
            } else {
                $response_etag = isset($response_headers['ETag']) ? $response_headers['ETag'] : null;
            }

            return [
                'status' => $response_status,
                'headers' => $response_headers,
                'body' => $response_body,
                'data' => $response_data,
                'etag' => $response_etag,
                'etag_hit' => $etag_hit,
            ];
        });

        $app['blimp_client.random'] = $app->protect(function ($bytes) {
            $buf = '';
            // http://sockpuppet.org/blog/2014/02/25/safely-generate-random-numbers/
            if (!ini_get('open_basedir')
                && is_readable('/dev/urandom')) {
                $fp = fopen('/dev/urandom', 'rb');
                if ($fp !== FALSE) {
                    $buf = fread($fp, $bytes);
                    fclose($fp);
                    if ($buf !== FALSE) {
                        return bin2hex($buf);
                    }
                }
            }

            if (function_exists('mcrypt_create_iv')) {
                $buf = mcrypt_create_iv($bytes, MCRYPT_DEV_URANDOM);
                if ($buf !== FALSE) {
                    return bin2hex($buf);
                }
            }

            while (strlen($buf) < $bytes) {
                $buf .= md5(uniqid(mt_rand(), true), true);
                // We are appending raw binary
            }

            return bin2hex(substr($buf, 0, $bytes));
        });
    }

    public function boot(Application $app) {
        $config = $this->ext->getConfig();

        if (!empty($config)) {
            $client_config = $config['blimp_client'];

            $app['blimp_client.backend_url'] = $client_config['backend_url'];
            $app['blimp_client.client_id'] = $client_config['client_id'];
            if (array_key_exists('client_secret', $client_config) && !empty($client_config['client_secret'])) {
                $app['blimp_client.client_secret'] = $client_config['client_secret'];
            } else {
                $app['blimp_client.client_secret'] = null;
            }
            if (array_key_exists('redirect_uri', $client_config)) {
                $app['blimp_client.redirect_uri'] = $client_config['redirect_uri'];
            } else {
                $app['blimp_client.redirect_uri'] = '';
            }
            if (array_key_exists('scope', $client_config) && !empty($client_config['scope'])) {
                $app['blimp_client.scope'] = $client_config['scope'];
            } else {
                $app['blimp_client.scope'] = null;
            }

            if (array_key_exists('certificate', $client_config)) {
                $app['blimp_client.certificate'] = $client_config['certificate'];
            }

            if (array_key_exists('authorization_endpoint', $client_config)) {
                $app['blimp_client.authorization_endpoint'] = $client_config['authorization_endpoint'];
            }

            if (array_key_exists('token_endpoint', $client_config)) {
                $app['blimp_client.token_endpoint'] = $client_config['token_endpoint'];
            }

            if (array_key_exists('code_endpoint', $client_config)) {
                $app['blimp_client.code_endpoint'] = $client_config['code_endpoint'];
            }

            if (array_key_exists('verify_endpoint', $client_config)) {
                $app['blimp_client.verify_endpoint'] = $client_config['verify_endpoint'];
            }
        }
    }
}
