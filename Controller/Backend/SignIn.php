<?php
namespace Bolt\Extension\Blimp\Client\Controller\Backend;

use Bolt\Controller\Backend\BackendBase;
use Bolt\Translation\Translator as Trans;
use Silex\ControllerCollection;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class SignIn extends BackendBase {
    protected function addRoutes(ControllerCollection $c) {
        $c->method('GET');

        $c->get('/sign-in', 'signin')->bind('postLogin');
    }

    public function signin(Request $request) {
        $login = new \Bolt\Extension\Blimp\Client\AccessControl\Login($this->app);
        $login->setRequest($request);

        $query = $request->query->all();

        $code = array_key_exists('code', $query) ? $query['code'] : null;
        $state = array_key_exists('state', $query) ? $query['state'] : null;

        $error = array_key_exists('error', $query) ? $query['error'] : null;
        $error_description = array_key_exists('error_description', $query) ? $query['error_description'] : null;

        $destination = $this->app['blimp_client.session_from_code']($code, $state, $error, $error_description);

        $access_token = $this->app['blimp_client.access_token']();

        $logged_in = !empty($access_token) ? $this->app['blimp_client.validate_access_token']($access_token, true, true) : false;

        if (!$logged_in) {
            $this->flashes()->error(Trans::__($error_description ?: $error));
            $response = new RedirectResponse($destination);
        } else {
            $user_id = $logged_in['profile_id'];

            if (!$userEntity = $this->getUser($user_id)) {
                $users_repo = $this->app['storage']->getRepository('Bolt\Storage\Entity\Users');

                $userEntity = new \Bolt\Storage\Entity\Users();
                $userEntity->setUsername($user_id);
                $userEntity->setPassword('not_local_user');
                $userEntity->setEmail($logged_in['profile']['email']);
                $userEntity->setDisplayname($logged_in['profile']['name']);
                $userEntity->setEnabled(true);

                $userEntity->setRoles(explode(' ', $logged_in['scope']));

                $users_repo->save($userEntity);
            }

            if (!$userEntity->getEnabled()) {
                $this->flashLogger->error(Trans::__('Your account is disabled. Sorry about that.'));

                $url = $this->app['blimp_client.request_code'](['return_to' => $destination]);
                $response = new RedirectResponse($url);
            } else {
                $login->loginFinish($userEntity);

                // Authentication data is cached in the session and if we can't get it
                // now, everyone is going to have a bad day. Make that obvious.
                if (!$token = $this->session()->get('authentication')) {
                    $this->flashes()->error(Trans::__("Unable to retrieve login session data. Please check your system's PHP session settings."));

                    $url = $this->app['blimp_client.request_code'](['return_to' => $destination]);
                    $response = new RedirectResponse($url);
                } else {
                    // Log in, if credentials are correct.
                    $this->app['logger.system']->info('Logged in: ' . $user_id, ['event' => 'authentication']);
                    $response = $this->setAuthenticationCookie(new RedirectResponse($destination), (string) $token);
                }
            }
        }

        return $response;
    }
}
