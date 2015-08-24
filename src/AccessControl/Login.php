<?php
namespace Bolt\Extension\Blimp\Client\AccessControl;

use Bolt\AccessControl\Token\Token;
use Bolt\Application;
use Bolt\Storage\Entity;
use Bolt\Translation\Translator as Trans;
use Hautelook\Phpass\PasswordHash;
use Symfony\Component\HttpFoundation\Request;

class Login extends \Bolt\AccessControl\Login {
    public function loginFinish(Entity\Users $userEntity) {
        return parent::loginFinish($userEntity);
    }
}
